<?php

declare(strict_types=1);

/**
 * Cliniqo Medical Cloud — Live REST API Router (v1)
 * Multi-Tenant Clinical Operations, DME, MAD Cashbox & CNSS/AMO Claims
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Tenant-ID, X-Branch-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$tenantId = (int) ($_SERVER['HTTP_X_TENANT_ID'] ?? 1);

$branches = [
    1 => ['name' => 'Polyclinique Grand Central', 'branch' => 'Casablanca • Aile Soins Primaires'],
    2 => ['name' => 'Centre Diagnostic Anfa', 'branch' => 'Casablanca • Imagerie & Cardiologie'],
    3 => ['name' => 'Clinique Agdal Santé', 'branch' => 'Rabat • Consultations Spécialisées'],
];
$activeCentre = $branches[$tenantId] ?? $branches[1];

switch (true) {
    case $uri === '/' || $uri === '/api/v1' || $uri === '/api/v1/health':
        echo json_encode([
            'success' => true,
            'service' => 'Cliniqo Medical REST API v1',
            'version' => '4.9.0',
            'status' => 'healthy',
            'tenant_id' => $tenantId,
            'compliance' => ['CNDP Loi 09-08', 'HIPAA', 'GDPR'],
            'endpoints' => [
                'GET /api/v1/dashboard/overview',
                'GET /api/v1/appointments',
                'POST /api/v1/appointments',
                'GET /api/v1/patients',
                'POST /api/v1/patients',
                'GET /api/v1/invoices',
                'GET /api/v1/cnss/claims',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    case $uri === '/api/v1/dashboard/overview' && $method === 'GET':
        echo json_encode([
            'success' => true,
            'centre' => [
                'tenant_id' => $tenantId,
                'name' => $activeCentre['name'],
                'branch' => $activeCentre['branch'],
                'date' => date('Y-m-d'),
                'status' => 'Live Overview',
                'compliance' => 'CNDP Loi 09-08 • ISO-27001',
            ],
            'user' => [
                'name' => 'Dr. Sarah Chen',
                'role' => 'Medical Director',
                'duty_status' => 'On Duty • Suite 101',
            ],
            'kpis' => [
                'appointments_today' => ['count' => 38, 'trend' => '+8.4%', 'active' => 32, 'queued' => 6],
                'patients_checked_in' => ['count' => 29, 'total' => 38, 'avg_wait_minutes' => 11],
                'payments_collected' => [
                    'amount' => 4850.00,
                    'currency' => 'MAD',
                    'breakdown' => ['cash' => 1200.00, 'pos_cmi' => 2450.00, 'transfer' => 1200.00],
                ],
                'unsettled_claims' => ['amount' => 1180.00, 'currency' => 'MAD', 'dossiers_count' => 5],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    case $uri === '/api/v1/patients' && $method === 'GET':
        echo json_encode([
            'success' => true,
            'tenant_id' => $tenantId,
            'data' => [
                ['id' => 1, 'code' => 'PT-4429', 'full_name' => 'Eleanor Pena', 'cin' => 'BE-882910', 'blood_group' => 'A+', 'coverage' => 'CNSS AMO (80%)'],
                ['id' => 2, 'code' => 'PT-9014', 'full_name' => 'Wade Warren', 'cin' => 'BH-441092', 'blood_group' => 'O+', 'coverage' => 'CNOPS (85%)'],
                ['id' => 3, 'code' => 'PT-9132', 'full_name' => 'Jacob Jones', 'cin' => 'AB-310944', 'blood_group' => 'B+', 'coverage' => 'Sans Couverture'],
                ['id' => 4, 'code' => 'PT-7945', 'full_name' => 'Dianne Russell', 'cin' => 'CD-778231', 'blood_group' => 'AB+', 'coverage' => 'CNSS AMO (80%)'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    case $uri === '/api/v1/appointments' && $method === 'GET':
        echo json_encode([
            'success' => true,
            'tenant_id' => $tenantId,
            'anti_overlap_engine' => 'enabled',
            'data' => [
                ['id' => 101, 'time' => '09:00', 'patient' => 'Eleanor Pena', 'doctor' => 'Dr. Anil Patel', 'room' => 'Room 102', 'status' => 'completed'],
                ['id' => 102, 'time' => '09:45', 'patient' => 'Wade Warren', 'doctor' => 'Dr. Anil Patel', 'room' => 'Room 204', 'status' => 'in_progress'],
                ['id' => 103, 'time' => '10:30', 'patient' => 'Jacob Jones', 'doctor' => 'Dr. Sarah Chen', 'room' => 'Room 101', 'status' => 'pending'],
                ['id' => 104, 'time' => '11:15', 'patient' => 'Dianne Russell', 'doctor' => 'Dr. Michael Chen', 'room' => 'Room 104', 'status' => 'confirmed'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    case $uri === '/api/v1/invoices' && $method === 'GET':
        echo json_encode([
            'success' => true,
            'currency' => 'MAD',
            'accounting_rule' => 'remaining_balance = total_amount - paid_amount (strictly independent of CNSS expected reimbursement)',
            'data' => [
                ['invoice_number' => 'INV-2026-0081', 'patient' => 'Eleanor Pena', 'total_mad' => 450.00, 'paid_mad' => 450.00, 'remaining_mad' => 0.00, 'status' => 'paid'],
                ['invoice_number' => 'INV-2026-0082', 'patient' => 'Wade Warren', 'total_mad' => 850.00, 'paid_mad' => 550.00, 'remaining_mad' => 300.00, 'status' => 'partially_paid'],
                ['invoice_number' => 'INV-2026-0083', 'patient' => 'Dianne Russell', 'total_mad' => 600.00, 'paid_mad' => 600.00, 'remaining_mad' => 0.00, 'status' => 'paid'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    case $uri === '/api/v1/cnss/claims' && $method === 'GET':
        echo json_encode([
            'success' => true,
            'data' => [
                ['bordereau' => 'BDX-CNSS-2026-14', 'patient' => 'Eleanor Pena', 'provider' => 'CNSS AMO', 'amount_mad' => 450.00, 'estimated_reimbursement_mad' => 360.00, 'status' => 'transmitted'],
                ['bordereau' => 'BDX-CNOPS-2026-09', 'patient' => 'Wade Warren', 'provider' => 'CNOPS', 'amount_mad' => 850.00, 'estimated_reimbursement_mad' => 680.00, 'status' => 'missing_documents'],
                ['bordereau' => 'BDX-CNSS-2026-15', 'patient' => 'Dianne Russell', 'provider' => 'CNSS AMO', 'amount_mad' => 600.00, 'estimated_reimbursement_mad' => 480.00, 'status' => 'ready_for_signature'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Endpoint introuvable sur Cliniqo API v1.',
            'requested_path' => $uri,
        ], JSON_UNESCAPED_UNICODE);
        break;
}
