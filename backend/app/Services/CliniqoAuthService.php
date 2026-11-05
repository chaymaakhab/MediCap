<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use PDO;

/**
 * CliniqoAuthService — Server-Side Authentication, RBAC, Branch Isolation,
 * Subscription Plan Gatekeeper, and CNDP Audit Logger.
 */
class CliniqoAuthService
{
    public const ROLE_PERMISSIONS = [
        // 3 Rôles Officiels Cliniqo - Isolation Stricte & Séparation des Espaces
        'owner' => [
            'saas.admin', 'subscriptions.manage', 'plans.manage', 'backups.manage', 'clinics.manage', 'audit.read',
            // NOTE: 'appointments.*', 'patients.*', 'finance.*', 'labs.*', 'stock.*' SONT STRICTEMENT INTERDITS À L'OWNER (ZÉRO DONNÉES CLINIQUES/MÉDICALES EXPOSÉES)
        ],
        'admin' => [
            'dashboard.view', 'appointments.read', 'appointments.write', 'doctors.write',
            'patients.read', 'patients.write',
            'labs.read',
            'finance.read', 'finance.write', 'expenses.read', 'expenses.write',
            'stock.read', 'stock.write', 'documents.read',
            'reports.read', 'reports.export', 'settings.manage', 'users.manage',
            'backups.manage',
            // NOTE: 'patients.dme', 'prescriptions.write', 'cabinet.ownership', 'saas.admin' SONT STRICTEMENT INTERDITS À L'ADMIN (SECRET MÉDICAL & PROPRIÉTÉ)
        ],
        'docteur' => [
            'dashboard.view', 'appointments.read', 'appointments.write',
            'patients.read', 'patients.dme',
            'labs.read', 'labs.write', 'labs.validate',
            'documents.read', 'documents.write', 'prescriptions.write',
            'reports.read',
            // NOTE: 'finance.write', 'expenses.write', 'stock.write', 'cabinet.ownership', 'saas.admin' SONT STRICTEMENT INTERDITS AU DOCTEUR (GESTION ADMIN & PROPRIÉTÉ)
        ],

        // Compatibilité avec les profils existants
        'admin_clinique' => [
            'dashboard.view', 'appointments.read', 'appointments.write', 'doctors.write',
            'patients.read', 'patients.write', 'patients.dme',
            'labs.read', 'labs.write', 'labs.validate',
            'finance.read', 'finance.write', 'expenses.read', 'expenses.write',
            'stock.read', 'stock.write', 'documents.read', 'documents.write',
            'reports.read', 'reports.export', 'settings.manage', 'users.manage',
            'branches.manage', 'backups.manage', 'audit.read', 'cabinet.ownership',
        ],
        'medecin' => [
            'dashboard.view', 'appointments.read', 'appointments.write',
            'patients.read', 'patients.write', 'patients.dme',
            'labs.read', 'labs.write', 'documents.read', 'documents.write',
            'reports.read',
        ],
        'secretaire' => [
            'dashboard.view', 'appointments.read', 'appointments.write',
            'patients.read', 'patients.write',
            'finance.read', 'finance.write', 'expenses.read', 'expenses.write',
            'stock.read', 'stock.write', 'documents.read', 'documents.write',
            'reports.read',
        ],
        'laborantin' => [
            'dashboard.view', 'patients.read',
            'labs.read', 'labs.write', 'labs.validate',
            'stock.read', 'stock.write',
        ],
        'comptable' => [
            'dashboard.view', 'patients.read',
            'finance.read', 'finance.write', 'expenses.read', 'expenses.write',
            'stock.read', 'documents.read', 'reports.read', 'reports.export', 'audit.read',
        ],
        'saas_owner' => [
            'saas.admin', 'subscriptions.manage', 'plans.manage', 'backups.manage', 'clinics.manage', 'audit.read',
        ],
    ];

    public static function extractBearerToken(Request $request): ?string
    {
        $auth = (string) $request->header('Authorization', '');
        if (str_starts_with($auth, 'Bearer ')) {
            $tok = trim(substr($auth, 7));
            if ($tok !== '') {
                return $tok;
            }
        }
        $custom = trim((string) $request->header('X-Auth-Token', ''));
        if ($custom !== '') {
            return $custom;
        }
        return null;
    }

