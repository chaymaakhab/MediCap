<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CliniqoAuthService;
use App\Services\CliniqoDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDO;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsAndAdminController
{
    // =========================================================================
    // AUTHENTICATION & ROLE SWITCHING (Section 3E)
    // =========================================================================
    public function listAuthProfiles(): JsonResponse
    {
        if (!app()->environment(['local', 'testing'])) {
            return response()->json([
                'success' => false,
                'message' => 'La connexion rapide est désactivée. Utilisez votre adresse e-mail et votre mot de passe.',
            ], 403);
        }

        $pdo = CliniqoDatabase::connection();
        $st = $pdo->query("
            SELECT u.id, u.tenant_id, u.name, u.email, u.role, u.title, u.avatar, u.all_branches,
                   t.short_name AS clinic_name
            FROM users u
            LEFT JOIN tenants t ON t.id = u.tenant_id
            WHERE u.is_active = 1
            ORDER BY CASE WHEN u.role='saas_owner' THEN 1 ELSE 0 END, u.id ASC
        ");
        return response()->json([
            'success' => true,
            'profiles' => $st->fetchAll(),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $pdo = CliniqoDatabase::connection();
        $userId = (int) $request->input('user_id', 0);
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        if ($userId > 0) {
            if (!app()->environment(['local', 'testing'])) {
                return response()->json(['success' => false, 'message' => 'La connexion rapide est désactivée. Utilisez votre adresse e-mail et votre mot de passe.'], 403);
            }

            $st = $pdo->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
            $st->execute([$userId]);
            $user = $st->fetch();
        } else {
            $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND is_active = 1 LIMIT 1');
            $st->execute([$email]);
            $user = $st->fetch();
            if ($user && !password_verify($password, (string) $user['password_hash'])) {
                return response()->json(['success' => false, 'message' => 'Identifiants invalides.'], 401);
            }
        }

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Compte utilisateur introuvable ou inactif.'], 401);
        }

        $tenantId = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
        $token = CliniqoAuthService::issueStaffToken((int) $user['id'], $tenantId);

        CliniqoAuthService::audit(
            $tenantId, null, (int) $user['id'], $user['name'], $user['role'],
            'AUTH_SESSION_ISSUED', 'user', $user['email'],
            "Connexion authentifiée rôle [{$user['role']}]",
            $request->ip() ?? '127.0.0.1'
        );

        // Build state for the newly authenticated token
        $request->headers->set('Authorization', 'Bearer ' . $token);
        if ($tenantId !== null) {
            $request->headers->set('X-Tenant-ID', (string) $tenantId);
        }
        $ctx = CliniqoAuthService::resolveStaffContext($request);

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $ctx['user'],
            'state' => SaasWorkspaceController::buildWorkspaceState($ctx),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        CliniqoAuthService::revokeStaffToken($request);

        return response()->json(['success' => true, 'message' => 'Déconnexion réussie.']);
    }

    // =========================================================================
    // SECTION 2 — COMPLETE ANALYTICAL REPORTS MODULE (100% REAL SQL DATA)
    // =========================================================================
    private function computeReportPayload(Request $request, array $ctx): array
    {
        $pdo = CliniqoDatabase::connection();
        $tenantId = (int) $ctx['tenant_id'];
        $today = CliniqoDatabase::today();

        $period = trim((string) $request->query('period', 'month'));
        if ($period === 'day') {
            $dateFrom = $today;
            $dateTo = $today;
        } elseif ($period === 'week') {
            $ts = strtotime($today);
            $dayOfWeek = (int) date('N', $ts); // 1 = Lundi, 7 = Dimanche
            $dateFrom = date('Y-m-d', strtotime('-' . ($dayOfWeek - 1) . ' days', $ts));
            $dateTo = date('Y-m-d', strtotime('+' . (7 - $dayOfWeek) . ' days', $ts));
        } elseif ($period === 'quarter') {
            $m = (int) date('n', strtotime($today));
            $y = date('Y', strtotime($today));
            $qStartMonth = sprintf('%02d', floor(($m - 1) / 3) * 3 + 1);
            $qEndMonth = sprintf('%02d', floor(($m - 1) / 3) * 3 + 3);
            $dateFrom = "{$y}-{$qStartMonth}-01";
            $dateTo = date('Y-m-t', strtotime("{$y}-{$qEndMonth}-01"));
        } elseif ($period === 'year') {
            $y = date('Y', strtotime($today));
            $dateFrom = "{$y}-01-01";
            $dateTo = "{$y}-12-31";
        } elseif ($period === 'custom') {
            $dateFrom = trim((string) $request->query('date_from', substr($today, 0, 8) . '01'));
            $dateTo = trim((string) $request->query('date_to', $today));
        } else {
            $period = 'month';
            $dateFrom = substr($today, 0, 8) . '01';
            $dateTo = date('Y-m-t', strtotime($today));
        }

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        // Branch filter respecting user's allowed_branch_ids!
        $allowedBranchIds = $ctx['allowed_branch_ids'] ?? [];
        $reqBranch = trim((string) ($request->query('branch_id', $ctx['branch_id'] ?? 'all')));
        $branchIdsFilter = $allowedBranchIds;
        if ($reqBranch !== '' && $reqBranch !== 'all' && $reqBranch !== '0') {
            $bInt = (int) $reqBranch;
            if (!in_array($bInt, $allowedBranchIds, true)) {
                CliniqoAuthService::abortJson(403, 'BRANCH_REPORT_FORBIDDEN', 'Accès non autorisé aux rapports de cette branche.');
            }
            $branchIdsFilter = [$bInt];
        }

        $doctorFilter = trim((string) $request->query('doctor', 'all'));
        $specialtyFilter = trim((string) $request->query('specialty', 'all'));
        $serviceFilter = trim((string) $request->query('service', 'all'));

        $bPlaceholders = !empty($branchIdsFilter) ? implode(',', array_fill(0, count($branchIdsFilter), '?')) : '0';

        // 1. Query Filtered Appointments in Period
        $aptSql = "SELECT * FROM appointments WHERE tenant_id = ? AND date >= ? AND date <= ? AND branch_id IN ({$bPlaceholders})";
        $aptParams = array_merge([$tenantId, $dateFrom, $dateTo], $branchIdsFilter);
        if ($doctorFilter !== '' && $doctorFilter !== 'all') {
            $aptSql .= ' AND LOWER(doctor) = LOWER(?)';
            $aptParams[] = $doctorFilter;
        }
        if ($specialtyFilter !== '' && $specialtyFilter !== 'all') {
            $aptSql .= ' AND LOWER(specialty) = LOWER(?)';
            $aptParams[] = $specialtyFilter;
        }
        if ($serviceFilter !== '' && $serviceFilter !== 'all') {
            $aptSql .= ' AND LOWER(service_name) = LOWER(?)';
            $aptParams[] = $serviceFilter;
        }
        $aptSql .= ' ORDER BY date ASC, time ASC';

        $stApt = $pdo->prepare($aptSql);
        $stApt->execute($aptParams);
        $apts = $stApt->fetchAll();

        $totalApts = count($apts);
        $completedCnt = 0;
        $inProgressWaitingCnt = 0;
        $confirmedCnt = 0;
        $cancelledCnt = 0;
        $noShowCnt = 0;

        $volumeByDate = [];
        $doctorStats = [];
        $specialtyStats = [];
        $serviceStats = [];
        $branchAptStats = [];

        foreach ($apts as $a) {
            $st = $a['status'];
            $dt = $a['date'];
            $doc = $a['doctor'];
            $spec = $a['specialty'] ?: 'Non spécifié';
            $srv = $a['service_name'] ?: 'Consultation';
            $bid = (int) $a['branch_id'];

            if (!isset($volumeByDate[$dt])) {
                $volumeByDate[$dt] = ['date' => $dt, 'total' => 0, 'completed' => 0, 'attended' => 0, 'cancelled' => 0, 'no_show' => 0];
            }
            $volumeByDate[$dt]['total']++;

            if (!isset($doctorStats[$doc])) {
                $doctorStats[$doc] = [
                    'doctor' => $doc, 'specialty' => $spec, 'total_appointments' => 0,
                    'consultations_completed' => 0, 'active_or_waiting' => 0,
                    'cancelled' => 0, 'no_show' => 0, 'scheduled_fees_mad' => 0.0,
                ];
            }
            $doctorStats[$doc]['total_appointments']++;

            if (!isset($specialtyStats[$spec])) {
                $specialtyStats[$spec] = ['specialty' => $spec, 'count' => 0, 'completed' => 0, 'fees_mad' => 0.0];
            }
            $specialtyStats[$spec]['count']++;

            if (!isset($serviceStats[$srv])) {
                $serviceStats[$srv] = ['service' => $srv, 'specialty' => $spec, 'count' => 0, 'completed' => 0, 'fees_mad' => 0.0];
            }
            $serviceStats[$srv]['count']++;

            if (!isset($branchAptStats[$bid])) {
                $branchAptStats[$bid] = ['total' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0];
            }
            $branchAptStats[$bid]['total']++;

            if ($st === 'completed') {
                $completedCnt++;
                $volumeByDate[$dt]['completed']++;
                $volumeByDate[$dt]['attended']++;
                $doctorStats[$doc]['consultations_completed']++;
                $doctorStats[$doc]['scheduled_fees_mad'] += (float) $a['fee_mad'];
                $specialtyStats[$spec]['completed']++;
                $specialtyStats[$spec]['fees_mad'] += (float) $a['fee_mad'];
                $serviceStats[$srv]['completed']++;
                $serviceStats[$srv]['fees_mad'] += (float) $a['fee_mad'];
                $branchAptStats[$bid]['completed']++;
            } elseif (in_array($st, ['in_progress', 'waiting'], true)) {
                $inProgressWaitingCnt++;
                $volumeByDate[$dt]['attended']++;
                $doctorStats[$doc]['active_or_waiting']++;
                $doctorStats[$doc]['scheduled_fees_mad'] += (float) $a['fee_mad'];
            } elseif ($st === 'cancelled') {
                $cancelledCnt++;
                $volumeByDate[$dt]['cancelled']++;
                $doctorStats[$doc]['cancelled']++;
                $branchAptStats[$bid]['cancelled']++;
            } elseif ($st === 'no_show') {
                $noShowCnt++;
                $volumeByDate[$dt]['no_show']++;
                $doctorStats[$doc]['no_show']++;
                $branchAptStats[$bid]['no_show']++;
            } else {
                $confirmedCnt++;
                $doctorStats[$doc]['scheduled_fees_mad'] += (float) $a['fee_mad'];
            }
        }

        $cancelRatePct = $totalApts > 0 ? round(($cancelledCnt / $totalApts) * 100, 1) : 0.0;
        $nonCancelled = max(0, $totalApts - $cancelledCnt);
        $noShowRatePct = $nonCancelled > 0 ? round(($noShowCnt / $nonCancelled) * 100, 1) : 0.0;
        $attendedTotal = $completedCnt + $inProgressWaitingCnt;
        $resolvedSlots = $attendedTotal + $noShowCnt;
        $attendanceRatePct = $resolvedSlots > 0 ? round(($attendedTotal / $resolvedSlots) * 100, 1) : ($nonCancelled > 0 ? 100.0 : 0.0);

        ksort($volumeByDate);
        $doctorActivityList = array_values($doctorStats);
        usort($doctorActivityList, static fn(array $a, array $b): int => $b['consultations_completed'] <=> $a['consultations_completed']);

        // Motifs de consultation les plus fréquents (Médecine Générale)
        $reasonsStats = [];
        $patientAptMap = [];
        foreach ($apts as $a) {
            $r = trim($a['reason'] ?? '');
            if ($r === '') {
                $r = trim($a['service_name'] ?? 'Consultation Générale');
            }
            if (!isset($reasonsStats[$r])) {
                $reasonsStats[$r] = ['reason' => $r, 'count' => 0, 'completed' => 0];
            }
            $reasonsStats[$r]['count']++;
            if ($a['status'] === 'completed') {
                $reasonsStats[$r]['completed']++;
            }

            $pid = (int) $a['patient_id'];
            if ($pid > 0) {
                if (!isset($patientAptMap[$pid])) {
                    $patientAptMap[$pid] = ['patient_id' => $pid, 'total' => 0, 'completed' => 0, 'fee' => 0.0];
                }
                $patientAptMap[$pid]['total']++;
                if ($a['status'] === 'completed') {
                    $patientAptMap[$pid]['completed']++;
                    $patientAptMap[$pid]['fee'] += (float) $a['fee_mad'];
                }
            }
        }
        $topReasonsList = array_values($reasonsStats);
        usort($topReasonsList, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        foreach ($topReasonsList as &$trItem) {
            $trItem['share_pct'] = $totalApts > 0 ? round(($trItem['count'] / $totalApts) * 100, 1) : 0.0;
        }
        unset($trItem);

        $specialtiesList = array_values($specialtyStats);
        usort($specialtiesList, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        foreach ($specialtiesList as &$spItem) {
            $spItem['share_pct'] = $totalApts > 0 ? round(($spItem['count'] / $totalApts) * 100, 1) : 0.0;
        }
        unset($spItem);

        // Nouveaux patients enregistrés sur la période
        $stNewPat = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE tenant_id = ? AND date(created_at) >= ? AND date(created_at) <= ? AND branch_id IN ({$bPlaceholders})");
        $stNewPat->execute(array_merge([$tenantId, $dateFrom, $dateTo], $branchIdsFilter));
        $newPatientsCount = (int) $stNewPat->fetchColumn();

        // Total patients actifs enregistrés
        $stTotPat = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE tenant_id = ? AND branch_id IN ({$bPlaceholders})");
        $stTotPat->execute(array_merge([$tenantId], $branchIdsFilter));
        $totalRegisteredPatients = (int) $stTotPat->fetchColumn();

        // Répartition des Rendez-vous par statut
        $appointmentsByStatus = [
            ['status' => 'completed', 'label' => 'Terminés', 'count' => $completedCnt, 'share_pct' => $totalApts > 0 ? round(($completedCnt / $totalApts) * 100, 1) : 0.0],
            ['status' => 'waiting_in_progress', 'label' => 'En cours / Salle d’attente', 'count' => $inProgressWaitingCnt, 'share_pct' => $totalApts > 0 ? round(($inProgressWaitingCnt / $totalApts) * 100, 1) : 0.0],
            ['status' => 'confirmed_upcoming', 'label' => 'Confirmés / À venir', 'count' => $confirmedCnt, 'share_pct' => $totalApts > 0 ? round(($confirmedCnt / $totalApts) * 100, 1) : 0.0],
            ['status' => 'cancelled', 'label' => 'Annulés', 'count' => $cancelledCnt, 'share_pct' => $totalApts > 0 ? round(($cancelledCnt / $totalApts) * 100, 1) : 0.0],
            ['status' => 'no_show', 'label' => 'Absents non excusés (No-Show)', 'count' => $noShowCnt, 'share_pct' => $totalApts > 0 ? round(($noShowCnt / $totalApts) * 100, 1) : 0.0],
        ];

        $servicesList = array_values($serviceStats);
        usort($servicesList, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        foreach ($servicesList as &$svItem) {
            $svItem['share_pct'] = $totalApts > 0 ? round(($svItem['count'] / $totalApts) * 100, 1) : 0.0;
        }
        unset($svItem);

        // 2. Financial Calculations (Invoiced vs Collected vs Refunds vs Outstanding vs Expenses & Payment Methods)
        $invSql = "SELECT * FROM invoices WHERE tenant_id = ? AND issued_date >= ? AND issued_date <= ? AND status != 'cancelled' AND branch_id IN ({$bPlaceholders})";
        $invParams = array_merge([$tenantId, $dateFrom, $dateTo], $branchIdsFilter);
        if ($doctorFilter !== '' && $doctorFilter !== 'all') {
            $invSql .= ' AND LOWER(doctor_name) = LOWER(?)';
            $invParams[] = $doctorFilter;
        }
        if ($specialtyFilter !== '' && $specialtyFilter !== 'all') {
            $invSql .= ' AND LOWER(specialty) = LOWER(?)';
            $invParams[] = $specialtyFilter;
        }
        if ($serviceFilter !== '' && $serviceFilter !== 'all') {
            $invSql .= ' AND LOWER(service) = LOWER(?)';
            $invParams[] = $serviceFilter;
        }
        $stInv = $pdo->prepare($invSql);
        $stInv->execute($invParams);
        $periodInvoices = $stInv->fetchAll();

        $invoicedAmount = 0.0;
        $outstandingInPeriod = 0.0;
        $partialCount = 0;
        $partialPaidSum = 0.0;
        $partialRemainingSum = 0.0;

        foreach ($periodInvoices as $inv) {
            $tot = (float) $inv['total'];
            $netP = max(0.0, (float) $inv['paid'] - (float) $inv['refunded']);
            $rem = max(0.0, $tot - $netP);
            $invoicedAmount += $tot;
            $outstandingInPeriod += $rem;
            if ($netP > 0.001 && $rem > 0.001) {
                $partialCount++;
                $partialPaidSum += $netP;
                $partialRemainingSum += $rem;
            }
        }

        // Global outstanding across all open invoices in selected branch(es)
        $stOutAll = $pdo->prepare("SELECT total, paid, refunded, branch_id FROM invoices WHERE tenant_id = ? AND status != 'cancelled' AND branch_id IN ({$bPlaceholders})");
        $stOutAll->execute(array_merge([$tenantId], $branchIdsFilter));
        $globalOutstanding = 0.0;
        $branchOutStats = [];
        foreach ($stOutAll->fetchAll() as $oi) {
            $rem = max(0.0, (float) $oi['total'] - max(0.0, (float) $oi['paid'] - (float) $oi['refunded']));
            $globalOutstanding += $rem;
            $bid = (int) $oi['branch_id'];
            $branchOutStats[$bid] = ($branchOutStats[$bid] ?? 0.0) + $rem;
        }

        // Actual Payments Collected in Period (joined with invoices when doctor/specialty/service filter active)
        $paySql = "
            SELECT p.*, i.doctor_name, i.specialty, i.service AS inv_service
            FROM payments p
            INNER JOIN invoices i ON i.id = p.invoice_id
            WHERE p.tenant_id = ? AND p.paid_date >= ? AND p.paid_date <= ? AND p.status = 'valid'
              AND p.branch_id IN ({$bPlaceholders})
        ";
        $payParams = array_merge([$tenantId, $dateFrom, $dateTo], $branchIdsFilter);
        if ($doctorFilter !== '' && $doctorFilter !== 'all') {
            $paySql .= ' AND LOWER(i.doctor_name) = LOWER(?)';
            $payParams[] = $doctorFilter;
        }
        if ($specialtyFilter !== '' && $specialtyFilter !== 'all') {
            $paySql .= ' AND LOWER(i.specialty) = LOWER(?)';
            $payParams[] = $specialtyFilter;
        }
        if ($serviceFilter !== '' && $serviceFilter !== 'all') {
            $paySql .= ' AND LOWER(i.service) = LOWER(?)';
            $payParams[] = $serviceFilter;
        }

        $stPay = $pdo->prepare($paySql);
        $stPay->execute($payParams);
        $periodPayments = $stPay->fetchAll();

        $grossCollected = 0.0;
        $refundsAmount = 0.0;
        $methodsMap = [];
        $branchRevStats = [];

        foreach ($periodPayments as $py) {
            $amt = (float) $py['amount'];
            $m = $py['method'] ?: 'Espèces';
            $bid = (int) $py['branch_id'];
            if ($py['payment_type'] === 'refund') {
                $refundsAmount += $amt;
                $branchRevStats[$bid] = ($branchRevStats[$bid] ?? 0.0) - $amt;
            } else {
                $grossCollected += $amt;
                $branchRevStats[$bid] = ($branchRevStats[$bid] ?? 0.0) + $amt;
                if (!isset($methodsMap[$m])) {
                    $methodsMap[$m] = ['method' => $m, 'transactions' => 0, 'amount' => 0.0];
                }
                $methodsMap[$m]['transactions']++;
                $methodsMap[$m]['amount'] += $amt;
            }
        }
        $netCollected = max(0.0, $grossCollected - $refundsAmount);

        $paymentMethodsList = array_values($methodsMap);
        usort($paymentMethodsList, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);
        foreach ($paymentMethodsList as &$pm) {
            $pm['amount'] = round($pm['amount'], 2);
            $pm['share_pct'] = $grossCollected > 0 ? round(($pm['amount'] / $grossCollected) * 100, 1) : 0.0;
        }
        unset($pm);

        // Expenses in Period (only when no doctor-specific filter is applied, or attributed to branch)
        $expSql = "SELECT * FROM expenses WHERE tenant_id = ? AND expense_date >= ? AND expense_date <= ? AND branch_id IN ({$bPlaceholders})";
        $stExp = $pdo->prepare($expSql);
        $stExp->execute(array_merge([$tenantId, $dateFrom, $dateTo], $branchIdsFilter));
        $periodExpenses = $stExp->fetchAll();

        $expensesTotal = 0.0;
        $expByCat = [];
        $branchExpStats = [];
        foreach ($periodExpenses as $ex) {
            $amt = (float) $ex['amount'];
            $expensesTotal += $amt;
            $cat = $ex['category'] ?: 'Autres';
            $expByCat[$cat] = ($expByCat[$cat] ?? 0.0) + $amt;
            $bid = (int) $ex['branch_id'];
            $branchExpStats[$bid] = ($branchExpStats[$bid] ?? 0.0) + $amt;
        }
        arsort($expByCat);
        $expensesCategories = [];
        foreach ($expByCat as $cLabel => $cAmt) {
            $expensesCategories[] = ['category' => $cLabel, 'amount' => round($cAmt, 2)];
        }

        // 3. Multi-Branch Comparison (when clinic has branches)
        $stBranches = $pdo->prepare("SELECT * FROM branches WHERE tenant_id = ? AND is_active = 1 AND id IN ({$bPlaceholders}) ORDER BY is_main DESC, id ASC");
        $stBranches->execute(array_merge([$tenantId], $branchIdsFilter));
        $branchComparison = [];
        foreach ($stBranches->fetchAll() as $br) {
            $bid = (int) $br['id'];
            $ba = $branchAptStats[$bid] ?? ['total' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0];
            $rev = round(max(0.0, $branchRevStats[$bid] ?? 0.0), 2);
            $exp = round($branchExpStats[$bid] ?? 0.0, 2);
            $out = round($branchOutStats[$bid] ?? 0.0, 2);
            $nonCanc = max(0, $ba['total'] - $ba['cancelled']);
            $att = ($ba['completed'] + $ba['no_show']) > 0
                ? round(($ba['completed'] / ($ba['completed'] + $ba['no_show'])) * 100, 1)
                : ($nonCanc > 0 ? 100.0 : 0.0);

            $branchComparison[] = [
                'branch_id' => $bid,
                'branch_name' => $br['name'],
                'code' => $br['code'],
                'appointments_total' => $ba['total'],
                'completed' => $ba['completed'],
                'cancelled' => $ba['cancelled'],
                'no_show' => $ba['no_show'],
                'attendance_pct' => $att,
                'revenue_collected' => $rev,
                'outstanding_balance' => $out,
                'expenses_total' => $exp,
                'net_margin' => round($rev - $exp, 2),
            ];
        }

        // Factures impayées de la période
        $unpaidInvoicesList = [];
        foreach ($periodInvoices as $inv) {
            $tot = (float) $inv['total'];
            $netP = max(0.0, (float) $inv['paid'] - (float) $inv['refunded']);
            $rem = max(0.0, $tot - $netP);
            if ($rem > 0.01) {
                $unpaidInvoicesList[] = [
                    'id' => (int) $inv['id'],
                    'num' => $inv['num'],
                    'patient_id' => (int) $inv['patient_id'],
                    'patient_name' => $inv['patient_name'],
                    'doctor_name' => $inv['doctor_name'],
                    'service' => $inv['service'],
                    'issued_date' => $inv['issued_date'],
                    'total' => round($tot, 2),
                    'paid' => round($netP, 2),
                    'remaining' => round($rem, 2),
                    'status' => $inv['status'],
                ];
            }
        }
        usort($unpaidInvoicesList, static fn($a, $b) => $b['remaining'] <=> $a['remaining']);

        // Historique des encaissements de la période
        $paymentsHistoryList = [];
        foreach ($periodPayments as $py) {
            $paymentsHistoryList[] = [
                'id' => (int) $py['id'],
                'invoice_id' => (int) $py['invoice_id'],
                'receipt_num' => $py['receipt_num'],
                'amount' => round((float) $py['amount'], 2),
                'payment_type' => $py['payment_type'],
                'method' => $py['method'] ?: 'Espèces',
                'paid_date' => $py['paid_date'],
                'paid_time' => $py['paid_time'] ?? '',
                'recorded_by' => $py['recorded_by'] ?? 'Caisse Cabinet',
                'notes' => $py['notes'] ?? '',
            ];
        }
        usort($paymentsHistoryList, static fn($a, $b) => strcmp(($b['paid_date'] ?? '') . ($b['paid_time'] ?? ''), ($a['paid_date'] ?? '') . ($a['paid_time'] ?? '')));

        // Patients les plus actifs sur la période
        $topPatientsList = [];
        if (!empty($patientAptMap)) {
            $pIds = array_keys($patientAptMap);
            $pPlaceholders = implode(',', array_fill(0, count($pIds), '?'));
            $stPat = $pdo->prepare("SELECT id, code, first, last, cin, phone, coverage FROM patients WHERE id IN ({$pPlaceholders})");
            $stPat->execute($pIds);
            $patInfo = [];
            foreach ($stPat->fetchAll() as $p) {
                $patInfo[(int)$p['id']] = $p;
            }
            foreach ($patientAptMap as $pid => $pData) {
                $pi = $patInfo[$pid] ?? null;
                $topPatientsList[] = [
                    'patient_id' => $pid,
                    'patient_name' => $pi ? trim($pi['first'] . ' ' . $pi['last']) : "Patient #{$pid}",
                    'code' => $pi['code'] ?? '',
                    'cin' => $pi['cin'] ?? '',
                    'phone' => $pi['phone'] ?? '',
                    'coverage' => $pi['coverage'] ?? '',
                    'appointments_total' => $pData['total'],
                    'consultations_completed' => $pData['completed'],
                    'fees_total_mad' => round($pData['fee'], 2),
                ];
            }
            usort($topPatientsList, static fn($a, $b) => ($b['consultations_completed'] <=> $a['consultations_completed']) ?: ($b['appointments_total'] <=> $a['appointments_total']));
            $topPatientsList = array_slice($topPatientsList, 0, 15);
        }

        return [
            'filters' => [
                'period' => $period,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'branch_id' => $reqBranch,
                'doctor' => $doctorFilter,
                'specialty' => $specialtyFilter,
                'service' => $serviceFilter,
            ],
            'has_data' => ($totalApts > 0 || count($periodInvoices) > 0 || count($periodPayments) > 0 || count($periodExpenses) > 0),
            'appointments_summary' => [
                'total' => $totalApts,
                'completed' => $completedCnt,
                'in_progress_or_waiting' => $inProgressWaitingCnt,
                'confirmed_upcoming' => $confirmedCnt,
                'cancelled' => $cancelledCnt,
                'no_show' => $noShowCnt,
                'cancellation_rate_pct' => $cancelRatePct,
                'no_show_rate_pct' => $noShowRatePct,
                'attendance_rate_pct' => $attendanceRatePct,
            ],
            'patients_summary' => [
                'new_patients_count' => $newPatientsCount,
                'total_registered' => $totalRegisteredPatients,
                'active_in_period' => count($patientAptMap),
            ],
            'volume_timeline' => array_values($volumeByDate),
            'doctor_activity' => $doctorActivityList,
            'top_reasons' => $topReasonsList,
            'top_specialties' => $specialtiesList,
            'top_services' => $servicesList,
            'appointments_by_status' => $appointmentsByStatus,
            'top_patients' => $topPatientsList,
            'unpaid_invoices' => $unpaidInvoicesList,
            'payments_history' => $paymentsHistoryList,
            'financial_summary' => [
                'invoiced_amount' => round($invoicedAmount, 2),
                'gross_collected' => round($grossCollected, 2),
                'refunds_amount' => round($refundsAmount, 2),
                'revenue_collected' => round($netCollected, 2),
                'outstanding_period' => round($outstandingInPeriod, 2),
                'outstanding_global' => round($globalOutstanding, 2),
                'expenses_total' => round($expensesTotal, 2),
                'net_cashflow' => round($netCollected - $expensesTotal, 2),
                'partial_payments' => [
                    'invoices_count' => $partialCount,
                    'paid_amount' => round($partialPaidSum, 2),
                    'remaining_amount' => round($partialRemainingSum, 2),
                ],
                'payment_methods' => $paymentMethodsList,
                'expenses_by_category' => $expensesCategories,
            ],
            'branch_comparison' => $branchComparison,
        ];
    }

    public function getReportsSummary(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'reports.read');
        $report = $this->computeReportPayload($request, $ctx);
        return response()->json([
            'success' => true,
            'report' => $report,
        ]);
    }

    public function exportReportsCsv(Request $request): StreamedResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'reports.read');
        $report = $this->computeReportPayload($request, $ctx);
        $f = $report['filters'];

        CliniqoAuthService::audit(
            (int) $ctx['tenant_id'],
            $ctx['branch_id'],
            (int) $ctx['user']['id'],
            $ctx['user']['name'],
            $ctx['role'],
            'EXPORT_REPORT_CSV',
            'report',
            "{$f['date_from']}_{$f['date_to']}",
            "Export CSV analytique ({$f['period']})",
            $request->ip() ?? '127.0.0.1'
        );

        $filename = sprintf('cliniqo_rapport_%s_%s.csv', $f['date_from'], $f['date_to']);

        return response()->streamDownload(static function () use ($report): void {
            $out = fopen('php://output', 'wb');
            if (!$out) {
                return;
            }
            // UTF-8 BOM for Excel FR compatibility
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['RAPPORT ANALYTIQUE CLINIQO', 'Du ' . $report['filters']['date_from'], 'Au ' . $report['filters']['date_to']]);
            fputcsv($out, []);

            $as = $report['appointments_summary'];
            $ps = $report['patients_summary'] ?? ['new_patients_count' => 0, 'total_registered' => 0];
            fputcsv($out, ['--- SYNTHÈSE ACTIVITÉ & PATIENTÈLE (12 KPIS) ---']);
            fputcsv($out, ['Total RDV', 'Consultations Réalisées', 'Annulations', 'No-Show', 'Taux Présence (%)', 'Nouveaux Patients', 'Patients Actifs Période']);
            fputcsv($out, [$as['total'], $as['completed'], $as['cancelled'], $as['no_show'], $as['attendance_rate_pct'], $ps['new_patients_count'], $ps['active_in_period'] ?? 0]);
            fputcsv($out, []);

            $fs = $report['financial_summary'];
            fputcsv($out, ['--- SYNTHÈSE FINANCIÈRE OBLIGATOIRE (MAD) ---']);
            fputcsv($out, ['Total Facturé', 'Total Encaissé', 'Soldes Impayés Période', 'Soldes Impayés Globaux', 'Total Dépenses', 'Trésorerie Nette Période', 'Remboursements']);
            fputcsv($out, [$fs['invoiced_amount'], $fs['revenue_collected'], $fs['outstanding_period'], $fs['outstanding_global'], $fs['expenses_total'], $fs['net_cashflow'], $fs['refunds_amount']]);
            fputcsv($out, []);

            fputcsv($out, ['--- MOTIFS DE CONSULTATION LES PLUS FRÉQUENTS ---']);
            fputcsv($out, ['Motif de Consultation', 'Occurrences', 'Consultations Terminées', 'Part (%)']);
            foreach ($report['top_reasons'] ?? [] as $tr) {
                fputcsv($out, [$tr['reason'], $tr['count'], $tr['completed'], $tr['share_pct']]);
            }
            fputcsv($out, []);

            fputcsv($out, ['--- ACTIVITÉ PAR PRATICIEN ---']);
            fputcsv($out, ['Médecin', 'Spécialité', 'Total RDV', 'Consultations Terminées', 'Annulés', 'No-Show', 'Honoraires Réalisés (MAD)']);
            foreach ($report['doctor_activity'] as $da) {
                fputcsv($out, [$da['doctor'], $da['specialty'], $da['total_appointments'], $da['consultations_completed'], $da['cancelled'], $da['no_show'], $da['scheduled_fees_mad']]);
            }
            fputcsv($out, []);

            fputcsv($out, ['--- FACTURES IMPAYÉES DE LA PÉRIODE ---']);
            fputcsv($out, ['N° Facture', 'Patient', 'Médecin', 'Date Émission', 'Total Facturé (MAD)', 'Montant Réglé (MAD)', 'Solde Restant (MAD)']);
            foreach ($report['unpaid_invoices'] ?? [] as $ui) {
                fputcsv($out, [$ui['num'], $ui['patient_name'], $ui['doctor_name'], $ui['issued_date'], $ui['total'], $ui['paid'], $ui['remaining']]);
            }
            fputcsv($out, []);

            fputcsv($out, ['--- MODES DE RÈGLEMENT ---']);
            fputcsv($out, ['Mode', 'Transactions', 'Montant (MAD)', 'Part (%)']);
            foreach ($fs['payment_methods'] as $pm) {
                fputcsv($out, [$pm['method'], $pm['transactions'], $pm['amount'], $pm['share_pct']]);
            }
            fputcsv($out, []);

            fputcsv($out, ['--- DÉPENSES PAR CATÉGORIE ---']);
            fputcsv($out, ['Catégorie', 'Montant (MAD)']);
            foreach ($fs['expenses_by_category'] as $ec) {
                fputcsv($out, [$ec['category'], $ec['amount']]);
            }
            fputcsv($out, []);

            fputcsv($out, ['--- COMPARATIF PAR BRANCHE / SITE ---']);
            fputcsv($out, ['Branche', 'Code', 'RDV', 'Terminés', 'Présence (%)', 'Recettes Encaissées (MAD)', 'Dépenses (MAD)', 'Marge Nette (MAD)']);
            foreach ($report['branch_comparison'] as $bc) {
                fputcsv($out, [$bc['branch_name'], $bc['code'], $bc['appointments_total'], $bc['completed'], $bc['attendance_pct'], $bc['revenue_collected'], $bc['expenses_total'], $bc['net_margin']]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // =========================================================================
    // SECTION 3A & 3B — CENTRES, BRANCHES, SETTINGS, SERVICES, HOLIDAYS & USERS
    // =========================================================================
    public function getCentreSettings(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'settings.manage');
        $pdo = CliniqoDatabase::connection();
        $tenantId = (int) $ctx['tenant_id'];

        $stUsers = $pdo->prepare('SELECT id, name, email, role, title, phone, all_branches, is_active FROM users WHERE tenant_id = ? ORDER BY id ASC');
        $stUsers->execute([$tenantId]);
        $users = $stUsers->fetchAll();

        $stUb = $pdo->prepare('SELECT branch_id FROM user_branches WHERE user_id = ?');
        foreach ($users as &$u) {
            $stUb->execute([(int) $u['id']]);
            $u['branch_ids'] = array_map('intval', array_column($stUb->fetchAll(), 'branch_id'));
        }
        unset($u);

        return response()->json([
            'success' => true,
            'users' => $users,
            'backups' => CliniqoDatabase::listBackups(),
            'state' => SaasWorkspaceController::buildWorkspaceState($ctx),
        ]);
    }

    public function updateCentreSettings(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'settings.manage');
        $pdo = CliniqoDatabase::connection();
        $tenantId = (int) $ctx['tenant_id'];

        $st = $pdo->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
        $st->execute([$tenantId]);
        $cur = $st->fetch();
        if (!$cur) {
            return response()->json(['success' => false, 'message' => 'Centre introuvable.'], 404);
        }

        $sub = CliniqoAuthService::requireActiveSubscription($tenantId);

        $enableLab = $request->has('enable_laboratory') ? (int) ((bool) $request->input('enable_laboratory')) : (int) $cur['enable_laboratory'];
        $enableInv = $request->has('enable_inventory') ? (int) ((bool) $request->input('enable_inventory')) : (int) $cur['enable_inventory'];
        $enableBeds = $request->has('enable_inpatient_beds') ? (int) ((bool) $request->input('enable_inpatient_beds')) : (int) $cur['enable_inpatient_beds'];

        if ($enableLab === 1 && (int) $sub['enable_lab_feature'] !== 1) {
            return response()->json(['success' => false, 'message' => "Votre forfait actuel ({$sub['plan_name']}) n'inclut pas le module Laboratoire."], 403);
        }
        if ($enableInv === 1 && (int) $sub['enable_inventory_feature'] !== 1) {
            return response()->json(['success' => false, 'message' => "Votre forfait actuel ({$sub['plan_name']}) n'inclut pas le module Stock."], 403);
        }

        $pdo->prepare('
            UPDATE tenants SET
                name=?, short_name=?, city=?, address=?, phone=?, email=?,
                ice=?, if_tax=?, inpe=?, cnss_affiliation=?, logo_url=?,
                primary_language=?, timezone=?, currency=?,
                enable_laboratory=?, enable_inventory=?, enable_inpatient_beds=?,
                total_beds=?, occupied_beds=?, default_slot_minutes=?,
                work_day_start=?, work_day_end=?,
                invoice_prefix=?, receipt_prefix=?, quote_prefix=?, prescription_prefix=?, certificate_prefix=?,
                doc_header_note=?, doc_footer_note=?
            WHERE id=?
        ')->execute([
            trim((string) $request->input('name', $cur['name'])),
            trim((string) $request->input('short_name', $cur['short_name'])),
            trim((string) $request->input('city', $cur['city'])),
            trim((string) $request->input('address', $cur['address'])),
            trim((string) $request->input('phone', $cur['phone'])),
            trim((string) $request->input('email', $cur['email'])),
            trim((string) $request->input('ice', $cur['ice'])),
            trim((string) $request->input('if_tax', $cur['if_tax'])),
            trim((string) $request->input('inpe', $cur['inpe'])),
            trim((string) $request->input('cnss_affiliation', $cur['cnss_affiliation'])),
            trim((string) $request->input('logo_url', $cur['logo_url'])),
            trim((string) $request->input('primary_language', $cur['primary_language'])),
            trim((string) $request->input('timezone', $cur['timezone'])),
            in_array($request->input('currency'), ['MAD', 'EUR'], true) ? $request->input('currency') : $cur['currency'],
            $enableLab,
            $enableInv,
            $enableBeds,
            max(0, (int) $request->input('total_beds', $cur['total_beds'])),
            max(0, (int) $request->input('occupied_beds', $cur['occupied_beds'])),
            max(10, (int) $request->input('default_slot_minutes', $cur['default_slot_minutes'])),
            trim((string) $request->input('work_day_start', $cur['work_day_start'])),
            trim((string) $request->input('work_day_end', $cur['work_day_end'])),
            strtoupper(trim((string) $request->input('invoice_prefix', $cur['invoice_prefix']))),
            strtoupper(trim((string) $request->input('receipt_prefix', $cur['receipt_prefix']))),
            strtoupper(trim((string) $request->input('quote_prefix', $cur['quote_prefix']))),
            strtoupper(trim((string) $request->input('prescription_prefix', $cur['prescription_prefix']))),
            strtoupper(trim((string) $request->input('certificate_prefix', $cur['certificate_prefix']))),
            trim((string) $request->input('doc_header_note', $cur['doc_header_note'])),
            trim((string) $request->input('doc_footer_note', $cur['doc_footer_note'])),
            $tenantId,
        ]);

        CliniqoAuthService::audit(
            $tenantId, $ctx['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'UPDATE_CENTRE_SETTINGS', 'tenant', (string) $tenantId,
            'Mise à jour configuration centre médical, modèles PDF et modules',
            $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'message' => 'Paramètres du centre médical enregistrés.',
            'state' => SaasWorkspaceController::buildWorkspaceState($ctx),
        ]);
    }

    public function createBranch(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'branches.manage');
        $tenantId = (int) $ctx['tenant_id'];
        $sub = CliniqoAuthService::requireActiveSubscription($tenantId, 'multibranch');
        $pdo = CliniqoDatabase::connection();

        $stCnt = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE tenant_id = ? AND is_active = 1');
        $stCnt->execute([$tenantId]);
        if ((int) $stCnt->fetchColumn() >= (int) $sub['max_branches']) {
            return response()->json([
                'success' => false,
                'error' => 'PLAN_BRANCH_LIMIT_REACHED',
                'message' => "Limite de branches atteinte pour votre forfait {$sub['plan_name']} (max {$sub['max_branches']}).",
            ], 403);
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Nom de la branche requis.'], 422);
        }
        $code = strtoupper(trim((string) $request->input('code', 'BR-' . random_int(10, 99))));
        $city = trim((string) $request->input('city', 'Casablanca'));
        $address = trim((string) $request->input('address', ''));
        $phone = trim((string) $request->input('phone', '+212 522-000000'));
        $specs = trim((string) $request->input('specialties_summary', 'Consultations Spécialisées'));

        $pdo->prepare('INSERT INTO branches (tenant_id, name, code, city, address, phone, specialties_summary, is_main, is_active) VALUES (?,?,?,?,?,?,?,0,1)')
            ->execute([$tenantId, $name, $code, $city, $address, $phone, $specs]);
        $newBranchId = (int) $pdo->lastInsertId();

        // Assign creator admin to new branch
        $pdo->prepare('INSERT OR IGNORE INTO user_branches (user_id, branch_id) VALUES (?,?)')
            ->execute([(int) $ctx['user']['id'], $newBranchId]);

        $pdo->prepare('INSERT INTO rooms (tenant_id, branch_id, name, room_type, daily_capacity_slots) VALUES (?,?,?,?,12)')
            ->execute([$tenantId, $newBranchId, "Salle 101 ({$code})", 'Consultation']);

        CliniqoAuthService::audit(
            $tenantId, $newBranchId, (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'CREATE_BRANCH', 'branch', $code, "Nouvelle branche créée : {$name}", $request->ip() ?? '127.0.0.1'
        );

        $freshCtx = CliniqoAuthService::resolveStaffContext($request);
        return response()->json([
            'success' => true,
            'branch_id' => $newBranchId,
            'state' => SaasWorkspaceController::buildWorkspaceState($freshCtx),
        ], 201);
    }

    public function createMedicalService(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'settings.manage');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $name = trim((string) $request->input('name', ''));
        $spec = trim((string) $request->input('specialty', 'Cardiologue'));
        $price = max(50.0, (float) $request->input('price_mad', 500));
        $dur = max(10, (int) $request->input('duration_min', 30));
        $code = strtoupper(trim((string) $request->input('code', 'ACT-' . random_int(100, 999))));

        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Intitulé de l’acte médical requis.'], 422);
        }

        $pdo->prepare('INSERT INTO medical_services (tenant_id, code, name, specialty, price_mad, duration_min, cnss_reimbursable, is_active) VALUES (?,?,?,?,?,?,1,1)')
            ->execute([$tenantId, $code, $name, $spec, $price, $dur]);

        CliniqoAuthService::audit(
            $tenantId, $ctx['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'CREATE_MEDICAL_SERVICE', 'service', $code, "{$name} ({$price} MAD • {$dur} min)", $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'state' => SaasWorkspaceController::buildWorkspaceState($ctx),
        ], 201);
    }

    public function deleteMedicalService(Request $request, int $id): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'settings.manage');
        $pdo = CliniqoDatabase::connection();
        $pdo->prepare('UPDATE medical_services SET is_active = 0 WHERE id = ? AND tenant_id = ?')
            ->execute([$id, (int) $ctx['tenant_id']]);
        return response()->json([
            'success' => true,
            'state' => SaasWorkspaceController::buildWorkspaceState($ctx),
        ]);
    }

    public function createHoliday(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'settings.manage');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $start = trim((string) $request->input('start_date', ''));
        $end = trim((string) $request->input('end_date', $start));
        $label = trim((string) $request->input('label', 'Fermeture exceptionnelle / Congé'));
        $docId = $request->filled('doctor_id') ? (int) $request->input('doctor_id') : null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            return response()->json(['success' => false, 'message' => 'Date de début valide requise.'], 422);
        }

        $pdo->prepare('INSERT INTO clinic_holidays (tenant_id, doctor_id, start_date, end_date, label) VALUES (?,?,?,?,?)')
            ->execute([$tenantId, $docId, $start, $end ?: $start, $label]);

        CliniqoAuthService::pushNotification(
            $tenantId, null, 'schedule', 'info',
            "Jour férié / Congé ajouté ({$start})",
            "{$label} enregistré du {$start} au {$end}.",
            'overview'
        );

        return response()->json([
            'success' => true,
            'state' => SaasWorkspaceController::buildWorkspaceState($ctx),
        ], 201);
    }

    public function deleteHoliday(Request $request, int $id): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'settings.manage');
        $pdo = CliniqoDatabase::connection();
        $pdo->prepare('DELETE FROM clinic_holidays WHERE id = ? AND tenant_id = ?')
            ->execute([$id, (int) $ctx['tenant_id']]);
        return response()->json([
            'success' => true,
            'state' => SaasWorkspaceController::buildWorkspaceState($ctx),
        ]);
    }

    public function createClinicUser(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'users.manage');
        $tenantId = (int) $ctx['tenant_id'];
        $sub = CliniqoAuthService::requireActiveSubscription($tenantId);
        $pdo = CliniqoDatabase::connection();

        $stCnt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE tenant_id = ? AND is_active = 1');
        $stCnt->execute([$tenantId]);
        if ((int) $stCnt->fetchColumn() >= (int) $sub['max_users']) {
            return response()->json([
                'success' => false,
                'error' => 'PLAN_USER_LIMIT_REACHED',
                'message' => "Limite de comptes utilisateurs atteinte ({$sub['max_users']}) pour votre forfait {$sub['plan_name']}.",
            ], 403);
        }

        $name = trim((string) $request->input('name', ''));
        $email = strtolower(trim((string) $request->input('email', '')));
        $role = trim((string) $request->input('role', 'secretaire'));
        $allowedRoles = ['admin_clinique', 'medecin', 'secretaire', 'laborantin', 'comptable'];
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'secretaire';
        }
        if ($name === '' || $email === '') {
            return response()->json(['success' => false, 'message' => 'Nom complet et email requis.'], 422);
        }

        $stEx = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stEx->execute([$email]);
        if ($stEx->fetch()) {
            return response()->json(['success' => false, 'message' => 'Cet email est déjà utilisé.'], 409);
        }

        $branchId = $request->filled('branch_id') && $request->input('branch_id') !== 'all'
            ? (int) $request->input('branch_id')
            : null;
        $allBranches = $branchId === null ? 1 : 0;

        $pwHash = password_hash((string) $request->input('password', 'Cliniqo2026!'), PASSWORD_BCRYPT);
        $pdo->prepare('INSERT INTO users (tenant_id, name, email, password_hash, role, title, all_branches, is_active) VALUES (?,?,?,?,?,?,?,1)')
            ->execute([$tenantId, $name, $email, $pwHash, $role, ucfirst(str_replace('_', ' ', $role)), $allBranches]);
        $newUid = (int) $pdo->lastInsertId();

        if ($branchId !== null) {
            $pdo->prepare('INSERT OR IGNORE INTO user_branches (user_id, branch_id) VALUES (?,?)')->execute([$newUid, $branchId]);
        }
        $pdo->prepare('INSERT OR IGNORE INTO notification_preferences (user_id) VALUES (?)')->execute([$newUid]);

        CliniqoAuthService::audit(
            $tenantId, $branchId, (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'CREATE_CLINIC_USER', 'user', $email, "Création compte [{$role}] {$name}", $request->ip() ?? '127.0.0.1'
        );

        return $this->getCentreSettings($request);
    }

    // =========================================================================
    // SECTION 3C — SAAS PLATFORM OWNER SUBSCRIPTION MANAGEMENT (ZERO MEDICAL DATA!)
    // =========================================================================
    public static function buildSaasOwnerPayload(PDO $pdo): array
    {
        $plans = $pdo->query('SELECT * FROM subscription_plans ORDER BY monthly_price_mad ASC')->fetchAll();

        $clinics = $pdo->query("
            SELECT t.id, t.name, t.short_name, t.city, t.email, t.phone, t.ice, t.created_at,
                   t.responsible_doctor, t.specialty_code, t.status AS cabinet_status,
                   s.id AS subscription_id, s.plan_id, s.status AS sub_status, s.is_trial, s.started_at, s.trial_ends_at,
                   s.expires_at, s.last_payment_date, s.last_payment_amount, s.payment_method, s.auto_renew,
                   p.code AS plan_code, p.name AS plan_name, p.monthly_price_mad, p.annual_price_mad,
                   p.max_users, p.max_branches, p.max_doctors, p.storage_limit_mb,
                   (SELECT u.name FROM users u WHERE u.tenant_id = t.id AND u.role IN ('admin_clinique','admin') AND u.is_active = 1 LIMIT 1) AS admin_name,
                   (SELECT u.email FROM users u WHERE u.tenant_id = t.id AND u.role IN ('admin_clinique','admin') AND u.is_active = 1 LIMIT 1) AS admin_email,
                   (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.is_active = 1) AS active_users_count,
                   (SELECT COUNT(*) FROM branches b WHERE b.tenant_id = t.id AND b.is_active = 1) AS active_branches_count,
                   (SELECT COUNT(*) FROM doctors d WHERE d.tenant_id = t.id AND d.is_active = 1) AS active_doctors_count
            FROM tenants t
            LEFT JOIN subscriptions s ON s.tenant_id = t.id
            LEFT JOIN subscription_plans p ON p.id = s.plan_id
            ORDER BY t.id ASC
        ")->fetchAll();

        $today = CliniqoDatabase::today();
        $mrr = 0.0;
        $arr = 0.0;
        $trialCount = 0;
        $activeCount = 0;
        $expiringSoonCount = 0;
        $expiredCount = 0;
        $suspendedCount = 0;

        foreach ($clinics as &$cl) {
            $days = !empty($cl['expires_at'])
                ? (int) round((strtotime($cl['expires_at']) - strtotime($today)) / 86400)
                : 0;
            $cl['days_remaining'] = $days;

            if ($cl['cabinet_status'] === 'suspended' || $cl['sub_status'] === 'suspended') {
                $suspendedCount++;
            } elseif ($cl['sub_status'] === 'expired' || ($days < 0 && $cl['sub_status'] !== 'trialing')) {
                $expiredCount++;
            } elseif ($cl['sub_status'] === 'trialing') {
                $trialCount++;
                if ($days >= 0 && $days <= 7) {
                    $expiringSoonCount++;
                }
            } elseif ($cl['sub_status'] === 'active') {
                $activeCount++;
                $planPrice = (float) ($cl['monthly_price_mad'] ?? 0);
                $mrr += $planPrice;
                $arr += ($cl['annual_price_mad'] ? (float)$cl['annual_price_mad'] : $planPrice * 12);
                if ($days >= 0 && $days <= 7) {
                    $expiringSoonCount++;
                }
            }
        }
        unset($cl);

        $payments = $pdo->query("
            SELECT sp.*, t.name AS tenant_name, p.name AS plan_name
            FROM subscription_payments sp
            JOIN tenants t ON t.id = sp.tenant_id
            JOIN subscription_plans p ON p.id = sp.plan_id
            ORDER BY sp.payment_date DESC, sp.id DESC
            LIMIT 50
        ")->fetchAll();

        return [
            'summary' => [
                'total_cabinets' => count($clinics),
                'active_subscriptions' => $activeCount,
                'active_trials' => $trialCount,
                'expiring_soon' => $expiringSoonCount,
                'expired_subscriptions' => $expiredCount,
                'suspended_cabinets' => $suspendedCount,
                'mrr_mad' => round($mrr, 2),
                'arr_mad' => round($arr, 2),
                'medical_records_exposed' => false,
            ],
            'plans' => $plans,
            'clinics' => $clinics,
            'recent_payments' => $payments,
        ];
    }

    public function saasOverview(Request $request): JsonResponse
    {
        CliniqoAuthService::requirePermission($request, 'saas.admin');
        $pdo = CliniqoDatabase::connection();
        return response()->json([
            'success' => true,
            'saas_console' => self::buildSaasOwnerPayload($pdo),
        ]);
    }

    public function saasCreateClinic(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'saas.admin');
        $pdo = CliniqoDatabase::connection();

        $name = trim((string) $request->input('name', ''));
        $city = trim((string) $request->input('city', 'Casablanca'));
        $adminEmail = strtolower(trim((string) $request->input('admin_email', '')));
        $adminName = trim((string) $request->input('admin_name', 'Dr. Directeur'));
        $responsibleDoctor = trim((string) $request->input('responsible_doctor', $adminName));
        $specialtyCode = trim((string) $request->input('specialty_code', 'general_practice'));
        $planId = max(1, (int) $request->input('plan_id', 2));
        $startTrial = (bool) $request->input('is_trial', true);
        $phone = trim((string) $request->input('phone', '+212 522-000000'));
        $address = trim((string) $request->input('address', ''));

        if ($name === '' || $adminEmail === '') {
            return response()->json(['success' => false, 'message' => 'Nom du cabinet et email administrateur requis.'], 422);
        }

        $stDup = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stDup->execute([$adminEmail]);
        if ($stDup->fetch()) {
            return response()->json(['success' => false, 'message' => 'Cet email administrateur existe déjà.'], 409);
        }

        $stPlan = $pdo->prepare('SELECT * FROM subscription_plans WHERE id = ? LIMIT 1');
        $stPlan->execute([$planId]);
        $plan = $stPlan->fetch() ?: ['id' => 2, 'enable_lab_feature' => 1, 'enable_inventory_feature' => 1];

        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name)) . '-' . random_int(10, 99);
        $short = mb_substr($name, 0, 18);
        $today = CliniqoDatabase::today();
        $trialEnd = date('Y-m-d', strtotime("{$today} +30 days"));
        $expiresAt = $startTrial ? $trialEnd : date('Y-m-d', strtotime("{$today} +365 days"));
        $status = $startTrial ? 'trialing' : 'active';

        $doctorSpecialty = 'Médecin Généraliste';
        if ($specialtyCode === 'dentistry') {
            $doctorSpecialty = 'Médecin Dentiste';
        } elseif ($specialtyCode === 'pediatrics') {
            $doctorSpecialty = 'Pédiatre';
        } elseif ($specialtyCode === 'cardiology') {
            $doctorSpecialty = 'Cardiologue';
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO tenants (name, short_name, slug, city, address, email, phone, responsible_doctor, specialty_code, status, enable_laboratory, enable_inventory) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $name, $short, $slug, $city, $address, $adminEmail, $phone,
                    $responsibleDoctor, $specialtyCode, 'active',
                    (int) $plan['enable_lab_feature'],
                    (int) $plan['enable_inventory_feature'],
                ]);
            $newTenantId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan_id, status, is_trial, started_at, trial_ends_at, expires_at, payment_method, notes) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $newTenantId, (int) $plan['id'], $status, $startTrial ? 1 : 0,
                    $today, $startTrial ? $trialEnd : null, $expiresAt,
                    $startTrial ? 'Essai Gratuit 30 Jours' : 'Virement Bancaire',
                    $startTrial ? 'Essai gratuit 30 jours activé à la création' : 'Abonnement activé',
                ]);

            $pdo->prepare('INSERT INTO branches (tenant_id, name, code, city, is_main, is_active) VALUES (?,?,?,?,1,1)')
                ->execute([$newTenantId, "Cabinet Principal — {$city}", strtoupper(substr($slug, 0, 6)), $city]);
            $bId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO rooms (tenant_id, branch_id, name, room_type, daily_capacity_slots) VALUES (?,?,?,?,12)')
                ->execute([$newTenantId, $bId, 'Fauteuil / Bureau 1', 'Consultation']);

            $pdo->prepare('INSERT INTO doctors (tenant_id, branch_id, name, specialty, pole, room, fee_mad) VALUES (?,?,?,?,?,?,400)')
                ->execute([$newTenantId, $bId, $responsibleDoctor, $doctorSpecialty, $doctorSpecialty, 'Bureau 1']);

            $pwHash = password_hash('Cliniqo2026!', PASSWORD_BCRYPT);
            $pdo->prepare('INSERT INTO users (tenant_id, name, email, password_hash, role, title, all_branches, is_active) VALUES (?,?,?,?,?,?,1,1)')
                ->execute([$newTenantId, $adminName, $adminEmail, $pwHash, 'admin_clinique', 'Directeur Médical']);
            $uId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_branches (user_id, branch_id) VALUES (?,?)')->execute([$uId, $bId]);
            $pdo->prepare('INSERT INTO notification_preferences (user_id) VALUES (?)')->execute([$uId]);

            if ($startTrial) {
                CliniqoAuthService::pushNotification(
                    $newTenantId, $bId, 'subscription', 'info',
                    'Bienvenue sur MediCab • Essai Gratuit 30 Jours',
                    "Votre essai gratuit de 30 jours est actif jusqu'au {$trialEnd}.",
                    'overview', 'admin_clinique'
                );
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        CliniqoAuthService::audit(
            null, null, (int) $ctx['user']['id'], $ctx['user']['name'], 'saas_owner',
            'SAAS_CREATE_CLINIC', 'tenant', (string) $newTenantId,
            "Création cabinet {$name} ({$specialtyCode} - {$status})",
            $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'message' => 'Cabinet créé avec succès.',
            'tenant_id' => $newTenantId,
            'saas_console' => self::buildSaasOwnerPayload($pdo),
        ], 201);
    }

    public function saasUpdateClinic(Request $request, int $tenantId): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'saas.admin');
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
        $st->execute([$tenantId]);
        $t = $st->fetch();
        if (!$t) {
            return response()->json(['success' => false, 'message' => 'Cabinet introuvable.'], 404);
        }

        $name = trim((string) $request->input('name', $t['name']));
        $responsibleDoctor = trim((string) $request->input('responsible_doctor', $t['responsible_doctor'] ?? ''));
        $specialtyCode = trim((string) $request->input('specialty_code', $t['specialty_code'] ?? 'general_practice'));
        $city = trim((string) $request->input('city', $t['city']));
        $address = trim((string) $request->input('address', $t['address'] ?? ''));
        $email = trim((string) $request->input('email', $t['email']));
        $phone = trim((string) $request->input('phone', $t['phone'] ?? ''));
        $status = trim((string) $request->input('status', $t['status'] ?? 'active'));

        $pdo->prepare('UPDATE tenants SET name=?, responsible_doctor=?, specialty_code=?, city=?, address=?, email=?, phone=?, status=? WHERE id=?')
            ->execute([$name, $responsibleDoctor, $specialtyCode, $city, $address, $email, $phone, $status, $tenantId]);

        CliniqoAuthService::audit(
            null, null, (int) $ctx['user']['id'], $ctx['user']['name'], 'saas_owner',
            'SAAS_UPDATE_CLINIC', 'tenant', (string) $tenantId,
            "Mise à jour coordonnées cabinet {$name}",
            $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'message' => 'Cabinet mis à jour avec succès.',
            'saas_console' => self::buildSaasOwnerPayload($pdo),
        ]);
    }

    public function saasToggleSuspendClinic(Request $request, int $tenantId): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'saas.admin');
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT status FROM tenants WHERE id = ? LIMIT 1');
        $st->execute([$tenantId]);
        $t = $st->fetch();
        if (!$t) {
            return response()->json(['success' => false, 'message' => 'Cabinet introuvable.'], 404);
        }

        $newStatus = ($t['status'] === 'suspended') ? 'active' : 'suspended';
        $subStatus = ($newStatus === 'suspended') ? 'suspended' : 'active';

        $pdo->prepare('UPDATE tenants SET status = ? WHERE id = ?')->execute([$newStatus, $tenantId]);
        $pdo->prepare('UPDATE subscriptions SET status = ? WHERE tenant_id = ?')->execute([$subStatus, $tenantId]);

        CliniqoAuthService::audit(
            null, null, (int) $ctx['user']['id'], $ctx['user']['name'], 'saas_owner',
            'SAAS_TOGGLE_SUSPEND', 'tenant', (string) $tenantId,
            "Statut cabinet basculé vers {$newStatus}",
            $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'message' => "Cabinet désormais {$newStatus}.",
            'saas_console' => self::buildSaasOwnerPayload($pdo),
        ]);
    }

    public function saasResetAdminPassword(Request $request, int $tenantId): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'saas.admin');
        $pdo = CliniqoDatabase::connection();

        $newPassword = trim((string) $request->input('password', 'MediCab2026!'));
        if (strlen($newPassword) < 6) {
            return response()->json(['success' => false, 'message' => 'Mot de passe trop court (min 6 caractères).'], 422);
        }

        $pwHash = password_hash($newPassword, PASSWORD_BCRYPT);

        $st = $pdo->prepare('UPDATE users SET password_hash = ? WHERE tenant_id = ? AND role = "admin_clinique"');
        $st->execute([$pwHash, $tenantId]);

        CliniqoAuthService::audit(
            null, null, (int) $ctx['user']['id'], $ctx['user']['name'], 'saas_owner',
            'SAAS_RESET_ADMIN_PW', 'tenant', (string) $tenantId,
            "Réinitialisation mot de passe administrateur",
            $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe administrateur réinitialisé avec succès.',
            'saas_console' => self::buildSaasOwnerPayload($pdo),
        ]);
    }

    public function saasRecordSubscriptionPayment(Request $request, int $tenantId): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'saas.admin');
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM subscriptions WHERE tenant_id = ? LIMIT 1');
        $st->execute([$tenantId]);
        $sub = $st->fetch();
        if (!$sub) {
            return response()->json(['success' => false, 'message' => 'Abonnement introuvable.'], 404);
        }

        $planId = (int) $request->input('plan_id', $sub['plan_id']);
        $amount = max(0.0, (float) $request->input('amount_mad', 0));
        $period = trim((string) $request->input('billing_period', 'monthly')); // 'monthly' or 'annual'
        $method = trim((string) $request->input('payment_method', 'virement'));
        $ref = trim((string) $request->input('payment_reference', 'VIR-' . date('Ymd') . '-' . rand(100, 999)));
        $paymentDate = trim((string) $request->input('payment_date', CliniqoDatabase::today()));

        $periodDays = ($period === 'annual') ? 365 : 30;
        $baseDate = max(CliniqoDatabase::today(), (string) ($sub['expires_at'] ?? CliniqoDatabase::today()));
        $newExpiry = date('Y-m-d', strtotime("{$baseDate} +{$periodDays} days"));

        $pdo->prepare('
            INSERT INTO subscription_payments (tenant_id, plan_id, amount_mad, billing_period, payment_method, payment_reference, payment_date, period_start, period_end, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "completed")
        ')->execute([
            $tenantId, $planId, $amount, $period, $method, $ref, $paymentDate, $baseDate, $newExpiry
        ]);

        $pdo->prepare('
            UPDATE subscriptions
            SET plan_id=?, status="active", is_trial=0, expires_at=?, last_payment_date=?, last_payment_amount=?, payment_method=?
            WHERE tenant_id=?
        ')->execute([
            $planId, $newExpiry, $paymentDate, $amount, $method, $tenantId
        ]);

        CliniqoAuthService::pushNotification(
            $tenantId, null, 'subscription', 'info',
            'Paiement d\'abonnement validé',
            "Paiement de {$amount} MAD enregistré. Abonnement actif jusqu'au {$newExpiry}.",
            'overview', 'admin_clinique'
        );

        CliniqoAuthService::audit(
            null, null, (int) $ctx['user']['id'], $ctx['user']['name'], 'saas_owner',
            'SAAS_RECORD_PAYMENT', 'subscription', "TENANT-{$tenantId}",
            "Paiement {$amount} MAD ({$period}) • Réf: {$ref}",
            $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'message' => 'Paiement d\'abonnement enregistré avec succès.',
            'saas_console' => self::buildSaasOwnerPayload($pdo),
        ]);
    }

    public function saasUpdateSubscription(Request $request, int $tenantId): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'saas.admin');
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM subscriptions WHERE tenant_id = ? LIMIT 1');
        $st->execute([$tenantId]);
        $sub = $st->fetch();
        if (!$sub) {
            return response()->json(['success' => false, 'message' => 'Abonnement introuvable.'], 404);
        }

        $planId = (int) $request->input('plan_id', $sub['plan_id']);
        $status = trim((string) $request->input('status', $sub['status']));
        $expiresAt = trim((string) $request->input('expires_at', $sub['expires_at']));
        $isTrial = $status === 'trialing' ? 1 : 0;

        if ((bool) $request->input('renew_30_days', false)) {
            $base = max(CliniqoDatabase::today(), (string) $sub['expires_at']);
            $expiresAt = date('Y-m-d', strtotime("{$base} +30 days"));
            $status = 'active';
            $isTrial = 0;
        }

        $stPlan = $pdo->prepare('SELECT * FROM subscription_plans WHERE id = ? LIMIT 1');
        $stPlan->execute([$planId]);
        $plan = $stPlan->fetch();

        $pdo->prepare('
            UPDATE subscriptions
            SET plan_id=?, status=?, is_trial=?, expires_at=?, last_payment_date=?, last_payment_amount=?
            WHERE tenant_id=?
        ')->execute([
            $planId, $status, $isTrial, $expiresAt,
            $status === 'active' ? CliniqoDatabase::today() : $sub['last_payment_date'],
            $status === 'active' && $plan ? (float) $plan['monthly_price_mad'] : (float) $sub['last_payment_amount'],
            $tenantId,
        ]);

        // Enforce plan feature restrictions on tenant modules automatically
        if ($plan) {
            if ((int) $plan['enable_lab_feature'] === 0) {
                $pdo->prepare('UPDATE tenants SET enable_laboratory = 0 WHERE id = ?')->execute([$tenantId]);
            }
            if ((int) $plan['enable_inventory_feature'] === 0) {
                $pdo->prepare('UPDATE tenants SET enable_inventory = 0 WHERE id = ?')->execute([$tenantId]);
            }
        }

        CliniqoAuthService::pushNotification(
            $tenantId, null, 'subscription', $status === 'suspended' ? 'urgent' : 'info',
            'Mise à jour Abonnement Cliniqo SaaS',
            "Statut contrat : {$status} • Échéance : {$expiresAt}.",
            'overview', 'admin_clinique'
        );

        CliniqoAuthService::audit(
            null, null, (int) $ctx['user']['id'], $ctx['user']['name'], 'saas_owner',
            'SAAS_UPDATE_SUBSCRIPTION', 'subscription', "TENANT-{$tenantId}",
            "Plan #{$planId} • Statut {$status} • Expire {$expiresAt}",
            $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'saas_console' => self::buildSaasOwnerPayload($pdo),
        ]);
    }

    public function saasUpdatePlan(Request $request, int $planId): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'saas.admin');
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM subscription_plans WHERE id = ? LIMIT 1');
        $st->execute([$planId]);
        $p = $st->fetch();
        if (!$p) {
            return response()->json(['success' => false, 'message' => 'Forfait introuvable.'], 404);
        }

        $pdo->prepare('
            UPDATE subscription_plans
            SET monthly_price_mad=?, max_users=?, max_branches=?, max_doctors=?,
                enable_lab_feature=?, enable_inventory_feature=?, enable_multibranch_feature=?
            WHERE id=?
        ')->execute([
            max(0.0, (float) $request->input('monthly_price_mad', $p['monthly_price_mad'])),
            max(1, (int) $request->input('max_users', $p['max_users'])),
            max(1, (int) $request->input('max_branches', $p['max_branches'])),
            max(1, (int) $request->input('max_doctors', $p['max_doctors'])),
            (int) ((bool) $request->input('enable_lab_feature', $p['enable_lab_feature'])),
            (int) ((bool) $request->input('enable_inventory_feature', $p['enable_inventory_feature'])),
            (int) ((bool) $request->input('enable_multibranch_feature', $p['enable_multibranch_feature'])),
            $planId,
        ]);

        CliniqoAuthService::audit(
            null, null, (int) $ctx['user']['id'], $ctx['user']['name'], 'saas_owner',
            'SAAS_UPDATE_PLAN', 'plan', $p['code'], 'Configuration limites forfait SaaS', $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'saas_console' => self::buildSaasOwnerPayload($pdo),
        ]);
    }

    public function saasCreatePlan(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'saas.admin');
        $pdo = CliniqoDatabase::connection();

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Le nom du pack est obligatoire.'], 422);
        }

        $code = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $name));
        $code = trim($code, '-');
        if ($code === '') {
            $code = 'pack-' . time();
        }

        $chk = $pdo->prepare('SELECT COUNT(*) FROM subscription_plans WHERE code = ?');
        $chk->execute([$code]);
        if ((int) $chk->fetchColumn() > 0) {
            $code .= '-' . rand(10, 99);
        }

        $price = max(0.0, (float) $request->input('monthly_price_mad', 490));
        $maxUsers = max(1, (int) $request->input('max_users', 5));
        $maxBranches = max(1, (int) $request->input('max_branches', 1));
        $maxDoctors = max(1, (int) $request->input('max_doctors', 5));
        $enableLab = (int) ((bool) $request->input('enable_lab_feature', 1));
        $enableInventory = (int) ((bool) $request->input('enable_inventory_feature', 1));
        $enablePortal = (int) ((bool) $request->input('enable_portal_feature', 1));
        $enableMulti = (int) ((bool) $request->input('enable_multibranch_feature', 0));

        $stmt = $pdo->prepare('
            INSERT INTO subscription_plans (
                code, name, monthly_price_mad, max_users, max_branches, max_doctors,
                enable_lab_feature, enable_inventory_feature, enable_portal_feature, enable_multibranch_feature
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $code, $name, $price, $maxUsers, $maxBranches, $maxDoctors,
            $enableLab, $enableInventory, $enablePortal, $enableMulti
        ]);

        $newId = (int) $pdo->lastInsertId();

        CliniqoAuthService::audit(
            null, null, (int) $ctx['user']['id'], $ctx['user']['name'], 'saas_owner',
            'SAAS_CREATE_PLAN', 'plan', $code, "Création du pack SaaS: {$name} ({$price} MAD/mois)", $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'message' => 'Nouveau pack d\'abonnement créé avec succès.',
            'plan_id' => $newId,
            'saas_console' => self::buildSaasOwnerPayload($pdo),
        ], 201);
    }

    public function saasDeletePlan(Request $request, int $planId): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'saas.admin');
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM subscription_plans WHERE id = ? LIMIT 1');
        $st->execute([$planId]);
        $p = $st->fetch();
        if (!$p) {
            return response()->json(['success' => false, 'message' => 'Pack introuvable.'], 404);
        }

        // Vérifier si des cliniques utilisent ce pack
        $usage = $pdo->prepare('SELECT COUNT(*) FROM subscriptions WHERE plan_id = ?');
        $usage->execute([$planId]);
        $count = (int) $usage->fetchColumn();
        if ($count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Impossible de supprimer ce pack : {$count} clinique(s) y sont actuellement abonnées."
            ], 422);
        }

        $pdo->prepare('DELETE FROM subscription_plans WHERE id = ?')->execute([$planId]);

        CliniqoAuthService::audit(
            null, null, (int) $ctx['user']['id'], $ctx['user']['name'], 'saas_owner',
            'SAAS_DELETE_PLAN', 'plan', $p['code'], "Suppression du pack SaaS: {$p['name']}", $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'message' => 'Pack d\'abonnement supprimé.',
            'saas_console' => self::buildSaasOwnerPayload($pdo),
        ]);
    }

    // =========================================================================
    // SECTION 3E — SECURE DATABASE BACKUPS & RESTORATION
    // =========================================================================
    public function listBackups(Request $request): JsonResponse
    {
        CliniqoAuthService::requirePermission($request, 'backups.manage');
        return response()->json([
            'success' => true,
            'backups' => CliniqoDatabase::listBackups(),
        ]);
    }

    public function createBackup(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'backups.manage');
        $meta = CliniqoDatabase::createBackup($ctx['user']['name'], $ctx['tenant_id']);

        CliniqoAuthService::audit(
            $ctx['tenant_id'], $ctx['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'CREATE_BACKUP', 'backup', $meta['filename'],
            "SHA-256: " . substr($meta['sha256'], 0, 16) . "... ({$meta['size_kb']} KB)",
            $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'backup' => $meta,
            'backups' => CliniqoDatabase::listBackups(),
        ], 201);
    }

    public function restoreBackup(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'backups.manage');
        $filename = trim((string) $request->input('filename', ''));
        if ($filename === '') {
            return response()->json(['success' => false, 'message' => 'Nom de l’archive requis.'], 422);
        }

        CliniqoDatabase::restoreBackup($filename);

        CliniqoAuthService::audit(
            $ctx['tenant_id'], $ctx['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'RESTORE_BACKUP', 'backup', basename($filename),
            'Restauration complète de la base de données effectuée',
            $request->ip() ?? '127.0.0.1'
        );

        $freshCtx = CliniqoAuthService::resolveStaffContext($request);
        return response()->json([
            'success' => true,
            'message' => 'Base de données restaurée avec succès.',
            'backups' => CliniqoDatabase::listBackups(),
            'state' => SaasWorkspaceController::buildWorkspaceState($freshCtx),
        ]);
    }
}
