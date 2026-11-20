<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Users, roles and permissions management.
 */
class UserService
{
    public static function listUsers(array $filters = [], ?int $viewerUserId = null): array
    {
        $pdo = Database::getConnection();
        $viewerIsOwner = $viewerUserId !== null && self::isOwnerUserId($pdo, $viewerUserId);
        $sql = '
            SELECT u.id, u.branch_id, u.role_id, u.username, u.full_name, u.phone, u.email,
                   u.is_active, u.must_change_password, u.failed_login_attempts, u.locked_until,
                   u.last_login_at, u.created_at, r.name AS role_name, r.display_name_ar AS role_display_ar,
                   b.name AS branch_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            JOIN branches b ON b.id = u.branch_id
            WHERE u.deleted_at IS NULL
        ';
        if (!$viewerIsOwner) {
            $sql .= " AND r.is_hidden = 0 AND r.name <> 'owner'";
        }
        $params = [];
        if (!empty($filters['q'])) {
            $sql .= ' AND (u.username LIKE :q1 OR u.full_name LIKE :q2 OR u.phone LIKE :q3)';
            $like = '%' . trim((string)$filters['q']) . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $sql .= ' AND u.is_active = :active';
            $params[':active'] = !empty($filters['is_active']) ? 1 : 0;
        }
        if (!empty($filters['role_id'])) {
            $sql .= ' AND u.role_id = :role';
            $params[':role'] = (int)$filters['role_id'];
        }
        $sql .= ' ORDER BY u.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function createUser(array $data, int $adminUserId, int $adminBranchId): array
    {
        return Database::transaction(function (PDO $pdo) use ($data, $adminUserId, $adminBranchId): array {
            $username = self::validateUsername($data['username'] ?? null);
            $fullName = Validator::requireNonEmpty($data['full_name'] ?? null, 'الاسم الكامل', 150);
            $password = self::validatePassword((string)($data['password'] ?? ''));
            $phone = Validator::phone($data['phone'] ?? '', 'رقم الهاتف', false);
            $email = Validator::email($data['email'] ?? '', 'البريد الإلكتروني', true);
            $roleId = Validator::positiveInt($data['role_id'] ?? 0, 'الدور الوظيفي');
            $branchId = !empty($data['branch_id'])
                ? Validator::positiveInt($data['branch_id'], 'الفرع')
                : $adminBranchId;
            self::assertRoleExists($pdo, $roleId);
            self::assertRoleAssignable($pdo, $roleId, $adminUserId);
            self::assertBranchExists($pdo, $branchId);
            self::assertUsernameFree($pdo, $username, null);

            $now = date('Y-m-d H:i:s');
            try {
                $pdo->prepare('
                    INSERT INTO users (
                        branch_id, role_id, username, full_name, phone, email,
                        password_hash, is_active, must_change_password, created_at, updated_at
                    ) VALUES (
                        :branch_id, :role_id, :username, :full_name, :phone, :email,
                        :password_hash, :active, 1, :created_at, :updated_at
                    )
                ')->execute([
                    ':branch_id' => $branchId,
                    ':role_id' => $roleId,
                    ':username' => $username,
                    ':full_name' => $fullName,
                    ':phone' => $phone !== '' ? $phone : null,
                    ':email' => $email !== '' ? $email : null,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':active' => !empty($data['is_active']) ? 1 : 0,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            } catch (PDOException $e) {
                if (self::isUniqueViolation($e)) {
                    throw new RuntimeException('اسم المستخدم مستخدم مسبقًا.');
                }
                throw $e;
            }
            $userId = (int)$pdo->lastInsertId();
            AuditService::log(
                'USER_CREATED',
                'users',
                $userId,
                "إنشاء حساب موظف جديد: {$fullName} ({$username})",
                null,
                ['username' => $username, 'role_id' => $roleId, 'branch_id' => $branchId],
                $adminUserId
            );
            return ['user_id' => $userId, 'username' => $username];
        });
    }

    public static function updateUser(int $userId, array $data, int $adminUserId): array
    {
        return Database::transaction(function (PDO $pdo) use ($userId, $data, $adminUserId): array {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $old = $stmt->fetch();
            if (!$old) {
                throw new RuntimeException('المستخدم غير موجود.');
            }
            self::assertCanManageUser($pdo, $userId, $adminUserId);
            $fullName = Validator::requireNonEmpty($data['full_name'] ?? $old['full_name'], 'الاسم الكامل', 150);
            $phone = Validator::phone($data['phone'] ?? $old['phone'], 'رقم الهاتف', false);
            $email = Validator::email($data['email'] ?? $old['email'], 'البريد الإلكتروني', true);
            $roleId = Validator::positiveInt($data['role_id'] ?? $old['role_id'], 'الدور الوظيفي');
            $branchId = Validator::positiveInt($data['branch_id'] ?? $old['branch_id'], 'الفرع');
            $isActive = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : (int)$old['is_active'];
            self::assertRoleExists($pdo, $roleId);
            self::assertRoleAssignable($pdo, $roleId, $adminUserId);
            self::assertBranchExists($pdo, $branchId);

            if ($userId === $adminUserId && $isActive === 0) {
                throw new RuntimeException('لا يمكنك تعطيل حسابك الحالي.');
            }
            if ($userId === $adminUserId && $roleId !== (int)$old['role_id']) {
                throw new RuntimeException('لا يمكنك تغيير دور حسابك الحالي.');
            }
            if ((int)$old['role_id'] !== $roleId && $roleId !== self::adminRoleId($pdo)) {
                self::assertNotLastAdmin($pdo, $userId);
            }
            if ($isActive === 0 && (int)$old['role_id'] === self::adminRoleId($pdo)) {
                self::assertNotLastAdmin($pdo, $userId);
            }
            if ((int)$old['role_id'] !== $roleId || $isActive === 0) {
                self::assertNotLastOwner($pdo, $userId);
            }

            $pdo->prepare('
                UPDATE users SET full_name = :full_name, phone = :phone, email = :email,
                    role_id = :role_id, branch_id = :branch_id, is_active = :active, updated_at = :now
                WHERE id = :id
            ')->execute([
                ':full_name' => $fullName,
                ':phone' => $phone !== '' ? $phone : null,
                ':email' => $email !== '' ? $email : null,
                ':role_id' => $roleId,
                ':branch_id' => $branchId,
                ':active' => $isActive,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $userId,
            ]);
            AuditService::log('USER_UPDATED', 'users', $userId, "تعديل حساب المستخدم: {$fullName}", $old, $data, $adminUserId);
            return ['user_id' => $userId];
        });
    }

    public static function resetPassword(int $userId, string $newPassword, int $adminUserId): array
    {
        return Database::transaction(function (PDO $pdo) use ($userId, $newPassword, $adminUserId): array {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            if (!$user) {
                throw new RuntimeException('المستخدم غير موجود.');
            }
            self::assertCanManageUser($pdo, $userId, $adminUserId);
            $newPassword = self::validatePassword($newPassword);
            $pdo->prepare('
                UPDATE users SET password_hash = :hash, must_change_password = 1,
                    failed_login_attempts = 0, locked_until = NULL, updated_at = :now
                WHERE id = :id
            ')->execute([
                ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $userId,
            ]);
            AuditService::log('PASSWORD_RESET', 'users', $userId, "إعادة تعيين كلمة مرور المستخدم {$user['username']} بواسطة المدير", null, null, $adminUserId);
            return ['user_id' => $userId];
        });
    }