    public static function revokeStaffToken(Request $request): void
    {
        $rawToken = self::extractBearerToken($request);
        if ($rawToken === null) {
            return;
        }

        $pdo = CliniqoDatabase::connection();
        $st = $pdo->prepare('DELETE FROM auth_tokens WHERE token_hash = ? AND token_type = ?');
        $st->execute([hash('sha256', $rawToken), 'staff']);
    }

    public static function issueStaffToken(int $userId, ?int $tenantId = null): string
    {
        $pdo = CliniqoDatabase::connection();
        $raw = 'clq_stf_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $raw);
        $expires = date('Y-m-d H:i:s', time() + 86400 * 7);

        $st = $pdo->prepare('INSERT INTO auth_tokens (token_hash, token_type, user_id, tenant_id, expires_at) VALUES (?,?,?,?,?)');
        $st->execute([$hash, 'staff', $userId, $tenantId, $expires]);

        return $raw;
    }

    public static function issuePatientToken(int $patientId, int $tenantId): string
    {
        $pdo = CliniqoDatabase::connection();
        $raw = 'clq_pat_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $raw);
        $expires = date('Y-m-d H:i:s', time() + 86400 * 3);

        $st = $pdo->prepare('INSERT INTO auth_tokens (token_hash, token_type, patient_id, tenant_id, expires_at) VALUES (?,?,?,?,?)');
        $st->execute([$hash, 'patient', $patientId, $tenantId, $expires]);

        return $raw;
    }

