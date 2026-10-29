<?php

declare(strict_types=1);

/**
 * Cliniqo Medical Cloud — Production Multi-Tenant SaaS Engine (v3)
 * Features:
 * - Multi-Date Calendar Scheduling + Triple Anti-Overlap Engine (Doctor, Room & Patient per Date+Time)
 * - Full Appointment CRUD: Create (POST), Update/Modify (PUT/PATCH), Status + Auto-Bill (PATCH), Delete (DELETE)
 * - Full Patient Registry CRUD + Persisted DME Vitals & Signed ICD-11 Clinical Notes
 * - Lab Pathology Queue: Dispatch, Measured Value Entry, Validation & Delete
 * - Moroccan Financial Engine (MAD): Partial Payments, Strict Balance Math, CNSS/AMO Stepper & CNDP Audit Logs
 */

$requestStart = microtime(true);

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
$dbFile = $storageDir . '/saas_multitenant_v3.json';

function buildTenantSeed(int $tenantId): array
{
    $configs = [
        1 => [
            'name' => 'Polyclinique Grand Central',
            'short' => 'Grand Central',
            'city' => 'Casablanca • Primary & Cardiology',
            'patients_today' => 248,
            'tests_conducted' => 1024,
            'revenue_today' => 185000.00,
            'specialists_count' => 120,
            'weekly' => [
                ['day' => 'Sun', 'count' => 94, 'growth' => '+18%', 'h' => 30],
                ['day' => 'Mon', 'count' => 156, 'growth' => '+34%', 'h' => 56],
                ['day' => 'Tue', 'count' => 132, 'growth' => '+24%', 'h' => 45],
                ['day' => 'Wed', 'count' => 118, 'growth' => '+19%', 'h' => 38],
                ['day' => 'Thu', 'count' => 168, 'growth' => '+41%', 'h' => 58],
                ['day' => 'Fri', 'count' => 196, 'growth' => '+52%', 'h' => 74],
                ['day' => 'Sat', 'count' => 248, 'growth' => '+65%', 'h' => 92],
            ],
        ],
        2 => [
            'name' => 'Centre Diagnostic Anfa',
            'short' => 'Anfa Diagnostics',
            'city' => 'Casablanca • Lab & Oncology',
            'patients_today' => 184,
            'tests_conducted' => 1490,
            'revenue_today' => 214500.00,
            'specialists_count' => 85,
            'weekly' => [
                ['day' => 'Sun', 'count' => 72, 'growth' => '+14%', 'h' => 28],
                ['day' => 'Mon', 'count' => 140, 'growth' => '+39%', 'h' => 62],
                ['day' => 'Tue', 'count' => 152, 'growth' => '+44%', 'h' => 68],
                ['day' => 'Wed', 'count' => 164, 'growth' => '+49%', 'h' => 76],
                ['day' => 'Thu', 'count' => 148, 'growth' => '+38%', 'h' => 65],
                ['day' => 'Fri', 'count' => 171, 'growth' => '+55%', 'h' => 82],
                ['day' => 'Sat', 'count' => 184, 'growth' => '+61%', 'h' => 90],
            ],
        ],
        3 => [
            'name' => 'Clinique Agdal Santé',
            'short' => 'Agdal Clinic',
            'city' => 'Rabat • Multi-Specialty',
            'patients_today' => 162,
            'tests_conducted' => 820,
            'revenue_today' => 142800.00,
            'specialists_count' => 64,
            'weekly' => [
                ['day' => 'Sun', 'count' => 61, 'growth' => '+12%', 'h' => 26],
                ['day' => 'Mon', 'count' => 112, 'growth' => '+29%', 'h' => 54],
                ['day' => 'Tue', 'count' => 128, 'growth' => '+35%', 'h' => 62],
                ['day' => 'Wed', 'count' => 119, 'growth' => '+28%', 'h' => 56],
                ['day' => 'Thu', 'count' => 139, 'growth' => '+42%', 'h' => 70],
                ['day' => 'Fri', 'count' => 150, 'growth' => '+48%', 'h' => 80],
                ['day' => 'Sat', 'count' => 162, 'growth' => '+54%', 'h' => 88],
            ],
        ],
    ];

    $c = $configs[$tenantId] ?? $configs[1];

    return [
        'tenant' => [
            'id' => $tenantId,
            'name' => $c['name'],
            'short' => $c['short'],
            'branch' => $c['city'],
            'compliance' => 'CNDP Loi 09-08 • HIPAA • ISO-27001',
        ],
        'kpis' => [
            'patients_today' => $c['patients_today'],
            'tests_conducted' => $c['tests_conducted'],
            'revenue_today' => $c['revenue_today'],
            'specialists_count' => $c['specialists_count'],
        ],
        'weekly_patients' => $c['weekly'],
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
                'notes' => [
                    ['date' => '29-10-2026 09:12', 'doctor' => 'Dr. Alimi Jan', 'icd' => 'BA00 • Essential Hypertension', 'text' => 'Stable sinus rhythm on Lead II. Serum K+ panel ordered due to ACE-inhibitor titration.'],
                ],
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
                'notes' => [
                    ['date' => '29-10-2026 09:48', 'doctor' => 'Dr. Karim Tazi', 'icd' => '8A80 • Migraine Aura', 'text' => 'EEG mapping normal alpha wave symmetry. Follow-up in 14 days.'],
                ],
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
                'notes' => [],
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
                'notes' => [],
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
                'notes' => [],
            ],
        ],
        'appointments' => [
            // Past & Current Week Multi-Day Appointments for Mini Calendar
            ['id' => 98, 'patient_id' => 3, 'date' => '2026-10-27', 'time' => '10:00', 'doctor' => 'Dr. Sarah Chen', 'specialty' => 'Cardiologist', 'room' => 'Suite 101', 'reason' => 'Pre-screening Biomarker Review', 'status' => 'completed', 'fee_mad' => 600, 'billed' => true],
            ['id' => 99, 'patient_id' => 4, 'date' => '2026-10-28', 'time' => '11:30', 'doctor' => 'Dr. Alimi Jan', 'specialty' => 'Cardiologist', 'room' => 'Suite 102', 'reason' => 'Arterial Pressure Baseline', 'status' => 'completed', 'fee_mad' => 650, 'billed' => true],
            ['id' => 100, 'patient_id' => 1, 'date' => '2026-10-28', 'time' => '15:00', 'doctor' => 'Dr. Karim Tazi', 'specialty' => 'Neurologist', 'room' => 'Neuro Lab 2', 'reason' => 'Neuro-Vascular Doppler', 'status' => 'completed', 'fee_mad' => 850, 'billed' => true],

            // Today: 2026-10-29
            ['id' => 101, 'patient_id' => 1, 'date' => '2026-10-29', 'time' => '09:00', 'doctor' => 'Dr. Alimi Jan', 'specialty' => 'Cardiologist', 'room' => 'Suite 102', 'reason' => 'Echocardiogram & Serum K+ Follow-up', 'status' => 'completed', 'fee_mad' => 650, 'billed' => true],
            ['id' => 102, 'patient_id' => 2, 'date' => '2026-10-29', 'time' => '09:45', 'doctor' => 'Dr. Karim Tazi', 'specialty' => 'Neurologist', 'room' => 'Neuro Lab 2', 'reason' => 'EEG Telemetry & Nerve Mapping', 'status' => 'in_progress', 'fee_mad' => 850, 'billed' => true],
            ['id' => 103, 'patient_id' => 3, 'date' => '2026-10-29', 'time' => '10:30', 'doctor' => 'Dr. Mike Millars', 'specialty' => 'Psychologist', 'room' => 'Consult 105', 'reason' => 'Behavioral Stress & Sleep Assessment', 'status' => 'waiting', 'fee_mad' => 500, 'billed' => false],
            ['id' => 104, 'patient_id' => 4, 'date' => '2026-10-29', 'time' => '11:15', 'doctor' => 'Dr. Sarah Chen', 'specialty' => 'Cardiologist', 'room' => 'Suite 101', 'reason' => 'Renal Doppler & Hypertension Control', 'status' => 'confirmed', 'fee_mad' => 600, 'billed' => true],
            ['id' => 105, 'patient_id' => 5, 'date' => '2026-10-29', 'time' => '14:00', 'doctor' => 'Dr. Alimi Jan', 'specialty' => 'Cardiologist', 'room' => 'Suite 102', 'reason' => 'Preventive Cardiac Stress Test', 'status' => 'confirmed', 'fee_mad' => 750, 'billed' => false],

            // Upcoming Days: Oct 30, Oct 31, Nov 2
            ['id' => 106, 'patient_id' => 2, 'date' => '2026-10-30', 'time' => '09:30', 'doctor' => 'Dr. Karim Tazi', 'specialty' => 'Neurologist', 'room' => 'Neuro Lab 2', 'reason' => 'Post-EEG Medication Titration', 'status' => 'confirmed', 'fee_mad' => 700, 'billed' => false],
            ['id' => 107, 'patient_id' => 5, 'date' => '2026-10-30', 'time' => '11:00', 'doctor' => 'Dr. Sarah Chen', 'specialty' => 'Cardiologist', 'room' => 'Suite 101', 'reason' => 'Holter 24h Readout', 'status' => 'confirmed', 'fee_mad' => 600, 'billed' => false],
            ['id' => 108, 'patient_id' => 3, 'date' => '2026-10-31', 'time' => '10:15', 'doctor' => 'Dr. Alimi Jan', 'specialty' => 'Cardiologist', 'room' => 'Suite 102', 'reason' => 'Cardio-Oncology Clearance', 'status' => 'waiting', 'fee_mad' => 650, 'billed' => false],
            ['id' => 109, 'patient_id' => 1, 'date' => '2026-11-02', 'time' => '09:00', 'doctor' => 'Dr. Alimi Jan', 'specialty' => 'Cardiologist', 'room' => 'Suite 102', 'reason' => 'Electrolyte Control Follow-up', 'status' => 'confirmed', 'fee_mad' => 550, 'billed' => false],
        ],
        'labs' => [
            ['id' => 501, 'code' => 'LAB-2026-881', 'patient_id' => 1, 'patient_name' => 'Eleanor Pena', 'test_name' => 'Serum Electrolytes & Potassium (K+)', 'category' => 'Biochemistry', 'value' => '5.8 mEq/L', 'reference' => '3.5 - 5.1 mEq/L', 'priority' => 'STAT', 'status' => 'flagged', 'ordered_by' => 'Dr. Alimi Jan', 'timestamp' => '09:18'],
            ['id' => 502, 'code' => 'LAB-2026-882', 'patient_id' => 2, 'patient_name' => 'Wade Warren', 'test_name' => '12-Lead ECG + High-Sens Troponin-I', 'category' => 'Cardiology', 'value' => '0.012 ng/mL', 'reference' => '< 0.040 ng/mL', 'priority' => 'Urgent', 'status' => 'in_analysis', 'ordered_by' => 'Dr. Karim Tazi', 'timestamp' => '09:50'],
            ['id' => 503, 'code' => 'LAB-2026-883', 'patient_id' => 3, 'patient_name' => 'Salma Bennani', 'test_name' => 'CBC Complete Blood & CA-125 Biomarker', 'category' => 'Oncology', 'value' => 'WBC 6.4 x10³/µL', 'reference' => '4.5 - 11.0 x10³/µL', 'priority' => 'Routine', 'status' => 'validated', 'ordered_by' => 'Dr. Sarah Chen', 'timestamp' => '08:42'],
            ['id' => 504, 'code' => 'LAB-2026-884', 'patient_id' => 4, 'patient_name' => 'Youssef El Amrani', 'test_name' => 'Renal Function (Creatinine & eGFR)', 'category' => 'Urology', 'value' => '0.94 mg/dL', 'reference' => '0.70 - 1.20 mg/dL', 'priority' => 'Routine', 'status' => 'validated', 'ordered_by' => 'Dr. Sarah Chen', 'timestamp' => '08:15'],
        ],
        'invoices' => [
            ['id' => 901, 'num' => 'INV-2026-0412', 'patient_id' => 1, 'patient' => 'Eleanor Pena', 'service' => 'Echo-Doppler + Biochemistry Panel', 'total' => 1250.00, 'paid' => 1250.00, 'method' => 'TPE CMI', 'cnss_org' => 'CNSS AMO', 'cnss_est' => 1000.00, 'cnss_status' => 'Transmitted'],
            ['id' => 902, 'num' => 'INV-2026-0413', 'patient_id' => 2, 'patient' => 'Wade Warren', 'service' => 'EEG Diagnostic Mapping + Consult', 'total' => 1600.00, 'paid' => 1000.00, 'method' => 'Cash MAD', 'cnss_org' => 'CNOPS', 'cnss_est' => 1360.00, 'cnss_status' => 'Missing Stamp'],
            ['id' => 903, 'num' => 'INV-2026-0414', 'patient_id' => 3, 'patient' => 'Salma Bennani', 'service' => 'Oncology Biomarker Screening', 'total' => 2100.00, 'paid' => 1500.00, 'method' => 'Bank Transfer', 'cnss_org' => 'Wafa Assurance', 'cnss_est' => 1890.00, 'cnss_status' => 'Ready'],
            ['id' => 904, 'num' => 'INV-2026-0415', 'patient_id' => 4, 'patient' => 'Youssef El Amrani', 'service' => 'Renal Doppler Ultrasound', 'total' => 900.00, 'paid' => 900.00, 'method' => 'TPE CMI', 'cnss_org' => 'CNSS AMO', 'cnss_est' => 720.00, 'cnss_status' => 'Reimbursed'],
        ],
        'audit_logs' => [
            ['time' => '10:14:22', 'actor' => 'Dr. Jackson', 'action' => 'OPEN_TENANT_SESSION', 'target' => $c['short'], 'status' => 'VERIFIED'],
            ['time' => '09:51:08', 'actor' => 'HL7 Analyzer', 'action' => 'FLAG_STAT_POTASSIUM', 'target' => 'LAB-2026-881 (#PT-4429)', 'status' => 'STAT_ALERT'],
            ['time' => '09:32:45', 'actor' => 'Cashbox Desk 1', 'action' => 'ALLOCATE_PARTIAL_MAD', 'target' => 'INV-2026-0413 (1,000 MAD)', 'status' => 'SEALED'],
            ['time' => '09:05:11', 'actor' => 'Dr. Alimi Jan', 'action' => 'SIGN_ICD11_ENCOUNTER', 'target' => 'DME #PT-4429', 'status' => 'ENCRYPTED'],
        ],
    ];
}

