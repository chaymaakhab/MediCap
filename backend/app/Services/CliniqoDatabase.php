<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * CliniqoDatabase — MediCab Relational SQLite Engine (PDO)
 * Dedicated to independent medical practices in Morocco (Cabinets Médicaux).
 * Supports General Practice, Dentistry (Odontogram), and multi-specialty workflows.
 */
class CliniqoDatabase
{
    private static ?PDO $pdo = null;

    public const TODAY = '2026-10-29';

    public static function dbPath(): string
    {
        $dir = storage_path('app');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'cliniqo_saas_prod.sqlite';
    }

    public static function backupDir(): string
    {
        $dir = storage_path('backups');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = self::dbPath();
        $needsSeed = !file_exists($path) || filesize($path) < 4096;

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');

        self::$pdo = $pdo;

        self::migrate($pdo);
        if ($needsSeed || self::isEmpty($pdo)) {
            self::seedAll($pdo);
        }

        return $pdo;
    }

    public static function resetAll(): void
    {
        $pdo = self::connection();
        self::dropAll($pdo);
        self::migrate($pdo);
        self::seedAll($pdo);
    }

    public static function createBackup(string $actorName, ?int $tenantId = null): array
    {
        $pdo = self::connection();
        $pdo->exec('PRAGMA wal_checkpoint(FULL);');

        $stamp = date('Ymd_His');
        $filename = sprintf('medicab_backup_%s_%s.sqlite', $stamp, bin2hex(random_bytes(3)));
        $dest = self::backupDir() . DIRECTORY_SEPARATOR . $filename;

        $escapedDest = str_replace("'", "''", $dest);
        $pdo->exec("VACUUM INTO '{$escapedDest}';");

        if (!file_exists($dest)) {
            if (!copy(self::dbPath(), $dest)) {
                throw new RuntimeException('Échec de la création de la sauvegarde SQLite.');
            }
        }

        $checksum = hash_file('sha256', $dest) ?: '';
        $sizeBytes = (int) filesize($dest);

        $meta = [
            'filename' => $filename,
            'size_bytes' => $sizeBytes,
            'size_kb' => round($sizeBytes / 1024, 1),
            'sha256' => $checksum,
            'created_by' => $actorName,
            'tenant_scope' => $tenantId !== null ? "Cabinet #{$tenantId}" : 'Plateforme MediCab Globale',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        @file_put_contents($dest . '.meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $meta;
    }

    public static function listBackups(): array
    {
        $dir = self::backupDir();
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.sqlite') ?: [];
        $list = [];
        foreach ($files as $file) {
            $metaFile = $file . '.meta.json';
            if (file_exists($metaFile)) {
                $decoded = json_decode((string) file_get_contents($metaFile), true);
                if (is_array($decoded)) {
                    $list[] = $decoded;
                    continue;
                }
            }
            $size = (int) filesize($file);
            $list[] = [
                'filename' => basename($file),
                'size_bytes' => $size,
                'size_kb' => round($size / 1024, 1),
                'sha256' => hash_file('sha256', $file) ?: '',
                'created_by' => 'Système MediCab',
                'created_at' => date('Y-m-d H:i:s', (int) filemtime($file)),
            ];
        }
        usort($list, static fn(array $a, array $b): int => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return $list;
    }

    public static function restoreBackup(string $filename): void
    {
        $safe = basename($filename);
        $src = self::backupDir() . DIRECTORY_SEPARATOR . $safe;
        if (!file_exists($src) || !str_ends_with($safe, '.sqlite')) {
            throw new RuntimeException('Fichier de sauvegarde introuvable ou invalide.');
        }

        $fh = fopen($src, 'rb');
        $header = $fh ? fread($fh, 16) : '';
        if ($fh) {
            fclose($fh);
        }
        if (!str_starts_with((string) $header, 'SQLite format 3')) {
            throw new RuntimeException('Intégrité de l’archive SQLite invalide.');
        }

        self::$pdo = null;
        gc_collect_cycles();

        $dbPath = self::dbPath();
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');
        if (!copy($src, $dbPath)) {
            throw new RuntimeException('Impossible de restaurer la base de données.');
        }

        self::connection();
    }

    private static function isEmpty(PDO $pdo): bool
    {
        $cnt = (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
        return $cnt === 0;
    }

    private static function dropAll(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = OFF;');
        $tables = [
            'dental_treatment_plans', 'dental_teeth_chart', 'quote_items', 'quotes',
            'family_links', 'subscription_payments',
            'audit_logs', 'notifications', 'notification_preferences', 'documents',
            'inventory_movements', 'inventory_items', 'expenses', 'payments',
            'invoices', 'cash_sessions', 'labs', 'encounters', 'appointments',
            'patients', 'clinic_holidays', 'doctors', 'rooms', 'medical_services',
            'specialties', 'auth_tokens', 'user_branches', 'users', 'branches',
            'subscriptions', 'tenants', 'subscription_plans',
        ];
        foreach ($tables as $t) {
            $pdo->exec("DROP TABLE IF EXISTS {$t};");
        }
        $pdo->exec('PRAGMA foreign_keys = ON;');
    }

    private static function migrate(PDO $pdo): void
    {
        $ddl = <<<'SQL'
CREATE TABLE IF NOT EXISTS subscription_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    monthly_price_mad REAL NOT NULL DEFAULT 0,
    annual_price_mad REAL NOT NULL DEFAULT 0,
    max_users INTEGER NOT NULL DEFAULT 2,
    max_branches INTEGER NOT NULL DEFAULT 1,
    max_doctors INTEGER NOT NULL DEFAULT 1,
    storage_limit_mb INTEGER NOT NULL DEFAULT 2000,
    description TEXT NOT NULL DEFAULT '',
    enable_lab_feature INTEGER NOT NULL DEFAULT 1,
    enable_inventory_feature INTEGER NOT NULL DEFAULT 1,
    enable_portal_feature INTEGER NOT NULL DEFAULT 1,
    enable_multibranch_feature INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS tenants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    short_name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    responsible_doctor TEXT NOT NULL DEFAULT '',
    specialty_code TEXT NOT NULL DEFAULT 'general_practice',
    status TEXT NOT NULL DEFAULT 'active',
    city TEXT NOT NULL DEFAULT 'Casablanca',
    address TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    ice TEXT NOT NULL DEFAULT '',
    if_tax TEXT NOT NULL DEFAULT '',
    inpe TEXT NOT NULL DEFAULT '',
    cnss_affiliation TEXT NOT NULL DEFAULT '',
    logo_url TEXT NOT NULL DEFAULT 'assets/logo.svg',
    stamp_signature_url TEXT NOT NULL DEFAULT '',
    primary_language TEXT NOT NULL DEFAULT 'fr',
    timezone TEXT NOT NULL DEFAULT 'Africa/Casablanca',
    currency TEXT NOT NULL DEFAULT 'MAD',
    enable_laboratory INTEGER NOT NULL DEFAULT 1,
    enable_inventory INTEGER NOT NULL DEFAULT 1,
    enable_inpatient_beds INTEGER NOT NULL DEFAULT 0,
    total_beds INTEGER NOT NULL DEFAULT 0,
    occupied_beds INTEGER NOT NULL DEFAULT 0,
    default_slot_minutes INTEGER NOT NULL DEFAULT 30,
    work_day_start TEXT NOT NULL DEFAULT '08:30',
    work_day_end TEXT NOT NULL DEFAULT '18:00',
    allow_portal_booking INTEGER NOT NULL DEFAULT 1,
    invoice_prefix TEXT NOT NULL DEFAULT 'FAC',
    receipt_prefix TEXT NOT NULL DEFAULT 'REC',
    quote_prefix TEXT NOT NULL DEFAULT 'DEV',
    prescription_prefix TEXT NOT NULL DEFAULT 'ORD',
    certificate_prefix TEXT NOT NULL DEFAULT 'CERT',
    doc_header_note TEXT NOT NULL DEFAULT 'Cabinet Médical Agréé • Loi 131-13 • Conformité CNDP Loi 09-08',
    doc_footer_note TEXT NOT NULL DEFAULT 'Document médical confidentiel généré par MediCab SaaS.',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL UNIQUE,
    plan_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    is_trial INTEGER NOT NULL DEFAULT 0,
    started_at TEXT NOT NULL,
    trial_ends_at TEXT,
    expires_at TEXT NOT NULL,
    last_payment_date TEXT,
    last_payment_amount REAL NOT NULL DEFAULT 0,
    payment_method TEXT NOT NULL DEFAULT 'Virement Bancaire',
    billing_cycle TEXT NOT NULL DEFAULT 'monthly',
    auto_renew INTEGER NOT NULL DEFAULT 1,
    notes TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
);

CREATE TABLE IF NOT EXISTS subscription_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    plan_id INTEGER,
    amount_mad REAL NOT NULL,
    payment_date TEXT NOT NULL,
    payment_method TEXT NOT NULL DEFAULT 'Virement Bancaire',
    receipt_ref TEXT NOT NULL,
    period_start TEXT,
    period_end TEXT,
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS branches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    code TEXT NOT NULL,
    city TEXT NOT NULL DEFAULT 'Casablanca',
    address TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    specialties_summary TEXT NOT NULL DEFAULT '',
    is_main INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    avatar TEXT NOT NULL DEFAULT '',
    all_branches INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_branches (
    user_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, branch_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS auth_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash TEXT NOT NULL UNIQUE,
    token_type TEXT NOT NULL DEFAULT 'staff',
    user_id INTEGER,
    patient_id INTEGER,
    tenant_id INTEGER,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id INTEGER PRIMARY KEY,
    notify_appointments INTEGER NOT NULL DEFAULT 1,
    notify_schedule INTEGER NOT NULL DEFAULT 1,
    notify_payments INTEGER NOT NULL DEFAULT 1,
    notify_labs INTEGER NOT NULL DEFAULT 1,
    notify_stock INTEGER NOT NULL DEFAULT 1,
    notify_subscription INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS specialties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    pole TEXT NOT NULL,
    code TEXT NOT NULL DEFAULT '',
    is_active INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS medical_services (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    specialty TEXT NOT NULL,
    price_mad REAL NOT NULL,
    duration_min INTEGER NOT NULL DEFAULT 30,
    cnss_reimbursable INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rooms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    room_type TEXT NOT NULL DEFAULT 'Consultation',
    daily_capacity_slots INTEGER NOT NULL DEFAULT 16,
    is_active INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS doctors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    user_id INTEGER,
    name TEXT NOT NULL,
    specialty TEXT NOT NULL,
    pole TEXT NOT NULL DEFAULT 'Médecine Générale',
    room TEXT NOT NULL DEFAULT 'Cabinet 1',
    fee_mad REAL NOT NULL DEFAULT 350,
    duration_min INTEGER NOT NULL DEFAULT 30,
    inpe TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    avatar TEXT NOT NULL DEFAULT '',
    working_days TEXT NOT NULL DEFAULT 'Lun,Mar,Mer,Jeu,Ven,Sam',
    work_start TEXT NOT NULL DEFAULT '08:30',
    work_end TEXT NOT NULL DEFAULT '17:30',
    daily_slot_capacity INTEGER NOT NULL DEFAULT 16,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS clinic_holidays (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER,
    doctor_id INTEGER,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    label TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS patients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    first TEXT NOT NULL,
    last TEXT NOT NULL,
    cin TEXT NOT NULL,
    birth_date TEXT,
    age INTEGER NOT NULL DEFAULT 35,
    gender TEXT NOT NULL DEFAULT 'F',
    blood TEXT NOT NULL DEFAULT 'O+',
    phone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    address TEXT NOT NULL DEFAULT 'Casablanca, Maroc',
    emergency_contact TEXT NOT NULL DEFAULT '',
    family_group_id INTEGER,
    coverage TEXT NOT NULL DEFAULT 'CNSS AMO (80%)',
    department TEXT NOT NULL DEFAULT 'Médecine Générale',
    allergy TEXT NOT NULL DEFAULT 'Aucune connue',
    bp TEXT NOT NULL DEFAULT '120/80',
    spo2 TEXT NOT NULL DEFAULT '98.8%',
    hr TEXT NOT NULL DEFAULT '72 bpm',
    weight_kg REAL NOT NULL DEFAULT 70,
    height_cm REAL NOT NULL DEFAULT 172,
    temperature_c REAL NOT NULL DEFAULT 37.0,
    portal_pin_hash TEXT NOT NULL DEFAULT '',
    portal_enabled INTEGER NOT NULL DEFAULT 1,
    avatar TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS family_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    patient_id INTEGER NOT NULL,
    related_patient_id INTEGER NOT NULL,
    relation_type TEXT NOT NULL DEFAULT 'Conjoint',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (related_patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS appointments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    patient_id INTEGER NOT NULL,
    doctor_id INTEGER,
    doctor TEXT NOT NULL,
    specialty TEXT NOT NULL,
    service_name TEXT NOT NULL DEFAULT 'Consultation Cabinet',
    room TEXT NOT NULL DEFAULT 'Cabinet 1',
    date TEXT NOT NULL,
    time TEXT NOT NULL,
    duration_min INTEGER NOT NULL DEFAULT 30,
    status TEXT NOT NULL DEFAULT 'confirmed', -- pending, confirmed, arrived, in_consultation, completed, cancelled, no_show
    cancellation_reason TEXT NOT NULL DEFAULT '',
    checked_in_at TEXT,
    seen_at TEXT,
    fee_mad REAL NOT NULL DEFAULT 350,
    reason TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT 'clinic', -- clinic, phone, portal
    created_by_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_apt_tenant_date ON appointments(tenant_id, date);

CREATE TABLE IF NOT EXISTS encounters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    patient_id INTEGER NOT NULL,
    appointment_id INTEGER,
    doctor_name TEXT NOT NULL,
    pole TEXT NOT NULL,
    icd_code TEXT NOT NULL,
    clinical_text TEXT NOT NULL,
    bp TEXT NOT NULL DEFAULT '',
    spo2 TEXT NOT NULL DEFAULT '',
    hr TEXT NOT NULL DEFAULT '',
    temperature_c REAL DEFAULT 37.0,
    weight_kg REAL DEFAULT 70,
    encounter_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_enc_tenant_date ON encounters(tenant_id, encounter_date);

CREATE TABLE IF NOT EXISTS dental_teeth_chart (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    patient_id INTEGER NOT NULL,
    doctor_id INTEGER,
    tooth_number INTEGER NOT NULL, -- 11-48 (Permanent), 51-85 (Temporaire)
    condition TEXT NOT NULL DEFAULT 'healthy', -- healthy, caries, missing, restored, crown, implant, root_canal, extraction_planned, fractured
    surface TEXT NOT NULL DEFAULT '', -- O, M, D, V, L
    notes TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    UNIQUE(tenant_id, patient_id, tooth_number)
);

CREATE TABLE IF NOT EXISTS dental_treatment_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    patient_id INTEGER NOT NULL,
    doctor_id INTEGER,
    tooth_number INTEGER,
    procedure_name TEXT NOT NULL,
    estimated_cost_mad REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'planned', -- planned, in_progress, completed
    treatment_date TEXT,
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS labs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    patient_id INTEGER NOT NULL,
    patient_name TEXT NOT NULL,
    test_name TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT 'Biologie Médicale',
    value TEXT NOT NULL DEFAULT 'En cours d''analyse',
    reference TEXT NOT NULL DEFAULT 'Normes biologiques',
    priority TEXT NOT NULL DEFAULT 'Routine',
    status TEXT NOT NULL DEFAULT 'pending',
    ordered_by TEXT NOT NULL DEFAULT 'Dr. Jackson',
    validated_by TEXT NOT NULL DEFAULT '',
    ordered_date TEXT NOT NULL,
    completed_date TEXT,
    timestamp TEXT NOT NULL DEFAULT '09:00',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cash_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    session_code TEXT NOT NULL,
    opened_by TEXT NOT NULL,
    opened_date TEXT NOT NULL,
    opening_balance REAL NOT NULL DEFAULT 1000,
    closing_balance REAL,
    counted_balance REAL,
    difference REAL NOT NULL DEFAULT 0,
    closed_by TEXT,
    closed_date TEXT,
    status TEXT NOT NULL DEFAULT 'open', -- open, closed
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS quotes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL DEFAULT 1,
    quote_num TEXT NOT NULL,
    patient_id INTEGER NOT NULL,
    patient_name TEXT NOT NULL,
    doctor_name TEXT NOT NULL DEFAULT '',
    issued_date TEXT NOT NULL,
    valid_until TEXT,
    subtotal REAL NOT NULL DEFAULT 0,
    discount REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft', -- draft, sent, accepted, rejected, converted
    converted_invoice_id INTEGER,
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS quote_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quote_id INTEGER NOT NULL,
    description TEXT NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1,
    unit_price REAL NOT NULL DEFAULT 0,
    total_price REAL NOT NULL DEFAULT 0,
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    num TEXT NOT NULL,
    patient_id INTEGER NOT NULL,
    patient_name TEXT NOT NULL,
    appointment_id INTEGER,
    doctor_name TEXT NOT NULL DEFAULT '',
    specialty TEXT NOT NULL DEFAULT '',
    service TEXT NOT NULL,
    issued_date TEXT NOT NULL,
    total REAL NOT NULL,
    paid REAL NOT NULL DEFAULT 0,
    refunded REAL NOT NULL DEFAULT 0,
    method TEXT NOT NULL DEFAULT 'Espèces',
    status TEXT NOT NULL DEFAULT 'unpaid', -- draft, unpaid, partial, paid, cancelled
    cnss_org TEXT NOT NULL DEFAULT 'CNSS AMO',
    cnss_est REAL NOT NULL DEFAULT 0,
    cnss_status TEXT NOT NULL DEFAULT 'Dossier prêt', -- Dossier prêt, Déposé, En cours, Rejeté, Remboursé au patient
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_inv_tenant_date ON invoices(tenant_id, issued_date);

CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    invoice_id INTEGER NOT NULL,
    cash_session_id INTEGER,
    receipt_num TEXT NOT NULL,
    amount REAL NOT NULL,
    payment_type TEXT NOT NULL DEFAULT 'payment',
    method TEXT NOT NULL DEFAULT 'Espèces',
    paid_date TEXT NOT NULL,
    paid_time TEXT NOT NULL DEFAULT '10:00',
    status TEXT NOT NULL DEFAULT 'valid',
    recorded_by TEXT NOT NULL DEFAULT 'Caisse Cabinet',
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_pay_tenant_date ON payments(tenant_id, paid_date);

CREATE TABLE IF NOT EXISTS expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    cash_session_id INTEGER,
    reference TEXT NOT NULL,
    category TEXT NOT NULL,
    amount REAL NOT NULL,
    expense_date TEXT NOT NULL,
    description TEXT NOT NULL,
    supplier TEXT NOT NULL DEFAULT '',
    supporting_doc_ref TEXT NOT NULL DEFAULT '',
    payment_method TEXT NOT NULL DEFAULT 'Virement',
    created_by TEXT NOT NULL DEFAULT 'Administration Cabinet',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_exp_tenant_date ON expenses(tenant_id, expense_date);

CREATE TABLE IF NOT EXISTS inventory_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    sku TEXT NOT NULL,
    name TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT 'Consommables',
    unit TEXT NOT NULL DEFAULT 'Boîte',
    quantity INTEGER NOT NULL DEFAULT 0,
    min_threshold INTEGER NOT NULL DEFAULT 10,
    unit_cost_mad REAL NOT NULL DEFAULT 0,
    supplier TEXT NOT NULL DEFAULT '',
    expiry_date TEXT,
    batch_number TEXT,
    last_movement_date TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS inventory_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    movement_type TEXT NOT NULL, -- entry, exit, adjustment
    quantity INTEGER NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    supplier_or_ref TEXT NOT NULL DEFAULT '',
    created_by TEXT NOT NULL DEFAULT '',
    movement_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    patient_id INTEGER,
    doctor_name TEXT NOT NULL DEFAULT 'Dr. Jackson',
    doc_type TEXT NOT NULL,
    reference TEXT NOT NULL,
    title TEXT NOT NULL,
    body_text TEXT NOT NULL DEFAULT '',
    amount_mad REAL NOT NULL DEFAULT 0,
    issued_date TEXT NOT NULL,
    shared_with_patient INTEGER NOT NULL DEFAULT 0,
    created_by TEXT NOT NULL DEFAULT 'Dr. Jackson',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    branch_id INTEGER,
    target_role TEXT,
    patient_id INTEGER,
    category TEXT NOT NULL,
    severity TEXT NOT NULL DEFAULT 'info',
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    action_tab TEXT NOT NULL DEFAULT 'overview',
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    branch_id INTEGER,
    user_id INTEGER,
    actor_name TEXT NOT NULL,
    actor_role TEXT NOT NULL,
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_ref TEXT NOT NULL,
    details TEXT NOT NULL DEFAULT '',
    ip_address TEXT NOT NULL DEFAULT '127.0.0.1',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_tenant ON audit_logs(tenant_id, id DESC);
SQL;
        $pdo->exec($ddl);
    }

    private static function seedAll(PDO $pdo): void
    {
        $pdo->beginTransaction();
        try {
            $today = self::TODAY;

            // 1. Subscription Plans (3 Plans Configurables MediCab)
            $plans = [
                ['starter', 'Pack Starter — Cabinet Solo', 490, 4900, 2, 1, 1, 1000, 'Gestion essentielle : 1 praticien, 2 utilisateurs, dossiers patients, agenda et facturation simple.', 1, 0, 0, 0],
                ['pro', 'Pack Professionnel — Multi-Praticiens', 990, 9900, 6, 1, 3, 3000, "Idéal cabinets en croissance : jusqu'à 3 médecins, 6 utilisateurs, devis, stock, suivi CNSS/AMO et rapports complets.", 1, 1, 1, 0],
                ['premium', 'Pack Premium — Cabinet & Centre', 1690, 16900, 20, 1, 10, 10000, "Toutes options incluses : jusqu'à 10 praticiens, Odontogramme dentaire, portail patient, sauvegardes automatiques.", 1, 1, 1, 0],
            ];
            $stPlan = $pdo->prepare('INSERT INTO subscription_plans (code, name, monthly_price_mad, annual_price_mad, max_users, max_branches, max_doctors, storage_limit_mb, description, enable_lab_feature, enable_inventory_feature, enable_portal_feature, enable_multibranch_feature) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($plans as $pl) {
                $stPlan->execute($pl);
            }

            // 2. Cabinets Médicaux (Tenants Indépendants)
            $stTenant = $pdo->prepare('INSERT INTO tenants (
                name, short_name, slug, responsible_doctor, specialty_code, status, city, address, phone, email, ice, if_tax, inpe, cnss_affiliation,
                logo_url, currency, enable_laboratory, enable_inventory, enable_inpatient_beds, total_beds, occupied_beds,
                invoice_prefix, receipt_prefix, quote_prefix, prescription_prefix, certificate_prefix, doc_header_note, doc_footer_note
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

            // Cabinet 1 : Médecine Générale
            $stTenant->execute([
                'Cabinet Médical Zerktouni', 'Cabinet Zerktouni', 'cabinet-zerktouni',
                'Dr. Jackson', 'general_practice', 'active', 'Casablanca',
                '142 Boulevard Zerktouni, Quartier Maarif, 20100 Casablanca', '+212 522-984410', 'contact@cabinet-zerktouni.ma',
                '002849102000047', '45892011', 'INPE-01004429', 'CNSS-884120',
                'assets/logo.svg', 'MAD', 1, 1, 0, 0, 0,
                'FAC', 'REC', 'DEV', 'ORD', 'CERT',
                'Cabinet de Médecine Générale Agréé • Loi 131-13 • CNDP',
                'Cabinet Médical Zerktouni • Tél: +212 522-984410 • Casablanca'
            ]); // id=1

            // Cabinet 2 : Cabinet Dentaire Dr. Alami
            $stTenant->execute([
                'Cabinet Dentaire Dr. Alami', 'Cabinet Dentaire', 'cabinet-dentaire-alami',
                'Dr. Youssef Alami', 'dentistry', 'active', 'Rabat',
                '24 Avenue de France, Agdal, 10090 Rabat', '+212 537-772211', 'contact@cabinet-dentaire-alami.ma',
                '003119842000055', '51203499', 'INPE-02008814', 'CNSS-992144',
                'assets/logo.svg', 'MAD', 0, 1, 0, 0, 0,
                'FAC', 'REC', 'DEV', 'ORD', 'CERT',
                'Cabinet de Chirurgie Dentaire & Implantologie Agréé • Loi 131-13',
                'Cabinet Dentaire Dr. Alami • Agdal Rabat • Tél: +212 537-772211'
            ]); // id=2

            // Cabinet 3 : Cabinet Pédiatrique Anfa (Essai gratuit 30j)
            $stTenant->execute([
                'Cabinet Pédiatrique Anfa', 'Pédiatrie Anfa', 'pediatrie-anfa',
                'Dr. Amina Tazi', 'pediatrics', 'trial', 'Casablanca',
                "88 Boulevard d'Anfa, 20050 Casablanca", '+212 522-203040', 'contact@pediatrie-anfa.ma',
                '001928374000012', '38920144', 'INPE-03001155', 'CNSS-771233',
                'assets/logo.svg', 'MAD', 0, 1, 0, 0, 0,
                'FAC', 'REC', 'DEV', 'ORD', 'CERT',
                'Cabinet Médical de Pédiatrie • Loi 131-13',
                'Cabinet Pédiatrique Anfa • Casablanca'
            ]); // id=3

            // 3. Subscriptions & Payment History
            $stSub = $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan_id, status, is_trial, started_at, trial_ends_at, expires_at, last_payment_date, last_payment_amount, payment_method, billing_cycle, auto_renew, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stSub->execute([1, 2, 'active', 0, '2026-01-01', null, '2026-12-31', '2026-10-01', 990, 'Virement Attijariwafa', 'monthly', 1, 'Contrat Actif Cabinet Zerktouni']);
            $stSub->execute([2, 3, 'active', 0, '2026-02-01', null, '2027-01-31', '2026-10-01', 16900, 'Virement Banque Populaire', 'annual', 1, 'Abonnement Annuel Premium Cabinet Dentaire']);
            $stSub->execute([3, 1, 'trialing', 1, '2026-10-15', '2026-11-14', '2026-11-14', null, 0, 'Essai Gratuit 30 Jours', 'monthly', 0, 'Essai 30j en cours (expire le 14 Nov)']);

            $stSubPay = $pdo->prepare('INSERT INTO subscription_payments (tenant_id, plan_id, amount_mad, payment_date, payment_method, receipt_ref, period_start, period_end, notes) VALUES (?,?,?,?,?,?,?,?,?)');
            $stSubPay->execute([1, 2, 990, '2026-10-01', 'Virement Attijariwafa', 'MEDICAB-REC-2026-001', '2026-10-01', '2026-10-31', 'Mensualité Octobre 2026']);
            $stSubPay->execute([2, 3, 16900, '2026-02-01', 'Virement Banque Populaire', 'MEDICAB-REC-2026-002', '2026-02-01', '2027-01-31', 'Paiement Annuel Premium 2026/2027']);

            // 4. Branches (1 par cabinet pour le modèle mono-cabinet)
            $stBranch = $pdo->prepare('INSERT INTO branches (tenant_id, name, code, city, address, phone, email, specialties_summary, is_main, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stBranch->execute([1, 'Cabinet Zerktouni', 'CAB-MAARIF', 'Casablanca', '142 Bd Zerktouni, Maarif, Casablanca', '+212 522-984410', 'contact@cabinet-zerktouni.ma', 'Médecine Générale', 1, 1]); // id=1
            $stBranch->execute([2, 'Cabinet Dentaire Agdal', 'DENT-AGDAL', 'Rabat', '24 Avenue de France, Agdal, Rabat', '+212 537-772211', 'contact@cabinet-dentaire-alami.ma', 'Chirurgie Dentaire & Implantologie', 1, 1]); // id=2
            $stBranch->execute([3, 'Cabinet Pédiatrique Anfa', 'PED-ANFA', 'Casablanca', "88 Bd d'Anfa, Casablanca", '+212 522-203040', 'contact@pediatrie-anfa.ma', 'Pédiatrie & Néonatalogie', 1, 1]); // id=3

            // 5. Users (Owner, Admin Cabinet 1, Docteur Cabinet 1, Dentiste Cabinet 2)
            $pwHash = password_hash('Cliniqo2026!', PASSWORD_BCRYPT);
            $stUser = $pdo->prepare('INSERT INTO users (tenant_id, name, email, password_hash, role, title, phone, avatar, all_branches, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)');

            // User 1: Dr. Jackson (SaaS Owner — Super-Admin MediCab, tenant_id = null)
            $stUser->execute([null, 'Dr. Jackson', 'owner@medicab.ma', $pwHash, 'owner', 'Propriétaire SaaS MediCab (Super-Admin)', '+212 661-001122', 'https://images.unsplash.com/photo-1612349317150-e413f6a5b16d?w=120', 1, 1]); // id=1
            // User 2: Sarah Benali (Admin Cabinet 1)
            $stUser->execute([1, 'Sarah Benali', 'admin@medicab.ma', $pwHash, 'admin', 'Gestionnaire Administrative (Admin)', '+212 662-445511', 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=120', 1, 1]); // id=2
            // User 3: Dr. Alimi Jan (Docteur MG Cabinet 1)
            $stUser->execute([1, 'Dr. Alimi Jan', 'docteur@medicab.ma', $pwHash, 'docteur', 'Médecin Généraliste & Praticien', '+212 661-102030', 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=120', 1, 1]); // id=3
            // User 4: Dr. Youssef Alami (Chirurgien-Dentiste Cabinet 2)
            $stUser->execute([2, 'Dr. Youssef Alami', 'dentiste@medicab.ma', $pwHash, 'docteur', 'Chirurgien-Dentiste (Docteur)', '+212 661-443322', 'https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=120', 1, 1]); // id=4

            $stUb = $pdo->prepare('INSERT INTO user_branches (user_id, branch_id) VALUES (?,?)');
            $stUb->execute([2, 1]);
            $stUb->execute([3, 1]);
            $stUb->execute([4, 2]);

            $stPref = $pdo->prepare('INSERT INTO notification_preferences (user_id) VALUES (?)');
            for ($u = 1; $u <= 4; $u++) {
                $stPref->execute([$u]);
            }

            // 6. Seed Operational Data for Tenant 1 (Cabinet de Médecine Générale)
            self::seedGeneralPracticeCabinet($pdo, 1, 1);

            // 7. Seed Operational Data for Tenant 2 (Cabinet Dentaire)
            self::seedDentalCabinet($pdo, 2, 2);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function seedGeneralPracticeCabinet(PDO $pdo, int $tenantId, int $branchId): void
    {
        $today = self::TODAY;

        // Specialties & Services
        $pdo->prepare('INSERT INTO specialties (tenant_id, name, pole, code) VALUES (?,?,?,?)')
            ->execute([$tenantId, 'Médecine Générale', 'Médecine Générale', 'MG']);

        $services = [
            ['CONS-MG', 'Consultation Médecine Générale', 'Médecine Générale', 350, 25, 1],
            ['CONS-URG', 'Consultation Urgente / Sans RDV', 'Médecine Générale', 450, 20, 1],
            ['ECG-CAB', 'Électrocardiogramme de repos (ECG)', 'Médecine Générale', 200, 15, 1],
            ['CERT-MED', 'Certificat Médical & Aptitude', 'Médecine Générale', 250, 15, 0],
            ['INJ-SOINS', 'Injection & Soins Infirmiers', 'Médecine Générale', 100, 10, 1],
        ];
        $stSrv = $pdo->prepare('INSERT INTO medical_services (tenant_id, code, name, specialty, price_mad, duration_min, cnss_reimbursable) VALUES (?,?,?,?,?,?,?)');
        foreach ($services as $srv) {
            $stSrv->execute([$tenantId, $srv[0], $srv[1], $srv[2], $srv[3], $srv[4], $srv[5]]);
        }

        // Room & Doctor
        $pdo->prepare('INSERT INTO rooms (tenant_id, branch_id, name, room_type, daily_capacity_slots) VALUES (?,?,?,?,?)')
            ->execute([$tenantId, $branchId, 'Cabinet 1 — Consultation', 'Consultation', 16]);

        $pdo->prepare('INSERT INTO doctors (tenant_id, branch_id, user_id, name, specialty, pole, room, fee_mad, inpe, phone, email, avatar) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $tenantId, $branchId, 3, 'Dr. Alimi Jan', 'Médecine Générale', 'Médecine Générale',
                'Cabinet 1 — Consultation', 350, 'INPE-01004429', '+212 661-102030',
                'docteur@medicab.ma', 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=120'
            ]);

        // Patients (avec lien familial pour démo : Fatima + Karim = Époux)
        $patients = [
            ['PAT-2026-001', 'Fatima', 'Zahra Mansouri', 'BE892144', '1988-04-12', 38, 'F', 'O+', '+212 661-458920', 'fatima.mansouri@gmail.com', '14 Rue Sebta, Maarif, Casablanca', 'CNSS AMO (80%)', 'Médecine Générale', 'Pénicilline', '125/82', '98.5%', '74 bpm', 64, 165, 36.8],
            ['PAT-2026-002', 'Karim', 'Mansouri', 'BE774120', '1984-09-22', 42, 'M', 'A+', '+212 661-778899', 'karim.mansouri@gmail.com', '14 Rue Sebta, Maarif, Casablanca', 'CNSS AMO (80%)', 'Médecine Générale', 'Aucune', '130/85', '99.0%', '68 bpm', 82, 178, 37.0],
            ['PAT-2026-003', 'Youssef', 'Berrada', 'BK551200', '1976-11-05', 50, 'M', 'B+', '+212 662-334455', 'youssef.berrada@menara.ma', '45 Bd Massira Khadra, Casablanca', 'CNOPS (80%)', 'Médecine Générale', 'Aspirine', '140/90', '97.8%', '78 bpm', 88, 175, 37.2],
            ['PAT-2026-004', 'Amina', 'El Fassi', 'BL994100', '1995-02-18', 31, 'F', 'AB+', '+212 663-112233', 'amina.elfassi@outlook.com', '8 Bd Ghandi, Casablanca', 'CNSS AMO (80%)', 'Médecine Générale', 'Aucune', '118/75', '99.2%', '70 bpm', 58, 168, 36.7],
            ['PAT-2026-005', 'Omar', 'Kabbaj', 'BK667822', '1962-07-30', 64, 'M', 'O-', '+212 664-998877', 'omar.kabbaj@gmail.com', '12 Rue Normandie, Casablanca', 'Saham / Sanlam (90%)', 'Médecine Générale', 'Sulfamides', '135/88', '98.0%', '76 bpm', 79, 170, 36.9],
        ];

        $stPat = $pdo->prepare('INSERT INTO patients (tenant_id, branch_id, code, first, last, cin, birth_date, age, gender, blood, phone, email, address, coverage, department, allergy, bp, spo2, hr, weight_kg, height_cm, temperature_c) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($patients as $p) {
            $stPat->execute(array_merge([$tenantId, $branchId], $p));
        }

        // Family Link : Fatima (ID 1) et Karim (ID 2)
        $pdo->prepare('INSERT INTO family_links (tenant_id, patient_id, related_patient_id, relation_type) VALUES (?,?,?,?)')
            ->execute([$tenantId, 1, 2, 'Époux']);
        $pdo->prepare('INSERT INTO family_links (tenant_id, patient_id, related_patient_id, relation_type) VALUES (?,?,?,?)')
            ->execute([$tenantId, 2, 1, 'Épouse']);

        // Appointments Today & This Week
        $apts = [
            [1, 1, 'Dr. Alimi Jan', 'Médecine Générale', 'Consultation Médecine Générale', 'Cabinet 1', $today, '09:00', 30, 'completed', 350, 'Contrôle tensionnel et renouvellement ordonnance'],
            [2, 1, 'Dr. Alimi Jan', 'Médecine Générale', 'Consultation Médecine Générale', 'Cabinet 1', $today, '09:45', 30, 'in_consultation', 350, 'Bilan annuel et fatigue persistante'],
            [3, 1, 'Dr. Alimi Jan', 'Médecine Générale', 'Consultation Médecine Générale', 'Cabinet 1', $today, '10:30', 30, 'arrived', 350, 'Suivi diabète type 2 et glycémie'],
            [4, 1, 'Dr. Alimi Jan', 'Médecine Générale', 'Consultation Médecine Générale', 'Cabinet 1', $today, '11:15', 30, 'confirmed', 350, 'Douleurs abdominales aiguës'],
            [5, 1, 'Dr. Alimi Jan', 'Médecine Générale', 'Consultation Médecine Générale', 'Cabinet 1', $today, '15:00', 30, 'confirmed', 350, 'Contrôle post-traitement antibiotique'],
        ];
        $stApt = $pdo->prepare('INSERT INTO appointments (tenant_id, branch_id, patient_id, doctor_id, doctor, specialty, service_name, room, date, time, duration_min, status, fee_mad, reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($apts as $a) {
            $stApt->execute(array_merge([$tenantId, $branchId], $a));
        }

        // Encounters / Consultation Clinical Notes (Versioned / Auditable)
        $pdo->prepare('INSERT INTO encounters (tenant_id, branch_id, patient_id, appointment_id, doctor_name, pole, icd_code, clinical_text, bp, spo2, hr, temperature_c, weight_kg, encounter_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $tenantId, $branchId, 1, 1, 'Dr. Alimi Jan', 'Médecine Générale', 'BA00 (Hypertension essentielle)',
                'Patiente vue en consultation de contrôle. Pas de céphalées ni vertiges. Auscultation cardio-pulmonaire normale. B1-B2 réguliers, pas de souffle. Bon contrôle tensionnel sous Amlodipine 5mg. Reconduction du traitement pour 3 mois.',
                '125/82', '98.5%', '74 bpm', 36.8, 64, $today
            ]);

        // Invoices & Payments (with Partial Payment Demo)
        $pdo->prepare('INSERT INTO invoices (tenant_id, branch_id, num, patient_id, patient_name, appointment_id, doctor_name, specialty, service, issued_date, total, paid, status, method, cnss_org, cnss_est, cnss_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $tenantId, $branchId, 'FAC-2026-001', 1, 'Fatima Zahra Mansouri', 1, 'Dr. Alimi Jan', 'Médecine Générale',
                'Consultation Médecine Générale', $today, 350, 350, 'paid', 'Espèces', 'CNSS AMO', 280, 'Dossier déposé'
            ]);
        $inv1Id = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO payments (tenant_id, branch_id, invoice_id, receipt_num, amount, payment_type, method, paid_date, paid_time, status, recorded_by, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $tenantId, $branchId, $inv1Id, 'REC-2026-001', 350, 'payment', 'Espèces', $today, '09:35', 'valid', 'Sarah Benali', 'Règlement total consultation'
            ]);

        // Invoice 2 : Facture Partiellement Payée (Démonstration Solde Restant)
        // Total = 550 MAD (Consultation + ECG), Payé = 300 MAD, Reste = 250 MAD
        $pdo->prepare('INSERT INTO invoices (tenant_id, branch_id, num, patient_id, patient_name, appointment_id, doctor_name, specialty, service, issued_date, total, paid, status, method, cnss_org, cnss_est, cnss_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $tenantId, $branchId, 'FAC-2026-002', 3, 'Youssef Berrada', 3, 'Dr. Alimi Jan', 'Médecine Générale',
                'Consultation MG + Électrocardiogramme ECG', $today, 550, 300, 'partial', 'TPE Carte Bancaire', 'CNOPS', 440, 'Dossier prêt'
            ]);
        $inv2Id = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO payments (tenant_id, branch_id, invoice_id, receipt_num, amount, payment_type, method, paid_date, paid_time, status, recorded_by, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $tenantId, $branchId, $inv2Id, 'REC-2026-002', 300, 'payment', 'TPE Carte Bancaire', $today, '10:45', 'valid', 'Sarah Benali', 'Acompte versé. Solde restant : 250 MAD'
            ]);

        // Caisse Session Active
        $pdo->prepare('INSERT INTO cash_sessions (tenant_id, branch_id, session_code, opened_by, opened_date, opening_balance, status, notes) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$tenantId, $branchId, 'CAISSE-2026-10-29', 'Sarah Benali', $today, 1000, 'open', 'Session de caisse du jour ouverte à 08:30']);

        // Stock Médical Consommables
        $stockItems = [
            ['STK-SER-01', 'Seringues stériles 5ml (Boîte de 100)', 'Consommables', 'Boîte', 24, 10, 85, 'Promamec Maroc', '2027-12-31', 'LOT-2024-991'],
            ['STK-GNT-02', "Gants d'examen nitrile Taille M (Boîte de 100)", 'Protection', 'Boîte', 8, 12, 65, 'Promamec Maroc', '2028-06-30', 'LOT-2025-102'], // Alerte stock bas !
            ['STK-CMP-03', 'Compresses stériles 7.5x7.5 (Paquet de 50)', 'Pansements', 'Paquet', 45, 15, 35, 'Cooper Pharma', '2027-09-15', 'LOT-2024-442'],
            ['STK-BET-04', 'Bétadine dermique 10% Flacon 500ml', 'Antiseptiques', 'Flacon', 6, 4, 48, 'Sothema', '2026-12-31', 'LOT-2023-881'],
            ['STK-ECG-05', 'Rouleaux papier thermique ECG 50mm', 'Diagnostic', 'Rouleau', 15, 5, 25, 'Médical Casablanca', '2029-01-01', 'LOT-2025-001'],
        ];
        $stStk = $pdo->prepare('INSERT INTO inventory_items (tenant_id, branch_id, sku, name, category, unit, quantity, min_threshold, unit_cost_mad, supplier, expiry_date, batch_number, last_movement_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($stockItems as $it) {
            $stStk->execute(array_merge([$tenantId, $branchId], $it, [$today]));
        }

        // Devis / Quote (Exemple Devis Bilan de Santé Complet)
        $pdo->prepare('INSERT INTO quotes (tenant_id, branch_id, quote_num, patient_id, patient_name, doctor_name, issued_date, valid_until, subtotal, discount, total, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $tenantId, $branchId, 'DEV-2026-001', 5, 'Omar Kabbaj', 'Dr. Alimi Jan', $today,
                date('Y-m-d', strtotime("{$today} +30 days")), 850, 50, 800, 'sent',
                'Devis bilan complet prévention cardiovasculaire & dépistage'
            ]);
        $quoteId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO quote_items (quote_id, description, quantity, unit_price, total_price) VALUES (?,?,?,?,?)')
            ->execute([$quoteId, 'Consultation Approfondie & Facteurs de risque', 1, 350, 350]);
        $pdo->prepare('INSERT INTO quote_items (quote_id, description, quantity, unit_price, total_price) VALUES (?,?,?,?,?)')
            ->execute([$quoteId, 'Électrocardiogramme ECG 12 dérivations', 1, 200, 200]);
        $pdo->prepare('INSERT INTO quote_items (quote_id, description, quantity, unit_price, total_price) VALUES (?,?,?,?,?)')
            ->execute([$quoteId, 'Bilan biologique standard prescrit', 1, 300, 300]);
    }

    private static function seedDentalCabinet(PDO $pdo, int $tenantId, int $branchId): void
    {
        $today = self::TODAY;

        // Specialty & Services Dentaires
        $pdo->prepare('INSERT INTO specialties (tenant_id, name, pole, code) VALUES (?,?,?,?)')
            ->execute([$tenantId, 'Chirurgie Dentaire', 'Dentisterie', 'DENT']);

        $services = [
            ['CONS-DENT', 'Consultation & Bilan Bucco-Dentaire', 'Chirurgie Dentaire', 300, 30, 1],
            ['DETART', 'Détartrage & Polissage Bimaxillaire', 'Chirurgie Dentaire', 500, 45, 1],
            ['COMP-1F', 'Obturation Composite 1 face', 'Chirurgie Dentaire', 400, 30, 1],
            ['COMP-2F', 'Obturation Composite 2 faces / Esthétique', 'Chirurgie Dentaire', 600, 45, 1],
            ['ENDO-MOL', 'Traitement Endodontique (Dévitalisation Molaire)', 'Chirurgie Dentaire', 950, 60, 1],
            ['COUR-CER', 'Couronne Céramo-Métallique', 'Chirurgie Dentaire', 2500, 60, 1],
            ['COUR-ZIRC', 'Couronne Zircone Haute Esthétique', 'Chirurgie Dentaire', 3500, 60, 0],
            ['EXTR-SMP', 'Extraction Dentaire Simple', 'Chirurgie Dentaire', 450, 30, 1],
            ['IMPL-TIT', 'Implant Dentaire Titane Grade V', 'Chirurgie Dentaire', 8000, 90, 0],
        ];
        $stSrv = $pdo->prepare('INSERT INTO medical_services (tenant_id, code, name, specialty, price_mad, duration_min, cnss_reimbursable) VALUES (?,?,?,?,?,?,?)');
        foreach ($services as $srv) {
            $stSrv->execute([$tenantId, $srv[0], $srv[1], $srv[2], $srv[3], $srv[4], $srv[5]]);
        }

        // Room & Doctor (Chirurgien-Dentiste)
        $pdo->prepare('INSERT INTO rooms (tenant_id, branch_id, name, room_type, daily_capacity_slots) VALUES (?,?,?,?,?)')
            ->execute([$tenantId, $branchId, 'Fauteuil Dentaire 1 (Adec 500)', 'Dentisterie', 12]);

        $pdo->prepare('INSERT INTO doctors (tenant_id, branch_id, user_id, name, specialty, pole, room, fee_mad, inpe, phone, email, avatar) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $tenantId, $branchId, 4, 'Dr. Youssef Alami', 'Chirurgien-Dentiste', 'Dentisterie',
                'Fauteuil Dentaire 1 (Adec 500)', 400, 'INPE-02008814', '+212 661-443322',
                'dentiste@medicab.ma', 'https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=120'
            ]);

        // Patients Dentaires
        $dentalPatients = [
            ['DENT-PAT-001', 'Hamza', 'Chraibi', 'A784120', '1992-06-15', 34, 'M', 'O+', '+212 661-889900', 'hamza.chraibi@gmail.com', '12 Rue Oukaimeden, Agdal, Rabat', 'CNSS AMO (80%)', 'Chirurgie Dentaire', 'Aucune', '120/80'],
            ['DENT-PAT-002', 'Leila', 'Bennani', 'A991204', '1990-10-28', 36, 'F', 'A+', '+212 662-771122', 'leila.bennani@yahoo.fr', '55 Av Mehdi Ben Barka, Rabat', 'Wafa Assurance (85%)', 'Chirurgie Dentaire', 'Latex', '115/75'],
            ['DENT-PAT-003', 'Taha', 'Idrissi', 'A665511', '1982-01-19', 44, 'M', 'B+', '+212 663-554433', 'taha.idrissi@gmail.com', '18 Rue Oued Fès, Rabat', 'CNSS AMO (80%)', 'Chirurgie Dentaire', 'Aucune', '128/82'],
        ];
        $stPat = $pdo->prepare('INSERT INTO patients (tenant_id, branch_id, code, first, last, cin, birth_date, age, gender, blood, phone, email, address, coverage, department, allergy, bp) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($dentalPatients as $dp) {
            $stPat->execute(array_merge([$tenantId, $branchId], $dp));
        }

        // Dentisterie : Odontogramme FDI (Patient 1 : Hamza Chraibi)
        // Dents permanentes : 11-18, 21-28, 31-38, 41-48
        $teethStates = [
            [16, 'caries', 'O', 'Carie occlusale profonde sur molaire 16'],
            [21, 'restored', 'M', 'Obturation composite face mésiale'],
            [26, 'root_canal', 'O', 'Dévitalisation et obturation canalaire satisfaisante'],
            [36, 'crown', 'ALL', 'Couronne céramo-métallique scellée en 2024'],
            [46, 'missing', '', 'Dent absente (extraction ancienne)'],
            [47, 'caries', 'O', 'Carie débutante sillon occlusal'],
        ];
        $stTooth = $pdo->prepare('INSERT INTO dental_teeth_chart (tenant_id, patient_id, doctor_id, tooth_number, condition, surface, notes) VALUES (?,?,?,?,?,?,?)');
        foreach ($teethStates as $ts) {
            $stTooth->execute([$tenantId, 6, 4, $ts[0], $ts[1], $ts[2], $ts[3]]); // patient_id = 6 (premier patient de ce cabinet)
        }

        // Plan de Traitement Dentaire (Treatment Plan)
        $plans = [
            [6, 4, 16, 'Traitement Endodontique & Obturation 16', 950, 'in_progress', $today, 'Séance 1 : ouverture de chambre et alésage effectuée'],
            [6, 4, 16, 'Pose de Couronne Céramo-Métallique 16', 2500, 'planned', null, 'À réaliser après obturation canalaire définitive'],
            [6, 4, 47, 'Composite photopolymérisable dent 47', 400, 'planned', null, 'Traitement conservateur molaire droite'],
            [6, 4, 46, 'Implant Titane & Couronne sur Implant 46', 10500, 'planned', null, 'Plan de réhabilitation molaire inférieure'],
        ];
        $stPlan = $pdo->prepare('INSERT INTO dental_treatment_plans (tenant_id, patient_id, doctor_id, tooth_number, procedure_name, estimated_cost_mad, status, treatment_date, notes) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($plans as $pl) {
            $stPlan->execute(array_merge([$tenantId], $pl));
        }

        // Appointments Dentaires
        $stApt = $pdo->prepare('INSERT INTO appointments (tenant_id, branch_id, patient_id, doctor_id, doctor, specialty, service_name, room, date, time, duration_min, status, fee_mad, reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stApt->execute([$tenantId, $branchId, 6, 4, 'Dr. Youssef Alami', 'Chirurgien-Dentiste', 'Traitement Endodontique Molaire', 'Fauteuil 1', $today, '10:00', 45, 'in_consultation', 950, 'Traitement pulpaire dent 16']);
        $stApt->execute([$tenantId, $branchId, 7, 4, 'Dr. Youssef Alami', 'Chirurgien-Dentiste', 'Détartrage & Polissage', 'Fauteuil 1', $today, '11:30', 30, 'confirmed', 500, 'Détartrage annuel et contrôle']);

        // Stock Dentaire Spécifique
        $dentalStock = [
            ['DENT-COMP-A2', 'Composite Filtek Z250 Seringue Teinte A2', 'Consommables Dentaires', 'Seringue', 12, 4, 380, 'Dentsply Sirona Maroc', '2028-04-30', 'LOT-COMP-881'],
            ['DENT-ART-40', 'Anesthésique Articaïne 1/200.000 Cartouches (Bte 50)', 'Anesthésie', 'Boîte', 6, 3, 420, 'Septodont Maroc', '2027-11-30', 'LOT-SEPT-441'],
            ['DENT-DIG-01', 'Feuilles de Digue Dentaire Sans Latex (Bte 36)', 'Endodontie', 'Boîte', 8, 4, 180, 'Coltène Maroc', '2029-01-01', 'LOT-COLT-102'],
            ['DENT-GUT-02', 'Cônes de Gutta-Percha Conicité .04 (Bte 60)', 'Endodontie', 'Boîte', 15, 5, 120, 'VDW Dental', '2029-06-30', 'LOT-VDW-991'],
        ];
        $stStk = $pdo->prepare('INSERT INTO inventory_items (tenant_id, branch_id, sku, name, category, unit, quantity, min_threshold, unit_cost_mad, supplier, expiry_date, batch_number, last_movement_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($dentalStock as $ds) {
            $stStk->execute(array_merge([$tenantId, $branchId], $ds, [$today]));
        }

        // Devis Dentaire
        $pdo->prepare('INSERT INTO quotes (tenant_id, branch_id, quote_num, patient_id, patient_name, doctor_name, issued_date, valid_until, subtotal, discount, total, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $tenantId, $branchId, 'DEV-DENT-2026-001', 6, 'Hamza Chraibi', 'Dr. Youssef Alami', $today,
                date('Y-m-d', strtotime("{$today} +60 days")), 3450, 250, 3200, 'accepted',
                'Devis traitement canalaire molaire 16 + Couronne céramo-métallique'
            ]);
    }
}