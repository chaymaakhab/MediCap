<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CliniqoAuthService;
use App\Services\CliniqoDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDO;

class SaasWorkspaceController
{
    public static function buildWorkspaceState(array $ctx): array
    {
        $pdo = CliniqoDatabase::connection();
        $today = CliniqoDatabase::TODAY;

        // If SaaS Platform Owner is logged in, return platform-owner state with ZERO medical records!
        if (($ctx['role'] ?? '') === 'saas_owner') {
            return [
                'auth' => [
                    'user' => $ctx['user'],
                    'role' => 'saas_owner',
                    'permissions' => $ctx['permissions'],
                    'is_saas_owner' => true,
                ],
                'saas_console' => ReportsAndAdminController::buildSaasOwnerPayload($pdo),
                'backups' => CliniqoDatabase::listBackups(),
            ];
        }

        $tenantId = (int) ($ctx['tenant_id'] ?? 1);
        $stT = $pdo->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
        $stT->execute([$tenantId]);
        $tenantRow = $stT->fetch();
        if (!$tenantRow) {
            $tenantId = 1;
            $stT->execute([1]);
            $tenantRow = $stT->fetch();
        }

        // Subscription & Plan details
        $stSub = $pdo->prepare('
            SELECT s.*, p.code AS plan_code, p.name AS plan_name, p.monthly_price_mad,
                   p.max_users, p.max_branches, p.max_doctors,
                   p.enable_lab_feature, p.enable_inventory_feature,
                   p.enable_portal_feature, p.enable_multibranch_feature
            FROM subscriptions s
            INNER JOIN subscription_plans p ON p.id = s.plan_id
            WHERE s.tenant_id = ?
            LIMIT 1
        ');
        $stSub->execute([$tenantId]);
        $sub = $stSub->fetch() ?: [];

        $daysRemaining = isset($sub['expires_at'])
            ? (int) floor((strtotime($sub['expires_at']) - strtotime($today)) / 86400)
            : 30;

        $labEnabled = ((int) ($tenantRow['enable_laboratory'] ?? 1) === 1) && ((int) ($sub['enable_lab_feature'] ?? 1) === 1);
        $inventoryEnabled = ((int) ($tenantRow['enable_inventory'] ?? 1) === 1) && ((int) ($sub['enable_inventory_feature'] ?? 1) === 1);
        $bedsEnabled = ((int) ($tenantRow['enable_inpatient_beds'] ?? 0) === 1) && ((int) ($tenantRow['total_beds'] ?? 0) > 0);

        // Branches for this tenant (filtered by allowed_branch_ids)
        $allowedBranchIds = $ctx['allowed_branch_ids'] ?? [];
        $stBranches = $pdo->prepare('SELECT * FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY is_main DESC, id ASC');
        $stBranches->execute([$tenantId]);
        $allTenantBranches = $stBranches->fetchAll();
        $visibleBranches = array_values(array_filter(
            $allTenantBranches,
            static fn(array $b): bool => empty($allowedBranchIds) || in_array((int) $b['id'], $allowedBranchIds, true)
        ));

        $activeBranchRow = null;
        if ($ctx['branch_id'] !== null) {
            foreach ($visibleBranches as $vb) {
                if ((int) $vb['id'] === (int) $ctx['branch_id']) {
                    $activeBranchRow = $vb;
                    break;
                }
            }
        }

        // Authorized Centres list for Grand Central switcher
        if ((int) ($ctx['user']['id'] ?? 0) === 1) {
            $centresList = $pdo->query('SELECT id, name, short_name, city FROM tenants ORDER BY id ASC')->fetchAll();
        } else {
            $centresList = [[
                'id' => (int) $tenantRow['id'],
                'name' => $tenantRow['name'],
                'short_name' => $tenantRow['short_name'],
                'city' => $tenantRow['city'],
            ]];
        }

        // Branch SQL filter helper
        $bParams = [$tenantId];
        $bSql = CliniqoAuthService::branchSqlFilter($ctx, 'branch_id', $bParams);

        // 1. Doctors (active in selected centre/branch)
        $stDocs = $pdo->prepare("SELECT * FROM doctors WHERE tenant_id = ? AND is_active = 1 {$bSql} ORDER BY id ASC");
        $stDocs->execute($bParams);
        $doctors = array_map(static fn(array $d): array => [
            'id' => (int) $d['id'],
            'branch_id' => (int) $d['branch_id'],
            'name' => $d['name'],
            'specialty' => $d['specialty'],
            'pole' => $d['pole'],
            'room' => $d['room'],
            'fee_mad' => (float) $d['fee_mad'],
            'duration_min' => (int) $d['duration_min'],
            'inpe' => $d['inpe'],
            'phone' => $d['phone'],
            'email' => $d['email'],
            'avatar' => $d['avatar'],
            'working_days' => $d['working_days'],
            'work_start' => $d['work_start'],
            'work_end' => $d['work_end'],
            'daily_slot_capacity' => (int) $d['daily_slot_capacity'],
        ], $stDocs->fetchAll());

        // 2. Patients & DME (clinical notes ONLY included if user has `patients.dme` permission!)
        $canReadDme = in_array('patients.dme', $ctx['permissions'], true);
        $stPats = $pdo->prepare("SELECT * FROM patients WHERE tenant_id = ? {$bSql} ORDER BY id ASC");
        $stPats->execute($bParams);
        $rawPatients = $stPats->fetchAll();

        $notesByPatient = [];
        if ($canReadDme) {
            $encParams = [$tenantId];
            $encBranchSql = CliniqoAuthService::branchSqlFilter($ctx, 'branch_id', $encParams);
            $stEnc = $pdo->prepare("SELECT * FROM encounters WHERE tenant_id = ? {$encBranchSql} ORDER BY encounter_date DESC, id DESC");
            $stEnc->execute($encParams);
            foreach ($stEnc->fetchAll() as $enc) {
                $pid = (int) $enc['patient_id'];
                $notesByPatient[$pid][] = [
                    'id' => (int) $enc['id'],
                    'date' => date('d-m-Y', strtotime($enc['encounter_date'])),
                    'doctor' => $enc['doctor_name'],
                    'pole' => $enc['pole'],
                    'icd' => $enc['icd_code'],
                    'text' => $enc['clinical_text'],
                ];
            }
        }

        $patients = array_map(static function (array $p) use ($canReadDme, $notesByPatient): array {
            $pid = (int) $p['id'];
            return [
                'id' => $pid,
                'branch_id' => (int) $p['branch_id'],
                'code' => $p['code'],
                'first' => $p['first'],
                'last' => $p['last'],
                'cin' => $p['cin'],
                'age' => (int) $p['age'],
                'gender' => $p['gender'],
                'blood' => $p['blood'],
                'phone' => $p['phone'],
                'email' => $p['email'],
                'address' => $p['address'],
                'coverage' => $p['coverage'],
                'department' => $p['department'],
                'allergy' => $canReadDme ? $p['allergy'] : 'Accès médical requis',
                'bp' => $canReadDme ? $p['bp'] : '—',
                'spo2' => $canReadDme ? $p['spo2'] : '—',
                'hr' => $canReadDme ? $p['hr'] : '—',
                'portal_enabled' => (bool) $p['portal_enabled'],
                'avatar' => $p['avatar'],
                'dme_authorized' => $canReadDme,
                'notes' => $canReadDme ? ($notesByPatient[$pid] ?? []) : [],
            ];
        }, $rawPatients);

        // 3. Appointments
        $stApts = $pdo->prepare("SELECT * FROM appointments WHERE tenant_id = ? {$bSql} ORDER BY date ASC, time ASC");
        $stApts->execute($bParams);
        $appointments = array_map(static fn(array $a): array => [
            'id' => (int) $a['id'],
            'branch_id' => (int) $a['branch_id'],
            'patient_id' => (int) $a['patient_id'],
            'doctor_id' => $a['doctor_id'] !== null ? (int) $a['doctor_id'] : null,
            'doctor' => $a['doctor'],
            'specialty' => $a['specialty'],
            'service_name' => $a['service_name'],
            'room' => $a['room'],
            'date' => $a['date'],
            'time' => $a['time'],
            'duration_min' => (int) $a['duration_min'],
            'status' => $a['status'],
            'cancellation_reason' => $a['cancellation_reason'],
            'checked_in_at' => $a['checked_in_at'],
            'seen_at' => $a['seen_at'],
            'fee_mad' => (float) $a['fee_mad'],
            'reason' => $a['reason'],
            'source' => $a['source'],
        ], $stApts->fetchAll());

        // 4. Labs (if enabled & authorized)
        $labs = [];
        if ($labEnabled && in_array('labs.read', $ctx['permissions'], true)) {
            $stLabs = $pdo->prepare("SELECT * FROM labs WHERE tenant_id = ? {$bSql} ORDER BY CASE WHEN status='flagged' THEN 0 WHEN status='pending' THEN 1 ELSE 2 END, id DESC");
            $stLabs->execute($bParams);
            $labs = array_map(static fn(array $l): array => [
                'id' => (int) $l['id'],
                'branch_id' => (int) $l['branch_id'],
                'code' => $l['code'],
                'patient_id' => (int) $l['patient_id'],
                'patient_name' => $l['patient_name'],
                'test_name' => $l['test_name'],
                'category' => $l['category'],
                'value' => $l['value'],
                'reference' => $l['reference'],
                'priority' => $l['priority'],
                'status' => $l['status'],
                'ordered_by' => $l['ordered_by'],
                'validated_by' => $l['validated_by'],
                'ordered_date' => $l['ordered_date'],
                'completed_date' => $l['completed_date'],
                'timestamp' => $l['timestamp'],
            ], $stLabs->fetchAll());
        }

        // 5. Invoices & Payments (if finance.read or dashboard.view)
        $invoices = [];
        $paymentsList = [];
        $stPayAll = $pdo->prepare("SELECT * FROM payments WHERE tenant_id = ? {$bSql} ORDER BY paid_date DESC, id DESC");
        $stPayAll->execute($bParams);
        $rawPayments = $stPayAll->fetchAll();
        $paymentsByInvoice = [];
        foreach ($rawPayments as $py) {
            $pItem = [
                'id' => (int) $py['id'],
                'invoice_id' => (int) $py['invoice_id'],
                'branch_id' => (int) $py['branch_id'],
                'receipt_num' => $py['receipt_num'],
                'amount' => (float) $py['amount'],
                'payment_type' => $py['payment_type'],
                'method' => $py['method'],
                'paid_date' => $py['paid_date'],
                'paid_time' => $py['paid_time'],
                'status' => $py['status'],
                'recorded_by' => $py['recorded_by'],
                'notes' => $py['notes'],
            ];
            $paymentsList[] = $pItem;
            $paymentsByInvoice[(int) $py['invoice_id']][] = $pItem;
        }

        if (in_array('finance.read', $ctx['permissions'], true)) {
            $stInvs = $pdo->prepare("SELECT * FROM invoices WHERE tenant_id = ? {$bSql} ORDER BY issued_date DESC, id DESC");
            $stInvs->execute($bParams);
            $invoices = array_map(static function (array $inv) use ($paymentsByInvoice): array {
                $iid = (int) $inv['id'];
                $netPaid = max(0.0, (float) $inv['paid'] - (float) $inv['refunded']);
                $rem = max(0.0, (float) $inv['total'] - $netPaid);
                return [
                    'id' => $iid,
                    'branch_id' => (int) $inv['branch_id'],
                    'num' => $inv['num'],
                    'patient_id' => (int) $inv['patient_id'],
                    'patient' => $inv['patient_name'],
                    'doctor_name' => $inv['doctor_name'],
                    'specialty' => $inv['specialty'],
                    'service' => $inv['service'],
                    'issued_date' => $inv['issued_date'],
                    'date' => date('d-m-Y', strtotime($inv['issued_date'])),
                    'total' => (float) $inv['total'],
                    'paid' => (float) $inv['paid'],
                    'refunded' => (float) $inv['refunded'],
                    'net_paid' => $netPaid,
                    'remaining' => $rem,
                    'method' => $inv['method'],
                    'status' => $inv['status'],
                    'cnss_org' => $inv['cnss_org'],
                    'cnss_est' => (float) $inv['cnss_est'],
                    'cnss_status' => $inv['cnss_status'],
                    'payments' => $paymentsByInvoice[$iid] ?? [],
                ];
            }, $stInvs->fetchAll());
        }

        // 6. Expenses & Cash Session
        $expenses = [];
        if (in_array('expenses.read', $ctx['permissions'], true)) {
            $stExp = $pdo->prepare("SELECT * FROM expenses WHERE tenant_id = ? {$bSql} ORDER BY expense_date DESC, id DESC");
            $stExp->execute($bParams);
            $expenses = array_map(static fn(array $e): array => [
                'id' => (int) $e['id'],
                'branch_id' => (int) $e['branch_id'],
                'cash_session_id' => $e['cash_session_id'] ? (int) $e['cash_session_id'] : null,
                'reference' => $e['reference'],
                'category' => $e['category'],
                'amount' => (float) $e['amount'],
                'expense_date' => $e['expense_date'],
                'description' => $e['description'],
                'supplier' => $e['supplier'],
                'supporting_doc_ref' => $e['supporting_doc_ref'],
                'payment_method' => $e['payment_method'],
                'created_by' => $e['created_by'],
            ], $stExp->fetchAll());
        }

        $stCash = $pdo->prepare("SELECT * FROM cash_sessions WHERE tenant_id = ? {$bSql} ORDER BY id DESC LIMIT 1");
        $stCash->execute($bParams);
        $cashSession = $stCash->fetch() ?: null;

        // 7. Optional Medical Supply Inventory
        $inventory = [];
        if ($inventoryEnabled && in_array('stock.read', $ctx['permissions'], true)) {
            $stInvItems = $pdo->prepare("SELECT * FROM inventory_items WHERE tenant_id = ? AND is_active = 1 {$bSql} ORDER BY (quantity <= min_threshold) DESC, name ASC");
            $stInvItems->execute($bParams);
            $inventory = array_map(static fn(array $it): array => [
                'id' => (int) $it['id'],
                'branch_id' => (int) $it['branch_id'],
                'sku' => $it['sku'],
                'name' => $it['name'],
                'category' => $it['category'],
                'unit' => $it['unit'],
                'quantity' => (int) $it['quantity'],
                'min_threshold' => (int) $it['min_threshold'],
                'is_low' => (int) $it['quantity'] <= (int) $it['min_threshold'],
                'unit_cost_mad' => (float) $it['unit_cost_mad'],
                'supplier' => $it['supplier'],
                'last_movement_date' => $it['last_movement_date'],
            ], $stInvItems->fetchAll());
        }

        // 8. Documents (Quotes, Prescriptions, Certificates, Confirmations)
        $documents = [];
        if (in_array('documents.read', $ctx['permissions'], true)) {
            $stDocsList = $pdo->prepare("
                SELECT d.*, p.first AS patient_first, p.last AS patient_last, p.code AS patient_code, p.cin AS patient_cin
                FROM documents d
                LEFT JOIN patients p ON p.id = d.patient_id
                WHERE d.tenant_id = ? " . str_replace('branch_id', 'd.branch_id', $bSql) . "
                ORDER BY d.issued_date DESC, d.id DESC
            ");
            $stDocsList->execute($bParams);
            foreach ($stDocsList->fetchAll() as $doc) {
                if (!$canReadDme && in_array($doc['doc_type'], ['prescription', 'report'], true)) {
                    // Hide clinical prescription/report text from non-clinical staff
                    continue;
                }
                $documents[] = [
                    'id' => (int) $doc['id'],
                    'branch_id' => (int) $doc['branch_id'],
                    'patient_id' => $doc['patient_id'] ? (int) $doc['patient_id'] : null,
                    'patient_name' => trim(($doc['patient_first'] ?? '') . ' ' . ($doc['patient_last'] ?? '')) ?: 'Établissement',
                    'patient_code' => $doc['patient_code'] ?? '',
                    'patient_cin' => $doc['patient_cin'] ?? '',
                    'doctor_name' => $doc['doctor_name'],
                    'doc_type' => $doc['doc_type'],
                    'reference' => $doc['reference'],
                    'title' => $doc['title'],
                    'body_text' => $doc['body_text'],
                    'amount_mad' => (float) $doc['amount_mad'],
                    'issued_date' => $doc['issued_date'],
                    'shared_with_patient' => (bool) $doc['shared_with_patient'],
                    'created_by' => $doc['created_by'],
                ];
            }
        }

        // 9. Catalogue Services & Holidays
        $stSrv = $pdo->prepare('SELECT * FROM medical_services WHERE tenant_id = ? AND is_active = 1 ORDER BY specialty ASC, name ASC');
        $stSrv->execute([$tenantId]);
        $services = $stSrv->fetchAll();

        $stHol = $pdo->prepare('SELECT * FROM clinic_holidays WHERE tenant_id = ? ORDER BY start_date ASC');
        $stHol->execute([$tenantId]);
        $holidays = $stHol->fetchAll();

        // 10. Notifications (respecting user preferences & role)
        $stPref = $pdo->prepare('SELECT * FROM notification_preferences WHERE user_id = ? LIMIT 1');
        $stPref->execute([(int) ($ctx['user']['id'] ?? 1)]);
        $prefs = $stPref->fetch() ?: [
            'notify_appointments' => 1, 'notify_schedule' => 1, 'notify_payments' => 1,
            'notify_labs' => 1, 'notify_stock' => 1, 'notify_subscription' => 1,
        ];

        $stNotifs = $pdo->prepare('
            SELECT * FROM notifications
            WHERE tenant_id = ? AND (target_role IS NULL OR target_role = ?)
            ORDER BY is_read ASC, id DESC
            LIMIT 25
        ');
        $stNotifs->execute([$tenantId, $ctx['role']]);
        $notifications = [];
        foreach ($stNotifs->fetchAll() as $nf) {
            if ($nf['category'] === 'lab' && (!$labEnabled || !(int) ($prefs['notify_labs'] ?? 1))) {
                continue;
            }
            if ($nf['category'] === 'stock' && (!$inventoryEnabled || !(int) ($prefs['notify_stock'] ?? 1))) {
                continue;
            }
            if ($nf['category'] === 'appointment' && !(int) ($prefs['notify_appointments'] ?? 1)) {
                continue;
            }
            if ($nf['category'] === 'payment' && !(int) ($prefs['notify_payments'] ?? 1)) {
                continue;
            }
            if ($nf['category'] === 'subscription' && !(int) ($prefs['notify_subscription'] ?? 1)) {
                continue;
            }
            $notifications[] = [
                'id' => (int) $nf['id'],
                'category' => $nf['category'],
                'severity' => $nf['severity'],
                'title' => $nf['title'],
                'message' => $nf['message'],
                'action_tab' => $nf['action_tab'],
                'is_read' => (bool) $nf['is_read'],
                'time' => substr((string) $nf['created_at'], 11, 5) ?: '09:00',
                'created_at' => $nf['created_at'],
            ];
        }

        // 11. Audit Logs
        $auditLogs = [];
        if (in_array('audit.read', $ctx['permissions'], true) || in_array('reports.read', $ctx['permissions'], true)) {
            $stAud = $pdo->prepare('SELECT * FROM audit_logs WHERE tenant_id = ? ORDER BY id DESC LIMIT 40');
            $stAud->execute([$tenantId]);
            $auditLogs = array_map(static fn(array $lg): array => [
                'id' => (int) $lg['id'],
                'time' => substr((string) $lg['created_at'], 11, 8) ?: $lg['created_at'],
                'created_at' => $lg['created_at'],
                'actor' => $lg['actor_name'],
                'role' => $lg['actor_role'],
                'action' => $lg['action'],
                'entity_type' => $lg['entity_type'],
                'target' => $lg['entity_ref'],
                'details' => $lg['details'],
                'status' => 'CNDP-OK',
            ], $stAud->fetchAll());
        }

        // =====================================================================
        // SECTION 4 — REAL SQL-COMPUTED DASHBOARD INDICATORS (ZERO FAKE NUMBERS)
        // =====================================================================
        $dashboardMetrics = self::computeDashboardMetrics($pdo, $ctx, $tenantId, $today, $doctors, $appointments, $labs, $labEnabled, $bedsEnabled, $tenantRow);

        $branchLabel = $activeBranchRow
            ? ($activeBranchRow['name'])
            : ($tenantRow['city'] . ' • ' . (count($visibleBranches) > 1 ? count($visibleBranches) . ' branches actives' : ($visibleBranches[0]['name'] ?? $tenantRow['short_name'])));

        return [
            'auth' => [
                'user' => $ctx['user'],
                'role' => $ctx['role'],
                'permissions' => $ctx['permissions'],
                'active_branch_id' => $ctx['branch_id'],
                'is_saas_owner' => false,
                'notification_preferences' => $prefs,
            ],
            'tenant' => [
                'id' => (int) $tenantRow['id'],
                'name' => $tenantRow['name'],
                'short' => $activeBranchRow ? $activeBranchRow['code'] : $tenantRow['short_name'],
                'short_name' => $tenantRow['short_name'],
                'city' => $tenantRow['city'],
                'branch' => $branchLabel,
                'address' => $tenantRow['address'],
                'phone' => $tenantRow['phone'],
                'email' => $tenantRow['email'],
                'ice' => $tenantRow['ice'],
                'if_tax' => $tenantRow['if_tax'],
                'inpe' => $tenantRow['inpe'],
                'cnss_affiliation' => $tenantRow['cnss_affiliation'],
                'logo_url' => $tenantRow['logo_url'],
                'currency' => $tenantRow['currency'],
                'primary_language' => $tenantRow['primary_language'],
                'timezone' => $tenantRow['timezone'],
                'enable_laboratory' => $labEnabled,
                'raw_enable_laboratory' => (bool) $tenantRow['enable_laboratory'],
                'enable_inventory' => $inventoryEnabled,
                'raw_enable_inventory' => (bool) $tenantRow['enable_inventory'],
                'enable_inpatient_beds' => $bedsEnabled,
                'raw_enable_inpatient_beds' => (bool) $tenantRow['enable_inpatient_beds'],
                'total_beds' => (int) $tenantRow['total_beds'],
                'occupied_beds' => (int) $tenantRow['occupied_beds'],
                'default_slot_minutes' => (int) $tenantRow['default_slot_minutes'],
                'work_day_start' => $tenantRow['work_day_start'],
                'work_day_end' => $tenantRow['work_day_end'],
                'invoice_prefix' => $tenantRow['invoice_prefix'],
                'receipt_prefix' => $tenantRow['receipt_prefix'],
                'quote_prefix' => $tenantRow['quote_prefix'],
                'prescription_prefix' => $tenantRow['prescription_prefix'],
                'certificate_prefix' => $tenantRow['certificate_prefix'],
                'doc_header_note' => $tenantRow['doc_header_note'],
                'doc_footer_note' => $tenantRow['doc_footer_note'],
                'subscription' => [
                    'plan_code' => $sub['plan_code'] ?? 'pro_clinic',
                    'plan_name' => $sub['plan_name'] ?? 'Pro Clinique',
                    'status' => $sub['status'] ?? 'active',
                    'is_trial' => (bool) ($sub['is_trial'] ?? false),
                    'started_at' => $sub['started_at'] ?? '',
                    'trial_ends_at' => $sub['trial_ends_at'] ?? null,
                    'expires_at' => $sub['expires_at'] ?? '',
                    'days_remaining' => $daysRemaining,
                    'monthly_price_mad' => (float) ($sub['monthly_price_mad'] ?? 0),
                    'max_users' => (int) ($sub['max_users'] ?? 10),
                    'max_branches' => (int) ($sub['max_branches'] ?? 2),
                    'max_doctors' => (int) ($sub['max_doctors'] ?? 10),
                ],
            ],
            'centres' => $centresList,
            'branches' => $visibleBranches,
            'kpis' => $dashboardMetrics['kpis'],
            'dashboard_metrics' => $dashboardMetrics,
            'weekly_patients' => $dashboardMetrics['weekly_patients'],
            'doctors' => $doctors,
            'patients' => $patients,
            'appointments' => $appointments,
            'labs' => $labs,
            'invoices' => $invoices,
            'payments' => $paymentsList,
            'expenses' => $expenses,
            'cash_session' => $cashSession,
            'inventory' => $inventory,
            'documents' => $documents,
            'services' => $services,
            'holidays' => $holidays,
            'notifications' => $notifications,
            'audit_logs' => $auditLogs,
            'saas_console' => in_array($ctx['role'] ?? '', ['owner', 'saas_owner']) ? ReportsAndAdminController::buildSaasOwnerPayload($pdo) : null,
        ];
    }

    private static function computeDashboardMetrics(
        PDO $pdo,
        array $ctx,
        int $tenantId,
        string $today,
        array $doctors,
        array $appointments,
        array $labs,
        bool $labEnabled,
        bool $bedsEnabled,
        array $tenantRow
    ): array {
        $bParams = [$tenantId];
        $bSql = CliniqoAuthService::branchSqlFilter($ctx, 'branch_id', $bParams);

        // --- A. PATIENTS AUJOURD'HUI (Real appointments, checked-in, seen) ---
        $todayApts = array_values(array_filter($appointments, static fn(array $a): bool => $a['date'] === $today));
        $scheduledToday = 0;
        $checkedInToday = 0;
        $seenToday = 0;
        $cancelledToday = 0;
        $noShowToday = 0;

        foreach ($todayApts as $ta) {
            if ($ta['status'] === 'cancelled') {
                $cancelledToday++;
                continue;
            }
            $scheduledToday++;
            if ($ta['status'] === 'no_show') {
                $noShowToday++;
            }
            if (!empty($ta['checked_in_at']) || in_array($ta['status'], ['waiting', 'in_progress', 'completed'], true)) {
                $checkedInToday++;
            }
            if ($ta['status'] === 'completed') {
                $seenToday++;
            }
        }

        // Real 7-day attendance bars (2026-10-23 to 2026-10-29)
        $dayNamesFr = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
        $weeklySeries = [];
        $maxDayCount = 1;
        $rawDays = [];
        for ($i = 6; $i >= 0; $i--) {
            $dIso = date('Y-m-d', strtotime("{$today} -{$i} days"));
            $dow = (int) date('w', strtotime($dIso));
            $dayApts = array_filter($appointments, static fn(array $a): bool => $a['date'] === $dIso && $a['status'] !== 'cancelled');
            $sched = count($dayApts);
            $seen = count(array_filter($dayApts, static fn(array $a): bool => $a['status'] === 'completed'));
            $checked = count(array_filter($dayApts, static fn(array $a): bool => !empty($a['checked_in_at']) || in_array($a['status'], ['waiting', 'in_progress', 'completed'], true)));
            if ($sched > $maxDayCount) {
                $maxDayCount = $sched;
            }
            $rawDays[] = [
                'date' => $dIso,
                'day' => $dayNamesFr[$dow],
                'count' => $sched,
                'scheduled' => $sched,
                'checked_in' => $checked,
                'seen' => $seen,
            ];
        }
        foreach ($rawDays as $rd) {
            $rd['growth'] = $rd['seen'] . '/' . $rd['scheduled'] . ' vus';
            $rd['h'] = $rd['scheduled'] > 0 ? max(18, (int) round(($rd['scheduled'] / $maxDayCount) * 92)) : 10;
            $weeklySeries[] = $rd;
        }

        // --- B. ANALYSES RÉALISÉES (Completed lab tests only when lab enabled; operational replacement otherwise) ---
        $completedLabsTotal = 0;
        $completedLabsToday = 0;
        $pendingLabs = 0;
        $flaggedLabs = 0;
        $labHeatmap = [];

        if ($labEnabled) {
            $stLabAll = $pdo->prepare("SELECT status, category, completed_date, test_name FROM labs WHERE tenant_id = ? {$bSql}");
            $stLabAll->execute($bParams);
            $labRows = $stLabAll->fetchAll();
            $catStats = [];
            foreach ($labRows as $lr) {
                $cat = $lr['category'] ?: 'Biochimie';
                if (!isset($catStats[$cat])) {
                    $catStats[$cat] = ['total' => 0, 'completed' => 0];
                }
                $catStats[$cat]['total']++;
                if ($lr['status'] === 'validated') {
                    $completedLabsTotal++;
                    $catStats[$cat]['completed']++;
                    if ($lr['completed_date'] === $today) {
                        $completedLabsToday++;
                    }
                } elseif ($lr['status'] === 'flagged') {
                    $flaggedLabs++;
                } else {
                    $pendingLabs++;
                }
            }
            foreach ($catStats as $cName => $cs) {
                $pct = $cs['total'] > 0 ? (int) round(($cs['completed'] / $cs['total']) * 100) : 0;
                $labHeatmap[] = [
                    'label' => $cName,
                    'completed' => $cs['completed'],
                    'total' => $cs['total'],
                    'rate' => $pct . '%',
                ];
            }
        }

        // Operational metric when Lab is disabled: Completed Consultations & Attendance Rate
        $nonCancelledAll = array_filter($appointments, static fn(array $a): bool => $a['status'] !== 'cancelled' && $a['date'] <= $today);
        $completedConsultsAll = count(array_filter($nonCancelledAll, static fn(array $a): bool => $a['status'] === 'completed'));
        $closedPastSlots = array_filter($nonCancelledAll, static fn(array $a): bool => in_array($a['status'], ['completed', 'no_show'], true));
        $attendanceRatePct = count($closedPastSlots) > 0
            ? round(($completedConsultsAll / count($closedPastSlots)) * 100, 1)
            : 100.0;

        // --- C. RECETTES DU JOUR (Actual valid payments received today, distinct from Invoiced, Outstanding & Refunds!) ---
        $payParams = array_merge([$today], $bParams);
        $stPayToday = $pdo->prepare("
            SELECT payment_type, amount, paid_time, method
            FROM payments
            WHERE paid_date = ? AND tenant_id = ? AND status = 'valid' {$bSql}
        ");
        $stPayToday->execute($payParams);
        $todayPayments = $stPayToday->fetchAll();

        $grossPaymentsToday = 0.0;
        $refundsToday = 0.0;
        $hourlyBuckets = [
            '08h' => 0.0, '09h' => 0.0, '10h' => 0.0, '11h' => 0.0, '12h' => 0.0,
            '13h' => 0.0, '14h' => 0.0, '15h' => 0.0, '16h' => 0.0, '17h' => 0.0,
        ];
        foreach ($todayPayments as $tp) {
            $amt = (float) $tp['amount'];
            $hrKey = substr((string) $tp['paid_time'], 0, 2) . 'h';
            if ($tp['payment_type'] === 'refund') {
                $refundsToday += $amt;
                if (isset($hourlyBuckets[$hrKey])) {
                    $hourlyBuckets[$hrKey] = max(0.0, $hourlyBuckets[$hrKey] - $amt);
                }
            } else {
                $grossPaymentsToday += $amt;
                if (isset($hourlyBuckets[$hrKey])) {
                    $hourlyBuckets[$hrKey] += $amt;
                } else {
                    $hourlyBuckets['10h'] += $amt;
                }
            }
        }
        $netCollectedToday = max(0.0, $grossPaymentsToday - $refundsToday);

        // Distinct: Total Invoiced Today vs Total Outstanding Balances
        $invTodayParams = array_merge([$today], $bParams);
        $stInvToday = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE issued_date = ? AND tenant_id = ? AND status != 'cancelled' {$bSql}");
        $stInvToday->execute($invTodayParams);
        $invoicedToday = (float) $stInvToday->fetchColumn();

        $stOut = $pdo->prepare("SELECT total, paid, refunded FROM invoices WHERE tenant_id = ? AND status NOT IN ('cancelled') {$bSql}");
        $stOut->execute($bParams);
        $outstandingTotal = 0.0;
        foreach ($stOut->fetchAll() as $ir) {
            $netP = max(0.0, (float) $ir['paid'] - (float) $ir['refunded']);
            $outstandingTotal += max(0.0, (float) $ir['total'] - $netP);
        }

        $maxHourAmt = max(1.0, max($hourlyBuckets));
        $revenueBars = [];
        foreach ($hourlyBuckets as $hLabel => $hAmt) {
            $revenueBars[] = [
                'hour' => $hLabel . '00',
                'amount' => round($hAmt, 2),
                'height' => $hAmt > 0 ? max(22, (int) round(($hAmt / $maxHourAmt) * 90)) : 8,
                'peak' => $hAmt > 0 && abs($hAmt - $maxHourAmt) < 0.01,
            ];
        }

        // --- D. NOS SPÉCIALISTES (Exact active doctors & registered specialties in active centre/branch) ---
        $specCountsMap = [];
        foreach ($doctors as $doc) {
            $sp = $doc['specialty'];
            $specCountsMap[$sp] = ($specCountsMap[$sp] ?? 0) + 1;
        }
        arsort($specCountsMap);
        $specialtiesBreakdown = [];
        foreach ($specCountsMap as $spName => $cnt) {
            $specialtiesBreakdown[] = ['specialty' => $spName, 'count' => $cnt];
        }

        // --- E. SCHEDULE & CONSULTATION ROOM UTILIZATION (Replaces Hospital Beds unless inpatient beds genuinely enabled) ---
        $stRooms = $pdo->prepare("SELECT * FROM rooms WHERE tenant_id = ? AND is_active = 1 {$bSql} ORDER BY id ASC");
        $stRooms->execute($bParams);
        $rooms = $stRooms->fetchAll();

        $dailyCapacitySlots = array_sum(array_map(static fn(array $d): int => (int) ($d['daily_slot_capacity'] ?: 10), $doctors));
        if ($dailyCapacitySlots <= 0) {
            $dailyCapacitySlots = max(10, count($rooms) * 10);
        }

        $utilizationSeries = [];
        for ($i = 5; $i >= 0; $i--) {
            $dIso = date('Y-m-d', strtotime("{$today} -{$i} days"));
            $label = date('d/m', strtotime($dIso));
            $dayRows = array_filter($appointments, static fn(array $a): bool => $a['date'] === $dIso);
            $booked = 0;
            $completed = 0;
            $noShowOrCancel = 0;
            $roomsUsed = [];
            foreach ($dayRows as $dr) {
                if ($dr['status'] === 'cancelled') {
                    $noShowOrCancel++;
                    continue;
                }
                $booked++;
                $roomsUsed[$dr['room']] = true;
                if ($dr['status'] === 'completed') {
                    $completed++;
                } elseif ($dr['status'] === 'no_show') {
                    $noShowOrCancel++;
                }
            }
            $slotUtil = min(100, (int) round(($booked / $dailyCapacitySlots) * 100));
            $roomUtil = count($rooms) > 0 ? min(100, (int) round((count($roomsUsed) / count($rooms)) * 100)) : $slotUtil;
            $attPct = ($completed + $noShowOrCancel) > 0
                ? (int) round(($completed / max(1, $booked)) * 100)
                : ($booked > 0 ? 100 : 0);

            $utilizationSeries[] = [
                'label' => $label,
                'date' => $dIso,
                'booked_slots' => $booked,
                'capacity_slots' => $dailyCapacitySlots,
                'completed_slots' => $completed,
                'missed_slots' => $noShowOrCancel,
                'rooms_used' => count($roomsUsed),
                'rooms_total' => count($rooms),
                'slot_utilization_pct' => $slotUtil,
                'room_utilization_pct' => $roomUtil,
                'attendance_pct' => $attPct,
            ];
        }

        $roomBreakdown = [];
        foreach ($rooms as $rm) {
            $rName = $rm['name'];
            $cap = max(1, (int) $rm['daily_capacity_slots']);
            $rToday = count(array_filter($todayApts, static fn(array $a): bool => $a['room'] === $rName && $a['status'] !== 'cancelled'));
            $roomBreakdown[] = [
                'room' => $rName,
                'type' => $rm['room_type'],
                'booked_today' => $rToday,
                'capacity' => $cap,
                'utilization_pct' => min(100, (int) round(($rToday / $cap) * 100)),
            ];
        }

        // --- F. MOYENNE DES DIAGNOSTICS PATIENTS (Real encounters by medical pole across Day / Week / Month) ---
        $encParams = [$tenantId];
        $encBSql = CliniqoAuthService::branchSqlFilter($ctx, 'branch_id', $encParams);
        $stEncAll = $pdo->prepare("SELECT pole, encounter_date, icd_code FROM encounters WHERE tenant_id = ? {$encBSql}");
        $stEncAll->execute($encParams);
        $encRows = $stEncAll->fetchAll();

        $poles = ['Neurologie', 'Oncologie', 'Urologie', 'Cardiologie'];
        $diagWindows = [
            'week' => ['label' => '7 derniers jours', 'from' => date('Y-m-d', strtotime("{$today} -6 days")), 'days' => 7],
            'month' => ['label' => 'Mois en cours', 'from' => substr($today, 0, 8) . '01', 'days' => 29],
            'day' => ['label' => 'Aujourd’hui', 'from' => $today, 'days' => 1],
        ];
        $diagnosticsByPeriod = [];
        foreach ($diagWindows as $wKey => $wMeta) {
            $counts = array_fill_keys($poles, 0);
            $otherCount = 0;
            $totalInWin = 0;
            foreach ($encRows as $er) {
                if ($er['encounter_date'] >= $wMeta['from'] && $er['encounter_date'] <= $today) {
                    $totalInWin++;
                    if (isset($counts[$er['pole']])) {
                        $counts[$er['pole']]++;
                    } else {
                        $otherCount++;
                    }
                }
            }
            $diagnosticsByPeriod[$wKey] = [
                'label' => $wMeta['label'],
                'total_encounters' => $totalInWin,
                'avg_per_day' => round($totalInWin / $wMeta['days'], 1),
                'poles' => $counts,
                'other' => $otherCount,
            ];
        }

        return [
            'kpis' => [
                'patients_today' => $scheduledToday,
                'patients_checked_in' => $checkedInToday,
                'patients_seen' => $seenToday,
                'tests_conducted' => $labEnabled ? $completedLabsTotal : $completedConsultsAll,
                'tests_completed_today' => $completedLabsToday,
                'revenue_today' => $netCollectedToday,
                'invoiced_today' => $invoicedToday,
                'outstanding_total' => $outstandingTotal,
                'refunds_today' => $refundsToday,
                'specialists_count' => count($doctors),
                'specialties_count' => count($specialtiesBreakdown),
            ],
            'patients_today_detail' => [
                'scheduled' => $scheduledToday,
                'checked_in' => $checkedInToday,
                'seen' => $seenToday,
                'cancelled' => $cancelledToday,
                'no_show' => $noShowToday,
            ],
            'weekly_patients' => $weeklySeries,
            'labs_summary' => [
                'enabled' => $labEnabled,
                'completed_total' => $completedLabsTotal,
                'completed_today' => $completedLabsToday,
                'pending' => $pendingLabs,
                'flagged' => $flaggedLabs,
                'heatmap' => $labHeatmap,
                'operational_replacement' => [
                    'title' => 'Consultations & Présence',
                    'completed_consultations' => $completedConsultsAll,
                    'attendance_rate_pct' => $attendanceRatePct,
                    'scheduled_today' => $scheduledToday,
                    'seen_today' => $seenToday,
                ],
            ],
            'revenue_today_detail' => [
                'collected_today' => $netCollectedToday,
                'gross_payments_today' => $grossPaymentsToday,
                'refunds_today' => $refundsToday,
                'invoiced_today' => $invoicedToday,
                'outstanding_total' => $outstandingTotal,
                'hourly_bars' => $revenueBars,
            ],
            'specialists_summary' => [
                'active_doctors_count' => count($doctors),
                'specialties_count' => count($specialtiesBreakdown),
                'by_specialty' => $specialtiesBreakdown,
            ],
            'utilization_summary' => [
                'beds_enabled' => $bedsEnabled,
                'total_beds' => (int) ($tenantRow['total_beds'] ?? 0),
                'occupied_beds' => (int) ($tenantRow['occupied_beds'] ?? 0),
                'attendance_rate_pct' => $attendanceRatePct,
                'series' => $utilizationSeries,
                'rooms' => $roomBreakdown,
            ],
            'diagnostics_summary' => $diagnosticsByPeriod,
        ];
    }

    private function respond(array $ctx, array $extra = [], float $startedAt = 0.0, int $status = 200): JsonResponse
    {
        $payload = array_merge([
            'success' => true,
            'state' => self::buildWorkspaceState($ctx),
        ], $extra);

        $payload['meta'] = [
            'engine' => 'Cliniqo SQLite Relational Engine v6',
            'latency_ms' => $startedAt > 0 ? round((microtime(true) - $startedAt) * 1000, 2) : 1.2,
            'timestamp' => date('c'),
        ];

        return response()->json($payload, $status);
    }

    public function getState(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = $request->attributes->get('cliniqo_ctx') ?? CliniqoAuthService::resolveStaffContext($request);
        return $this->respond($ctx, [], $t0);
    }

    public function resetTenant(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'settings.manage');
        CliniqoDatabase::resetAll();
        $freshCtx = CliniqoAuthService::resolveStaffContext($request);
        CliniqoAuthService::audit(
            $freshCtx['tenant_id'],
            $freshCtx['branch_id'],
            (int) $freshCtx['user']['id'],
            $freshCtx['user']['name'],
            $freshCtx['role'],
            'RESET_DEMO_DB',
            'system',
            'SQLITE_SEED',
            'Réinitialisation complète des données de démonstration',
            $request->ip() ?? '127.0.0.1'
        );
        return $this->respond($freshCtx, ['message' => 'Base de données réinitialisée avec succès.'], $t0);
    }

    // =========================================================================
    // APPOINTMENTS CRUD (with Anti-Overlap, Holiday Check, Confirmation & Audit)
    // =========================================================================
    public function listAppointments(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'appointments.read');
        $state = self::buildWorkspaceState($ctx);
        $list = $state['appointments'];
        if ($request->filled('date')) {
            $d = (string) $request->query('date');
            $list = array_values(array_filter($list, static fn(array $a): bool => $a['date'] === $d));
        }
        return response()->json(['success' => true, 'data' => $list]);
    }

    public function createAppointment(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'appointments.write');
        $tenantId = (int) $ctx['tenant_id'];
        CliniqoAuthService::requireActiveSubscription($tenantId);

        $pdo = CliniqoDatabase::connection();
        $patientId = (int) $request->input('patient_id', 0);
        $date = trim((string) $request->input('date', CliniqoDatabase::TODAY));
        $time = trim((string) $request->input('time', '09:30'));
        $doctorName = trim((string) $request->input('doctor', ''));
        $room = trim((string) $request->input('room', ''));
        $status = trim((string) $request->input('status', 'confirmed'));
        $fee = max(0.0, (float) $request->input('fee_mad', 600));
        $reason = trim((string) $request->input('reason', 'Consultation médicale'));
        $serviceName = trim((string) $request->input('service_name', 'Consultation Spécialisée'));

        if ($patientId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return response()->json(['success' => false, 'message' => 'Patient, date (AAAA-MM-JJ) et heure (HH:MM) valides requis.'], 422);
        }

        $allowedStatuses = ['confirmed', 'waiting', 'in_progress', 'completed', 'cancelled', 'no_show'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'confirmed';
        }

        // Verify patient belongs to this tenant
        $stP = $pdo->prepare('SELECT * FROM patients WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stP->execute([$patientId, $tenantId]);
        $pat = $stP->fetch();
        if (!$pat) {
            return response()->json(['success' => false, 'message' => 'Patient introuvable dans cet établissement.'], 404);
        }

        // Resolve doctor
        $stD = $pdo->prepare('SELECT * FROM doctors WHERE tenant_id = ? AND is_active = 1 AND (name = ? OR id = ?) LIMIT 1');
        $stD->execute([$tenantId, $doctorName, (int) $request->input('doctor_id', 0)]);
        $doc = $stD->fetch();
        if (!$doc) {
            $stD2 = $pdo->prepare('SELECT * FROM doctors WHERE tenant_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1');
            $stD2->execute([$tenantId]);
            $doc = $stD2->fetch();
        }

        $doctorId = $doc ? (int) $doc['id'] : null;
        $doctorName = $doc ? $doc['name'] : ($doctorName ?: 'Dr. Alimi Jan');
        $specialty = trim((string) $request->input('specialty', $doc['specialty'] ?? 'Cardiologue'));
        $room = $room !== '' ? $room : ($doc['room'] ?? 'Salle 101');
        $branchId = CliniqoAuthService::defaultWriteBranchId(
            $ctx,
            $request->filled('branch_id') ? (int) $request->input('branch_id') : ($doc ? (int) $doc['branch_id'] : (int) $pat['branch_id'])
        );

        // Check clinic/doctor holidays
        $stHol = $pdo->prepare('SELECT label FROM clinic_holidays WHERE tenant_id = ? AND start_date <= ? AND end_date >= ? AND (doctor_id IS NULL OR doctor_id = ?) LIMIT 1');
        $stHol->execute([$tenantId, $date, $date, $doctorId]);
        $hol = $stHol->fetch();
        if ($hol) {
            return response()->json([
                'success' => false,
                'error' => 'HOLIDAY_CONFLICT',
                'message' => "Créneau indisponible ({$hol['label']}). Veuillez choisir une autre date.",
            ], 409);
        }

        // Anti-overlap check on active appointments (same date + time + doctor or room)
        if (!in_array($status, ['cancelled', 'no_show'], true)) {
            $stConf = $pdo->prepare("
                SELECT id, doctor, room FROM appointments
                WHERE tenant_id = ? AND date = ? AND time = ? AND status NOT IN ('cancelled', 'no_show')
                  AND (LOWER(doctor) = LOWER(?) OR (branch_id = ? AND LOWER(room) = LOWER(?)))
                LIMIT 1
            ");
            $stConf->execute([$tenantId, $date, $time, $doctorName, $branchId, $room]);
            $conf = $stConf->fetch();
            if ($conf) {
                return response()->json([
                    'success' => false,
                    'error' => 'SLOT_CONFLICT',
                    'message' => "Conflit d’agenda : {$doctorName} ou {$room} a déjà un rendez-vous le {$date} à {$time}.",
                ], 409);
            }
        }

        $checkedInAt = in_array($status, ['waiting', 'in_progress', 'completed'], true) ? $time : null;
        $seenAt = in_array($status, ['in_progress', 'completed'], true) ? $time : null;

        $stIns = $pdo->prepare('
            INSERT INTO appointments (tenant_id, branch_id, patient_id, doctor_id, doctor, specialty, service_name, room, date, time, duration_min, status, checked_in_at, seen_at, fee_mad, reason, source, created_by_user_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $stIns->execute([
            $tenantId, $branchId, $patientId, $doctorId, $doctorName, $specialty, $serviceName, $room,
            $date, $time, (int) ($doc['duration_min'] ?? 30), $status, $checkedInAt, $seenAt,
            $fee > 0 ? $fee : (float) ($doc['fee_mad'] ?? 600), $reason, 'clinic', (int) $ctx['user']['id'],
        ]);
        $aptId = (int) $pdo->lastInsertId();

        // Create appointment confirmation document + privacy-safe notification
        $confRef = sprintf('CONF-%d-%d', $tenantId, 1000 + $aptId);
        $pdo->prepare('INSERT INTO documents (tenant_id, branch_id, patient_id, doctor_name, doc_type, reference, title, body_text, amount_mad, issued_date, shared_with_patient, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,1,?)')
            ->execute([
                $tenantId, $branchId, $patientId, $doctorName, 'appointment_confirmation', $confRef,
                "Confirmation de Rendez-vous — {$date} à {$time}",
                "Rendez-vous confirmé pour {$pat['first']} {$pat['last']} ({$pat['code']}) avec {$doctorName} ({$specialty}) en {$room} le {$date} à {$time}.",
                $fee, CliniqoDatabase::TODAY, $ctx['user']['name'],
            ]);

        CliniqoAuthService::pushNotification(
            $tenantId,
            $branchId,
            'appointment',
            'info',
            "Nouveau RDV confirmé ({$pat['code']})",
            "Créneau réservé le {$date} à {$time} avec {$doctorName} ({$room}).",
            'overview',
            null,
            $patientId
        );

        CliniqoAuthService::audit(
            $tenantId, $branchId, (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'CREATE_APPOINTMENT', 'appointment', "RDV-{$aptId}",
            "{$pat['code']} • {$doctorName} le {$date} à {$time}",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['appointment_id' => $aptId, 'confirmation_ref' => $confRef], $t0, 201);
    }

    public function updateAppointment(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'appointments.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM appointments WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $apt = $st->fetch();
        if (!$apt) {
            return response()->json(['success' => false, 'message' => 'Rendez-vous introuvable.'], 404);
        }

        $patientId = (int) $request->input('patient_id', $apt['patient_id']);
        $date = trim((string) $request->input('date', $apt['date']));
        $time = trim((string) $request->input('time', $apt['time']));
        $doctorName = trim((string) $request->input('doctor', $apt['doctor']));
        $specialty = trim((string) $request->input('specialty', $apt['specialty']));
        $room = trim((string) $request->input('room', $apt['room']));
        $status = trim((string) $request->input('status', $apt['status']));
        $cancelReason = trim((string) $request->input('cancellation_reason', $apt['cancellation_reason']));
        $fee = (float) $request->input('fee_mad', $apt['fee_mad']);
        $reason = trim((string) $request->input('reason', $apt['reason']));

        if (!in_array($status, ['cancelled', 'no_show'], true)) {
            $stConf = $pdo->prepare("
                SELECT id FROM appointments
                WHERE tenant_id = ? AND id != ? AND date = ? AND time = ? AND status NOT IN ('cancelled', 'no_show')
                  AND (LOWER(doctor) = LOWER(?) OR (branch_id = ? AND LOWER(room) = LOWER(?)))
                LIMIT 1
            ");
            $stConf->execute([$tenantId, $id, $date, $time, $doctorName, (int) $apt['branch_id'], $room]);
            if ($stConf->fetch()) {
                return response()->json([
                    'success' => false,
                    'error' => 'SLOT_CONFLICT',
                    'message' => "Conflit d’horaire : {$doctorName} ou {$room} est déjà occupé le {$date} à {$time}.",
                ], 409);
            }
        }

        $checkedInAt = $apt['checked_in_at'];
        $seenAt = $apt['seen_at'];
        if (in_array($status, ['waiting', 'in_progress', 'completed'], true) && !$checkedInAt) {
            $checkedInAt = date('H:i');
        }
        if (in_array($status, ['in_progress', 'completed'], true) && !$seenAt) {
            $seenAt = date('H:i');
        }

        $pdo->prepare('
            UPDATE appointments
            SET patient_id=?, doctor=?, specialty=?, room=?, date=?, time=?, status=?, cancellation_reason=?, checked_in_at=?, seen_at=?, fee_mad=?, reason=?
            WHERE id=? AND tenant_id=?
        ')->execute([$patientId, $doctorName, $specialty, $room, $date, $time, $status, $cancelReason, $checkedInAt, $seenAt, $fee, $reason, $id, $tenantId]);

        if ($date !== $apt['date'] || $time !== $apt['time'] || $status !== $apt['status']) {
            CliniqoAuthService::pushNotification(
                $tenantId, (int) $apt['branch_id'], 'appointment', 'info',
                "Mise à jour RDV #RDV-{$id}",
                "Créneau du {$date} à {$time} ({$doctorName}) passé au statut : {$status}.",
                'overview', null, $patientId
            );
        }

        CliniqoAuthService::audit(
            $tenantId, (int) $apt['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'UPDATE_APPOINTMENT', 'appointment', "RDV-{$id}",
            "Modification RDV -> {$date} {$time} ({$status})",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, [], $t0);
    }

    public function updateAppointmentStatus(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'appointments.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM appointments WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $apt = $st->fetch();
        if (!$apt) {
            return response()->json(['success' => false, 'message' => 'Rendez-vous introuvable.'], 404);
        }

        $next = trim((string) $request->input('status', ''));
        $cycle = ['confirmed' => 'waiting', 'waiting' => 'in_progress', 'in_progress' => 'completed', 'completed' => 'completed'];
        if ($next === '') {
            $next = $cycle[$apt['status']] ?? 'confirmed';
        }
        $cancelReason = trim((string) $request->input('cancellation_reason', $apt['cancellation_reason']));

        $checkedInAt = $apt['checked_in_at'];
        $seenAt = $apt['seen_at'];
        if (in_array($next, ['waiting', 'in_progress', 'completed'], true) && !$checkedInAt) {
            $checkedInAt = date('H:i');
        }
        if (in_array($next, ['in_progress', 'completed'], true) && !$seenAt) {
            $seenAt = date('H:i');
        }

        $pdo->prepare('UPDATE appointments SET status = ?, cancellation_reason = ?, checked_in_at = ?, seen_at = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$next, $cancelReason, $checkedInAt, $seenAt, $id, $tenantId]);

        // Auto-generate unpaid invoice when consultation is completed if none linked yet!
        if ($next === 'completed' && $apt['status'] !== 'completed') {
            $stExistInv = $pdo->prepare('SELECT id FROM invoices WHERE appointment_id = ? AND tenant_id = ? LIMIT 1');
            $stExistInv->execute([$id, $tenantId]);
            if (!$stExistInv->fetch()) {
                $stPat = $pdo->prepare('SELECT * FROM patients WHERE id = ? LIMIT 1');
                $stPat->execute([(int) $apt['patient_id']]);
                $pat = $stPat->fetch();
                $stTen = $pdo->prepare('SELECT invoice_prefix FROM tenants WHERE id = ? LIMIT 1');
                $stTen->execute([$tenantId]);
                $pref = (string) ($stTen->fetchColumn() ?: 'FAC');
                $invNum = sprintf('%s-2026-%d', $pref, 3100 + $id);
                $fee = (float) $apt['fee_mad'];
                $cnssOrg = str_contains((string) ($pat['coverage'] ?? ''), 'CNOPS') ? 'CNOPS' : 'CNSS AMO';
                $cnssEst = round($fee * 0.8, 2);

                $pdo->prepare('
                    INSERT INTO invoices (tenant_id, branch_id, num, patient_id, patient_name, appointment_id, doctor_name, specialty, service, issued_date, total, paid, refunded, method, status, cnss_org, cnss_est, cnss_status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,?,?,?,?,?)
                ')->execute([
                    $tenantId, (int) $apt['branch_id'], $invNum, (int) $apt['patient_id'],
                    trim(($pat['first'] ?? '') . ' ' . ($pat['last'] ?? '')),
                    $id, $apt['doctor'], $apt['specialty'], $apt['service_name'] ?: ('Consultation ' . $apt['specialty']),
                    CliniqoDatabase::TODAY, $fee, 'En attente', 'unpaid', $cnssOrg, $cnssEst, 'Dossier prêt',
                ]);
            }
        }

        if (in_array($next, ['cancelled', 'no_show'], true)) {
            CliniqoAuthService::pushNotification(
                $tenantId, (int) $apt['branch_id'], 'appointment', 'warning',
                $next === 'cancelled' ? "RDV Annulé (#RDV-{$id})" : "Absence / No-Show (#RDV-{$id})",
                "Créneau du {$apt['date']} à {$apt['time']} ({$apt['doctor']}) marqué comme {$next}.",
                'reports', null, (int) $apt['patient_id']
            );
        }

        CliniqoAuthService::audit(
            $tenantId, (int) $apt['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'STATUS_APPOINTMENT', 'appointment', "RDV-{$id}",
            "{$apt['status']} -> {$next}",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, [], $t0);
    }

    public function deleteAppointment(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'appointments.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM appointments WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $apt = $st->fetch();
        if (!$apt) {
            return response()->json(['success' => false, 'message' => 'Rendez-vous introuvable.'], 404);
        }

        $pdo->prepare('DELETE FROM appointments WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);

        CliniqoAuthService::audit(
            $tenantId, (int) $apt['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'DELETE_APPOINTMENT', 'appointment', "RDV-{$id}",
            "Suppression RDV {$apt['date']} {$apt['time']} ({$apt['doctor']})",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, [], $t0);
    }

    // =========================================================================
    // DOCTORS CRUD (Respects Subscription Doctor Limit & Schedule Config)
    // =========================================================================
    public function listDoctors(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'dashboard.view');
        $state = self::buildWorkspaceState($ctx);
        return response()->json(['success' => true, 'data' => $state['doctors']]);
    }

    public function createDoctor(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'doctors.write');
        $tenantId = (int) $ctx['tenant_id'];
        $sub = CliniqoAuthService::requireActiveSubscription($tenantId);
        $pdo = CliniqoDatabase::connection();

        $cntSt = $pdo->prepare('SELECT COUNT(*) FROM doctors WHERE tenant_id = ? AND is_active = 1');
        $cntSt->execute([$tenantId]);
        if ((int) $cntSt->fetchColumn() >= (int) $sub['max_doctors']) {
            return response()->json([
                'success' => false,
                'error' => 'PLAN_DOCTOR_LIMIT_REACHED',
                'message' => "Limite de praticiens atteinte pour votre forfait {$sub['plan_name']} (max {$sub['max_doctors']}).",
            ], 403);
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Le nom du médecin est requis.'], 422);
        }
        if (!preg_match('/^(Dr\.|Pr\.|Docteur|Professeur)\s/i', $name)) {
            $name = 'Dr. ' . $name;
        }

        $stDup = $pdo->prepare('SELECT id FROM doctors WHERE tenant_id = ? AND LOWER(name) = LOWER(?) AND is_active = 1 LIMIT 1');
        $stDup->execute([$tenantId, $name]);
        if ($stDup->fetch()) {
            return response()->json(['success' => false, 'message' => "Le praticien {$name} existe déjà dans cet établissement."], 409);
        }

        $branchId = CliniqoAuthService::defaultWriteBranchId($ctx, $request->filled('branch_id') ? (int) $request->input('branch_id') : null);
        $specialty = trim((string) $request->input('specialty', 'Cardiologue'));
        $poleMap = ['Cardiologue' => 'Cardiologie', 'Neurologue' => 'Neurologie', 'Oncologue' => 'Oncologie', 'Urologue' => 'Urologie', 'Psychologue' => 'Psychologie'];
        $pole = $poleMap[$specialty] ?? $specialty;
        $room = trim((string) $request->input('room', 'Salle 108'));
        $fee = max(50.0, (float) $request->input('fee_mad', 600));
        $duration = max(10, (int) $request->input('duration_min', 30));
        $inpe = strtoupper(trim((string) $request->input('inpe', 'INPE-' . random_int(100000, 999999))));
        $phone = trim((string) $request->input('phone', '+212 661-506070'));
        $avatar = trim((string) $request->input('avatar', ''));
        $workingDays = trim((string) $request->input('working_days', 'Lun,Mar,Mer,Jeu,Ven,Sam'));
        $workStart = trim((string) $request->input('work_start', '08:30'));
        $workEnd = trim((string) $request->input('work_end', '17:30'));

        $pdo->prepare('
            INSERT INTO doctors (tenant_id, branch_id, name, specialty, pole, room, fee_mad, duration_min, inpe, phone, avatar, working_days, work_start, work_end)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ')->execute([$tenantId, $branchId, $name, $specialty, $pole, $room, $fee, $duration, $inpe, $phone, $avatar, $workingDays, $workStart, $workEnd]);

        $docId = (int) $pdo->lastInsertId();
        // Ensure room exists in rooms table
        $stRm = $pdo->prepare('SELECT id FROM rooms WHERE tenant_id = ? AND branch_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
        $stRm->execute([$tenantId, $branchId, $room]);
        if (!$stRm->fetch()) {
            $pdo->prepare('INSERT INTO rooms (tenant_id, branch_id, name, room_type, daily_capacity_slots) VALUES (?,?,?,?,12)')
                ->execute([$tenantId, $branchId, $room, $pole]);
        }

        CliniqoAuthService::audit(
            $tenantId, $branchId, (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'CREATE_DOCTOR', 'doctor', $name,
            "{$specialty} • {$room} ({$fee} MAD)",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, [
            'doctor' => ['id' => $docId, 'name' => $name, 'specialty' => $specialty, 'room' => $room, 'fee_mad' => $fee],
        ], $t0, 201);
    }

    public function updateDoctor(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'doctors.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM doctors WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $doc = $st->fetch();
        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'Médecin introuvable.'], 404);
        }

        $name = trim((string) $request->input('name', $doc['name']));
        if (!preg_match('/^(Dr\.|Pr\.|Docteur|Professeur)\s/i', $name)) {
            $name = 'Dr. ' . $name;
        }
        $specialty = trim((string) $request->input('specialty', $doc['specialty']));
        $room = trim((string) $request->input('room', $doc['room']));
        $fee = max(50.0, (float) $request->input('fee_mad', $doc['fee_mad']));
        $duration = max(10, (int) $request->input('duration_min', $doc['duration_min']));
        $inpe = trim((string) $request->input('inpe', $doc['inpe']));
        $phone = trim((string) $request->input('phone', $doc['phone']));
        $avatar = trim((string) $request->input('avatar', $doc['avatar']));
        $workingDays = trim((string) $request->input('working_days', $doc['working_days']));
        $workStart = trim((string) $request->input('work_start', $doc['work_start']));
        $workEnd = trim((string) $request->input('work_end', $doc['work_end']));

        $pdo->prepare('
            UPDATE doctors SET name=?, specialty=?, room=?, fee_mad=?, duration_min=?, inpe=?, phone=?, avatar=?, working_days=?, work_start=?, work_end=?
            WHERE id=? AND tenant_id=?
        ')->execute([$name, $specialty, $room, $fee, $duration, $inpe, $phone, $avatar, $workingDays, $workStart, $workEnd, $id, $tenantId]);

        $pdo->prepare('UPDATE appointments SET doctor=?, specialty=?, room=? WHERE doctor_id=? AND tenant_id=? AND date >= ?')
            ->execute([$name, $specialty, $room, $id, $tenantId, CliniqoDatabase::TODAY]);

        if ($workStart !== $doc['work_start'] || $workEnd !== $doc['work_end'] || $workingDays !== $doc['working_days']) {
            CliniqoAuthService::pushNotification(
                $tenantId, (int) $doc['branch_id'], 'schedule', 'info',
                "Horaires modifiés : {$name}",
                "Nouveaux horaires de consultation : {$workingDays} ({$workStart} - {$workEnd}).",
                'overview'
            );
        }

        CliniqoAuthService::audit(
            $tenantId, (int) $doc['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'UPDATE_DOCTOR', 'doctor', $name,
            "Mise à jour fiche praticien ({$specialty}, {$room})",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['doctor' => ['id' => $id, 'name' => $name]], $t0);
    }

    public function deleteDoctor(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'doctors.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $stCnt = $pdo->prepare('SELECT COUNT(*) FROM doctors WHERE tenant_id = ? AND is_active = 1');
        $stCnt->execute([$tenantId]);
        if ((int) $stCnt->fetchColumn() <= 1) {
            return response()->json(['success' => false, 'message' => 'Au moins un praticien actif doit être conservé.'], 422);
        }

        $st = $pdo->prepare('SELECT * FROM doctors WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $doc = $st->fetch();
        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'Praticien introuvable.'], 404);
        }

        $pdo->prepare('UPDATE doctors SET is_active = 0 WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);

        CliniqoAuthService::audit(
            $tenantId, (int) $doc['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'ARCHIVE_DOCTOR', 'doctor', $doc['name'],
            'Désactivation praticien de l’équipe active',
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, [], $t0);
    }

    // =========================================================================
    // PATIENTS & DME (Server-Side Clinical Access Protection!)
    // =========================================================================
    public function listPatients(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'patients.read');
        $state = self::buildWorkspaceState($ctx);
        return response()->json(['success' => true, 'data' => $state['patients']]);
    }

    public function createPatient(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'patients.write');
        $tenantId = (int) $ctx['tenant_id'];
        CliniqoAuthService::requireActiveSubscription($tenantId);
        $pdo = CliniqoDatabase::connection();

        $first = trim((string) $request->input('first', ''));
        $last = trim((string) $request->input('last', ''));
        $cin = strtoupper(trim((string) $request->input('cin', '')));
        if ($first === '' || $last === '' || $cin === '') {
            return response()->json(['success' => false, 'message' => 'Prénom, Nom et N° CIN sont obligatoires.'], 422);
        }

        $stDup = $pdo->prepare('SELECT id FROM patients WHERE tenant_id = ? AND UPPER(cin) = ? LIMIT 1');
        $stDup->execute([$tenantId, $cin]);
        if ($stDup->fetch()) {
            return response()->json(['success' => false, 'message' => "Un dossier patient avec le N° CIN {$cin} existe déjà."], 409);
        }

        $branchId = CliniqoAuthService::defaultWriteBranchId($ctx, $request->filled('branch_id') ? (int) $request->input('branch_id') : null);
        $code = 'PT-' . random_int(1000, 9999);
        $phone = trim((string) $request->input('phone', '+212 661-000000'));
        $email = trim((string) $request->input('email', strtolower($first . '.' . $last . '@patient.ma')));
        $dept = trim((string) $request->input('department', 'Cardiologie'));
        $cov = trim((string) $request->input('coverage', 'CNSS AMO (80%)'));
        $age = max(1, min(120, (int) $request->input('age', 38)));
        $gender = in_array($request->input('gender'), ['M', 'F'], true) ? $request->input('gender') : 'F';
        $pinHash = password_hash((string) $request->input('portal_pin', '1234'), PASSWORD_BCRYPT);

        $pdo->prepare('
            INSERT INTO patients (tenant_id, branch_id, code, first, last, cin, age, gender, phone, email, coverage, department, portal_pin_hash, portal_enabled, avatar)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)
        ')->execute([$tenantId, $branchId, $code, $first, $last, $cin, $age, $gender, $phone, $email, $cov, $dept, $pinHash, '']);

        $patId = (int) $pdo->lastInsertId();

        CliniqoAuthService::audit(
            $tenantId, $branchId, (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'CREATE_PATIENT', 'patient', $code,
            "Création dossier administratif {$first} {$last} (CIN {$cin})",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['patient_id' => $patId, 'patient_code' => $code], $t0, 201);
    }

    public function deletePatient(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'settings.manage');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT code, branch_id FROM patients WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $p = $st->fetch();
        if (!$p) {
            return response()->json(['success' => false, 'message' => 'Dossier introuvable.'], 404);
        }

        $pdo->prepare('DELETE FROM patients WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
        CliniqoAuthService::audit(
            $tenantId, (int) $p['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'DELETE_PATIENT', 'patient', $p['code'], 'Suppression dossier patient', $request->ip() ?? '127.0.0.1'
        );
        return $this->respond($ctx, [], $t0);
    }

    public function getPatientDme(Request $request, int $id): JsonResponse
    {
        // Server-side RBAC check: Secrétaire & Comptable get HTTP 403!
        $ctx = CliniqoAuthService::requirePermission($request, 'patients.dme');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM patients WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $p = $st->fetch();
        if (!$p) {
            return response()->json(['success' => false, 'message' => 'Patient introuvable.'], 404);
        }

        $stEnc = $pdo->prepare('SELECT * FROM encounters WHERE patient_id = ? AND tenant_id = ? ORDER BY encounter_date DESC, id DESC');
        $stEnc->execute([$id, $tenantId]);

        CliniqoAuthService::audit(
            $tenantId, (int) $p['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'VIEW_SENSITIVE_DME', 'patient', $p['code'],
            'Accès autorisé au Dossier Médical Électronique',
            $request->ip() ?? '127.0.0.1'
        );

        return response()->json([
            'success' => true,
            'patient' => $p,
            'encounters' => $stEnc->fetchAll(),
        ]);
    }

    public function savePatientDme(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        // Strict server-side RBAC check (`patients.dme`)!
        $ctx = CliniqoAuthService::requirePermission($request, 'patients.dme');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM patients WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $p = $st->fetch();
        if (!$p) {
            return response()->json(['success' => false, 'message' => 'Patient introuvable.'], 404);
        }

        $bp = trim((string) $request->input('bp', $p['bp']));
        $spo2 = trim((string) $request->input('spo2', $p['spo2']));
        $hr = trim((string) $request->input('hr', $p['hr']));
        $allergy = trim((string) $request->input('allergy', $p['allergy']));
        $icd = trim((string) $request->input('icd', 'QA00 • Bilan clinique'));
        $note = trim((string) $request->input('note', ''));
        $pole = trim((string) $request->input('pole', $p['department'] ?: 'Cardiologie'));
        $shareWithPatient = (int) ((bool) $request->input('share_with_patient', false));

        $pdo->prepare('UPDATE patients SET bp=?, spo2=?, hr=?, allergy=? WHERE id=? AND tenant_id=?')
            ->execute([$bp, $spo2, $hr, $allergy, $id, $tenantId]);

        if ($note !== '') {
            $pdo->prepare('
                INSERT INTO encounters (tenant_id, branch_id, patient_id, doctor_name, pole, icd_code, clinical_text, bp, spo2, hr, encounter_date)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ')->execute([
                $tenantId, (int) $p['branch_id'], $id, $ctx['user']['name'], $pole, $icd, $note, $bp, $spo2, $hr, CliniqoDatabase::TODAY,
            ]);

            if ($shareWithPatient === 1) {
                $ref = sprintf('CR-%d-%d', $tenantId, random_int(1000, 9999));
                $pdo->prepare('INSERT INTO documents (tenant_id, branch_id, patient_id, doctor_name, doc_type, reference, title, body_text, amount_mad, issued_date, shared_with_patient, created_by) VALUES (?,?,?,?,?,?,?,0,?,1,?)')
                    ->execute([$tenantId, (int) $p['branch_id'], $id, $ctx['user']['name'], 'report', $ref, "Compte-rendu médical ({$icd})", $note, CliniqoDatabase::TODAY, $ctx['user']['name']]);
            }
        }

        CliniqoAuthService::audit(
            $tenantId, (int) $p['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'SEAL_CLINICAL_DME', 'patient', $p['code'],
            "Signature observation CIM-11 ({$icd})",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, [], $t0);
    }

    // =========================================================================
    // LABORATORY MODULE (Guarded by Clinic Feature Flag + Plan + RBAC)
    // =========================================================================
    public function listLabs(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'labs.read');
        $state = self::buildWorkspaceState($ctx);
        return response()->json(['success' => true, 'data' => $state['labs']]);
    }

    public function createLab(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'labs.write');
        $tenantId = (int) $ctx['tenant_id'];
        CliniqoAuthService::requireActiveSubscription($tenantId, 'lab');
        $pdo = CliniqoDatabase::connection();

        $stT = $pdo->prepare('SELECT enable_laboratory FROM tenants WHERE id = ? LIMIT 1');
        $stT->execute([$tenantId]);
        if ((int) $stT->fetchColumn() !== 1) {
            return response()->json(['success' => false, 'message' => 'Le module Laboratoire est désactivé pour ce centre médical.'], 403);
        }

        $patientId = (int) $request->input('patient_id', 0);
        $testName = trim((string) $request->input('test_name', ''));
        if ($patientId <= 0 || $testName === '') {
            return response()->json(['success' => false, 'message' => 'Patient et intitulé du bilan requis.'], 422);
        }

        $stP = $pdo->prepare('SELECT * FROM patients WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stP->execute([$patientId, $tenantId]);
        $pat = $stP->fetch();
        if (!$pat) {
            return response()->json(['success' => false, 'message' => 'Patient introuvable.'], 404);
        }

        $branchId = CliniqoAuthService::defaultWriteBranchId($ctx, (int) $pat['branch_id']);
        $code = 'LAB-' . random_int(1000, 9999);
        $category = trim((string) $request->input('category', 'Biochimie'));
        $priority = trim((string) $request->input('priority', 'Routine'));
        $status = $priority === 'URGENT' ? 'flagged' : 'pending';

        $pdo->prepare('
            INSERT INTO labs (tenant_id, branch_id, code, patient_id, patient_name, test_name, category, value, reference, priority, status, ordered_by, ordered_date, timestamp)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ')->execute([
            $tenantId, $branchId, $code, $patientId, $pat['first'] . ' ' . $pat['last'],
            $testName, $category, 'En analyse...', 'Valeurs usuelles', $priority, $status,
            $ctx['user']['name'], CliniqoDatabase::TODAY, date('H:i'),
        ]);

        if ($priority === 'URGENT') {
            CliniqoAuthService::pushNotification(
                $tenantId, $branchId, 'lab', 'urgent',
                "Bilan URGENT prescrit ({$code})",
                "Analyse prioritaire enregistrée pour le dossier {$pat['code']} au laboratoire.",
                'labs'
            );
        }

        CliniqoAuthService::audit(
            $tenantId, $branchId, (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'CREATE_LAB_ORDER', 'lab', $code, "{$testName} ({$priority})", $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['lab_code' => $code], $t0, 201);
    }

    public function validateLab(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'labs.validate');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM labs WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $lab = $st->fetch();
        if (!$lab) {
            return response()->json(['success' => false, 'message' => 'Analyse introuvable.'], 404);
        }

        $val = trim((string) $request->input('value', ''));
        if ($val === '' || $val === 'En analyse...') {
            $val = 'Conforme (Validé)';
        }

        $pdo->prepare("UPDATE labs SET value=?, status='validated', validated_by=?, completed_date=? WHERE id=? AND tenant_id=?")
            ->execute([$val, $ctx['user']['name'], CliniqoDatabase::TODAY, $id, $tenantId]);

        CliniqoAuthService::pushNotification(
            $tenantId, (int) $lab['branch_id'], 'lab', 'info',
            "Résultat Labo disponible ({$lab['code']})",
            "Le bilan biologique {$lab['code']} a été validé et signé.",
            'labs'
        );

        CliniqoAuthService::audit(
            $tenantId, (int) $lab['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'VALIDATE_LAB_RESULT', 'lab', $lab['code'], "Validation résultat biologique ({$lab['code']})", $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, [], $t0);
    }

    public function deleteLab(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'labs.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT code, branch_id FROM labs WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $lab = $st->fetch();
        if ($lab) {
            $pdo->prepare('DELETE FROM labs WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
            CliniqoAuthService::audit(
                $tenantId, (int) $lab['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
                'DELETE_LAB_ORDER', 'lab', $lab['code'], 'Annulation demande labo', $request->ip() ?? '127.0.0.1'
            );
        }

        return $this->respond($ctx, [], $t0);
    }

    // =========================================================================
    // INVOICES, PARTIAL PAYMENTS, REFUNDS & CNSS
    // =========================================================================
    public function listInvoices(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'finance.read');
        $state = self::buildWorkspaceState($ctx);
        return response()->json(['success' => true, 'data' => $state['invoices']]);
    }

    public function createInvoice(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'finance.write');
        $tenantId = (int) $ctx['tenant_id'];
        CliniqoAuthService::requireActiveSubscription($tenantId);
        $pdo = CliniqoDatabase::connection();

        $patientId = (int) $request->input('patient_id', 0);
        $service = trim((string) $request->input('service', ''));
        $total = round(max(1.0, (float) $request->input('total', 0)), 2);
        $paidNow = round(max(0.0, min($total, (float) $request->input('paid', 0))), 2);
        $method = trim((string) $request->input('method', 'TPE CMI'));

        if ($patientId <= 0 || $service === '') {
            return response()->json(['success' => false, 'message' => 'Patient et acte médical requis.'], 422);
        }

        $stP = $pdo->prepare('SELECT * FROM patients WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stP->execute([$patientId, $tenantId]);
        $pat = $stP->fetch();
        if (!$pat) {
            return response()->json(['success' => false, 'message' => 'Patient introuvable.'], 404);
        }

        $stTen = $pdo->prepare('SELECT invoice_prefix, receipt_prefix FROM tenants WHERE id = ? LIMIT 1');
        $stTen->execute([$tenantId]);
        $tenPrefs = $stTen->fetch() ?: ['invoice_prefix' => 'FAC', 'receipt_prefix' => 'REC'];

        $branchId = CliniqoAuthService::defaultWriteBranchId($ctx, $request->filled('branch_id') ? (int) $request->input('branch_id') : (int) $pat['branch_id']);
        $invNum = sprintf('%s-2026-%d', $tenPrefs['invoice_prefix'], random_int(3200, 9999));
        $status = $paidNow >= $total ? 'paid' : ($paidNow > 0 ? 'partial' : 'unpaid');
        $cnssOrg = str_contains((string) $pat['coverage'], 'CNOPS') ? 'CNOPS' : (str_contains((string) $pat['coverage'], 'AXA') ? 'AXA Assurance' : 'CNSS AMO');
        $cnssEst = round($total * 0.8, 2);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('
                INSERT INTO invoices (tenant_id, branch_id, num, patient_id, patient_name, doctor_name, specialty, service, issued_date, total, paid, refunded, method, status, cnss_org, cnss_est, cnss_status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?)
            ')->execute([
                $tenantId, $branchId, $invNum, $patientId, $pat['first'] . ' ' . $pat['last'],
                trim((string) $request->input('doctor_name', $ctx['user']['name'])),
                trim((string) $request->input('specialty', $pat['department'])),
                $service, CliniqoDatabase::TODAY, $total, $paidNow, $method, $status, $cnssOrg, $cnssEst, 'Dossier prêt',
            ]);
            $invId = (int) $pdo->lastInsertId();

            if ($paidNow > 0) {
                $stCs = $pdo->prepare("SELECT id FROM cash_sessions WHERE tenant_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
                $stCs->execute([$tenantId]);
                $csId = $stCs->fetchColumn() ?: null;
                $recNum = sprintf('%s-2026-%d', $tenPrefs['receipt_prefix'], random_int(5100, 9999));

                $pdo->prepare('
                    INSERT INTO payments (tenant_id, branch_id, invoice_id, cash_session_id, receipt_num, amount, payment_type, method, paid_date, paid_time, status, recorded_by, notes)
                    VALUES (?,?,?,?,?,?,\'payment\',?,?,?,\'valid\',?,?)
                ')->execute([
                    $tenantId, $branchId, $invId, $csId, $recNum, $paidNow, $method,
                    CliniqoDatabase::TODAY, date('H:i'), $ctx['user']['name'], 'Règlement initial à l’émission',
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($paidNow < $total) {
            $rem = round($total - $paidNow, 2);
            CliniqoAuthService::pushNotification(
                $tenantId, $branchId, 'payment', 'info',
                "Solde ouvert sur facture {$invNum}",
                "Facture {$invNum} ({$pat['code']}) : reste à payer de {$rem} MAD.",
                'finance', null, $patientId
            );
        }

        CliniqoAuthService::audit(
            $tenantId, $branchId, (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'CREATE_INVOICE', 'invoice', $invNum,
            "Total {$total} MAD • Encaissé {$paidNow} MAD ({$method})",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['invoice_num' => $invNum], $t0, 201);
    }

    public function recordInvoicePayment(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'finance.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $inv = $st->fetch();
        if (!$inv) {
            return response()->json(['success' => false, 'message' => 'Facture introuvable.'], 404);
        }

        $netPaid = max(0.0, (float) $inv['paid'] - (float) $inv['refunded']);
        $remaining = round(max(0.0, (float) $inv['total'] - $netPaid), 2);
        if ($remaining <= 0.001) {
            return response()->json(['success' => false, 'message' => 'Cette facture est déjà intégralement soldée.'], 409);
        }

        $amount = round((float) $request->input('amount', 0), 2);
        if ($amount <= 0 || $amount > $remaining + 0.01) {
            return response()->json(['success' => false, 'message' => "Montant invalide (maximum autorisé : {$remaining} MAD)."], 422);
        }
        $amount = min($amount, $remaining);
        $method = trim((string) $request->input('method', 'TPE CMI'));

        $stTen = $pdo->prepare('SELECT receipt_prefix FROM tenants WHERE id = ? LIMIT 1');
        $stTen->execute([$tenantId]);
        $recPref = (string) ($stTen->fetchColumn() ?: 'REC');
        $recNum = sprintf('%s-2026-%d', $recPref, random_int(5200, 9999));

        $stCs = $pdo->prepare("SELECT id FROM cash_sessions WHERE tenant_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
        $stCs->execute([$tenantId]);
        $csId = $stCs->fetchColumn() ?: null;

        $newPaid = round((float) $inv['paid'] + $amount, 2);
        $newNet = max(0.0, $newPaid - (float) $inv['refunded']);
        $newStatus = ($newNet + 0.01 >= (float) $inv['total']) ? 'paid' : 'partial';

        $pdo->beginTransaction();
        try {
            $pdo->prepare('
                INSERT INTO payments (tenant_id, branch_id, invoice_id, cash_session_id, receipt_num, amount, payment_type, method, paid_date, paid_time, status, recorded_by, notes)
                VALUES (?,?,?,?,?,?,\'payment\',?,?,?,\'valid\',?,?)
            ')->execute([
                $tenantId, (int) $inv['branch_id'], $id, $csId, $recNum, $amount, $method,
                CliniqoDatabase::TODAY, date('H:i'), $ctx['user']['name'],
                trim((string) $request->input('notes', 'Règlement sur facture ' . $inv['num'])),
            ]);

            $pdo->prepare('UPDATE invoices SET paid = ?, method = ?, status = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$newPaid, $method, $newStatus, $id, $tenantId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        CliniqoAuthService::pushNotification(
            $tenantId, (int) $inv['branch_id'], 'payment', 'info',
            "Paiement enregistré ({$recNum})",
            "Règlement de {$amount} MAD ({$method}) encaissé sur {$inv['num']}.",
            'finance', null, (int) $inv['patient_id']
        );

        CliniqoAuthService::audit(
            $tenantId, (int) $inv['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'RECORD_PAYMENT', 'payment', $recNum,
            "Encaissement {$amount} MAD ({$method}) sur {$inv['num']}",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['receipt_num' => $recNum], $t0);
    }

    public function refundInvoicePayment(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'finance.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $inv = $st->fetch();
        if (!$inv) {
            return response()->json(['success' => false, 'message' => 'Facture introuvable.'], 404);
        }

        $netPaid = max(0.0, (float) $inv['paid'] - (float) $inv['refunded']);
        $amount = round((float) $request->input('amount', 0), 2);
        if ($amount <= 0 || $amount > $netPaid + 0.01) {
            return response()->json(['success' => false, 'message' => "Montant d'avoir invalide (max remboursable : {$netPaid} MAD)."], 422);
        }

        $recNum = 'AV-' . random_int(1000, 9999);
        $method = trim((string) $request->input('method', $inv['method'] ?: 'Espèces'));
        $reason = trim((string) $request->input('reason', 'Avoir / Remboursement patient'));

        $newRefunded = round((float) $inv['refunded'] + $amount, 2);
        $afterNet = max(0.0, (float) $inv['paid'] - $newRefunded);
        $newStatus = $afterNet <= 0.01 ? 'refunded' : ($afterNet < (float) $inv['total'] ? 'partial' : 'paid');

        $pdo->beginTransaction();
        try {
            $pdo->prepare('
                INSERT INTO payments (tenant_id, branch_id, invoice_id, receipt_num, amount, payment_type, method, paid_date, paid_time, status, recorded_by, notes)
                VALUES (?,?,?,?,\'refund\',?,?,?,\'valid\',?,?)
            ');
            $stIns = $pdo->prepare('
                INSERT INTO payments (tenant_id, branch_id, invoice_id, receipt_num, amount, payment_type, method, paid_date, paid_time, status, recorded_by, notes)
                VALUES (?,?,?,?,?,\'refund\',?,?,?,\'valid\',?,?)
            ');
            $stIns->execute([
                $tenantId, (int) $inv['branch_id'], $id, $recNum, $amount, $method,
                CliniqoDatabase::TODAY, date('H:i'), $ctx['user']['name'], $reason,
            ]);

            $pdo->prepare('UPDATE invoices SET refunded = ?, status = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$newRefunded, $newStatus, $id, $tenantId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        CliniqoAuthService::audit(
            $tenantId, (int) $inv['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'REFUND_PAYMENT', 'payment', $recNum,
            "Remboursement / Avoir {$amount} MAD sur {$inv['num']} ({$reason})",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['refund_ref' => $recNum], $t0);
    }

    public function advanceCnssClaim(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'finance.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $inv = $st->fetch();
        if (!$inv) {
            return response()->json(['success' => false, 'message' => 'Facture introuvable.'], 404);
        }

        $flow = [
            'En attente règlement' => 'Dossier prêt',
            'Cachet manquant' => 'Dossier prêt',
            'Dossier prêt' => 'Transmis CNSS',
            'Transmis CNSS' => 'Remboursé',
            'Remboursé' => 'Dossier prêt',
        ];
        $next = $flow[$inv['cnss_status']] ?? 'Transmis CNSS';

        $pdo->prepare('UPDATE invoices SET cnss_status = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$next, $id, $tenantId]);

        CliniqoAuthService::audit(
            $tenantId, (int) $inv['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'ADVANCE_CNSS_CLAIM', 'invoice', $inv['num'], "Statut CNSS -> {$next}", $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, [], $t0);
    }

    // =========================================================================
    // SECTION 3H — EXPENSES & OPTIONAL MEDICAL INVENTORY (STOCK)
    // =========================================================================
    public function listExpenses(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'expenses.read');
        $state = self::buildWorkspaceState($ctx);
        return response()->json(['success' => true, 'data' => $state['expenses']]);
    }

    public function createExpense(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'expenses.write');
        $tenantId = (int) $ctx['tenant_id'];
        CliniqoAuthService::requireActiveSubscription($tenantId);
        $pdo = CliniqoDatabase::connection();

        $category = trim((string) $request->input('category', 'Consommables Médicaux'));
        $amount = round((float) $request->input('amount', 0), 2);
        $date = trim((string) $request->input('expense_date', CliniqoDatabase::TODAY));
        $desc = trim((string) $request->input('description', ''));
        $supplier = trim((string) $request->input('supplier', ''));
        $docRef = trim((string) $request->input('supporting_doc_ref', ''));
        $method = trim((string) $request->input('payment_method', 'Espèces'));

        if ($amount <= 0 || $desc === '') {
            return response()->json(['success' => false, 'message' => 'Description et montant strictement positif requis.'], 422);
        }

        $branchId = CliniqoAuthService::defaultWriteBranchId($ctx, $request->filled('branch_id') ? (int) $request->input('branch_id') : null);
        $ref = sprintf('DEP-%d-%d', $tenantId, random_int(200, 9999));

        $csId = null;
        if ($method === 'Espèces') {
            $stCs = $pdo->prepare("SELECT id FROM cash_sessions WHERE tenant_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
            $stCs->execute([$tenantId]);
            $csId = $stCs->fetchColumn() ?: null;
        }

        $pdo->prepare('
            INSERT INTO expenses (tenant_id, branch_id, cash_session_id, reference, category, amount, expense_date, description, supplier, supporting_doc_ref, payment_method, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ')->execute([$tenantId, $branchId, $csId, $ref, $category, $amount, $date, $desc, $supplier, $docRef, $method, $ctx['user']['name']]);

        CliniqoAuthService::audit(
            $tenantId, $branchId, (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'CREATE_EXPENSE', 'expense', $ref,
            "{$category} : {$amount} MAD ({$supplier})",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['expense_ref' => $ref], $t0, 201);
    }

    public function deleteExpense(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'expenses.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT reference, branch_id FROM expenses WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $exp = $st->fetch();
        if ($exp) {
            $pdo->prepare('DELETE FROM expenses WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
            CliniqoAuthService::audit(
                $tenantId, (int) $exp['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
                'DELETE_EXPENSE', 'expense', $exp['reference'], 'Suppression dépense', $request->ip() ?? '127.0.0.1'
            );
        }

        return $this->respond($ctx, [], $t0);
    }

    public function listInventory(Request $request): JsonResponse
    {
        $ctx = CliniqoAuthService::requirePermission($request, 'stock.read');
        $state = self::buildWorkspaceState($ctx);
        return response()->json(['success' => true, 'data' => $state['inventory']]);
    }

    public function createInventoryItem(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'stock.write');
        $tenantId = (int) $ctx['tenant_id'];
        CliniqoAuthService::requireActiveSubscription($tenantId, 'inventory');
        $pdo = CliniqoDatabase::connection();

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Désignation du produit requise.'], 422);
        }

        $branchId = CliniqoAuthService::defaultWriteBranchId($ctx, $request->filled('branch_id') ? (int) $request->input('branch_id') : null);
        $sku = strtoupper(trim((string) $request->input('sku', 'STK-' . random_int(200, 999))));
        $cat = trim((string) $request->input('category', 'Consommables'));
        $unit = trim((string) $request->input('unit', 'Boîte'));
        $qty = max(0, (int) $request->input('quantity', 0));
        $minT = max(1, (int) $request->input('min_threshold', 10));
        $cost = max(0.0, (float) $request->input('unit_cost_mad', 0));
        $supplier = trim((string) $request->input('supplier', ''));

        $pdo->prepare('
            INSERT INTO inventory_items (tenant_id, branch_id, sku, name, category, unit, quantity, min_threshold, unit_cost_mad, supplier, last_movement_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ')->execute([$tenantId, $branchId, $sku, $name, $cat, $unit, $qty, $minT, $cost, $supplier, CliniqoDatabase::TODAY]);
        $itemId = (int) $pdo->lastInsertId();

        if ($qty > 0) {
            $pdo->prepare('INSERT INTO inventory_movements (tenant_id, branch_id, item_id, movement_type, quantity, reason, supplier_or_ref, created_by, movement_date) VALUES (?,?,?,\'in\',?,?,?,?,?)')
                ->execute([$tenantId, $branchId, $itemId, $qty, 'Stock initial à la création', $supplier, $ctx['user']['name'], CliniqoDatabase::TODAY]);
        }

        if ($qty <= $minT) {
            CliniqoAuthService::pushNotification(
                $tenantId, $branchId, 'stock', 'warning',
                "Seuil critique atteint : {$name}",
                "Quantité actuelle ({$qty}) inférieure ou égale au seuil minimum ({$minT}).",
                'finance', 'admin_clinique'
            );
        }

        CliniqoAuthService::audit(
            $tenantId, $branchId, (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'CREATE_STOCK_ITEM', 'inventory', $sku, "{$name} (Qté {$qty}, Seuil {$minT})", $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['sku' => $sku], $t0, 201);
    }

    public function recordStockMovement(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'stock.write');
        $tenantId = (int) $ctx['tenant_id'];
        CliniqoAuthService::requireActiveSubscription($tenantId, 'inventory');
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM inventory_items WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $item = $st->fetch();
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Article introuvable.'], 404);
        }

        $type = in_array($request->input('movement_type'), ['in', 'out'], true) ? $request->input('movement_type') : 'out';
        $qty = max(1, (int) $request->input('quantity', 1));
        $reason = trim((string) $request->input('reason', $type === 'in' ? 'Réapprovisionnement fournisseur' : 'Sortie soins'));
        $ref = trim((string) $request->input('supplier_or_ref', $item['supplier']));

        $currentQty = (int) $item['quantity'];
        if ($type === 'out' && $qty > $currentQty) {
            return response()->json(['success' => false, 'message' => "Stock insuffisant ({$currentQty} disponible)."], 422);
        }
        $newQty = $type === 'in' ? ($currentQty + $qty) : ($currentQty - $qty);

        $pdo->prepare('UPDATE inventory_items SET quantity = ?, last_movement_date = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$newQty, CliniqoDatabase::TODAY, $id, $tenantId]);

        $pdo->prepare('INSERT INTO inventory_movements (tenant_id, branch_id, item_id, movement_type, quantity, reason, supplier_or_ref, created_by, movement_date) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$tenantId, (int) $item['branch_id'], $id, $type, $qty, $reason, $ref, $ctx['user']['name'], CliniqoDatabase::TODAY]);

        if ($newQty <= (int) $item['min_threshold']) {
            CliniqoAuthService::pushNotification(
                $tenantId, (int) $item['branch_id'], 'stock', 'warning',
                "Alerte Rupture / Stock Bas ({$item['sku']})",
                "{$item['name']} : {$newQty} restants (seuil min : {$item['min_threshold']}).",
                'finance'
            );
        }

        CliniqoAuthService::audit(
            $tenantId, (int) $item['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'STOCK_MOVEMENT', 'inventory', $item['sku'],
            strtoupper($type) . " {$qty} -> Nouveau stock {$newQty}",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['new_quantity' => $newQty], $t0);
    }

    // =========================================================================
    // SECTION 3G — DOCUMENTS & PDF GENERATION (Quotes, Certificates, Prescriptions)
    // =========================================================================
    public function createDocument(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'documents.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $docType = trim((string) $request->input('doc_type', 'quote'));
        if (in_array($docType, ['prescription', 'certificate', 'report'], true) && !in_array('patients.dme', $ctx['permissions'], true)) {
            return response()->json(['success' => false, 'message' => 'Seul un médecin habilité peut émettre une ordonnance ou un certificat médical.'], 403);
        }

        $patientId = $request->filled('patient_id') ? (int) $request->input('patient_id') : null;
        $title = trim((string) $request->input('title', ''));
        $body = trim((string) $request->input('body_text', ''));
        $amount = round(max(0.0, (float) $request->input('amount_mad', 0)), 2);
        $shared = (int) ((bool) $request->input('shared_with_patient', true));

        if ($title === '' || $body === '') {
            return response()->json(['success' => false, 'message' => 'Titre et contenu du document requis.'], 422);
        }

        $stTen = $pdo->prepare('SELECT quote_prefix, prescription_prefix, certificate_prefix FROM tenants WHERE id = ? LIMIT 1');
        $stTen->execute([$tenantId]);
        $tp = $stTen->fetch() ?: ['quote_prefix' => 'DEV', 'prescription_prefix' => 'ORD', 'certificate_prefix' => 'CERT'];

        $prefMap = [
            'quote' => $tp['quote_prefix'],
            'prescription' => $tp['prescription_prefix'],
            'certificate' => $tp['certificate_prefix'],
            'report' => 'CR',
            'appointment_confirmation' => 'CONF',
        ];
        $prefix = $prefMap[$docType] ?? 'DOC';
        $ref = sprintf('%s-2026-%d', $prefix, random_int(1000, 9999));
        $branchId = CliniqoAuthService::defaultWriteBranchId($ctx, null);

        $pdo->prepare('
            INSERT INTO documents (tenant_id, branch_id, patient_id, doctor_name, doc_type, reference, title, body_text, amount_mad, issued_date, shared_with_patient, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ')->execute([
            $tenantId, $branchId, $patientId, trim((string) $request->input('doctor_name', $ctx['user']['name'])),
            $docType, $ref, $title, $body, $amount, CliniqoDatabase::TODAY, $shared, $ctx['user']['name'],
        ]);

        CliniqoAuthService::audit(
            $tenantId, $branchId, (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            'GENERATE_DOCUMENT', 'document', $ref,
            "{$docType} ({$title}) • Partagé portail: {$shared}",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['reference' => $ref], $t0, 201);
    }

    public function toggleDocumentShare(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'documents.write');
        $tenantId = (int) $ctx['tenant_id'];
        $pdo = CliniqoDatabase::connection();

        $st = $pdo->prepare('SELECT * FROM documents WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tenantId]);
        $doc = $st->fetch();
        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'Document introuvable.'], 404);
        }

        $next = (int) $doc['shared_with_patient'] === 1 ? 0 : 1;
        $pdo->prepare('UPDATE documents SET shared_with_patient = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$next, $id, $tenantId]);

        CliniqoAuthService::audit(
            $tenantId, (int) $doc['branch_id'], (int) $ctx['user']['id'], $ctx['user']['name'], $ctx['role'],
            $next === 1 ? 'SHARE_DOC_PORTAL' : 'REVOKE_DOC_PORTAL', 'document', $doc['reference'],
            $next === 1 ? 'Document rendu accessible au patient sur son portail' : 'Accès portail patient révoqué',
            $request->ip() ?? '127.0.0.1'
        );

        return $this->respond($ctx, ['shared_with_patient' => (bool) $next], $t0);
    }

    // =========================================================================
    // SECTION 3D — NOTIFICATIONS & PREFERENCES
    // =========================================================================
    public function markNotificationRead(Request $request, int $id): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'dashboard.view');
        $pdo = CliniqoDatabase::connection();
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND tenant_id = ?')
            ->execute([$id, (int) $ctx['tenant_id']]);
        return $this->respond($ctx, [], $t0);
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'dashboard.view');
        $pdo = CliniqoDatabase::connection();
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE tenant_id = ?')
            ->execute([(int) $ctx['tenant_id']]);
        return $this->respond($ctx, [], $t0);
    }

    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $ctx = CliniqoAuthService::requirePermission($request, 'dashboard.view');
        $pdo = CliniqoDatabase::connection();
        $uid = (int) $ctx['user']['id'];

        $pdo->prepare('
            INSERT INTO notification_preferences (user_id, notify_appointments, notify_schedule, notify_payments, notify_labs, notify_stock, notify_subscription)
            VALUES (?,?,?,?,?,?,?)
            ON CONFLICT(user_id) DO UPDATE SET
                notify_appointments=excluded.notify_appointments,
                notify_schedule=excluded.notify_schedule,
                notify_payments=excluded.notify_payments,
                notify_labs=excluded.notify_labs,
                notify_stock=excluded.notify_stock,
                notify_subscription=excluded.notify_subscription
        ')->execute([
            $uid,
            (int) ((bool) $request->input('notify_appointments', true)),
            (int) ((bool) $request->input('notify_schedule', true)),
            (int) ((bool) $request->input('notify_payments', true)),
            (int) ((bool) $request->input('notify_labs', true)),
            (int) ((bool) $request->input('notify_stock', true)),
            (int) ((bool) $request->input('notify_subscription', true)),
        ]);

        return $this->respond($ctx, [], $t0);
    }
}