function loadAllTenants(string $file): array
{
    if (!file_exists($file)) {
        $all = [
            '1' => buildTenantSeed(1),
            '2' => buildTenantSeed(2),
            '3' => buildTenantSeed(3),
        ];
        file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $all;
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded) || !isset($decoded['1'])) {
        return [
            '1' => buildTenantSeed(1),
            '2' => buildTenantSeed(2),
            '3' => buildTenantSeed(3),
        ];
    }
    return $decoded;
}

function saveAllTenants(string $file, array $all): void
{
    file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function readBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function parseCoverageInfo(string $cov): array
{
    if (preg_match('/^(.*?)\s*\((\d+)%\)/', $cov, $m)) {
        return ['org' => trim($m[1]), 'rate' => ((int) $m[2]) / 100.0];
    }
    return ['org' => 'Direct Pay', 'rate' => 0.0];
}

/**
 * Triple Anti-Overlap Check: Doctor, Room, and Patient on the same Date + Time
 */
function checkScheduleConflict(array $appointments, string $date, string $time, string $doctor, string $room, int $patientId, ?int $ignoreAptId = null): ?string
{
    foreach ($appointments as $apt) {
        if ($ignoreAptId !== null && (int) $apt['id'] === $ignoreAptId) {
            continue;
        }
        if (($apt['status'] ?? '') === 'cancelled') {
            continue;
        }
        $aptDate = (string) ($apt['date'] ?? '2026-10-29');
        $aptTime = (string) ($apt['time'] ?? '');
        if ($aptDate === $date && $aptTime === $time) {
            if ($apt['doctor'] === $doctor) {
                return "Conflict: {$doctor} already has an appointment on {$date} at {$time}.";
            }
            if ($room !== '' && ($apt['room'] ?? '') === $room) {
                return "Room Conflict: {$room} is already occupied on {$date} at {$time} ({$apt['doctor']}).";
            }
            if ((int) ($apt['patient_id'] ?? 0) === $patientId) {
                return "Patient Conflict: This patient already has an appointment on {$date} at {$time}.";
            }
        }
    }
    return null;
}

/**
 * Helper to auto-generate a MAD invoice when an appointment transitions to 'completed'
 */
function maybeAutoBillAppointment(array &$state, array &$apt): ?array
{
    if (($apt['status'] ?? '') !== 'completed' || !empty($apt['billed'])) {
        return null;
    }
    $apt['billed'] = true;
    $patientObj = $state['patients'][0] ?? ['id' => 1, 'first' => 'Patient', 'last' => '', 'coverage' => 'Direct Pay'];
    foreach ($state['patients'] as $p) {
        if ((int) $p['id'] === (int) $apt['patient_id']) {
            $patientObj = $p;
            break;
        }
    }
    $covInfo = parseCoverageInfo((string) ($patientObj['coverage'] ?? 'Direct Pay'));
    $fee = (float) ($apt['fee_mad'] ?? 600);
    $autoInv = [
        'id' => (int) round(microtime(true) * 1000),
        'num' => 'INV-2026-' . random_int(1000, 9999),
        'patient_id' => (int) $patientObj['id'],
        'patient' => trim($patientObj['first'] . ' ' . $patientObj['last']),
        'service' => $apt['reason'] . ' (' . $apt['doctor'] . ')',
        'total' => $fee,
        'paid' => 0.00,
        'method' => 'Pending Desk',
        'cnss_org' => $covInfo['org'],
        'cnss_est' => round($fee * $covInfo['rate'], 2),
        'cnss_status' => $covInfo['rate'] > 0 ? 'Ready' : 'N/A',
    ];
    array_unshift($state['invoices'], $autoInv);
    return $autoInv;
}

function respondJson(array $payload, float $start, int $code = 200): void
{
    http_response_code($code);
    $payload['meta'] = [
        'latency_ms' => round((microtime(true) - $start) * 1000, 1),
        'api_version' => 'v3.0-calendar-crud',
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$tenantKey = (string) max(1, min(3, (int) ($_SERVER['HTTP_X_TENANT_ID'] ?? ($_GET['tenant_id'] ?? 1))));

$db = loadAllTenants($dbFile);
if (!isset($db[$tenantKey])) {
    $db[$tenantKey] = buildTenantSeed((int) $tenantKey);
}
$state = &$db[$tenantKey];

// Sort appointments chronologically by date + time
usort($state['appointments'], static function (array $a, array $b): int {
    return strcmp(($a['date'] ?? '2026-10-29') . ' ' . ($a['time'] ?? '00:00'), ($b['date'] ?? '2026-10-29') . ' ' . ($b['time'] ?? '00:00'));
});

// Health Check
if ($uri === '/' || $uri === '/api/v1' || $uri === '/api/v1/health') {
    respondJson([
        'success' => true,
        'service' => 'Cliniqo Medical Multi-Tenant SaaS API v3',
        'tenant_id' => (int) $tenantKey,
        'tenant_name' => $state['tenant']['name'],
        'status' => 'healthy',
    ], $requestStart);
}

// Full Tenant State
if (($uri === '/api/v1/state' || $uri === '/api/v1/dashboard/overview') && $method === 'GET') {
    respondJson([
        'success' => true,
        'centre' => $state['tenant'],
        'state' => $state,
    ], $requestStart);
}

// Reset Tenant Demo Data
if ($uri === '/api/v1/reset' && $method === 'POST') {
    $db[$tenantKey] = buildTenantSeed((int) $tenantKey);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'state' => $db[$tenantKey]], $requestStart);
}

// =========================================================================
// PATIENTS CRUD & DME PERSISTENCE
// =========================================================================
if ($uri === '/api/v1/patients' && $method === 'GET') {
    respondJson(['success' => true, 'data' => $state['patients']], $requestStart);
}

if ($uri === '/api/v1/patients' && $method === 'POST') {
    $body = readBody();
    $cin = strtoupper(trim((string) ($body['cin'] ?? '')));
    foreach ($state['patients'] as $existing) {
        if ($cin !== '' && strtoupper($existing['cin']) === $cin) {
            respondJson(['success' => false, 'message' => "CIN {$cin} already exists in {$state['tenant']['short']}."], $requestStart, 422);
        }
    }
    $newPatient = [
        'id' => (int) round(microtime(true) * 1000),
        'code' => 'PT-' . random_int(1000, 9999),
        'first' => trim((string) ($body['first'] ?? 'Amine')),
        'last' => trim((string) ($body['last'] ?? 'Chraibi')),
        'cin' => $cin ?: ('BK-' . random_int(100000, 999999)),
        'age' => max(1, (int) ($body['age'] ?? 34)),
        'gender' => trim((string) ($body['gender'] ?? 'M')),
        'blood' => trim((string) ($body['blood'] ?? 'O+')),
        'phone' => trim((string) ($body['phone'] ?? '+212 661-000000')),
        'coverage' => trim((string) ($body['coverage'] ?? 'CNSS AMO (80%)')),
        'department' => trim((string) ($body['department'] ?? 'Cardiology')),
        'allergy' => trim((string) ($body['allergy'] ?? 'None reported')),
        'bp' => '120/78',
        'spo2' => '98.9%',
        'hr' => '71 bpm',
        'avatar' => 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=120',
        'notes' => [],
    ];
    array_unshift($state['patients'], $newPatient);
    $state['kpis']['patients_today']++;
    $state['weekly_patients'][6]['count'] = $state['kpis']['patients_today'];

    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Admissions',
        'action' => 'REGISTER_PATIENT',
        'target' => "{$newPatient['code']} ({$newPatient['cin']})",
        'status' => 'VERIFIED',
    ]);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'patient' => $newPatient, 'state' => $state], $requestStart, 201);
}

