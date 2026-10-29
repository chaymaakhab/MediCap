<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * CliniqoDatabase — Relational SQLite Multi-Tenant Engine (PDO)
 * Provides real normalized SQL tables, ACID transactions, foreign-key integrity,
 * multi-centre / multi-branch isolation, and verified file-level backups.
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
        // Checkpoint WAL to ensure main SQLite file has 100% of committed pages
        $pdo->exec('PRAGMA wal_checkpoint(FULL);');

        $stamp = date('Ymd_His');
        $filename = sprintf('cliniqo_backup_%s_%s.sqlite', $stamp, bin2hex(random_bytes(3)));
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
            'tenant_scope' => $tenantId ? "Tenant #{$tenantId}" : 'Plateforme Globale',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        file_put_contents($dest . '.meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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
                'created_by' => 'Système',
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

        // Verify SQLite header magic bytes before restoring
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
    max_users INTEGER NOT NULL DEFAULT 5,
    max_branches INTEGER NOT NULL DEFAULT 1,
    max_doctors INTEGER NOT NULL DEFAULT 5,
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
    city TEXT NOT NULL DEFAULT 'Casablanca',
    address TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    ice TEXT NOT NULL DEFAULT '',
    if_tax TEXT NOT NULL DEFAULT '',
    inpe TEXT NOT NULL DEFAULT '',
    cnss_affiliation TEXT NOT NULL DEFAULT '',
    logo_url TEXT NOT NULL DEFAULT 'assets/logo.svg',
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
    doc_header_note TEXT NOT NULL DEFAULT 'Établissement de Santé Agréé • Loi 131-13 • Conformité CNDP Loi 09-08',
    doc_footer_note TEXT NOT NULL DEFAULT 'Document médical confidentiel généré par Cliniqo Cloud Médical.',
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
    auto_renew INTEGER NOT NULL DEFAULT 1,
    notes TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
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
    is_main INTEGER NOT NULL DEFAULT 0,
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
    room TEXT NOT NULL DEFAULT 'Salle 101',
    fee_mad REAL NOT NULL DEFAULT 500,
    duration_min INTEGER NOT NULL DEFAULT 30,
    inpe TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    avatar TEXT NOT NULL DEFAULT '',
    working_days TEXT NOT NULL DEFAULT 'Lun,Mar,Mer,Jeu,Ven,Sam',
    work_start TEXT NOT NULL DEFAULT '08:30',
    work_end TEXT NOT NULL DEFAULT '17:30',
    daily_slot_capacity INTEGER NOT NULL DEFAULT 12,
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
    age INTEGER NOT NULL DEFAULT 35,
    gender TEXT NOT NULL DEFAULT 'F',
    blood TEXT NOT NULL DEFAULT 'O+',
    phone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    address TEXT NOT NULL DEFAULT 'Casablanca, Maroc',
    coverage TEXT NOT NULL DEFAULT 'CNSS AMO (80%)',
    department TEXT NOT NULL DEFAULT 'Cardiologie',
    allergy TEXT NOT NULL DEFAULT 'Aucune connue',
    bp TEXT NOT NULL DEFAULT '120/80',
    spo2 TEXT NOT NULL DEFAULT '98.8%',
    hr TEXT NOT NULL DEFAULT '72 bpm',
    portal_pin_hash TEXT NOT NULL DEFAULT '',
    portal_enabled INTEGER NOT NULL DEFAULT 1,
    avatar TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS appointments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    patient_id INTEGER NOT NULL,
    doctor_id INTEGER,
    doctor TEXT NOT NULL,
    specialty TEXT NOT NULL,
    service_name TEXT NOT NULL DEFAULT 'Consultation Spécialisée',
    room TEXT NOT NULL DEFAULT 'Salle 101',
    date TEXT NOT NULL,
    time TEXT NOT NULL,
    duration_min INTEGER NOT NULL DEFAULT 30,
    status TEXT NOT NULL DEFAULT 'confirmed',
    cancellation_reason TEXT NOT NULL DEFAULT '',
    checked_in_at TEXT,
    seen_at TEXT,
    fee_mad REAL NOT NULL DEFAULT 500,
    reason TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT 'clinic',
    created_by_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_apt_tenant_date ON appointments(tenant_id, branch_id, date);

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
    encounter_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_enc_tenant_date ON encounters(tenant_id, branch_id, encounter_date);

CREATE TABLE IF NOT EXISTS labs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    patient_id INTEGER NOT NULL,
    patient_name TEXT NOT NULL,
    test_name TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT 'Biochimie',
    value TEXT NOT NULL DEFAULT 'En analyse...',
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
    opening_balance REAL NOT NULL DEFAULT 1500,
    closing_balance REAL,
    status TEXT NOT NULL DEFAULT 'open',
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
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
    method TEXT NOT NULL DEFAULT 'TPE CMI',
    status TEXT NOT NULL DEFAULT 'unpaid',
    cnss_org TEXT NOT NULL DEFAULT 'CNSS AMO',
    cnss_est REAL NOT NULL DEFAULT 0,
    cnss_status TEXT NOT NULL DEFAULT 'Dossier prêt',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_inv_tenant_date ON invoices(tenant_id, branch_id, issued_date);

CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    invoice_id INTEGER NOT NULL,
    cash_session_id INTEGER,
    receipt_num TEXT NOT NULL,
    amount REAL NOT NULL,
    payment_type TEXT NOT NULL DEFAULT 'payment',
    method TEXT NOT NULL DEFAULT 'TPE CMI',
    paid_date TEXT NOT NULL,
    paid_time TEXT NOT NULL DEFAULT '10:00',
    status TEXT NOT NULL DEFAULT 'valid',
    recorded_by TEXT NOT NULL DEFAULT 'Caisse Principale',
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_pay_tenant_date ON payments(tenant_id, branch_id, paid_date);

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
    created_by TEXT NOT NULL DEFAULT 'Administration',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_exp_tenant_date ON expenses(tenant_id, branch_id, expense_date);

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
    movement_type TEXT NOT NULL,
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
            // 1. Subscription Plans
            $plans = [
                ['starter', 'Essentiel Cabinet Médical', 690, 5, 1, 3, 1, 1, 1, 0],
                ['pro_clinic', 'Pro Cabinet & Spécialités', 1490, 15, 1, 10, 1, 1, 1, 0],
            ];
            $stPlan = $pdo->prepare('INSERT INTO subscription_plans (code, name, monthly_price_mad, max_users, max_branches, max_doctors, enable_lab_feature, enable_inventory_feature, enable_portal_feature, enable_multibranch_feature) VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach ($plans as $pl) {
                $stPlan->execute($pl);
            }

            // 2. Tenant Unique : Cabinet Médical Cliniqo (Un seul cabinet)
            $stTenant = $pdo->prepare('INSERT INTO tenants (
                name, short_name, slug, city, address, phone, email, ice, if_tax, inpe, cnss_affiliation,
                logo_url, currency, enable_laboratory, enable_inventory, enable_inpatient_beds, total_beds, occupied_beds,
                invoice_prefix, receipt_prefix, quote_prefix, prescription_prefix, certificate_prefix
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

            $stTenant->execute([
                'Cabinet Médical Cliniqo', 'Cabinet Cliniqo', 'cabinet-cliniqo', 'Casablanca',
                '142 Boulevard Zerktouni, Quartier Maarif, 20100 Casablanca', '+212 522-984410', 'contact@cabinet-cliniqo.ma',
                '002849102000047', '45892011', 'INPE-01004429', 'CNSS-884120',
                'assets/logo.svg', 'MAD', 1, 1, 0, 0, 0,
                'FAC', 'REC', 'DEV', 'ORD', 'CERT',
            ]);

            // 3. Subscription (Cabinet Médical Actif)
            $stSub = $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan_id, status, is_trial, started_at, trial_ends_at, expires_at, last_payment_date, last_payment_amount, payment_method, auto_renew, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $stSub->execute([1, 2, 'active', 0, '2026-01-01', null, '2026-12-31', '2026-10-01', 1490, 'Virement Attijariwafa', 1, 'Contrat Annuel Cabinet Médical Cliniqo']);

            // 4. Branch Unique : Cabinet Médical — Maarif
            $stBranch = $pdo->prepare('INSERT INTO branches (tenant_id, name, code, city, address, phone, email, specialties_summary, is_main, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stBranch->execute([1, 'Cabinet Médical — Maarif', 'CAB-MAARIF', 'Casablanca', '142 Bd Zerktouni, Maarif, Casablanca', '+212 522-984410', 'contact@cabinet-cliniqo.ma', 'Médecine Générale • Cardiologie • Biologie', 1, 1]); // id=1

            // 5. Users & Roles (Exactement 3 Rôles Officiels : owner + admin + docteur)
            $pwHash = password_hash('Cliniqo2026!', PASSWORD_BCRYPT);
            $stUser = $pdo->prepare('INSERT INTO users (tenant_id, name, email, password_hash, role, title, phone, avatar, all_branches, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)');

            // User 1: Dr. Jackson (Owner — Propriétaire & Médecin Fondateur)
            $stUser->execute([1, 'Dr. Jackson', 'owner@cabinet-cliniqo.ma', $pwHash, 'owner', 'Médecin Fondateur & Propriétaire (Owner)', '+212 661-001122', 'https://images.unsplash.com/photo-1612349317150-e413f6a5b16d?w=120', 1, 1]);
            // User 2: Sarah Benali (Admin — Gestionnaire & Administration du cabinet)
            $stUser->execute([1, 'Sarah Benali', 'admin@cabinet-cliniqo.ma', $pwHash, 'admin', 'Gestionnaire Administrative (Admin)', '+212 662-445511', 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=120', 1, 1]);
            // User 3: Dr. Alimi Jan (Docteur — Médecin Praticien Cardiologue)
            $stUser->execute([1, 'Dr. Alimi Jan', 'docteur@cabinet-cliniqo.ma', $pwHash, 'docteur', 'Médecin Praticien (Docteur)', '+212 661-102030', 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=120', 1, 1]);

            $stUb = $pdo->prepare('INSERT INTO user_branches (user_id, branch_id) VALUES (?,?)');
            $stUb->execute([1, 1]);
            $stUb->execute([2, 1]);
            $stUb->execute([3, 1]);

            $stPref = $pdo->prepare('INSERT INTO notification_preferences (user_id) VALUES (?)');
            for ($u = 1; $u <= 3; $u++) {
                $stPref->execute([$u]);
            }

            // Seed Données Opérationnelles du Cabinet Médical Unique
            self::seedTenantOperationalData($pdo, 1);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function seedTenantOperationalData(PDO $pdo, int $tenantId): void
    {
        $mainBranch = 1;
        $secondBranch = 1;

        // Specialties & Services Catalogue
        $specs = [
            ['Médecine Générale', 'Médecine Générale', 'MG'],
            ['Cardiologue', 'Cardiologie', 'CARD'],
            ['Neurologue', 'Neurologie', 'NEUR'],
            ['Psychologue', 'Psychologie', 'PSYC'],
            ['Biologie Médicale', 'Laboratoire & Analyses', 'BIO'],
        ];
        $stSpec = $pdo->prepare('INSERT INTO specialties (tenant_id, name, pole, code) VALUES (?,?,?,?)');
        foreach ($specs as $sp) {
            $stSpec->execute([$tenantId, $sp[0], $sp[1], $sp[2]]);
        }

        $services = [
            ['CONS-MG', 'Consultation Médecine Générale', 'Médecine Générale', 350, 25, 1],
            ['CONS-CARD', 'Consultation Cardiologie + ECG', 'Cardiologue', 650, 30, 1],
            ['ECHO-DOP', 'Écho-Doppler Cardiaque Couleur', 'Cardiologue', 850, 45, 1],
            ['CONS-NEUR', 'Exploration EEG & Consultation Neuro', 'Neurologue', 850, 45, 1],
            ['CONS-PSYC', 'Séance Psychothérapie TCC', 'Psychologue', 500, 45, 0],
            ['BILAN-BIO', 'Bilan Biologique Standard', 'Biologie Médicale', 400, 20, 1],
        ];
        $stSrv = $pdo->prepare('INSERT INTO medical_services (tenant_id, code, name, specialty, price_mad, duration_min, cnss_reimbursable) VALUES (?,?,?,?,?,?,?)');
        foreach ($services as $srv) {
            $stSrv->execute([$tenantId, $srv[0], $srv[1], $srv[2], $srv[3], $srv[4], $srv[5]]);
        }

        // Consultation Rooms (Cabinet Médical Solo / Maarif)
        $stRoom = $pdo->prepare('INSERT INTO rooms (tenant_id, branch_id, name, room_type, daily_capacity_slots) VALUES (?,?,?,?,?)');
        $stRoom->execute([1, 1, 'Salle 101', 'Médecine Générale & Titulaire', 14]);
        $stRoom->execute([1, 1, 'Salle 102', 'Cardiologie & Doppler', 12]);
        $stRoom->execute([1, 1, 'Salle 103', 'Soins, ECG & Prélèvements', 10]);
        $stRoom->execute([1, 1, 'Cabinet 104', 'Consultation & Spécialités', 12]);

        // Doctors (Dr. Jackson - Owner, Dr. Alimi Jan - Docteur, Dr. Sarah Chen - Consultante)
        $stDoc = $pdo->prepare('INSERT INTO doctors (tenant_id, branch_id, name, specialty, pole, room, fee_mad, duration_min, inpe, phone, email, avatar, working_days, work_start, work_end, daily_slot_capacity) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $docIds = [];
        $docs = [
            [1, 1, 'Dr. Jackson', 'Médecine Générale', 'Médecine Générale', 'Salle 101', 500, 30, 'INPE-01004429', '+212 661-001122', 'owner@cabinet-cliniqo.ma', 'https://images.unsplash.com/photo-1612349317150-e413f6a5b16d?w=120', 'Lun,Mar,Mer,Jeu,Ven,Sam', '08:30', '18:00', 14],
            [1, 1, 'Dr. Alimi Jan', 'Cardiologue', 'Cardiologie', 'Salle 102', 650, 30, 'INPE-0412890', '+212 661-102030', 'docteur@cabinet-cliniqo.ma', 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=120', 'Lun,Mar,Mer,Jeu,Ven,Sam', '08:30', '17:30', 12],
            [1, 1, 'Dr. Sarah Chen', 'Cardiologue Consultante', 'Cardiologie', 'Cabinet 104', 600, 30, 'INPE-0884512', '+212 663-778899', 'chen@cabinet-cliniqo.ma', 'https://images.unsplash.com/photo-1651008376811-b90baee60c1f?w=120', 'Lun,Mar,Jeu,Ven,Sam', '08:30', '16:30', 12],
        ];
        foreach ($docs as $d) {
            $stDoc->execute($d);
            $docIds[] = (int) $pdo->lastInsertId();
        }

        // Holidays (Marche Verte 06 Nov)
        $pdo->prepare('INSERT INTO clinic_holidays (tenant_id, branch_id, doctor_id, start_date, end_date, label) VALUES (?,?,?,?,?,?)')
            ->execute([$tenantId, null, null, '2026-11-06', '2026-11-06', 'Jour Férié National — Marche Verte']);

        // Patients (Portal PIN default: 1234)
        $pinHash = password_hash('1234', PASSWORD_BCRYPT);
        $stPat = $pdo->prepare('INSERT INTO patients (tenant_id, branch_id, code, first, last, cin, age, gender, blood, phone, email, address, coverage, department, allergy, bp, spo2, hr, portal_pin_hash, portal_enabled, avatar) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $rawPatients = [
            [$mainBranch, 'PT-4429', 'Salma', 'Bennani', "BE-882910", 58, 'F', 'A+', '+212 661-234890', 'salma.bennani@patient.ma', 'Rue Sebou N°14, Maarif, Casablanca', 'CNSS AMO (80%)', 'Cardiologie', 'Pénicilline', '126/80', '98.6%', '74 bpm', 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=120'],
            [$mainBranch, 'PT-9014', 'Youssef', 'El Mansouri', "BH-451029", 44, 'M', 'O+', '+212 662-908123', 'youssef.mansouri@patient.ma', 'Bd Ghandi Résidence Al Yassamine, Casablanca', 'CNOPS (90%)', 'Cardiologie', 'Aucune connue', '132/84', '99.0%', '78 bpm', 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=120'],
            [$secondBranch, 'PT-9132', 'Mehdi', 'Chraibi', "AB-774120", 36, 'M', 'B+', '+212 664-112233', 'mehdi.chraibi@patient.ma', 'Quartier Racine Villa 9, Casablanca', 'AXA Assurance (85%)', 'Médecine Générale', 'Aspirine (AINS)', '118/76', '99.2%', '68 bpm', 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=120'],
            [$secondBranch, 'PT-7945', 'Kenza', 'Berrada', "CD-339011", 49, 'F', 'AB+', '+212 668-445566', 'kenza.berrada@patient.ma', 'Bd Zerktouni Résidence Farah, Casablanca', 'CNSS AMO (80%)', 'Cardiologie', 'Produits iodés', '122/78', '98.4%', '72 bpm', 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=120'],
            [$mainBranch, 'PT-6210', 'Zineb', 'Tahiri', "EE-910442", 31, 'F', 'O-', '+212 661-998877', 'zineb.tahiri@patient.ma', '2 Mars Résidence Atlas, Casablanca', 'CNOPS (90%)', 'Médecine Générale', 'Latex', '116/74', '99.4%', '69 bpm', 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=120'],
            [$mainBranch, 'PT-5180', 'Rachid', 'Kettani', "BK-602195", 63, 'M', 'A-', '+212 661-554433', 'rachid.kettani@patient.ma', 'Quartier Maarif Extension, Casablanca', 'CNSS AMO (80%)', 'Cardiologie', 'Sulfamides', '138/86', '97.9%', '76 bpm', 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=120'],
        ];
        $patIds = [];
        foreach ($rawPatients as $rp) {
            $stPat->execute([$tenantId, $rp[0], $rp[1], trim($rp[2]), trim($rp[3]), $rp[4], $rp[5], $rp[6], $rp[7], $rp[8], $rp[9], $rp[10], $rp[11], $rp[12], $rp[13], $rp[14], $rp[15], $rp[16], $pinHash, 1, $rp[17]]);
            $patIds[] = (int) $pdo->lastInsertId();
        }

        // Appointments across October & November 2026
        $d0 = $docs[0];
        $d1 = $docs[1];
        $d2 = $docs[2];

        $stApt = $pdo->prepare('INSERT INTO appointments (tenant_id, branch_id, patient_id, doctor_id, doctor, specialty, service_name, room, date, time, duration_min, status, cancellation_reason, checked_in_at, seen_at, fee_mad, reason, source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

        $aptRows = [
            // Earlier in October
            [$d0[1], $patIds[0], $docIds[0], $d0[2], $d0[3], 'Consultation Médecine Générale', $d0[5], '2026-10-08', '09:30', 30, 'completed', '', '09:20', '09:35', $d0[6], 'Bilan HTA initial', 'clinic'],
            [$d1[1], $patIds[1], $docIds[1], $d1[2], $d1[3], 'Consultation Cardiologie + ECG', $d1[5], '2026-10-14', '10:30', 45, 'completed', '', '10:15', '10:32', $d1[6], 'Palpitations & ECG', 'clinic'],
            [$d2[1], $patIds[3], $docIds[2], $d2[2], $d2[3], 'Consultation Cardiologie + ECG', $d2[5], '2026-10-19', '11:00', 45, 'completed', '', '10:50', '11:05', $d2[6], 'Contrôle annuel', 'clinic'],
            [$d0[1], $patIds[2], $docIds[0], $d0[2], $d0[3], 'Consultation Médecine Générale', $d0[5], '2026-10-21', '15:00', 30, 'cancelled', 'Report demandé par le patient (déplacement)', null, null, $d0[6], 'Suivi', 'portal'],

            // Last 7 days: 2026-10-23 -> 2026-10-28
            [$d1[1], $patIds[4], $docIds[1], $d1[2], $d1[3], 'Consultation Cardiologie + ECG', $d1[5], '2026-10-23', '09:30', 30, 'completed', '', '09:22', '09:34', $d1[6], 'Palpitations & ECG', 'clinic'],
            [$d0[1], $patIds[2], $docIds[0], $d0[2], $d0[3], 'Consultation Médecine Générale', $d0[5], '2026-10-23', '11:00', 30, 'completed', '', '10:50', '11:05', $d0[6], 'Bilan santé', 'clinic'],
            [$d1[1], $patIds[5], $docIds[1], $d1[2], $d1[3], 'Écho-Doppler Cardiaque Couleur', $d1[5], '2026-10-24', '10:00', 45, 'completed', '', '09:50', '10:04', 850, 'Contrôle Doppler', 'clinic'],
            [$d2[1], $patIds[0], $docIds[2], $d2[2], $d2[3], 'Consultation Cardiologie + ECG', $d2[5], '2026-10-24', '11:30', 30, 'no_show', 'Patient non présenté sans préavis', null, null, 600, 'Suivi tensionnel', 'clinic'],
            [$d0[1], $patIds[1], $docIds[0], $d0[2], $d0[3], 'Consultation Médecine Générale', $d0[5], '2026-10-25', '10:30', 30, 'completed', '', '10:25', '10:32', $d0[6], 'Certificat médical', 'clinic'],
            [$d1[1], $patIds[0], $docIds[1], $d1[2], $d1[3], 'Écho-Doppler Cardiaque Couleur', $d1[5], '2026-10-26', '09:00', 45, 'completed', '', '08:50', '09:05', 850, 'Écho-Doppler complet', 'clinic'],
            [$d2[1], $patIds[3], $docIds[2], $d2[2], $d2[3], 'Consultation Cardiologie + ECG', $d2[5], '2026-10-26', '14:00', 30, 'completed', '', '13:50', '14:05', 600, 'Bilan trimestriel', 'clinic'],
            [$d0[1], $patIds[1], $docIds[0], $d0[2], $d0[3], 'Consultation Médecine Générale', $d0[5], '2026-10-27', '09:30', 30, 'completed', '', '09:20', '09:35', $d0[6], 'Suivi traitement', 'clinic'],
            [$d1[1], $patIds[2], $docIds[1], $d1[2], $d1[3], 'Consultation Cardiologie + ECG', $d1[5], '2026-10-27', '15:00', 30, 'cancelled', 'Indisponibilité professionnelle', null, null, 650, 'Contrôle annuel', 'clinic'],
            [$d2[1], $patIds[4], $docIds[2], $d2[2], $d2[3], 'Consultation Cardiologie + ECG', $d2[5], '2026-10-28', '10:00', 30, 'completed', '', '09:50', '10:02', 600, 'Certificat aptitude sportive + ECG', 'clinic'],
            [$d0[1], $patIds[5], $docIds[0], $d0[2], $d0[3], 'Consultation Médecine Générale', $d0[5], '2026-10-28', '14:30', 30, 'completed', '', '14:20', '14:35', $d0[6], 'Contrôle tensionnel', 'clinic'],

            // TODAY: 2026-10-29
            [$d1[1], $patIds[0], $docIds[1], $d1[2], $d1[3], 'Écho-Doppler Cardiaque Couleur', $d1[5], '2026-10-29', '09:00', 45, 'completed', '', '08:48', '09:02', 850, 'Contrôle Écho-Doppler cardiaque & HTA', 'clinic'],
            [$d0[1], $patIds[4], $docIds[0], $d0[2], $d0[3], 'Consultation Médecine Générale', $d0[5], '2026-10-29', '09:30', 30, 'completed', '', '09:20', '09:32', 500, 'Suivi tensionnel & renouvellement', 'clinic'],
            [$d1[1], $patIds[1], $docIds[1], $d1[2], $d1[3], 'Consultation Cardiologie + ECG', $d1[5], '2026-10-29', '10:15', 45, 'in_progress', '', '10:05', '10:18', 650, 'Bilan cardiaque de suivi', 'clinic'],
            [$d0[1], $patIds[5], $docIds[0], $d0[2], $d0[3], 'Consultation Médecine Générale', $d0[5], '2026-10-29', '11:00', 30, 'waiting', '', '10:50', null, 500, 'Examen général & ordonnance', 'clinic'],
            [$d2[1], $patIds[3], $docIds[2], $d2[2], $d2[3], 'Consultation Cardiologie + ECG', $d2[5], '2026-10-29', '14:00', 45, 'confirmed', '', null, null, 600, 'Consultation contrôle résultats', 'clinic'],
            [$d1[1], $patIds[2], $docIds[1], $d1[2], $d1[3], 'Consultation Cardiologie + ECG', $d1[5], '2026-10-29', '15:30', 30, 'confirmed', '', null, null, 650, 'Évaluation cardiovasculaire', 'portal'],
            [$d0[1], $patIds[5], $docIds[0], $d0[2], $d0[3], 'Consultation Médecine Générale', $d0[5], '2026-10-29', '16:30', 30, 'no_show', 'Absent au créneau sans appel', null, null, 500, 'Contrôle tensionnel', 'clinic'],
            [$d2[1], $patIds[1], $docIds[2], $d2[2], $d2[3], 'Consultation Cardiologie + ECG', $d2[5], '2026-10-29', '17:00', 45, 'cancelled', 'Annulé 24h avant via Portail Patient', null, null, 600, 'Consultation suivi', 'portal'],

            // Upcoming days
            [$d1[1], $patIds[0], $docIds[1], $d1[2], $d1[3], 'Consultation Cardiologie + ECG', $d1[5], '2026-10-30', '09:30', 30, 'confirmed', '', null, null, 650, 'Lecture Holter ECG 24h', 'clinic'],
            [$d2[1], $patIds[3], $docIds[2], $d2[2], $d2[3], 'Consultation Cardiologie + ECG', $d2[5], '2026-10-31', '10:30', 45, 'confirmed', '', null, null, 600, 'Suivi protocole', 'clinic'],
            [$d0[1], $patIds[1], $docIds[0], $d0[2], $d0[3], 'Consultation Médecine Générale', $d0[5], '2026-11-02', '11:00', 30, 'confirmed', '', null, null, 500, 'Contrôle général mensuel', 'portal'],
        ];

        $aptIds = [];
        foreach ($aptRows as $ar) {
            $stApt->execute(array_merge([$tenantId], $ar));
            $aptIds[] = (int) $pdo->lastInsertId();
        }

        // Clinical Encounters
        $stEnc = $pdo->prepare('INSERT INTO encounters (tenant_id, branch_id, patient_id, appointment_id, doctor_name, pole, icd_code, clinical_text, bp, spo2, hr, encounter_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $encRows = [
            [$d0[1], $patIds[0], $aptIds[0], $d0[2], 'Médecine Générale', 'BA00 • Hypertension essentielle', 'Bilan HTA initial, mise sous Bisoprolol 5mg.', '134/85', '98.4%', '76 bpm', '2026-10-08'],
            [$d1[1], $patIds[1], $aptIds[1], $d1[2], 'Cardiologie', 'BC41 • Troubles du rythme bénins', 'ECG sinusal, pas d’anomalie ischémique.', '128/82', '99.0%', '75 bpm', '2026-10-14'],
            [$d2[1], $patIds[3], $aptIds[2], $d2[2], 'Cardiologie', 'QA00 • Bilan préventif', 'Tolérance clinique satisfaisante, bilan stable.', '120/76', '98.5%', '71 bpm', '2026-10-19'],
            [$d1[1], $patIds[4], $aptIds[4], $d1[2], 'Cardiologie', 'BC41 • Extrasystoles bénignes', 'Extrasystoles bénignes liées au stress, réduction caféine.', '118/75', '99.2%', '72 bpm', '2026-10-23'],
            [$d1[1], $patIds[0], $aptIds[15], $d1[2], 'Cardiologie', 'BA00 • Hypertension essentielle', 'Tension artérielle stabilisée sous Bisoprolol 5mg. Écho-Doppler normal.', '126/80', '98.6%', '74 bpm', '2026-10-29'],
            [$d0[1], $patIds[4], $aptIds[16], $d0[2], 'Médecine Générale', 'QA00 • Bilan cardiovasculaire', 'ECG normal, profil tensionnel optimal.', '116/74', '99.4%', '69 bpm', '2026-10-29'],
        ];
        foreach ($encRows as $er) {
            $stEnc->execute(array_merge([$tenantId], $er));
        }

        // Laboratory Tests
        $stLab = $pdo->prepare('INSERT INTO labs (tenant_id, branch_id, code, patient_id, patient_name, test_name, category, value, reference, priority, status, ordered_by, validated_by, ordered_date, completed_date, timestamp) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $labRows = [
            [$mainBranch, 'LAB-8821', $patIds[0], 'Salma Bennani', 'Ionogramme Sanguin & Kaliémie (K+)', 'Biochimie', '5.8 mEq/L', '3.5 - 5.1 mEq/L', 'URGENT', 'flagged', $d1[2], '', '2026-10-29', null, '09:18'],
            [$mainBranch, 'LAB-8822', $patIds[1], 'Youssef El Mansouri', 'NFS Plaquettes & CRP Ultra-Sensible', 'Hématologie', 'CRP 2.1 mg/L • Hb 14.4 g/dL', 'CRP < 5.0 mg/L', 'Routine', 'validated', $d0[2], 'Sarah Benali', '2026-10-29', '2026-10-29', '09:45'],
            [$mainBranch, 'LAB-8823', $patIds[4], 'Zineb Tahiri', 'Bilan Lipidique Complet (LDL / HDL)', 'Biochimie', 'LDL 1.08 g/L', '< 1.30 g/L', 'Routine', 'validated', $d2[2], 'Sarah Benali', '2026-10-29', '2026-10-29', '10:05'],
            [$mainBranch, 'LAB-8817', $patIds[0], 'Salma Bennani', 'Troponine Ic Haute Sensibilité', 'Cardiologie', '4.2 ng/L (Négatif)', '< 14.0 ng/L', 'Prioritaire', 'validated', $d1[2], 'Sarah Benali', '2026-10-26', '2026-10-26', '09:50'],
        ];
        foreach ($labRows as $lr) {
            $stLab->execute(array_merge([$tenantId], $lr));
        }

        // Cash Session for Today
        $stCash = $pdo->prepare('INSERT INTO cash_sessions (tenant_id, branch_id, session_code, opened_by, opened_date, opening_balance, status, notes) VALUES (?,?,?,?,?,?,?,?)');
        $stCash->execute([$tenantId, $mainBranch, "CAISSE-{$tenantId}-20261029", 'Sarah Benali', '2026-10-29', 2000.00, 'open', 'Caisse principale ouverte à 08h15']);
        $cashSessionId = (int) $pdo->lastInsertId();

        // Invoices & Payments
        $invPrefix = 'FAC';
        $recPrefix = 'REC';

        $stInv = $pdo->prepare('INSERT INTO invoices (tenant_id, branch_id, num, patient_id, patient_name, doctor_name, specialty, service, issued_date, total, paid, refunded, method, status, cnss_org, cnss_est, cnss_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stPay = $pdo->prepare('INSERT INTO payments (tenant_id, branch_id, invoice_id, cash_session_id, receipt_num, amount, payment_type, method, paid_date, paid_time, status, recorded_by, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');

        // Inv 0: Mid-October Consultation (650 MAD paid in full)
        $stInv->execute([$tenantId, $mainBranch, "{$invPrefix}-2026-3004", $patIds[3], 'Kenza Berrada', $d1[2], $d1[3], 'Consultation Cardiologie + ECG', '2026-10-19', 650.00, 650.00, 0.00, 'Virement', 'paid', 'CNSS AMO', 520.00, 'Remboursé']);
        $inv0 = (int) $pdo->lastInsertId();
        $stPay->execute([$tenantId, $mainBranch, $inv0, null, "{$recPrefix}-2026-4998", 650.00, 'payment', 'Virement', '2026-10-19', '11:45', 'valid', 'Sarah Benali', 'Virement réglé']);

        // Inv 1: Issued 2026-10-26 (850 MAD) -> 500 paid TODAY
        $stInv->execute([$tenantId, $mainBranch, "{$invPrefix}-2026-3008", $patIds[3], 'Kenza Berrada', $d1[2], $d1[3], 'Écho-Doppler Cardiaque Couleur', '2026-10-26', 850.00, 500.00, 0.00, 'TPE CMI', 'partial', 'CNSS AMO', 680.00, 'Transmis CNSS']);
        $inv1 = (int) $pdo->lastInsertId();
        $stPay->execute([$tenantId, $mainBranch, $inv1, $cashSessionId, "{$recPrefix}-2026-5006", 500.00, 'payment', 'TPE CMI', '2026-10-29', '09:15', 'valid', 'Sarah Benali', 'Acompte carte bancaire']);

        // Inv 2: Issued TODAY 2026-10-29 (850 MAD) -> Paid in full TODAY via TPE CMI
        $stInv->execute([$tenantId, $mainBranch, "{$invPrefix}-2026-3011", $patIds[0], 'Salma Bennani', $d1[2], $d1[3], 'Écho-Doppler Cardiaque Couleur', '2026-10-29', 850.00, 850.00, 0.00, 'TPE CMI', 'paid', 'CNSS AMO', 680.00, 'Transmis CNSS']);
        $inv2 = (int) $pdo->lastInsertId();
        $stPay->execute([$tenantId, $mainBranch, $inv2, $cashSessionId, "{$recPrefix}-2026-5007", 850.00, 'payment', 'TPE CMI', '2026-10-29', '09:50', 'valid', 'Sarah Benali', 'Paiement TPE CMI terminal cabinet']);

        // Inv 3: Issued TODAY 2026-10-29 (500 MAD) -> Paid in full
        $stInv->execute([$tenantId, $mainBranch, "{$invPrefix}-2026-3012", $patIds[4], 'Zineb Tahiri', $d0[2], $d0[3], 'Consultation Médecine Générale', '2026-10-29', 500.00, 500.00, 0.00, 'Espèces', 'paid', 'CNOPS', 400.00, 'Dossier prêt']);
        $inv3 = (int) $pdo->lastInsertId();
        $stPay->execute([$tenantId, $mainBranch, $inv3, $cashSessionId, "{$recPrefix}-2026-5008", 500.00, 'payment', 'Espèces', '2026-10-29', '10:10', 'valid', 'Sarah Benali', 'Encaissement espèces']);

        // Expenses (Charges & Dépenses du Cabinet)
        $stExp = $pdo->prepare('INSERT INTO expenses (tenant_id, branch_id, cash_session_id, reference, category, amount, expense_date, description, supplier, supporting_doc_ref, payment_method, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stExp->execute([$tenantId, $mainBranch, null, "DEP-{$tenantId}-101", 'Fournitures Médicales', 1200.00, '2026-10-25', 'Tubes prélèvement & réactifs', 'BioMaroc Diagnostics', 'BL-2026-884', 'Virement', 'Sarah Benali']);
        $stExp->execute([$tenantId, $mainBranch, null, "DEP-{$tenantId}-102", 'Maintenance Biomédicale', 800.00, '2026-10-27', 'Étalonnage électrocardiographe', 'MedTech Maroc', 'FAC-MT-419', 'Chèque', 'Dr. Jackson']);
        $stExp->execute([$tenantId, $mainBranch, $cashSessionId, "DEP-{$tenantId}-103", 'Consommables Cabinet', 450.00, '2026-10-29', 'Drap d’examen, gants nitrile & gel stérile', 'PharmaSupply Casa', 'BON-9921', 'Espèces', 'Sarah Benali']);

        // Inventory (Stock du Cabinet Médical)
        $stItem = $pdo->prepare('INSERT INTO inventory_items (tenant_id, branch_id, sku, name, category, unit, quantity, min_threshold, unit_cost_mad, supplier, last_movement_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stMov = $pdo->prepare('INSERT INTO inventory_movements (tenant_id, branch_id, item_id, movement_type, quantity, reason, supplier_or_ref, created_by, movement_date) VALUES (?,?,?,?,?,?,?,?,?)');

        $items = [
            [$mainBranch, 'STK-101', 'Gants d’examen Nitrile (S/M)', 'Protection & Hygiène', 'Boîte (100)', 34, 15, 65.00, 'PharmaSupply Casa', '2026-10-29'],
            [$mainBranch, 'STK-102', 'Gel Échographique Stérile 250ml', 'Imagerie & Doppler', 'Flacon', 4, 10, 42.00, 'MedTech Maroc', '2026-10-29'], // LOW STOCK!
            [$mainBranch, 'STK-103', 'Kits Prélèvement Sous Vide Vacutainer', 'Laboratoire', 'Boîte (50)', 3, 8, 145.00, 'BioMaroc Diagnostics', '2026-10-28'], // LOW STOCK!
            [$mainBranch, 'STK-104', 'Électrodes ECG Adultes Usage Unique', 'Cardiologie', 'Sachet (50)', 22, 10, 55.00, 'MedTech Maroc', '2026-10-27'],
            [$mainBranch, 'STK-105', 'Draps d’examen Gaufrés Pure Ouate', 'Consommables', 'Rouleau', 18, 12, 38.00, 'PharmaSupply Casa', '2026-10-29'],
        ];
        foreach ($items as $it) {
            $stItem->execute(array_merge([$tenantId], $it));
            $itemId = (int) $pdo->lastInsertId();
            $stMov->execute([$tenantId, $it[0], $itemId, 'in', $it[5] + 5, 'Dotation initiale stock', $it[8], 'Dr. Jackson', '2026-10-20']);
            $stMov->execute([$tenantId, $it[0], $itemId, 'out', 5, 'Consommation consultations', 'SORTIE-SOINS', 'Sarah Benali', '2026-10-29']);
        }

        // Generated Medical & Financial Documents
        $stDocu = $pdo->prepare('INSERT INTO documents (tenant_id, branch_id, patient_id, doctor_name, doc_type, reference, title, body_text, amount_mad, issued_date, shared_with_patient, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stDocu->execute([$tenantId, $mainBranch, $patIds[0], $d1[2], 'prescription', "ORD-{$tenantId}-2026-401", 'Ordonnance Cardiologie — Salma Bennani', "1. Bisoprolol 5 mg : 1 comprimé chaque matin pendant 30 jours.\n2. Amlodipine 5 mg : 1 comprimé le soir pendant 30 jours.\n3. Contrôle kaliémie et créatininémie sous 7 jours.", 0, '2026-10-29', 1, $d1[2]]);
        $stDocu->execute([$tenantId, $mainBranch, $patIds[0], $d0[2], 'certificate', "CERT-{$tenantId}-2026-112", 'Certificat Médical de Repos (3 jours)', "Je soussigné(e) {$d0[2]}, certifie avoir examiné ce jour Mme Salma Bennani (CIN BE-882910) et atteste que son état de santé nécessite un repos médical de trois (3) jours sauf complications.", 0, '2026-10-29', 1, $d0[2]]);
        $stDocu->execute([$tenantId, $mainBranch, $patIds[0], $d1[2], 'report', "CR-{$tenantId}-2026-089", 'Note Staff Interne — Réévaluation Cardiologique', 'Observation réservée au dossier médical avant validation finale du Holter.', 0, '2026-10-29', 0, $d1[2]]);
        $stDocu->execute([$tenantId, $mainBranch, $patIds[1], $d0[2], 'quote', "DEV-{$tenantId}-2026-205", 'Devis Bilan Cardiovasculaire Complet', "Consultation spécialisée (500 MAD) + Échocardiographie Doppler (850 MAD). Devis valable 30 jours pour accord CNSS / CNOPS.", 1350.00, '2026-10-29', 1, 'Sarah Benali']);

        // Notifications
        $stNotif = $pdo->prepare('INSERT INTO notifications (tenant_id, branch_id, target_role, patient_id, category, severity, title, message, action_tab, is_read) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stNotif->execute([$tenantId, $mainBranch, null, null, 'lab', 'urgent', 'Résultat biologique prioritaire (#LAB-8821)', 'Le dossier patient #PT-4429 requiert une validation biologique au cabinet.', 'labs', 0]);
        $stNotif->execute([$tenantId, $mainBranch, 'admin', null, 'stock', 'warning', 'Alerte Seuil de Stock Minimum (2 articles)', 'Gel Échographique Stérile (4 restants / min 10) et Kits Vacutainer (3 restants / min 8).', 'finance', 0]);
        $stNotif->execute([$tenantId, $mainBranch, null, null, 'payment', 'info', 'Solde restant à recouvrer • Facture partielle', "Facture {$invPrefix}-2026-3008 (#PT-7945) : acompte 500 MAD encaissé, reste dû 350 MAD.", 'finance', 0]);
        $stNotif->execute([$tenantId, $mainBranch, null, $patIds[0], 'appointment', 'info', 'Rappel & Confirmation RDV (#PT-4429)', "Votre suivi avec {$d1[2]} du 30/10/2026 à 09h30 est confirmé.", 'overview', 0]);
        $stNotif->execute([$tenantId, $mainBranch, null, $patIds[1], 'appointment', 'info', 'Annulation enregistrée (#PT-9014)', 'Le créneau du 29/10/2026 à 17h00 a été annulé sur demande portail.', 'overview', 1]);

        // Audit Logs (Traces immuables CNDP / Loi 09-08)
        $stAud = $pdo->prepare('INSERT INTO audit_logs (tenant_id, branch_id, user_id, actor_name, actor_role, action, entity_type, entity_ref, details, ip_address, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stAud->execute([$tenantId, $mainBranch, 1, 'Dr. Jackson', 'owner', 'CONSULTATION_DME', 'patient', 'PT-4429', 'Ouverture sécurisée dossier médical & constantes signées', '127.0.0.1', '2026-10-29 09:05:12']);
        $stAud->execute([$tenantId, $mainBranch, 2, 'Sarah Benali', 'admin', 'ENCAISSEMENT_PAIEMENT', 'payment', "{$recPrefix}-2026-5007", 'Encaissement TPE CMI 850.00 MAD validé en caisse', '127.0.0.1', '2026-10-29 09:50:44']);
        $stAud->execute([$tenantId, $mainBranch, 3, 'Dr. Alimi Jan', 'docteur', 'PRESCRIPTION_DME', 'document', "ORD-{$tenantId}-2026-401", 'Ordonnance médicale établie pour Salma Bennani', '127.0.0.1', '2026-10-29 10:15:22']);
    }
}