    public static function resolveStaffContext(Request $request): array
    {
        $pdo = CliniqoDatabase::connection();
        $rawToken = self::extractBearerToken($request);

        $requestedTenant = (int) ($request->header('X-Tenant-ID') ?? $request->query('tenant_id', 1));
        if ($requestedTenant <= 0) {
            $requestedTenant = 1;
        }

        if ($rawToken === null) {
            self::abortJson(401, 'AUTHENTICATION_REQUIRED', 'Connexion requise. Veuillez vous authentifier.');
        }

        $hash = hash('sha256', $rawToken);
        $st = $pdo->prepare('SELECT * FROM auth_tokens WHERE token_hash = ? AND token_type = ? LIMIT 1');
        $st->execute([$hash, 'staff']);
        $tokRow = $st->fetch();

        if (!$tokRow || ($tokRow['expires_at'] ?? '') < date('Y-m-d H:i:s')) {
            self::abortJson(401, 'INVALID_OR_EXPIRED_TOKEN', 'Session expirée ou jeton invalide. Veuillez vous reconnecter.');
        }

        $stU = $pdo->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $stU->execute([(int) $tokRow['user_id']]);
        $user = $stU->fetch();
        if (!$user) {
            self::abortJson(401, 'USER_DISABLED', 'Ce compte utilisateur est désactivé.');
        }

        $roleAliases = [
            'saas_owner' => 'owner',
            'admin_clinique' => 'admin',
            'medecin' => 'docteur',
            'doctor' => 'docteur',
        ];
        $storedRole = strtolower((string) ($user['role'] ?? ''));
        $role = $roleAliases[$storedRole] ?? $storedRole;
        $permissions = self::ROLE_PERMISSIONS[$role] ?? [];

        // Platform Owner has tenant_id = NULL and MUST NOT access clinic medical workspaces
        if ($role === 'owner') {
            return [
                'user' => self::sanitizeUser($user, []),
                'role' => 'owner',
                'permissions' => self::ROLE_PERMISSIONS['owner'],
                'tenant_id' => null,
                'branch_id' => null,
                'allowed_branch_ids' => [],
            ];
        }

        // Non-owner staff are bound strictly to their own tenant
        $userTenantId = (int) ($user['tenant_id'] ?? 1);
        $activeTenantId = $userTenantId;

        if ($requestedTenant !== $userTenantId && $request->hasHeader('X-Tenant-ID')) {
            // If user explicitly tries to read another clinic's tenant_id -> 403!
            self::abortJson(403, 'CROSS_TENANT_FORBIDDEN', 'Accès interdit : votre compte est rattaché uniquement à votre centre médical.');
        }

        // Verify tenant exists
        $stT = $pdo->prepare('SELECT id FROM tenants WHERE id = ? LIMIT 1');
        $stT->execute([$activeTenantId]);
        if (!$stT->fetch()) {
            $activeTenantId = 1;
        }

        // Determine allowed branches for this user within activeTenantId
        $stAllB = $pdo->prepare('SELECT id FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY is_main DESC, id ASC');
        $stAllB->execute([$activeTenantId]);
        $tenantBranchIds = array_map('intval', array_column($stAllB->fetchAll(), 'id'));

        if ((int) ($user['all_branches'] ?? 0) === 1) {
            $allowedBranchIds = $tenantBranchIds;
        } else {
            $stUb = $pdo->prepare('SELECT ub.branch_id FROM user_branches ub INNER JOIN branches b ON b.id = ub.branch_id WHERE ub.user_id = ? AND b.tenant_id = ? AND b.is_active = 1');
            $stUb->execute([(int) $user['id'], $activeTenantId]);
            $allowedBranchIds = array_map('intval', array_column($stUb->fetchAll(), 'branch_id'));
            if (empty($allowedBranchIds)) {
                $allowedBranchIds = !empty($tenantBranchIds) ? [$tenantBranchIds[0]] : [];
            }
        }

        // Requested branch filter ('all' or numeric branch ID)
        $rawBranch = trim((string) ($request->header('X-Branch-ID') ?? $request->query('branch_id', 'all')));
        $activeBranchId = null; // null means "all allowed branches of this tenant"
        if ($rawBranch !== '' && $rawBranch !== 'all' && $rawBranch !== '0') {
            $cand = (int) $rawBranch;
            if (!in_array($cand, $tenantBranchIds, true)) {
                // Branch belongs to a previous tenant before switching; fallback cleanly to all allowed branches
                $activeBranchId = count($allowedBranchIds) === 1 ? $allowedBranchIds[0] : null;
            } elseif (!in_array($cand, $allowedBranchIds, true)) {
                self::abortJson(403, 'BRANCH_ACCESS_DENIED', 'Accès refusé : vous n’êtes pas affecté(e) à cette branche / annexe.');
            } else {
                $activeBranchId = $cand;
            }
        } elseif (count($allowedBranchIds) === 1 && (int) ($user['all_branches'] ?? 0) === 0) {
            $activeBranchId = $allowedBranchIds[0];
        }

        return [
            'user' => self::sanitizeUser($user, $allowedBranchIds),
            'role' => $role,
            'permissions' => $permissions,
            'tenant_id' => $activeTenantId,
            'branch_id' => $activeBranchId,
            'allowed_branch_ids' => $allowedBranchIds,
        ];
    }

    public static function resolvePatientPortalContext(Request $request): array
    {
        $pdo = CliniqoDatabase::connection();
        $rawToken = self::extractBearerToken($request);
        if (!$rawToken) {
            self::abortJson(401, 'PORTAL_AUTH_REQUIRED', 'Authentification Portail Patient requise.');
        }

        $hash = hash('sha256', $rawToken);
        $st = $pdo->prepare('SELECT * FROM auth_tokens WHERE token_hash = ? AND token_type = ? LIMIT 1');
        $st->execute([$hash, 'patient']);
        $tok = $st->fetch();

        if (!$tok || ($tok['expires_at'] ?? '') < date('Y-m-d H:i:s')) {
            self::abortJson(401, 'PORTAL_SESSION_EXPIRED', 'Session Portail Patient expirée. Veuillez vous reconnecter.');
        }

        $stP = $pdo->prepare('SELECT * FROM patients WHERE id = ? AND tenant_id = ? AND portal_enabled = 1 LIMIT 1');
        $stP->execute([(int) $tok['patient_id'], (int) $tok['tenant_id']]);
        $patient = $stP->fetch();

        if (!$patient) {
            self::abortJson(403, 'PORTAL_ACCESS_DISABLED', 'Accès portail désactivé pour ce dossier.');
        }

        return [
            'patient' => $patient,
            'patient_id' => (int) $patient['id'],
            'tenant_id' => (int) $patient['tenant_id'],
            'branch_id' => (int) $patient['branch_id'],
        ];
    }