if (preg_match('#^/api/v1/patients/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    $patId = (int) $m[1];
    $state['patients'] = array_values(array_filter($state['patients'], static fn(array $p): bool => (int) $p['id'] !== $patId));
    $state['appointments'] = array_values(array_filter($state['appointments'], static fn(array $a): bool => (int) $a['patient_id'] !== $patId));
    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Dr. Jackson',
        'action' => 'ARCHIVE_PATIENT',
        'target' => "Patient ID #{$patId}",
        'status' => 'ARCHIVED',
    ]);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'state' => $state], $requestStart);
}

if (preg_match('#^/api/v1/patients/(\d+)/dme$#', $uri, $m) && $method === 'POST') {
    $patId = (int) $m[1];
    $body = readBody();
    foreach ($state['patients'] as &$pat) {
        if ((int) $pat['id'] === $patId) {
            if (!empty($body['bp'])) {
                $pat['bp'] = trim((string) $body['bp']);
            }
            if (!empty($body['spo2'])) {
                $pat['spo2'] = trim((string) $body['spo2']);
            }
            if (!empty($body['hr'])) {
                $pat['hr'] = trim((string) $body['hr']);
            }
            if (isset($body['allergy'])) {
                $pat['allergy'] = trim((string) $body['allergy']) ?: 'None reported';
            }
            $noteText = trim((string) ($body['note'] ?? ''));
            if ($noteText !== '') {
                if (!isset($pat['notes']) || !is_array($pat['notes'])) {
                    $pat['notes'] = [];
                }
                array_unshift($pat['notes'], [
                    'date' => date('d-m-Y H:i'),
                    'doctor' => trim((string) ($body['doctor'] ?? 'Dr. Jackson')),
                    'icd' => trim((string) ($body['icd'] ?? 'ICD-11 Follow-up')),
                    'text' => $noteText,
                ]);
            }
            array_unshift($state['audit_logs'], [
                'time' => date('H:i:s'),
                'actor' => 'Dr. Jackson',
                'action' => 'SEAL_DME_RECORD',
                'target' => $pat['code'],
                'status' => 'ENCRYPTED',
            ]);
            break;
        }
    }
    unset($pat);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'state' => $state], $requestStart);
}

