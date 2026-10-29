<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CliniqoAuthService;
use App\Services\CliniqoDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PatientPortalController — Section 3F Secure Patient Portal
 * Enforces strict server-side record ownership: an authenticated patient can ONLY
 * access their own appointments, invoices/payments, notifications, and documents
 * explicitly marked `shared_with_patient = 1`. Zero access to other patients or relatives.
 */
class PatientPortalController
{
    public function publicClinics(): JsonResponse
    {
        $pdo = CliniqoDatabase::connection();
        $clinics = $pdo->query('SELECT id, name, short_name, city, phone, address FROM tenants WHERE allow_portal_booking = 1 ORDER BY id ASC')->fetchAll();
        return response()->json([
            'success' => true,
            'clinics' => $clinics,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $pdo = CliniqoDatabase::connection();
        $raw = json_decode((string) $request->getContent(), true);
        if (!is_array($raw)) {
            $raw = $request->json()->all() ?: $request->all();
        }

        $tenantId = max(1, (int) ($raw['tenant_id'] ?? $request->input('tenant_id', 1)));
        $cin = strtoupper(trim((string) ($raw['cin'] ?? $request->input('cin', ''))));
        $code = strtoupper(trim((string) ($raw['code'] ?? $request->input('code', ''))));
        $pin = trim((string) ($raw['pin'] ?? $request->input('pin', '')));

        if ($cin === '' || $pin === '') {
            return response()->json(['success' => false, 'message' => 'Numéro CIN et code PIN personnel requis.'], 422);
        }

        $sql = 'SELECT * FROM patients WHERE tenant_id = ? AND UPPER(cin) = ? AND portal_enabled = 1';
        $params = [$tenantId, $cin];
        if ($code !== '') {
            $sql .= ' AND UPPER(code) = ?';
            $params[] = ltrim($code, '#');
        }
        $sql .= ' LIMIT 1';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $patient = $st->fetch();

        if (!$patient || !password_verify($pin, (string) $patient['portal_pin_hash'])) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_PORTAL_CREDENTIALS',
                'message' => 'Identifiants Portail Patient incorrects (vérifiez votre CIN, code dossier et PIN).',
            ], 401);
        }

        $token = CliniqoAuthService::issuePatientToken((int) $patient['id'], (int) $patient['tenant_id']);

        CliniqoAuthService::audit(
            (int) $patient['tenant_id'], (int) $patient['branch_id'], null,
            "{$patient['first']} {$patient['last']} ({$patient['code']})", 'patient_portal',
            'PORTAL_PATIENT_LOGIN', 'patient', $patient['code'],
            'Connexion sécurisée au Portail Patient personnel',
            $request->ip() ?? '127.0.0.1'
        );