    public static function unlockUser(int $userId, int $adminUserId): array
    {
        $pdo = Database::getConnection();
        self::assertCanManageUser($pdo, $userId, $adminUserId);
        $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, updated_at = :now WHERE id = :id')
            ->execute([':now' => date('Y-m-d H:i:s'), ':id' => $userId]);
        AuditService::log('USER_UNLOCKED', 'users', $userId, 'فتح قفل حساب المستخدم', null, null, $adminUserId);
        return ['user_id' => $userId];
    }

    public static function deleteUser(int $userId, int $adminUserId): string
    {
        if ($userId === $adminUserId) {
            throw new RuntimeException('لا يمكنك حذف حسابك الحالي.');
        }
        return Database::transaction(function (PDO $pdo) use ($userId, $adminUserId): string {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            if (!$user) {
                throw new RuntimeException('المستخدم غير موجود.');
            }
            self::assertCanManageUser($pdo, $userId, $adminUserId);
            self::assertNotLastAdmin($pdo, $userId);
            self::assertNotLastOwner($pdo, $userId);
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('UPDATE users SET is_active = 0, deleted_at = :now1, updated_at = :now2 WHERE id = :id')
                ->execute([':now1' => $now, ':now2' => $now, ':id' => $userId]);
            AuditService::log('USER_DELETED', 'users', $userId, "حذف حساب المستخدم: {$user['username']}", $user, null, $adminUserId);
            return 'deleted';
        });
    }