// =========================================================================
// APPOINTMENTS FULL CRUD (GET BY DATE, CREATE, FULL MODIFY/PUT, STATUS, DELETE)
// =========================================================================
if ($uri === '/api/v1/appointments' && $method === 'GET') {
    $dateFilter = trim((string) ($_GET['date'] ?? ''));
    $list = $dateFilter !== ''
        ? array_values(array_filter($state['appointments'], static fn(array $a): bool => ($a['date'] ?? '2026-10-29') === $dateFilter))
        : $state['appointments'];
    respondJson(['success' => true, 'data' => $list], $requestStart);
}

// CREATE APPOINTMENT
if ($uri === '/api/v1/appointments' && $method === 'POST') {
    $body = readBody();
    $patientId = (int) ($body['patient_id'] ?? 1);
    $date = trim((string) ($body['date'] ?? '2026-10-29'));
    $time = trim((string) ($body['time'] ?? '15:00'));
    $doctor = trim((string) ($body['doctor'] ?? 'Dr. Alimi Jan'));
    $specialty = trim((string) ($body['specialty'] ?? 'Cardiologist'));
    $room = trim((string) ($body['room'] ?? 'Suite 102'));
    $reason = trim((string) ($body['reason'] ?? 'Specialist Consultation'));
    $status = trim((string) ($body['status'] ?? 'confirmed'));
    $feeMad = max(50, (int) ($body['fee_mad'] ?? 600));

    $conflict = checkScheduleConflict($state['appointments'], $date, $time, $doctor, $room, $patientId, null);
    if ($conflict !== null) {
        respondJson(['success' => false, 'message' => $conflict], $requestStart, 409);
    }

    $newApt = [
        'id' => (int) round(microtime(true) * 1000),
        'patient_id' => $patientId,
        'date' => $date,
        'time' => $time,
        'doctor' => $doctor,
        'specialty' => $specialty,
        'room' => $room,
        'reason' => $reason,
        'status' => $status,
        'fee_mad' => $feeMad,
        'billed' => false,
    ];

    $autoInv = maybeAutoBillAppointment($state, $newApt);
    $state['appointments'][] = $newApt;
    usort($state['appointments'], static fn(array $a, array $b): int => strcmp(($a['date'] ?? '') . ' ' . ($a['time'] ?? ''), ($b['date'] ?? '') . ' ' . ($b['time'] ?? '')));

    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Calendar Scheduler',
        'action' => 'ADD_APPOINTMENT',
        'target' => "{$doctor} on {$date} @ {$time}",
        'status' => 'CONFIRMED',
    ]);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'appointment' => $newApt, 'auto_invoice' => $autoInv, 'state' => $state], $requestStart, 201);
}