    public static function requirePermission(Request $request, string $permission): array
    {
        $ctx = $request->attributes->get('cliniqo_ctx') ?? self::resolveStaffContext($request);

        if (in_array($ctx['role'], ['owner', 'saas_owner'], true) && !in_array($permission, ['saas.admin', 'backups.manage', 'subscriptions.manage', 'plans.manage', 'clinics.manage', 'audit.read'], true)) {
            self::abortJson(403, 'SAAS_OWNER_MEDICAL_ISOLATION', 'Sécurité SaaS : le propriétaire de la plateforme n’a pas accès aux dossiers médicaux ni aux opérations cliniques.');
        }

        if (!in_array($permission, $ctx['permissions'], true)) {
            self::audit(
                $ctx['tenant_id'],
                $ctx['branch_id'],
                (int) ($ctx['user']['id'] ?? 0),
                (string) ($ctx['user']['name'] ?? 'Utilisateur'),
                (string) $ctx['role'],
                'ACCES_REFUSE_RBAC',
                'security',
                $permission,
                "Tentative non autorisée sur la permission [{$permission}]",
                $request->ip() ?? '127.0.0.1'
            );
            self::abortJson(403, 'INSUFFICIENT_ROLE_PERMISSIONS', "Accès refusé : votre rôle ({$ctx['role']}) ne dispose pas de la permission requise ({$permission}).");
        }

        return $ctx;
    }

    public static function requireRole(Request $request, array $allowedRoles, string $actionLabel = 'cette action'): array
    {
        $ctx = $request->attributes->get('cliniqo_ctx') ?? self::resolveStaffContext($request);
        $role = (string) ($ctx['role'] ?? '');

        if (!in_array($role, $allowedRoles, true)) {
            self::audit(
                $ctx['tenant_id'],
                $ctx['branch_id'],
                (int) ($ctx['user']['id'] ?? 0),
                (string) ($ctx['user']['name'] ?? 'Utilisateur'),
                $role,
                'ROLE_GUARD_VIOLATION',
                'security',
                implode(',', $allowedRoles),
                "Tentative d'accès non autorisé à {$actionLabel} par le rôle [{$role}]",
                $request->ip() ?? '127.0.0.1'
            );
            self::abortJson(403, 'ROLE_ACCESS_DENIED', "Accès refusé : {$actionLabel} est strictement réservé aux rôles (" . implode(', ', $allowedRoles) . "). Votre rôle est : {$role}.");
        }

        return $ctx;
    }