    public static function listRoles(?int $viewerUserId = null): array
    {
        $pdo = Database::getConnection();
        $viewerIsOwner = $viewerUserId !== null && self::isOwnerUserId($pdo, $viewerUserId);
        $sql = '
            SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.deleted_at IS NULL) AS users_count
            FROM roles r
        ';
        if (!$viewerIsOwner) {
            $sql .= " WHERE r.is_hidden = 0 AND r.name <> 'owner'";
        }
        $sql .= ' ORDER BY r.id ASC';
        return $pdo->query($sql)->fetchAll();
    }

    public static function listPermissions(?int $viewerUserId = null): array
    {
        $pdo = Database::getConnection();
        $viewerIsOwner = $viewerUserId !== null && self::isOwnerUserId($pdo, $viewerUserId);
        $sql = 'SELECT * FROM permissions';
        if (!$viewerIsOwner) {
            $sql .= " WHERE code <> 'settings.store_identity'";
        }
        $sql .= ' ORDER BY module ASC, id ASC';
        return $pdo->query($sql)->fetchAll();
    }

    public static function getRolePermissions(int $roleId, ?int $viewerUserId = null): array
    {
        $pdo = Database::getConnection();
        $roleStmt = $pdo->prepare('SELECT * FROM roles WHERE id = :id LIMIT 1');
        $roleStmt->execute([':id' => $roleId]);
        $role = $roleStmt->fetch();
        if (!$role) {
            throw new RuntimeException('الدور الوظيفي غير موجود.');
        }
        if ((int)($role['is_hidden'] ?? 0) === 1 || $role['name'] === 'owner') {
            if ($viewerUserId === null || !self::isOwnerUserId($pdo, $viewerUserId)) {
                throw new RuntimeException('هذا الدور محمي ولا يمكن عرضه من هذا الحساب.');
            }
        }
        $stmt = $pdo->prepare('
            SELECT p.code FROM permissions p
            JOIN role_permissions rp ON rp.permission_id = p.id
            WHERE rp.role_id = :rid
        ');
        $stmt->execute([':rid' => $roleId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function updateRolePermissions(int $roleId, array $permissionCodes, int $adminUserId): array
    {
        return Database::transaction(function (PDO $pdo) use ($roleId, $permissionCodes, $adminUserId): array {
            $roleStmt = $pdo->prepare('SELECT * FROM roles WHERE id = :id LIMIT 1');
            $roleStmt->execute([':id' => $roleId]);
            $role = $roleStmt->fetch();
            if (!$role) {
                throw new RuntimeException('الدور الوظيفي غير موجود.');
            }
            if ($role['name'] === 'admin') {
                throw new RuntimeException('لا يمكن تعديل صلاحيات دور المدير العام.');
            }
            if ($role['name'] === 'owner' || (int)($role['is_hidden'] ?? 0) === 1) {
                throw new RuntimeException('دور المالك محمي ولا يمكن تعديل صلاحياته.');
            }
            $codes = array_values(array_unique(array_map('strval', $permissionCodes)));
            if (!self::isOwnerUserId($pdo, $adminUserId)) {
                $codes = array_values(array_filter($codes, static function (string $code): bool {
                    return $code !== 'settings.store_identity';
                }));
            }
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :rid')->execute([':rid' => $roleId]);
            if (!empty($codes)) {
                $placeholders = [];
                $params = [':rid' => $roleId];
                foreach ($codes as $i => $code) {
                    $key = ':c' . $i;
                    $placeholders[] = $key;
                    $params[$key] = $code;
                }
                $permStmt = $pdo->prepare('SELECT id FROM permissions WHERE code IN (' . implode(',', $placeholders) . ')');
                $permStmt->execute(array_filter($params, static function ($key) {
                    return $key !== ':rid';
                }, ARRAY_FILTER_USE_KEY));
                $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:rid, :pid)');
                foreach ($permStmt->fetchAll(PDO::FETCH_COLUMN) as $permissionId) {
                    $insert->execute([':rid' => $roleId, ':pid' => $permissionId]);
                }
            }
            AuditService::log('ROLE_PERMISSIONS_UPDATED', 'roles', $roleId, "تحديث صلاحيات الدور {$role['display_name_ar']}", null, ['permissions' => $codes], $adminUserId);
            return ['role_id' => $roleId, 'permissions' => $codes];
        });
    }

    /**
     * Effective permissions and individual overrides for one user.
     */
    public static function getUserPermissions(int $userId, ?int $viewerUserId = null): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT u.id, u.full_name, u.username, r.name AS role_name, r.display_name_ar AS role_display_ar
            FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1
        ');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new RuntimeException('المستخدم غير موجود.');
        }
        if ((string)($user['role_name'] ?? '') === 'owner'
            && ($viewerUserId === null || !self::isOwnerUserId($pdo, $viewerUserId))) {
            throw new RuntimeException('صلاحيات المالك محمية ولا يمكن عرضها من هذا الحساب.');
        }

        $rolePermissions = self::getRolePermissions((int)$pdo->query('SELECT role_id FROM users WHERE id = ' . $userId)->fetchColumn(), $viewerUserId);
        $overrides = [];
        if (Database::tableExists('user_permissions')) {
            $overrideStmt = $pdo->prepare('
                SELECT p.code, up.effect
                FROM user_permissions up JOIN permissions p ON p.id = up.permission_id
                WHERE up.user_id = :uid
            ');
            $overrideStmt->execute([':uid' => $userId]);
            foreach ($overrideStmt->fetchAll() as $row) {
                $overrides[(string)$row['code']] = (string)$row['effect'];
            }
        }
        $denied = [];
        foreach ($overrides as $code => $effect) {
            if ($effect === 'deny') {
                $denied[] = (string)$code;
            }
        }
        $effective = array_values(array_unique(array_merge(
            array_diff($rolePermissions, $denied),
            array_keys(array_filter($overrides, static function ($effect): bool {
                return $effect === 'allow';
            }))
        )));

        return [
            'user_id' => $userId,
            'full_name' => $user['full_name'],
            'username' => $user['username'],
            'role_name' => $user['role_name'],
            'role_display_ar' => $user['role_display_ar'],
            'role_permissions' => $rolePermissions,
            'overrides' => $overrides,
            'effective_permissions' => $effective,
            'all_permissions' => self::allPermissionCodes(),
        ];
    }

    /**
     * Replace the individual allow/deny overrides of a user. Changes apply to
     * open sessions on their next request (no re-login required).
     *
     * @param array<mixed> $permissions
     */
    public static function updateUserPermissions(int $userId, array $permissions, int $adminUserId): array
    {
        if (!Database::tableExists('user_permissions')) {
            throw new RuntimeException('جدول الصلاحيات الفردية غير متاح في هذا التثبيت.');
        }
        return Database::transaction(function (PDO $pdo) use ($userId, $permissions, $adminUserId): array {
            $stmt = $pdo->prepare('
                SELECT u.*, r.name AS role_name
                FROM users u JOIN roles r ON r.id = u.role_id
                WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1
            ');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            if (!$user) {
                throw new RuntimeException('المستخدم غير موجود.');
            }
            if ((string)($user['role_name'] ?? '') === 'owner'
                || (int)($user['is_hidden'] ?? 0) === 1) {
                throw new RuntimeException('صلاحيات المالك محمية ولا يمكن تعديلها.');
            }
            if (!$pdo->query('SELECT 1 FROM users WHERE id = ' . $adminUserId . ' AND deleted_at IS NULL LIMIT 1')->fetchColumn()) {
                throw new RuntimeException('حساب المشرف غير صالح.');
            }

            $normalised = [];
            foreach ($permissions as $key => $value) {
                if (is_array($value)) {
                    $code = (string)($value['code'] ?? $value['permission'] ?? '');
                    $effect = (string)($value['effect'] ?? 'allow');
                } else {
                    $code = (string)$key;
                    $effect = (string)$value;
                }
                $code = trim($code);
                $effect = strtolower(trim($effect));
                if ($code === '') {
                    continue;
                }
                if (!in_array($effect, ['allow', 'deny'], true)) {
                    $effect = 'allow';
                }
                $normalised[$code] = $effect;
            }
            if (!self::isOwnerUserId($pdo, $adminUserId)) {
                unset($normalised['settings.store_identity']);
            }

            $validCodes = self::allPermissionCodes();
            $normalised = array_intersect_key($normalised, array_flip($validCodes));

            $pdo->prepare('DELETE FROM user_permissions WHERE user_id = :uid')->execute([':uid' => $userId]);
            $permStmt = $pdo->prepare('SELECT id FROM permissions WHERE code = :code LIMIT 1');
            $insert = $pdo->prepare('
                INSERT INTO user_permissions (user_id, permission_id, effect, created_by)
                VALUES (:uid, :pid, :effect, :admin)
            ');
            foreach ($normalised as $code => $effect) {
                $permStmt->execute([':code' => $code]);
                $permissionId = (int)$permStmt->fetchColumn();
                if ($permissionId <= 0) {
                    continue;
                }
                $insert->execute([
                    ':uid' => $userId,
                    ':pid' => $permissionId,
                    ':effect' => $effect,
                    ':admin' => $adminUserId,
                ]);
            }

            AuditService::log('USER_PERMISSIONS_UPDATED', 'users', $userId,
                'تحديث الصلاحيات الفردية للمستخدم ' . $user['full_name'], null,
                ['overrides' => $normalised], $adminUserId);
            return ['user_id' => $userId, 'overrides' => $normalised];
        });
    }

    /** @return array<int,string> */
    private static function allPermissionCodes(): array
    {
        try {
            return array_map('strval', Database::getConnection()->query('SELECT code FROM permissions ORDER BY code ASC')->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function getAuditLogs(array $filters = [], ?int $viewerUserId = null): array
    {
        $pdo = Database::getConnection();
        $viewerIsOwner = $viewerUserId !== null && self::isOwnerUserId($pdo, $viewerUserId);
        $sql = '
            SELECT al.*, COALESCE(u.full_name, al.username_snapshot, \'النظام\') AS user_name,
                   r.name AS actor_role_name
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE 1 = 1
        ';
        $params = [];
        if (!empty($filters['start_date'])) {
            $sql .= ' AND al.created_at >= :start';
            $params[':start'] = $filters['start_date'] . ' 00:00:00';
        }
        if (!empty($filters['end_date'])) {
            $sql .= ' AND al.created_at <= :end';
            $params[':end'] = $filters['end_date'] . ' 23:59:59';
        }
        if (!empty($filters['action_type'])) {
            $sql .= ' AND al.action_type = :action';
            $params[':action'] = (string)$filters['action_type'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= ' AND al.user_id = :uid';
            $params[':uid'] = (int)$filters['user_id'];
        }
        if (!empty($filters['q'])) {
            $query = trim((string)$filters['q']);
            $clauses = ['al.description LIKE :q1'];
            $params[':q1'] = '%' . $query . '%';

            $actionCodes = AuditService::actionCodesMatching($query);
            if (!empty($actionCodes)) {
                $actionPlaceholders = [];
                foreach ($actionCodes as $index => $code) {
                    $key = ':qa' . $index;
                    $actionPlaceholders[] = $key;
                    $params[$key] = $code;
                }
                $clauses[] = 'al.action_type IN (' . implode(', ', $actionPlaceholders) . ')';
            } else {
                $clauses[] = 'al.action_type LIKE :q2';
                $params[':q2'] = '%' . $query . '%';
            }

            $entityCodes = AuditService::entityCodesMatching($query);
            if (!empty($entityCodes)) {
                $entityPlaceholders = [];
                foreach ($entityCodes as $index => $code) {
                    $key = ':qe' . $index;
                    $entityPlaceholders[] = $key;
                    $params[$key] = $code;
                }
                $clauses[] = 'al.entity_type IN (' . implode(', ', $entityPlaceholders) . ')';
            } else {
                $clauses[] = 'al.entity_type LIKE :q3';
                $params[':q3'] = '%' . $query . '%';
            }

            $sql .= ' AND (' . implode(' OR ', $clauses) . ')';
        }
        $limit = min(max((int)($filters['limit'] ?? 300), 1), 1000);
        $sql .= ' ORDER BY al.id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $ownerIdentity = $viewerIsOwner ? ['ids' => [], 'names' => []] : self::ownerIdentity($pdo);
        foreach ($rows as &$row) {
            $row['action_label'] = AuditService::actionLabel((string)($row['action_type'] ?? ''));
            $row['entity_label'] = AuditService::entityLabel((string)($row['entity_type'] ?? ''));
            $actorRole = (string)($row['actor_role_name'] ?? '');
            unset($row['actor_role_name']);
            if ($viewerIsOwner) {
                continue;
            }
            $rowUserId = (int)($row['user_id'] ?? 0);
            $snapshotName = trim((string)($row['username_snapshot'] ?? ''));
            $isOwnerRow = ($rowUserId > 0 && in_array($rowUserId, $ownerIdentity['ids'], true))
                || $actorRole === 'owner'
                || ($snapshotName !== '' && in_array($snapshotName, $ownerIdentity['names'], true));
            if ($isOwnerRow) {
                $row['user_name'] = 'إدارة النظام';
                if (array_key_exists('username_snapshot', $row)) {
                    $row['username_snapshot'] = 'إدارة النظام';
                }
            }
            if (!empty($ownerIdentity['names'])) {
                foreach (['description', 'old_values', 'new_values'] as $field) {
                    if (array_key_exists($field, $row) && $row[$field] !== null) {
                        $row[$field] = str_replace($ownerIdentity['names'], 'إدارة النظام', (string)$row[$field]);
                    }
                }
            }
        }
        unset($row);
        return $rows;
    }

    private static function isOwnerUserId(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare('
            SELECT 1 FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id AND r.name = :role AND u.deleted_at IS NULL
            LIMIT 1
        ');
        $stmt->execute([':id' => $userId, ':role' => 'owner']);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return array{ids: array<int,int>, names: array<int,string>}
     */
    private static function ownerIdentity(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT u.id, u.username, u.full_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'owner' OR r.is_hidden = 1
        ");
        $ids = [];
        $names = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = (int)$row['id'];
            foreach ([(string)$row['username'], (string)$row['full_name']] as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        return [
            'ids' => array_values(array_unique($ids)),
            'names' => array_values(array_unique($names)),
        ];
    }

    private static function assertCanManageUser(PDO $pdo, int $targetUserId, int $actorUserId): void
    {
        if ($targetUserId === $actorUserId) {
            return;
        }
        $stmt = $pdo->prepare('
            SELECT r.name, r.is_hidden
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $targetUserId]);
        $target = $stmt->fetch();
        if (!$target) {
            return;
        }
        $protected = $target['name'] === 'owner' || (int)($target['is_hidden'] ?? 0) === 1;
        if ($protected && !self::isOwnerUserId($pdo, $actorUserId)) {
            throw new RuntimeException('هذا الحساب محمي ولا يمكن تعديله إلا بواسطة المالك.');
        }
    }

    private static function assertRoleAssignable(PDO $pdo, int $roleId, int $actorUserId): void
    {
        $stmt = $pdo->prepare('SELECT name, is_hidden FROM roles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $roleId]);
        $role = $stmt->fetch();
        if (!$role) {
            return;
        }
        $protected = $role['name'] === 'owner' || (int)($role['is_hidden'] ?? 0) === 1;
        if ($protected && !self::isOwnerUserId($pdo, $actorUserId)) {
            throw new RuntimeException('لا يمكن إسناد دور المالك من هذا الحساب.');
        }
    }

    private static function assertNotLastOwner(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('
            SELECT r.name FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $userId]);
        if ((string)$stmt->fetchColumn() !== 'owner') {
            return;
        }
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'owner' AND u.is_active = 1 AND u.deleted_at IS NULL
        ");
        $countStmt->execute();
        if ((int)$countStmt->fetchColumn() <= 1) {
            throw new RuntimeException('لا يمكن تعطيل أو حذف حساب المالك الوحيد في النظام.');
        }
    }

    private static function validateUsername($value): string
    {
        $username = trim((string)$value);
        if ($username === '') {
            throw new RuntimeException('اسم المستخدم مطلوب.');
        }
        if (strlen($username) < 3 || strlen($username) > 50) {
            throw new RuntimeException('اسم المستخدم يجب أن يكون بين 3 و50 خانة.');
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $username) !== 1) {
            throw new RuntimeException('اسم المستخدم يقبل الحروف الإنجليزية والأرقام والنقطة والشرطة فقط.');
        }
        return $username;
    }

    private static function validatePassword(string $password): string
    {
        if (strlen($password) < 8) {
            throw new RuntimeException('كلمة المرور يجب ألا تقل عن 8 خانات.');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new RuntimeException('كلمة المرور يجب أن تحتوي على حروف وأرقام.');
        }
        return $password;
    }

    private static function assertUsernameFree(PDO $pdo, string $username, ?int $ignoreId): void
    {
        $sql = 'SELECT id FROM users WHERE username = :username';
        $params = [':username' => $username];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore_id';
            $params[':ignore_id'] = $ignoreId;
        }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        if ($stmt->fetch()) {
            throw new RuntimeException('اسم المستخدم مستخدم مسبقًا.');
        }
    }

    private static function assertRoleExists(PDO $pdo, int $roleId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $roleId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('الدور الوظيفي غير موجود.');
        }
    }

    private static function assertBranchExists(PDO $pdo, int $branchId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM branches WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute([':id' => $branchId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('الفرع غير موجود أو غير مفعل.');
        }
    }

    private static function adminRoleId(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT id FROM roles WHERE name = \'admin\' LIMIT 1');
        return (int)($stmt->fetchColumn() ?: 1);
    }

    private static function assertNotLastAdmin(PDO $pdo, int $userId): void
    {
        $adminRole = self::adminRoleId($pdo);
        $stmt = $pdo->prepare('SELECT role_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        if ((int)$stmt->fetchColumn() !== $adminRole) {
            return;
        }
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = :role AND is_active = 1 AND deleted_at IS NULL');
        $countStmt->execute([':role' => $adminRole]);
        if ((int)$countStmt->fetchColumn() <= 1) {
            throw new RuntimeException('لا يمكن تعطيل أو حذف آخر حساب مدير نشط في النظام.');
        }
    }

    private static function isUniqueViolation(PDOException $e): bool
    {
        $code = (string)$e->getCode();
        $message = strtolower($e->getMessage());
        return $code === '23000' || strpos($message, 'unique') !== false || strpos($message, 'duplicate') !== false;
    }
}