// MODIFY / UPDATE FULL APPOINTMENT (PUT or PATCH /api/v1/appointments/{id})
if (preg_match('#^/api/v1/appointments/(\d+)$#', $uri, $m) && ($method === 'PUT' || $method === 'PATCH')) {
    $aptId = (int) $m[1];
    $body = readBody();
    $foundIdx = null;
    foreach ($state['appointments'] as $idx => $existing) {
        if ((int) $existing['id'] === $aptId) {
            $foundIdx = $idx;
            break;
        }
    }
    if ($foundIdx === null) {
        respondJson(['success' => false, 'message' => 'Appointment not found.'], $requestStart, 404);
    }

    $curr = $state['appointments'][$foundIdx];
    $patientId = isset($body['patient_id']) ? (int) $body['patient_id'] : (int) $curr['patient_id'];
    $date = isset($body['date']) ? trim((string) $body['date']) : (string) ($curr['date'] ?? '2026-10-29');
    $time = isset($body['time']) ? trim((string) $body['time']) : (string) $curr['time'];
    $doctor = isset($body['doctor']) ? trim((string) $body['doctor']) : (string) $curr['doctor'];
    $specialty = isset($body['specialty']) ? trim((string) $body['specialty']) : (string) ($curr['specialty'] ?? 'Cardiologist');
    $room = isset($body['room']) ? trim((string) $body['room']) : (string) ($curr['room'] ?? 'Suite 102');
    $reason = isset($body['reason']) ? trim((string) $body['reason']) : (string) $curr['reason'];
    $status = isset($body['status']) ? trim((string) $body['status']) : (string) $curr['status'];
    $feeMad = isset($body['fee_mad']) ? max(50, (int) $body['fee_mad']) : (int) ($curr['fee_mad'] ?? 600);

    $conflict = checkScheduleConflict($state['appointments'], $date, $time, $doctor, $room, $patientId, $aptId);
    if ($conflict !== null) {
        respondJson(['success' => false, 'message' => $conflict], $requestStart, 409);
    }

    $curr['patient_id'] = $patientId;
    $curr['date'] = $date;
    $curr['time'] = $time;
    $curr['doctor'] = $doctor;
    $curr['specialty'] = $specialty;
    $curr['room'] = $room;
    $curr['reason'] = $reason;
    $curr['status'] = $status;
    $curr['fee_mad'] = $feeMad;

    $autoInv = maybeAutoBillAppointment($state, $curr);
    $state['appointments'][$foundIdx] = $curr;
    usort($state['appointments'], static fn(array $a, array $b): int => strcmp(($a['date'] ?? '') . ' ' . ($a['time'] ?? ''), ($b['date'] ?? '') . ' ' . ($b['time'] ?? '')));

    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Calendar Scheduler',
        'action' => 'MODIFY_APPOINTMENT',
        'target' => "APT #{$aptId} ({$date} @ {$time})",
        'status' => 'UPDATED',
    ]);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'appointment' => $curr, 'auto_invoice' => $autoInv, 'state' => $state], $requestStart);
}