    public static function requireActiveSubscription(int $tenantId, ?string $featureKey = null): array
    {
        $pdo = CliniqoDatabase::connection();
        $st = $pdo->prepare('
            SELECT s.*, p.code AS plan_code, p.name AS plan_name, p.monthly_price_mad,
                   p.max_users, p.max_branches, p.max_doctors,
                   p.enable_lab_feature, p.enable_inventory_feature,
                   p.enable_portal_feature, p.enable_multibranch_feature
            FROM subscriptions s
            INNER JOIN subscription_plans p ON p.id = s.plan_id
            WHERE s.tenant_id = ?
            LIMIT 1
        ');
        $st->execute([$tenantId]);
        $sub = $st->fetch();

        if (!$sub) {
            self::abortJson(403, 'NO_SUBSCRIPTION', 'Aucun abonnement actif trouvé pour cet établissement.');
        }

        if (in_array($sub['status'], ['suspended', 'expired'], true)) {
            self::abortJson(403, 'SUBSCRIPTION_INACTIVE', 'Abonnement expiré ou suspendu. Veuillez renouveler votre contrat SaaS.');
        }

        if ($featureKey === 'lab' && (int) $sub['enable_lab_feature'] !== 1) {
            self::abortJson(403, 'PLAN_FEATURE_RESTRICTED', "Le module Laboratoire n'est pas inclus dans votre forfait actuel ({$sub['plan_name']}).");
        }
        if ($featureKey === 'inventory' && (int) $sub['enable_inventory_feature'] !== 1) {
            self::abortJson(403, 'PLAN_FEATURE_RESTRICTED', "La gestion de Stock n'est pas incluse dans votre forfait actuel ({$sub['plan_name']}).");
        }
        if ($featureKey === 'multibranch' && (int) $sub['enable_multibranch_feature'] !== 1) {
            self::abortJson(403, 'PLAN_FEATURE_RESTRICTED', "La gestion Multi-Branches nécessite le forfait Pro Clinique ou Enterprise.");
        }

        return $sub;
    }

    public static function branchSqlFilter(array $ctx, string $column = 'branch_id', array &$bindings = []): string
    {
        if ($ctx['branch_id'] !== null) {
            $bindings[] = (int) $ctx['branch_id'];
            return " AND {$column} = ? ";
        }
        $allowed = $ctx['allowed_branch_ids'] ?? [];
        if (empty($allowed)) {
            return '';
        }
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        foreach ($allowed as $bid) {
            $bindings[] = (int) $bid;
        }
        return " AND {$column} IN ({$placeholders}) ";
    }

    public static function defaultWriteBranchId(array $ctx, ?int $requestedBranchId = null): int
    {
        $allowed = $ctx['allowed_branch_ids'] ?? [];
        if ($requestedBranchId !== null && $requestedBranchId > 0) {
            if (!in_array($requestedBranchId, $allowed, true)) {
                self::abortJson(403, 'BRANCH_WRITE_FORBIDDEN', 'Vous ne pouvez pas créer d’enregistrement dans une branche non autorisée.');
            }
            return $requestedBranchId;
        }
        if ($ctx['branch_id'] !== null) {
            return (int) $ctx['branch_id'];
        }
        return (int) ($allowed[0] ?? 1);
    }

    public static function audit(
        ?int $tenantId,
        ?int $branchId,
        ?int $userId,
        string $actorName,
        string $actorRole,
        string $action,
        string $entityType,
        string $entityRef,
        string $details = '',
        string $ip = '127.0.0.1'
    ): void {
        $pdo = CliniqoDatabase::connection();
        $st = $pdo->prepare('
            INSERT INTO audit_logs (tenant_id, branch_id, user_id, actor_name, actor_role, action, entity_type, entity_ref, details, ip_address, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ');
        $st->execute([
            $tenantId,
            $branchId,
            $userId,
            $actorName,
            $actorRole,
            $action,
            $entityType,
            $entityRef,
            $details,
            $ip,
            date('Y-m-d H:i:s'),
        ]);
    }

    public static function pushNotification(
        ?int $tenantId,
        ?int $branchId,
        string $category,
        string $severity,
        string $title,
        string $message,
        string $actionTab = 'overview',
        ?string $targetRole = null,
        ?int $patientId = null
    ): void {
        $pdo = CliniqoDatabase::connection();
        $st = $pdo->prepare('
            INSERT INTO notifications (tenant_id, branch_id, target_role, patient_id, category, severity, title, message, action_tab, is_read, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,0,?)
        ');
        $st->execute([
            $tenantId,
            $branchId,
            $targetRole,
            $patientId,
            $category,
            $severity,
            $title,
            $message,
            $actionTab,
            date('Y-m-d H:i:s'),
        ]);
    }

    public static function sanitizeUser(array $user, array $allowedBranchIds): array
    {
        $role = (string) ($user['role'] ?? 'admin_clinique');
        return [
            'id' => (int) $user['id'],
            'tenant_id' => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => $role,
            'title' => (string) ($user['title'] ?? ''),
            'phone' => (string) ($user['phone'] ?? ''),
            'avatar' => (string) ($user['avatar'] ?? ''),
            'all_branches' => (bool) ($user['all_branches'] ?? false),
            'allowed_branch_ids' => $allowedBranchIds,
            'permissions' => self::ROLE_PERMISSIONS[$role] ?? [],
        ];
    }

    public static function abortJson(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => $code,
            'message' => $message,
        ], $status));
    }
}
