<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use PDO;
use RuntimeException;

/**
 * Operating expenses and expense categories.
 *
 * Expenses are money-out records. The amount and payment method are immutable
 * after creation; corrections are made by archiving and re-creating the expense
 * so the cash ledger always matches the drawer.
 */
class ExpenseService
{
    public static function listExpenses(int $branchId, array $filters = []): array
    {
        $pdo = Database::getConnection();
        $sql = '
            SELECT e.*, ec.name AS category_name, u.full_name AS created_by_name
            FROM expenses e
            JOIN expense_categories ec ON ec.id = e.category_id
            JOIN users u ON u.id = e.created_by
            WHERE e.branch_id = :b AND e.deleted_at IS NULL AND e.is_archived = 0
        ';
        $params = [':b' => $branchId];
        if (!empty($filters['category_id'])) {
            $sql .= ' AND e.category_id = :cat';
            $params[':cat'] = (int)$filters['category_id'];
        }
        if (!empty($filters['start_date'])) {
            $sql .= ' AND e.expense_date >= :start';
            $params[':start'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $sql .= ' AND e.expense_date <= :end';
            $params[':end'] = $filters['end_date'];
        }
        if (!empty($filters['q'])) {
            $sql .= ' AND (e.description LIKE :q1 OR e.expense_number LIKE :q2)';
            $like = '%' . trim((string)$filters['q']) . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
        }
        $limit = min(max((int)($filters['limit'] ?? 200), 1), 500);
        $sql .= ' ORDER BY e.expense_date DESC, e.id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function createExpense(array $data, int $userId, int $branchId): array
    {
        return Database::transaction(function (PDO $pdo) use ($data, $userId, $branchId): array {
            $categoryId = Validator::positiveInt($data['category_id'] ?? 0, 'تصنيف المصروف');
            $catStmt = $pdo->prepare('SELECT * FROM expense_categories WHERE id = :id AND is_active = 1 LIMIT 1');
            $catStmt->execute([':id' => $categoryId]);
            $category = $catStmt->fetch();
            if (!$category) {
                throw new RuntimeException('تصنيف المصروف غير موجود أو غير مفعل.');
            }
            $description = Validator::requireNonEmpty($data['description'] ?? null, 'وصف المصروف', 500);
            $amount = Validator::positiveDecimal($data['amount'] ?? 0, 'مبلغ المصروف', false);
            $baseCurrency = strtoupper(trim((string)SettingsService::getValue('base_currency', 'LYD')));
            $currency = strtoupper(trim((string)($data['currency_code'] ?? $baseCurrency)));
            if ($currency !== '' && $currency !== $baseCurrency) {
                throw new RuntimeException('العملة غير مدعومة في إصدار الصيدلية: ' . $currency);
            }
            $currency = $baseCurrency;
            $rate = 1.0;
            $amountBase = round($amount, 3);
            $method = Validator::enum($data['payment_method'] ?? 'cash', ['cash', 'card', 'transfer', 'cheque'], 'طريقة الدفع', 'cash');
            $expenseDate = !empty($data['expense_date'])
                ? Validator::date($data['expense_date'], 'تاريخ المصروف')
                : date('Y-m-d');
            $notes = Validator::optionalString($data['notes'] ?? '', 2000);
            $attachment = Validator::optionalString($data['attachment_path'] ?? '', 255);
            $expenseNo = NumberService::generate($pdo, 'expenses', 'expense_number', 'EXP');
            $now = date('Y-m-d H:i:s');

            $pdo->prepare('
                INSERT INTO expenses (
                    branch_id, expense_number, category_id, description, amount,
                    currency_code, exchange_rate, amount_in_base, payment_method,
                    expense_date, attachment_path, notes, is_archived, created_by, created_at, updated_at
                ) VALUES (
                    :branch_id, :expense_no, :category_id, :description, :amount,
                    :currency, :rate, :amount_base, :method,
                    :expense_date, :attachment, :notes, 0, :created_by, :now1, :now2
                )
            ')->execute([
                ':branch_id' => $branchId,
                ':expense_no' => $expenseNo,
                ':category_id' => $categoryId,
                ':description' => $description,
                ':amount' => $amount,
                ':currency' => $currency,
                ':rate' => $rate,
                ':amount_base' => $amountBase,
                ':method' => $method,
                ':expense_date' => $expenseDate,
                ':attachment' => $attachment,
                ':notes' => $notes,
                ':created_by' => $userId,
                ':now1' => $now,
                ':now2' => $now,
            ]);
            $expenseId = (int)$pdo->lastInsertId();

            if ($method === 'cash') {
                $sessionId = self::findOpenSessionId($pdo, $branchId, $userId);
                if ($sessionId === null) {
                    throw new RuntimeException('لا توجد وردية كاشير مفتوحة. يرجى فتح وردية الصندوق لتسجيل المصروف النقدي.');
                }
                $pdo->prepare('
                    INSERT INTO cash_movements (
                        session_id, branch_id, movement_type, amount, reference_type,
                        reference_id, description, created_by, created_at
                    ) VALUES (
                        :sess, :branch, \'expense_out\', :amt, \'expense\', :eid, :desc, :uid, :now
                    )
                ')->execute([
                    ':sess' => $sessionId,
                    ':branch' => $branchId,
                    ':amt' => $amountBase,
                    ':eid' => $expenseId,
                    ':desc' => "مصروف {$expenseNo} - {$category['name']}: {$description}",
                    ':uid' => $userId,
                    ':now' => $now,
                ]);
            }

            AuditService::log(
                'EXPENSE_CREATED',
                'expenses',
                $expenseId,
                "تسجيل مصروف {$expenseNo} ({$category['name']}) بمبلغ {$amountBase} د.ل: {$description}",
                null,
                ['expense_number' => $expenseNo, 'amount_base' => $amountBase, 'method' => $method, 'currency' => $currency],
                $userId
            );

            return [
                'expense_id' => $expenseId,
                'expense_number' => $expenseNo,
                'category_name' => $category['name'],
                'amount' => $amount,
                'currency_code' => $currency,
                'exchange_rate' => $rate,
                'amount_in_base' => $amountBase,
                'payment_method' => $method,
                'expense_date' => $expenseDate,
            ];
        });
    }