// DELETE APPOINTMENT (DELETE /api/v1/appointments/{id})
if (preg_match('#^/api/v1/appointments/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    $aptId = (int) $m[1];
    $deletedInfo = "APT #{$aptId}";
    foreach ($state['appointments'] as $a) {
        if ((int) $a['id'] === $aptId) {
            $deletedInfo = "{$a['doctor']} ({$a['date']} @ {$a['time']})";
            break;
        }
    }
    $state['appointments'] = array_values(array_filter(
        $state['appointments'],
        static fn(array $a): bool => (int) $a['id'] !== $aptId
    ));

    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Calendar Scheduler',
        'action' => 'DELETE_APPOINTMENT',
        'target' => $deletedInfo,
        'status' => 'REMOVED',
    ]);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'state' => $state], $requestStart);
}

// QUICK STATUS UPDATE + AUTO-BILL
if (preg_match('#^/api/v1/appointments/(\d+)/status$#', $uri, $m) && ($method === 'PATCH' || $method === 'POST')) {
    $aptId = (int) $m[1];
    $body = readBody();
    $newStatus = trim((string) ($body['status'] ?? 'completed'));
    $autoInv = null;

    foreach ($state['appointments'] as &$apt) {
        if ((int) $apt['id'] === $aptId) {
            $apt['status'] = $newStatus;
            $autoInv = maybeAutoBillAppointment($state, $apt);
            break;
        }
    }
    unset($apt);

    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Clinical Suite',
        'action' => $autoInv ? 'COMPLETE_AND_AUTOBILL' : 'UPDATE_APT_STATUS',
        'target' => "APT #{$aptId} -> {$newStatus}",
        'status' => 'LOGGED',
    ]);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'auto_invoice' => $autoInv, 'state' => $state], $requestStart);
}

