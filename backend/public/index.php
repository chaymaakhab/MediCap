<?php

declare(strict_types=1);

/**
 * Cliniqo Medical Cloud — Production-Grade Stateful SaaS REST API (v1)
 * Multi-Tenant Diagnostics, Treatment/Appointments, DME, Lab Orders, MAD Billing & CNSS Claims
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Tenant-ID, X-Branch-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$storageDir = dirname(__DIR__) . '/storage';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0775, true);
}
$stateFile = $storageDir . '/saas_state.json';

function defaultSaasState(): array
{
    return [
        'tenant' => [
            'id' => 1,
            'name' => 'Cliniqo Grand Central',
            'branch' => 'Casablanca • Diagnostic & Polyclinic',
            'compliance' => 'CNDP Loi 09-08 • HIPAA • ISO-27001',
        ],
        'kpis' => [
            'patients_today' => 248,
            'patients_growth' => '+65%',
            'tests_conducted' => 1024,
            'tests_growth' => '+86%',
            'revenue_today' => 185000,
            'revenue_growth' => '+58%',
            'specialists_count' => 124,
        ],
        'patients' => [
            [
                'id' => 1,
                'code' => 'PT-4429',
                'first' => 'Eleanor',
                'last' => 'Pena',
                'cin' => 'BE-882910',
                'age' => 58,
                'gender' => 'F',
                'blood' => 'A+',
                'phone' => '+212 661-234890',
                'coverage' => 'CNSS AMO (80%)',
                'department' => 'Cardiology',
                'allergy' => 'Penicillin',
                'bp' => '126/80',
                'spo2' => '98.6%',
                'hr' => '74 bpm',
                'avatar' => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=120',
            ],
            [
                'id' => 2,
                'code' => 'PT-9014',
                'first' => 'Wade',
                'last' => 'Warren',
                'cin' => 'BH-441092',
                'age' => 44,
                'gender' => 'M',
                'blood' => 'O+',
                'phone' => '+212 662-981120',
                'coverage' => 'CNOPS (85%)',
                'department' => 'Neurology',
                'allergy' => 'None reported',
                'bp' => '132/84',
                'spo2' => '97.9%',
                'hr' => '81 bpm',
                'avatar' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=120',
            ],
            [
                'id' => 3,
                'code' => 'PT-9132',
                'first' => 'Salma',
                'last' => 'Bennani',
                'cin' => 'BK-619204',
                'age' => 33,
                'gender' => 'F',
                'blood' => 'B+',
                'phone' => '+212 664-771230',
                'coverage' => 'Wafa Assurance (90%)',
                'department' => 'Oncology',
                'allergy' => 'Sulfonamides',
                'bp' => '118/75',
                'spo2' => '99.1%',
                'hr' => '68 bpm',
                'avatar' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=120',
            ],
            [
                'id' => 4,
                'code' => 'PT-7945',
                'first' => 'Youssef',
                'last' => 'El Amrani',
                'cin' => 'CD-778231',
                'age' => 49,
                'gender' => 'M',
                'blood' => 'AB+',
                'phone' => '+212 668-334519',
                'coverage' => 'CNSS AMO (80%)',
                'department' => 'Urology',
                'allergy' => 'Aspirin (NSAIDs)',
                'bp' => '122/78',
                'spo2' => '98.4%',
                'hr' => '72 bpm',
                'avatar' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=120',
            ],
            [
                'id' => 5,
                'code' => 'PT-8310',
                'first' => 'Dianne',
                'last' => 'Russell',
                'cin' => 'AB-502918',
                'age' => 37,
                'gender' => 'F',
                'blood' => 'O-',
                'phone' => '+212 661-908214',
                'coverage' => 'Direct Pay',
                'department' => 'Cardiology',
                'allergy' => 'None reported',
                'bp' => '116/74',
                'spo2' => '99.2%',
                'hr' => '66 bpm',
                'avatar' => 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=120',
            ],
        ],
        'appointments' => [
            [
                'id' => 101,
                'patient_id' => 1,
                'time' => '09:00',
                'doctor' => 'Dr. Alimi Jan',
                'specialty' => 'Cardiologist',
                'room' => 'Suite 102',
                'reason' => 'Echocardiogram & Serum K+ Follow-up',
                'status' => 'completed',
                'fee_mad' => 650,
            ],
            [
                'id' => 102,
                'patient_id' => 2,
                'time' => '09:45',
                'doctor' => 'Dr. Karim Tazi',
                'specialty' => 'Neurologist',
                'room' => 'Neuro Lab 2',
                'reason' => 'EEG Telemetry & Cranial Nerve Mapping',
                'status' => 'in_progress',
                'fee_mad' => 850,
            ],
            [
                'id' => 103,
                'patient_id' => 3,
                'time' => '10:30',
                'doctor' => 'Dr. Mike Millars',
                'specialty' => 'Psychologist',
                'room' => 'Consult 105',
                'reason' => 'Behavioral Stress & Sleep Assessment',
                'status' => 'waiting',
                'fee_mad' => 500,
            ],
            [
                'id' => 104,
                'patient_id' => 4,
                'time' => '11:15',
                'doctor' => 'Dr. Sarah Chen',
                'specialty' => 'Cardiologist',
                'room' => 'Suite 101',
                'reason' => 'Renal Doppler & Hypertension Control',
                'status' => 'confirmed',
                'fee_mad' => 600,
            ],
            [
                'id' => 105,
                'patient_id' => 5,
                'time' => '14:00',
                'doctor' => 'Dr. Alimi Jan',
                'specialty' => 'Cardiologist',
                'room' => 'Suite 102',
                'reason' => 'Preventive Cardiac Stress Test',
                'status' => 'confirmed',
                'fee_mad' => 750,
            ],
        ],
        'labs' => [
            [
                'id' => 501,
                'code' => 'LAB-2026-881',
                'patient_id' => 1,
                'patient_name' => 'Eleanor Pena',
                'test_name' => 'Serum Electrolytes & Potassium Panel',
                'category' => 'Biochemistry',
                'value' => '5.8 mEq/L',
                'reference' => '3.5 - 5.1 mEq/L',
                'priority' => 'STAT',
                'status' => 'flagged',
                'ordered_by' => 'Dr. Alimi Jan',
                'timestamp' => '09:18',
            ],
            [
                'id' => 502,
                'code' => 'LAB-2026-882',
                'patient_id' => 2,
                'patient_name' => 'Wade Warren',
                'test_name' => 'High-Resolution 12-Lead ECG + Troponin-I',
                'category' => 'Cardiology',
                'value' => '0.012 ng/mL',
                'reference' => '< 0.040 ng/mL',
                'priority' => 'Urgent',
                'status' => 'in_analysis',
                'ordered_by' => 'Dr. Karim Tazi',
                'timestamp' => '09:50',
            ],
            [
                'id' => 503,
                'code' => 'LAB-2026-883',
                'patient_id' => 3,
                'patient_name' => 'Salma Bennani',
                'test_name' => 'Complete Blood Count (CBC) & Tumor Markers',
                'category' => 'Oncology',
                'value' => 'WBC 6.4 x10³/µL',
                'reference' => '4.5 - 11.0 x10³/µL',
                'priority' => 'Routine',
                'status' => 'validated',
                'ordered_by' => 'Dr. Sarah Chen',
                'timestamp' => '08:42',
            ],
            [
                'id' => 504,
                'code' => 'LAB-2026-884',
                'patient_id' => 4,
                'patient_name' => 'Youssef El Amrani',
                'test_name' => 'Renal Function (Creatinine & eGFR)',
                'category' => 'Urology',
                'value' => '0.94 mg/dL',
                'reference' => '0.70 - 1.20 mg/dL',
                'priority' => 'Routine',
                'status' => 'validated',
                'ordered_by' => 'Dr. Sarah Chen',
                'timestamp' => '08:15',
            ],
        ],
        'invoices' => [
            [
                'id' => 901,
                'num' => 'INV-2026-0412',
                'patient_id' => 1,
                'patient' => 'Eleanor Pena',
                'service' => 'Echo-Doppler + Biochemistry Panel',
                'total' => 1250.00,
                'paid' => 1250.00,
                'method' => 'TPE CMI',
                'cnss_org' => 'CNSS AMO',
                'cnss_est' => 1000.00,
                'cnss_status' => 'Transmitted',
                'date' => '29-10-2026',
            ],
            [
                'id' => 902,
                'num' => 'INV-2026-0413',
                'patient_id' => 2,
                'patient' => 'Wade Warren',
                'service' => 'EEG Diagnostic Mapping + Consult',
                'total' => 1600.00,
                'paid' => 1000.00,
                'method' => 'Cash MAD',
                'cnss_org' => 'CNOPS',
                'cnss_est' => 1360.00,
                'cnss_status' => 'Missing Prescription Stamp',
                'date' => '29-10-2026',
            ],
            [
                'id' => 903,
                'num' => 'INV-2026-0414',
                'patient_id' => 3,
                'patient' => 'Salma Bennani',
                'service' => 'Oncology Biomarker Screening',
                'total' => 2100.00,
                'paid' => 1500.00,
                'method' => 'Bank Transfer',
                'cnss_org' => 'Wafa Assurance',
                'cnss_est' => 1890.00,
                'cnss_status' => 'Ready for Bordereau',
                'date' => '29-10-2026',
            ],
            [
                'id' => 904,
                'num' => 'INV-2026-0415',
                'patient_id' => 4,
                'patient' => 'Youssef El Amrani',
                'service' => 'Renal Doppler Ultrasound',
                'total' => 900.00,
                'paid' => 900.00,
                'method' => 'TPE CMI',
                'cnss_org' => 'CNSS AMO',
                'cnss_est' => 720.00,
                'cnss_status' => 'Reimbursed',
                'date' => '29-10-2026',
            ],
        ],
        'audit_logs' => [
            ['time' => '10:14:22', 'actor' => 'Dr. Jackson (Director)', 'action' => 'VIEW_DIAGNOSTIC_MONITORING', 'target' => 'Overview Suite', 'status' => 'VERIFIED'],
            ['time' => '09:51:08', 'actor' => 'Central Lab Auto-Analyzer', 'action' => 'FLAG_STAT_POTASSIUM', 'target' => 'LAB-2026-881 (#PT-4429)', 'status' => 'ALERT_SENT'],
            ['time' => '09:32:45', 'actor' => 'Nadia (Cashier Desk 1)', 'action' => 'PARTIAL_PAYMENT_ALLOCATED', 'target' => 'INV-2026-0413 (1,000 MAD)', 'status' => 'SEALED'],
            ['time' => '09:05:11', 'actor' => 'Dr. Alimi Jan', 'action' => 'SIGN_CLINICAL_ENCOUNTER', 'target' => 'DME #PT-4429', 'status' => 'ENCRYPTED'],
        ],
    ];
}

function loadState(string $file): array
{
    if (!file_exists($file)) {
        $initial = defaultSaasState();
        file_put_contents($file, json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $initial;
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : defaultSaasState();
}

function saveState(string $file, array $state): void
{
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$state = loadState($stateFile);

if ($uri === '/' || $uri === '/api/v1' || $uri === '/api/v1/health') {
    echo json_encode([
        'success' => true,
        'service' => 'Cliniqo Clinical Cloud SaaS API v1',
        'version' => '5.0.0',
        'status' => 'healthy',
        'persistence' => 'active',
        'compliance' => ['CNDP Loi 09-08', 'HIPAA', 'ISO-27001'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($uri === '/api/v1/state' || $uri === '/api/v1/dashboard/overview') && $method === 'GET') {
    echo json_encode([
        'success' => true,
        'centre' => $state['tenant'],
        'state' => $state,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($uri === '/api/v1/patients' && $method === 'GET') {
    echo json_encode(['success' => true, 'data' => $state['patients']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($uri === '/api/v1/patients' && $method === 'POST') {
    $body = readJsonBody();
    $cin = strtoupper(trim((string) ($body['cin'] ?? '')));
    foreach ($state['patients'] as $existing) {
        if ($cin !== '' && strtoupper($existing['cin']) === $cin) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => "Duplicate CIN detected ({$cin}). Each patient must hold a unique dossier."]);
            exit;
        }
    }
    $newPatient = [
        'id' => (int) round(microtime(true) * 1000),
        'code' => 'PT-' . random_int(1000, 9999),
        'first' => trim((string) ($body['first'] ?? 'New')),
        'last' => trim((string) ($body['last'] ?? 'Patient')),
        'cin' => $cin ?: ('BK-' . random_int(100000, 999999)),
        'age' => (int) ($body['age'] ?? 34),
        'gender' => trim((string) ($body['gender'] ?? 'M')),
        'blood' => trim((string) ($body['blood'] ?? 'O+')),
        'phone' => trim((string) ($body['phone'] ?? '+212 661-000000')),
        'coverage' => trim((string) ($body['coverage'] ?? 'CNSS AMO (80%)')),
        'department' => trim((string) ($body['department'] ?? 'Cardiology')),
        'allergy' => trim((string) ($body['allergy'] ?? 'None reported')),
        'bp' => '120/78',
        'spo2' => '98.8%',
        'hr' => '72 bpm',
        'avatar' => 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=120',
    ];
    array_unshift($state['patients'], $newPatient);
    $state['kpis']['patients_today']++;
    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Admissions Desk',
        'action' => 'CREATE_PATIENT_DOSSIER',
        'target' => $newPatient['code'] . ' (' . $newPatient['cin'] . ')',
        'status' => 'VERIFIED',
    ]);
    saveState($stateFile, $state);
    echo json_encode(['success' => true, 'patient' => $newPatient, 'state' => $state], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($uri === '/api/v1/appointments' && $method === 'GET') {
    echo json_encode(['success' => true, 'data' => $state['appointments']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($uri === '/api/v1/appointments' && $method === 'POST') {
    $body = readJsonBody();
    $doctor = trim((string) ($body['doctor'] ?? 'Dr. Alimi Jan'));
    $time = trim((string) ($body['time'] ?? '15:00'));

    foreach ($state['appointments'] as $apt) {
        if ($apt['doctor'] === $doctor && $apt['time'] === $time && $apt['status'] !== 'cancelled') {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => "Schedule Conflict: {$doctor} already has an active appointment at {$time}.",
            ]);
            exit;
        }
    }

    $newApt = [
        'id' => (int) round(microtime(true) * 1000),
        'patient_id' => (int) ($body['patient_id'] ?? 1),
        'time' => $time,
        'doctor' => $doctor,
        'specialty' => trim((string) ($body['specialty'] ?? 'Cardiologist')),
        'room' => trim((string) ($body['room'] ?? 'Suite 102')),
        'reason' => trim((string) ($body['reason'] ?? 'Diagnostic Consultation')),
        'status' => 'confirmed',
        'fee_mad' => (int) ($body['fee_mad'] ?? 600),
    ];
    $state['appointments'][] = $newApt;
    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Scheduling Engine',
        'action' => 'BOOK_SLOT_ANTI_OVERLAP',
        'target' => "{$doctor} @ {$time}",
        'status' => 'CONFIRMED',
    ]);
    saveState($stateFile, $state);
    echo json_encode(['success' => true, 'appointment' => $newApt, 'state' => $state], JSON_UNESCAPED_UNICODE);
    exit;
}

if (preg_match('#^/api/v1/appointments/(\d+)/status$#', $uri, $m) && ($method === 'PATCH' || $method === 'POST')) {
    $id = (int) $m[1];
    $body = readJsonBody();
    $newStatus = trim((string) ($body['status'] ?? 'completed'));
    foreach ($state['appointments'] as &$apt) {
        if ((int) $apt['id'] === $id) {
            $apt['status'] = $newStatus;
            break;
        }
    }
    unset($apt);
    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Clinical Workstation',
        'action' => 'UPDATE_ENCOUNTER_STATUS',
        'target' => "APT #{$id} -> {$newStatus}",
        'status' => 'LOGGED',
    ]);
    saveState($stateFile, $state);
    echo json_encode(['success' => true, 'state' => $state], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($uri === '/api/v1/labs' && $method === 'POST') {
    $body = readJsonBody();
    $newLab = [
        'id' => (int) round(microtime(true) * 1000),
        'code' => 'LAB-2026-' . random_int(885, 999),
        'patient_id' => (int) ($body['patient_id'] ?? 1),
        'patient_name' => trim((string) ($body['patient_name'] ?? 'Eleanor Pena')),
        'test_name' => trim((string) ($body['test_name'] ?? 'Comprehensive Metabolic Panel')),
        'category' => trim((string) ($body['category'] ?? 'Biochemistry')),
        'value' => 'Pending Analyzer',
        'reference' => 'Standard Clinical Range',
        'priority' => trim((string) ($body['priority'] ?? 'Urgent')),
        'status' => 'in_analysis',
        'ordered_by' => trim((string) ($body['ordered_by'] ?? 'Dr. Jackson')),
        'timestamp' => date('H:i'),
    ];
    array_unshift($state['labs'], $newLab);
    $state['kpis']['tests_conducted']++;
    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => $newLab['ordered_by'],
        'action' => 'ORDER_DIAGNOSTIC_LAB',
        'target' => $newLab['code'] . ' (' . $newLab['priority'] . ')',
        'status' => 'QUEUED',
    ]);
    saveState($stateFile, $state);
    echo json_encode(['success' => true, 'lab' => $newLab, 'state' => $state], JSON_UNESCAPED_UNICODE);
    exit;
}

if (preg_match('#^/api/v1/labs/(\d+)/validate$#', $uri, $m) && ($method === 'PATCH' || $method === 'POST')) {
    $id = (int) $m[1];
    foreach ($state['labs'] as &$lab) {
        if ((int) $lab['id'] === $id) {
            $lab['status'] = 'validated';
            break;
        }
    }
    unset($lab);
    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Pathology Supervisor',
        'action' => 'VALIDATE_LAB_RESULT',
        'target' => "Lab ID #{$id}",
        'status' => 'SIGNED',
    ]);
    saveState($stateFile, $state);
    echo json_encode(['success' => true, 'state' => $state], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($uri === '/api/v1/invoices' && $method === 'GET') {
    echo json_encode(['success' => true, 'data' => $state['invoices']], JSON_UNESCAPED_UNICODE);
    exit;
}

if (preg_match('#^/api/v1/invoices/(\d+)/payments$#', $uri, $m) && $method === 'POST') {
    $id = (int) $m[1];
    $body = readJsonBody();
    $amount = max(0.0, (float) ($body['amount'] ?? 0));
    $methodName = trim((string) ($body['method'] ?? 'TPE CMI'));

    foreach ($state['invoices'] as &$inv) {
        if ((int) $inv['id'] === $id) {
            $remaining = max(0.0, (float) $inv['total'] - (float) $inv['paid']);
            $applied = min($remaining, $amount);
            $inv['paid'] = round((float) $inv['paid'] + $applied, 2);
            $inv['method'] = $methodName;
            $state['kpis']['revenue_today'] = round((float) $state['kpis']['revenue_today'] + $applied, 2);
            array_unshift($state['audit_logs'], [
                'time' => date('H:i:s'),
                'actor' => 'Cashbox Desk',
                'action' => 'COLLECT_PATIENT_PAYMENT',
                'target' => "{$inv['num']} (+{$applied} MAD via {$methodName})",
                'status' => 'SEALED',
            ]);
            break;
        }
    }
    unset($inv);
    saveState($stateFile, $state);
    echo json_encode(['success' => true, 'state' => $state], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($uri === '/api/v1/cnss/claims' && $method === 'GET') {
    echo json_encode(['success' => true, 'data' => $state['invoices']], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode([
    'success' => false,
    'message' => 'Endpoint not found on Cliniqo SaaS API v1.',
    'requested_path' => $uri,
], JSON_UNESCAPED_UNICODE);