    public static function updateExpense(int $expenseId, array $data, int $userId, int $branchId): array
    {
        return Database::transaction(function (PDO $pdo) use ($expenseId, $data, $userId, $branchId): array {
            $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = :id AND branch_id = :b AND deleted_at IS NULL AND is_archived = 0 LIMIT 1');
            $stmt->execute([':id' => $expenseId, ':b' => $branchId]);
            $old = $stmt->fetch();
            if (!$old) {
                throw new RuntimeException('المصروف غير موجود أو مؤرشف.');
            }
            $categoryId = Validator::positiveInt($data['category_id'] ?? $old['category_id'], 'تصنيف المصروف');
            $catStmt = $pdo->prepare('SELECT id FROM expense_categories WHERE id = :id AND is_active = 1 LIMIT 1');
            $catStmt->execute([':id' => $categoryId]);
            if (!$catStmt->fetch()) {
                throw new RuntimeException('تصنيف المصروف غير موجود أو غير مفعل.');
            }
            $description = Validator::requireNonEmpty($data['description'] ?? $old['description'], 'وصف المصروف', 500);
            $notes = Validator::optionalString($data['notes'] ?? $old['notes'], 2000);
            $expenseDate = !empty($data['expense_date'])
                ? Validator::date($data['expense_date'], 'تاريخ المصروف')
                : $old['expense_date'];
            $pdo->prepare('
                UPDATE expenses SET category_id = :category_id, description = :description,
                    expense_date = :expense_date, notes = :notes, updated_at = :now
                WHERE id = :id
            ')->execute([
                ':category_id' => $categoryId,
                ':description' => $description,
                ':expense_date' => $expenseDate,
                ':notes' => $notes,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $expenseId,
            ]);
            AuditService::log('EXPENSE_UPDATED', 'expenses', $expenseId, "تعديل بيانات المصروف {$old['expense_number']}", $old, $data, $userId);
            return ['expense_id' => $expenseId];
        });
    }

    public static function archiveExpense(int $expenseId, string $reason, int $userId, int $branchId): array
    {
        return Database::transaction(function (PDO $pdo) use ($expenseId, $reason, $userId, $branchId): array {
            $reason = Validator::requireNonEmpty($reason, 'سبب إلغاء المصروف', 500);
            $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = :id AND branch_id = :b AND deleted_at IS NULL AND is_archived = 0 LIMIT 1');
            $stmt->execute([':id' => $expenseId, ':b' => $branchId]);
            $expense = $stmt->fetch();
            if (!$expense) {
                throw new RuntimeException('المصروف غير موجود أو مؤرشف مسبقًا.');
            }
            $now = date('Y-m-d H:i:s');
            $refundedCash = 0.0;
            if ($expense['payment_method'] === 'cash' && (float)$expense['amount_in_base'] > 0) {
                $sessionId = self::findOpenSessionId($pdo, $branchId, $userId);
                if ($sessionId === null) {
                    throw new RuntimeException('لا توجد وردية كاشير مفتوحة. يرجى فتح وردية الصندوق لإرجاع مبلغ المصروف للدرج.');
                }
                $refundedCash = (float)$expense['amount_in_base'];
                $pdo->prepare('
                    INSERT INTO cash_movements (
                        session_id, branch_id, movement_type, amount, reference_type,
                        reference_id, description, created_by, created_at
                    ) VALUES (
                        :sess, :branch, \'manual_in\', :amt, \'expense_cancel\', :eid, :desc, :uid, :now
                    )
                ')->execute([
                    ':sess' => $sessionId,
                    ':branch' => $branchId,
                    ':amt' => $refundedCash,
                    ':eid' => $expenseId,
                    ':desc' => "إلغاء مصروف {$expense['expense_number']} - {$reason}",
                    ':uid' => $userId,
                    ':now' => $now,
                ]);
            }
            $pdo->prepare('UPDATE expenses SET is_archived = 1, deleted_at = :now1, updated_at = :now2 WHERE id = :id')
                ->execute([':now1' => $now, ':now2' => $now, ':id' => $expenseId]);

            AuditService::log(
                'EXPENSE_CANCELLED',
                'expenses',
                $expenseId,
                "إلغاء المصروف {$expense['expense_number']} بمبلغ {$expense['amount_in_base']} د.ل - السبب: {$reason}",
                $expense,
                ['is_archived' => 1, 'cash_returned' => $refundedCash, 'reason' => $reason],
                $userId
            );
            return ['expense_id' => $expenseId, 'cash_returned' => $refundedCash];
        });
    }

    public static function listCategories(bool $onlyActive = false): array
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT * FROM expense_categories';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';
        return $pdo->query($sql)->fetchAll();
    }

    public static function saveCategory(array $data, int $userId): array
    {
        $id = !empty($data['id']) ? Validator::positiveInt($data['id'], 'معرف التصنيف') : null;
        $name = Validator::requireNonEmpty($data['name'] ?? null, 'اسم تصنيف المصروف', 100);
        $description = Validator::optionalString($data['description'] ?? '', 500);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $pdo = Database::getConnection();
        $now = date('Y-m-d H:i:s');
        if ($id !== null) {
            $pdo->prepare('UPDATE expense_categories SET name = :name, description = :desc, is_active = :active WHERE id = :id')
                ->execute([':name' => $name, ':desc' => $description, ':active' => $isActive, ':id' => $id]);
            AuditService::log('EXPENSE_CATEGORY_UPDATED', 'expense_categories', $id, "تعديل تصنيف مصروف: {$name}", null, $data, $userId);
            return ['category_id' => $id];
        }
        try {
            $pdo->prepare('INSERT INTO expense_categories (name, description, is_active, created_at) VALUES (:name, :desc, :active, :now)')
                ->execute([':name' => $name, ':desc' => $description, ':active' => $isActive, ':now' => $now]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000' || stripos($e->getMessage(), 'unique') !== false) {
                throw new RuntimeException('اسم تصنيف المصروف مستخدم مسبقًا.');
            }
            throw $e;
        }
        $newId = (int)$pdo->lastInsertId();
        AuditService::log('EXPENSE_CATEGORY_CREATED', 'expense_categories', $newId, "إضافة تصنيف مصروف: {$name}", null, $data, $userId);
        return ['category_id' => $newId];
    }

    private static function findOpenSessionId(PDO $pdo, int $branchId, int $userId): ?int
    {
        // Prefer the user's own open shift, then fall back to a shared register.
        $own = $pdo->prepare('
            SELECT s.id
            FROM cash_register_sessions s
            JOIN cash_registers r ON r.id = s.cash_register_id
            WHERE s.status = \'open\' AND r.branch_id = :b AND s.user_id = :u
            ORDER BY s.id DESC
            LIMIT 1
        ');
        $own->execute([':b' => $branchId, ':u' => $userId]);
        $ownId = $own->fetchColumn();
        if ($ownId !== false) {
            return (int)$ownId;
        }
        // A shift opened by another user must never receive this expense.
        return null;
    }
}