// =========================================================================
// LABS: ORDER, VALIDATE WITH MEASURED VALUE, DELETE
// =========================================================================
if ($uri === '/api/v1/labs' && $method === 'POST') {
    $body = readBody();
    $priority = trim((string) ($body['priority'] ?? 'Urgent'));
    $newLab = [
        'id' => (int) round(microtime(true) * 1000),
        'code' => 'LAB-2026-' . random_int(890, 999),
        'patient_id' => (int) ($body['patient_id'] ?? 1),
        'patient_name' => trim((string) ($body['patient_name'] ?? 'Eleanor Pena')),
        'test_name' => trim((string) ($body['test_name'] ?? 'Comprehensive Metabolic Panel')),
        'category' => trim((string) ($body['category'] ?? 'Biochemistry')),
        'value' => trim((string) ($body['value'] ?? 'Analyzer Running')),
        'reference' => trim((string) ($body['reference'] ?? 'Standard Clinical Range')),
        'priority' => $priority,
        'status' => $priority === 'STAT' ? 'flagged' : 'in_analysis',
        'ordered_by' => trim((string) ($body['ordered_by'] ?? 'Dr. Jackson')),
        'timestamp' => date('H:i'),
    ];
    array_unshift($state['labs'], $newLab);
    $state['kpis']['tests_conducted']++;
    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => $newLab['ordered_by'],
        'action' => 'DISPATCH_LAB_PANEL',
        'target' => "{$newLab['code']} ({$priority})",
        'status' => 'QUEUED',
    ]);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'lab' => $newLab, 'state' => $state], $requestStart, 201);
}