        $request->headers->set('Authorization', 'Bearer ' . $token);
        return $this->me($request, $token);
    }

    public function me(Request $request, ?string $newlyIssuedToken = null): JsonResponse
    {
        $pCtx = CliniqoAuthService::resolvePatientPortalContext($request);
        $pdo = CliniqoDatabase::connection();
        $pid = (int) $pCtx['patient_id'];
        $tid = (int) $pCtx['tenant_id'];
        $p = $pCtx['patient'];
        $today = CliniqoDatabase::TODAY;

        $stTenant = $pdo->prepare('SELECT id, name, short_name, city, address, phone, email, ice, inpe, logo_url, currency, doc_header_note, doc_footer_note FROM tenants WHERE id = ? LIMIT 1');
        $stTenant->execute([$tid]);
        $clinic = $stTenant->fetch();

        // Available doctors & branches in this clinic for booking
        $stDocs = $pdo->prepare('SELECT id, branch_id, name, specialty, room, fee_mad, working_days, work_start, work_end FROM doctors WHERE tenant_id = ? AND is_active = 1 ORDER BY name ASC');
        $stDocs->execute([$tid]);
        $doctors = $stDocs->fetchAll();

        $stBranches = $pdo->prepare('SELECT id, name, code, city, address, phone FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY is_main DESC');
        $stBranches->execute([$tid]);
        $branches = $stBranches->fetchAll();

        // STRICT ISOLATION: ONLY this authenticated patient's appointments (`patient_id = ? AND tenant_id = ?`)
        $stApts = $pdo->prepare('SELECT id, branch_id, doctor, specialty, service_name, room, date, time, status, fee_mad, reason, cancellation_reason FROM appointments WHERE patient_id = ? AND tenant_id = ? ORDER BY date DESC, time DESC');
        $stApts->execute([$pid, $tid]);
        $allApts = $stApts->fetchAll();

        $upcoming = [];
        $past = [];
        foreach ($allApts as $ap) {
            if ($ap['date'] >= $today && !in_array($ap['status'], ['completed', 'cancelled', 'no_show'], true)) {
                $upcoming[] = $ap;
            } else {
                $past[] = $ap;
            }
        }

        // STRICT ISOLATION: ONLY documents explicitly shared with this patient (`patient_id = ? AND shared_with_patient = 1`)!
        $stDocsShared = $pdo->prepare('
            SELECT id, doc_type, reference, doctor_name, title, body_text, amount_mad, issued_date
            FROM documents
            WHERE patient_id = ? AND tenant_id = ? AND shared_with_patient = 1
            ORDER BY issued_date DESC, id DESC
        ');
        $stDocsShared->execute([$pid, $tid]);
        $sharedDocuments = $stDocsShared->fetchAll();

        // STRICT ISOLATION: ONLY this patient's own invoices & payment history
        $stInvs = $pdo->prepare("SELECT id, num, service, doctor_name, issued_date, total, paid, refunded, status, cnss_org, cnss_status FROM invoices WHERE patient_id = ? AND tenant_id = ? AND status != 'cancelled' ORDER BY issued_date DESC, id DESC");
        $stInvs->execute([$pid, $tid]);
        $rawInvs = $stInvs->fetchAll();

        $stPay = $pdo->prepare("
            SELECT py.id, py.invoice_id, py.receipt_num, py.amount, py.payment_type, py.method, py.paid_date, py.paid_time, i.num AS invoice_num
            FROM payments py
            INNER JOIN invoices i ON i.id = py.invoice_id
            WHERE i.patient_id = ? AND py.tenant_id = ? AND py.status = 'valid'
            ORDER BY py.paid_date DESC, py.id DESC
        ");
        $stPay->execute([$pid, $tid]);
        $payments = $stPay->fetchAll();

        $totalInvoiced = 0.0;
        $totalPaidNet = 0.0;
        $totalOutstanding = 0.0;
        $invoices = [];
        foreach ($rawInvs as $iv) {
            $tot = (float) $iv['total'];
            $netP = max(0.0, (float) $iv['paid'] - (float) $iv['refunded']);
            $rem = max(0.0, $tot - $netP);
            $totalInvoiced += $tot;
            $totalPaidNet += $netP;
            $totalOutstanding += $rem;
            $iv['net_paid'] = $netP;
            $iv['remaining'] = $rem;
            $invoices[] = $iv;
        }

        // STRICT ISOLATION: ONLY notifications addressed to this specific `patient_id`!
        $stNotif = $pdo->prepare('SELECT id, category, severity, title, message, is_read, created_at FROM notifications WHERE patient_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 20');
        $stNotif->execute([$pid, $tid]);
        $notifications = $stNotif->fetchAll();

        $resp = [
            'success' => true,
            'clinic' => $clinic,
            'branches' => $branches,
            'doctors' => $doctors,
            'patient' => [
                'id' => $pid,
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
                'avatar' => $p['avatar'],
            ],
            'appointments' => [
                'upcoming' => $upcoming,
                'past' => $past,
            ],
            'documents' => $sharedDocuments,
            'billing' => [
                'total_invoiced' => round($totalInvoiced, 2),
                'total_paid' => round($totalPaidNet, 2),
                'outstanding_balance' => round($totalOutstanding, 2),
                'invoices' => $invoices,
                'payments' => $payments,
            ],
            'notifications' => $notifications,
        ];

        if ($newlyIssuedToken !== null) {
            $resp['token'] = $newlyIssuedToken;
        }

        return response()->json($resp);
    }

    public function requestAppointment(Request $request): JsonResponse
    {
        $pCtx = CliniqoAuthService::resolvePatientPortalContext($request);
        $pdo = CliniqoDatabase::connection();
        $pid = (int) $pCtx['patient_id'];
        $tid = (int) $pCtx['tenant_id'];
        $p = $pCtx['patient'];

        // Ignore any spoofed patient_id in payload; strictly enforce authenticated patient!
        if ($request->filled('patient_id') && (int) $request->input('patient_id') !== $pid) {
            return response()->json([
                'success' => false,
                'error' => 'PATIENT_ISOLATION_VIOLATION',
                'message' => 'Action refusée : vous ne pouvez gérer que votre propre dossier personnel.',
            ], 403);
        }

        $doctorId = (int) $request->input('doctor_id', 0);
        $date = trim((string) $request->input('date', ''));
        $time = trim((string) $request->input('time', '10:00'));
        $reason = trim((string) $request->input('reason', 'Consultation demandée via Portail Patient'));

        if ($doctorId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < CliniqoDatabase::TODAY) {
            return response()->json(['success' => false, 'message' => 'Veuillez sélectionner un praticien et une date à venir valide.'], 422);
        }

        $stDoc = $pdo->prepare('SELECT * FROM doctors WHERE id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1');
        $stDoc->execute([$doctorId, $tid]);
        $doc = $stDoc->fetch();
        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'Praticien non disponible.'], 404);
        }

        $stHol = $pdo->prepare('SELECT label FROM clinic_holidays WHERE tenant_id = ? AND start_date <= ? AND end_date >= ? AND (doctor_id IS NULL OR doctor_id = ?) LIMIT 1');
        $stHol->execute([$tid, $date, $date, $doctorId]);
        if ($hol = $stHol->fetch()) {
            return response()->json(['success' => false, 'message' => "Fermeture / congé ce jour : {$hol['label']}."], 409);
        }

        $stConf = $pdo->prepare("SELECT id FROM appointments WHERE tenant_id = ? AND date = ? AND time = ? AND status NOT IN ('cancelled','no_show') AND LOWER(doctor) = LOWER(?) LIMIT 1");
        $stConf->execute([$tid, $date, $time, $doc['name']]);
        if ($stConf->fetch()) {
            return response()->json(['success' => false, 'message' => 'Ce créneau horaire vient d’être réservé. Choisissez un autre horaire.'], 409);
        }

        $pdo->prepare('
            INSERT INTO appointments (tenant_id, branch_id, patient_id, doctor_id, doctor, specialty, service_name, room, date, time, duration_min, status, fee_mad, reason, source)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,\'confirmed\',?,?, \'portal\')
        ')->execute([
            $tid, (int) $doc['branch_id'], $pid, $doctorId, $doc['name'], $doc['specialty'],
            'Consultation ' . $doc['specialty'], $doc['room'], $date, $time, (int) $doc['duration_min'],
            (float) $doc['fee_mad'], $reason,
        ]);
        $aptId = (int) $pdo->lastInsertId();

        $confRef = sprintf('CONF-%d-%d', $tid, 2000 + $aptId);
        $pdo->prepare('INSERT INTO documents (tenant_id, branch_id, patient_id, doctor_name, doc_type, reference, title, body_text, amount_mad, issued_date, shared_with_patient, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,1,?)')
            ->execute([
                $tid, (int) $doc['branch_id'], $pid, $doc['name'], 'appointment_confirmation', $confRef,
                "Confirmation de Rendez-vous — {$date} à {$time}",
                "Rendez-vous confirmé via le Portail Patient avec {$doc['name']} ({$doc['specialty']}) en {$doc['room']} le {$date} à {$time}.",
                (float) $doc['fee_mad'], CliniqoDatabase::TODAY, 'Portail Patient',
            ]);

        CliniqoAuthService::pushNotification(
            $tid, (int) $doc['branch_id'], 'appointment', 'info',
            "RDV réservé en ligne ({$p['code']})",
            "Demande portail confirmée le {$date} à {$time} avec {$doc['name']}.",
            'overview', null, $pid
        );

        CliniqoAuthService::audit(
            $tid, (int) $doc['branch_id'], null, "{$p['first']} {$p['last']} ({$p['code']})", 'patient_portal',
            'PORTAL_BOOK_APPOINTMENT', 'appointment', "RDV-{$aptId}",
            "{$doc['name']} le {$date} à {$time}",
            $request->ip() ?? '127.0.0.1'
        );

        return $this->me($request);
    }

    public function cancelAppointment(Request $request, int $id): JsonResponse
    {
        $pCtx = CliniqoAuthService::resolvePatientPortalContext($request);
        $pdo = CliniqoDatabase::connection();
        $pid = (int) $pCtx['patient_id'];
        $tid = (int) $pCtx['tenant_id'];
        $p = $pCtx['patient'];

        // Strictly check ownership: `WHERE id = ? AND patient_id = ? AND tenant_id = ?`
        $st = $pdo->prepare('SELECT * FROM appointments WHERE id = ? AND patient_id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $pid, $tid]);
        $apt = $st->fetch();
        if (!$apt) {
            return response()->json([
                'success' => false,
                'error' => 'NOT_FOUND_OR_FORBIDDEN',
                'message' => 'Rendez-vous introuvable dans votre espace personnel.',
            ], 404);
        }

        $reason = trim((string) $request->input('reason', 'Annulé par le patient depuis le Portail Patient'));
        $pdo->prepare("UPDATE appointments SET status = 'cancelled', cancellation_reason = ? WHERE id = ? AND patient_id = ?")
            ->execute([$reason, $id, $pid]);

        CliniqoAuthService::pushNotification(
            $tid, (int) $apt['branch_id'], 'appointment', 'warning',
            "Annulation RDV Portail ({$p['code']})",
            "Le créneau du {$apt['date']} à {$apt['time']} ({$apt['doctor']}) a été annulé par le patient.",
            'overview', null, $pid
        );

        CliniqoAuthService::audit(
            $tid, (int) $apt['branch_id'], null, "{$p['first']} {$p['last']} ({$p['code']})", 'patient_portal',
            'PORTAL_CANCEL_APPOINTMENT', 'appointment', "RDV-{$id}", $reason, $request->ip() ?? '127.0.0.1'
        );

        return $this->me($request);
    }

    public function updateContact(Request $request): JsonResponse
    {
        $pCtx = CliniqoAuthService::resolvePatientPortalContext($request);
        $pdo = CliniqoDatabase::connection();
        $pid = (int) $pCtx['patient_id'];
        $tid = (int) $pCtx['tenant_id'];
        $p = $pCtx['patient'];

        // Permitted contact details ONLY: phone, email, address (CIN, name, medical fields cannot be changed by patient!)
        $phone = trim((string) $request->input('phone', $p['phone']));
        $email = trim((string) $request->input('email', $p['email']));
        $address = trim((string) $request->input('address', $p['address']));

        if ($phone === '') {
            return response()->json(['success' => false, 'message' => 'Un numéro de téléphone valide est requis.'], 422);
        }

        $pdo->prepare('UPDATE patients SET phone = ?, email = ?, address = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$phone, $email, $address, $pid, $tid]);

        CliniqoAuthService::audit(
            $tid, (int) $p['branch_id'], null, "{$p['first']} {$p['last']} ({$p['code']})", 'patient_portal',
            'PORTAL_UPDATE_CONTACT', 'patient', $p['code'],
            'Mise à jour coordonnées autorisées (téléphone / email / adresse)',
            $request->ip() ?? '127.0.0.1'
        );

        return $this->me($request);
    }

    public function guardCrossPatientAttempt(Request $request, int $targetPatientId): JsonResponse
    {
        $pCtx = CliniqoAuthService::resolvePatientPortalContext($request);
        if ((int) $pCtx['patient_id'] !== $targetPatientId) {
            return response()->json([
                'success' => false,
                'error' => 'PATIENT_ISOLATION_FORBIDDEN',
                'message' => 'Accès strictement interdit : un patient ne peut jamais consulter le dossier d’un autre patient.',
            ], 403);
        }
        return $this->me($request);
    }
}