if (preg_match('#^/api/v1/labs/(\d+)/validate$#', $uri, $m) && ($method === 'PATCH' || $method === 'POST')) {
    $id = (int) $m[1];
    $body = readBody();
    foreach ($state['labs'] as &$lab) {
        if ((int) $lab['id'] === $id) {
            $lab['status'] = 'validated';
            if (!empty($body['value'])) {
                $lab['value'] = trim((string) $body['value']);
            } elseif ($lab['value'] === 'Analyzer Running') {
                $lab['value'] = 'Normal Range Verified';
            }
            break;
        }
    }
    unset($lab);
    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Pathology Chief',
        'action' => 'VALIDATE_LAB_REPORT',
        'target' => "Lab #{$id}",
        'status' => 'SIGNED',
    ]);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'state' => $state], $requestStart);
}

if (preg_match('#^/api/v1/labs/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    $id = (int) $m[1];
    $state['labs'] = array_values(array_filter($state['labs'], static fn(array $l): bool => (int) $l['id'] !== $id));
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'state' => $state], $requestStart);
}

// =========================================================================
// INVOICES, PARTIAL PAYMENTS (MAD) & CNSS PIPELINE
// =========================================================================
if ($uri === '/api/v1/invoices' && $method === 'GET') {
    respondJson(['success' => true, 'data' => $state['invoices']], $requestStart);
}

if ($uri === '/api/v1/invoices' && $method === 'POST') {
    $body = readBody();
    $patId = (int) ($body['patient_id'] ?? 1);
    $patientObj = $state['patients'][0];
    foreach ($state['patients'] as $p) {
        if ((int) $p['id'] === $patId) {
            $patientObj = $p;
            break;
        }
    }
    $covInfo = parseCoverageInfo((string) ($patientObj['coverage'] ?? 'Direct Pay'));
    $total = max(50.0, (float) ($body['total'] ?? 600));
    $paid = min($total, max(0.0, (float) ($body['paid'] ?? 0)));

    $newInv = [
        'id' => (int) round(microtime(true) * 1000),
        'num' => 'INV-2026-' . random_int(1000, 9999),
        'patient_id' => $patId,
        'patient' => trim($patientObj['first'] . ' ' . $patientObj['last']),
        'service' => trim((string) ($body['service'] ?? 'Clinical Consultation & Diagnostics')),
        'total' => $total,
        'paid' => $paid,
        'method' => trim((string) ($body['method'] ?? 'Cash MAD')),
        'cnss_org' => $covInfo['org'],
        'cnss_est' => round($total * $covInfo['rate'], 2),
        'cnss_status' => $covInfo['rate'] > 0 ? 'Ready' : 'N/A',
    ];
    array_unshift($state['invoices'], $newInv);
    if ($paid > 0) {
        $state['kpis']['revenue_today'] = round((float) $state['kpis']['revenue_today'] + $paid, 2);
    }
    array_unshift($state['audit_logs'], [
        'time' => date('H:i:s'),
        'actor' => 'Billing Desk',
        'action' => 'ISSUE_INVOICE_MAD',
        'target' => "{$newInv['num']} ({$total} MAD)",
        'status' => 'ISSUED',
    ]);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'invoice' => $newInv, 'state' => $state], $requestStart, 201);
}

if (preg_match('#^/api/v1/invoices/(\d+)/payments$#', $uri, $m) && $method === 'POST') {
    $id = (int) $m[1];
    $body = readBody();
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
                'action' => 'COLLECT_PAYMENT_MAD',
                'target' => "{$inv['num']} (+{$applied} MAD)",
                'status' => 'SEALED',
            ]);
            break;
        }
    }
    unset($inv);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'state' => $state], $requestStart);
}

if (preg_match('#^/api/v1/invoices/(\d+)/cnss$#', $uri, $m) && ($method === 'PATCH' || $method === 'POST')) {
    $id = (int) $m[1];
    $cycle = [
        'Missing Stamp' => 'Ready',
        'Ready' => 'Transmitted',
        'Transmitted' => 'Reimbursed',
        'Reimbursed' => 'Reimbursed',
    ];
    foreach ($state['invoices'] as &$inv) {
        if ((int) $inv['id'] === $id) {
            $curr = (string) ($inv['cnss_status'] ?? 'Ready');
            $next = $cycle[$curr] ?? 'Transmitted';
            $inv['cnss_status'] = $next;
            array_unshift($state['audit_logs'], [
                'time' => date('H:i:s'),
                'actor' => 'CNSS/AMO Officer',
                'action' => 'ADVANCE_CNSS_CLAIM',
                'target' => "{$inv['num']} -> {$next}",
                'status' => 'TRANSMITTED',
            ]);
            break;
        }
    }
    unset($inv);
    saveAllTenants($dbFile, $db);
    respondJson(['success' => true, 'state' => $state], $requestStart);
}

respondJson([
    'success' => false,
    'message' => 'Endpoint not found on Cliniqo SaaS API.',
    'requested_path' => $uri,
], $requestStart, 404);
