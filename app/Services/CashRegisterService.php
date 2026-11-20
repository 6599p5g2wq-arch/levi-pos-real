<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use PDO;
use RuntimeException;

/**
 * Cash register sessions and end-of-day closing.
 *
 * The expected cash is always derived from the append-only cash_movements
 * ledger, not from estimates. Every money-in/money-out operation in the system
 * writes a movement row inside the same database transaction.
 */
class CashRegisterService
{
    public static function openSession(float $openingCash, int $userId, int $registerId, int $branchId = 1): array
    {
        return Database::transaction(function (PDO $pdo) use ($openingCash, $userId, $registerId, $branchId): array {
            $openingCash = Validator::positiveDecimal($openingCash, 'النقد الافتتاحي', true);

            // One open shift per user, regardless of the closing setting. Other
            // users may still open their own shift on their own register.
            $userStmt = $pdo->prepare('SELECT session_number FROM cash_register_sessions WHERE user_id = :u AND status = \'open\' LIMIT 1');
            $userStmt->execute([':u' => $userId]);
            $userSession = $userStmt->fetch();
            if ($userSession) {
                throw new RuntimeException('لديك وردية مفتوحة بالفعل (' . $userSession['session_number'] . '). يرجى إغلاقها قبل فتح وردية جديدة.');
            }

            $regStmt = $pdo->prepare('SELECT * FROM cash_registers WHERE id = :id AND branch_id = :b AND is_active = 1 LIMIT 1');
            $regStmt->execute([':id' => $registerId, ':b' => $branchId]);
            $register = $regStmt->fetch();
            if (!$register) {
                throw new RuntimeException('الصندوق غير موجود أو غير مفعل في هذا الفرع.');
            }
            if (!Database::isSqlite()) {
                // Serialise concurrent opens on the same register in MySQL.
                $lock = $pdo->prepare('SELECT id FROM cash_registers WHERE id = :id FOR UPDATE');
                $lock->execute([':id' => $registerId]);
            }

            $openStmt = $pdo->prepare('SELECT * FROM cash_register_sessions WHERE cash_register_id = :r AND status = \'open\' LIMIT 1');
            $openStmt->execute([':r' => $registerId]);
            if ($openStmt->fetch()) {
                throw new RuntimeException('الصندوق يتم استخدامه حاليا. اختر صندوقًا آخر أو انتظر إغلاق الوردية الحالية.');
            }

            $sessionNo = NumberService::generate($pdo, 'cash_register_sessions', 'session_number', 'SHIFT');
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('
                INSERT INTO cash_register_sessions (
                    cash_register_id, user_id, session_number, opening_cash, expected_cash,
                    status, opened_at
                ) VALUES (
                    :register_id, :user_id, :session_no, :opening_cash, :expected_cash,
                    \'open\', :opened_at
                )
            ')->execute([
                ':register_id' => $registerId,
                ':user_id' => $userId,
                ':session_no' => $sessionNo,
                ':opening_cash' => $openingCash,
                ':expected_cash' => $openingCash,
                ':opened_at' => $now,
            ]);
            $sessionId = (int)$pdo->lastInsertId();

            if ($openingCash > 0) {
                $pdo->prepare('
                    INSERT INTO cash_movements (
                        session_id, branch_id, movement_type, amount, reference_type,
                        reference_id, description, created_by, created_at
                    ) VALUES (
                        :sess, :branch, \'opening\', :amt, \'session\', :sid, :desc, :uid, :now
                    )
                ')->execute([
                    ':sess' => $sessionId,
                    ':branch' => $branchId,
                    ':amt' => $openingCash,
                    ':sid' => $sessionId,
                    ':desc' => 'رصيد افتتاحي للوردية ' . $sessionNo,
                    ':uid' => $userId,
                    ':now' => $now,
                ]);
            }

            AuditService::log(
                'CASH_SESSION_OPENED',
                'cash_register_sessions',
                $sessionId,
                "فتح وردية صندوق {$sessionNo} برصيد افتتاحي {$openingCash} د.ل",
                null,
                ['session_number' => $sessionNo, 'register_id' => $registerId, 'opening_cash' => $openingCash],
                $userId
            );

            return [
                'session_id' => $sessionId,
                'session_number' => $sessionNo,
                'register_id' => $registerId,
                'register_name' => $register['name'],
                'opening_cash' => $openingCash,
                'expected_cash' => $openingCash,
                'status' => 'open',
                'opened_at' => $now,
            ];
        });
    }

    /**
     * Registers that already have an open shift. The UI uses this list to
     * block choosing a register that is currently in use.
     *
     * @return array<int,int>
     */
    public static function getBusyRegisterIds(int $branchId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT s.cash_register_id
            FROM cash_register_sessions s
            JOIN cash_registers r ON r.id = s.cash_register_id
            WHERE s.status = \'open\' AND r.branch_id = :b
        ');
        $stmt->execute([':b' => $branchId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int)$id;
        }
        return $ids;
    }

    /**
     * Whether an open shift must block the user from logging out.
     * This follows the "إلزام إغلاق الوردية" setting.
     */
    public static function logoutBlockedByOpenShift(int $branchId, int $userId): bool
    {
        if (!SettingsService::isEnabled('enable_cash_register_closing', true)) {
            return false;
        }
        return self::getOpenSession($branchId, $userId) !== null;
    }

    public static function requiresShiftClosing(): bool
    {
        return SettingsService::isEnabled('enable_cash_register_closing', true);
    }

    public static function getOpenSession(int $branchId, ?int $userId = null): ?array
    {
        $pdo = Database::getConnection();
        $sql = '
            SELECT s.*, r.name AS register_name, r.code AS register_code, u.full_name AS user_name
            FROM cash_register_sessions s
            JOIN cash_registers r ON r.id = s.cash_register_id
            JOIN users u ON u.id = s.user_id
            WHERE s.status = \'open\' AND r.branch_id = :b
        ';
        $params = [':b' => $branchId];
        if ($userId !== null) {
            $sql .= ' AND s.user_id = :uid';
            $params[':uid'] = $userId;
        }
        $sql .= ' ORDER BY s.id DESC LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function addManualMovement(int $sessionId, string $type, float $amount, string $description, int $userId, int $branchId, ?int $ownerUserId = null): array
    {
        return Database::transaction(function (PDO $pdo) use ($sessionId, $type, $amount, $description, $userId, $branchId, $ownerUserId): array {
            $type = Validator::enum($type, ['manual_in', 'manual_out'], 'نوع الحركة النقدية');
            $amount = Validator::positiveDecimal($amount, 'مبلغ الحركة', false);
            $description = Validator::requireNonEmpty($description, 'بيان الحركة النقدية', 500);
            $session = self::loadOpenSession($pdo, $sessionId, $branchId, $ownerUserId);

            $now = date('Y-m-d H:i:s');
            $pdo->prepare('
                INSERT INTO cash_movements (
                    session_id, branch_id, movement_type, amount, reference_type,
                    reference_id, description, created_by, created_at
                ) VALUES (
                    :sess, :branch, :type, :amt, \'manual\', NULL, :desc, :uid, :now
                )
            ')->execute([
                ':sess' => $sessionId,
                ':branch' => $branchId,
                ':type' => $type,
                ':amt' => $amount,
                ':desc' => $description,
                ':uid' => $userId,
                ':now' => $now,
            ]);

            AuditService::log(
                'CASH_MANUAL_MOVEMENT',
                'cash_movements',
                (int)$pdo->lastInsertId(),
                "حركة نقدية يدوية ({$type}) بمبلغ {$amount} د.ل: {$description}",
                null,
                ['session_id' => $sessionId, 'type' => $type, 'amount' => $amount],
                $userId
            );

            return self::calculateSession($pdo, (int)$session['id'], $branchId, $ownerUserId);
        });
    }

    public static function getSessionSummary(int $sessionId, int $branchId, ?int $ownerUserId = null): array
    {
        $pdo = Database::getConnection();
        return self::calculateSession($pdo, $sessionId, $branchId, $ownerUserId);
    }

    public static function closeSession(int $sessionId, float $actualCash, ?string $reason, int $userId, int $branchId, ?int $ownerUserId = null): array
    {
        return Database::transaction(function (PDO $pdo) use ($sessionId, $actualCash, $reason, $userId, $branchId, $ownerUserId): array {
            $session = self::loadOpenSession($pdo, $sessionId, $branchId, $ownerUserId);
            $actualCash = Validator::positiveDecimal($actualCash, 'النقد الفعلي', true);
            $summary = self::calculateSession($pdo, $sessionId, $branchId, $ownerUserId);
            $expected = (float)$summary['expected_cash'];
            $discrepancy = round($actualCash - $expected, 3);
            $reason = Validator::optionalString($reason ?? '', 1000);
            if (abs($discrepancy) > 0.009 && $reason === '') {
                throw new RuntimeException('يوجد فرق في الصندوق بمقدار ' . $discrepancy . ' د.ل. يرجى كتابة سبب الفرق.');
            }

            $now = date('Y-m-d H:i:s');
            $pdo->prepare('
                UPDATE cash_register_sessions
                SET total_sales = :total_sales,
                    cash_sales = :cash_sales,
                    card_sales = :card_sales,
                    cash_expenses = :cash_expenses,
                    cash_refunds = :cash_refunds,
                    expected_cash = :expected_cash,
                    actual_cash = :actual_cash,
                    discrepancy = :discrepancy,
                    discrepancy_reason = :reason,
                    status = \'closed\',
                    closed_at = :closed_at,
                    closed_by = :closed_by
                WHERE id = :id
            ')->execute([
                ':total_sales' => $summary['total_sales'],
                ':cash_sales' => $summary['cash_sales'],
                ':card_sales' => $summary['card_sales'],
                ':cash_expenses' => $summary['cash_expenses'],
                ':cash_refunds' => $summary['cash_refunds'],
                ':expected_cash' => $expected,
                ':actual_cash' => $actualCash,
                ':discrepancy' => $discrepancy,
                ':reason' => $reason !== '' ? $reason : null,
                ':closed_at' => $now,
                ':closed_by' => $userId,
                ':id' => $sessionId,
            ]);

            AuditService::log(
                'CASH_SESSION_CLOSED',
                'cash_register_sessions',
                $sessionId,
                "إغلاق وردية {$session['session_number']} - المتوقع {$expected} د.ل والفعلي {$actualCash} د.ل والفرق {$discrepancy} د.ل",
                ['status' => 'open', 'expected_cash' => $expected],
                [
                    'status' => 'closed',
                    'actual_cash' => $actualCash,
                    'discrepancy' => $discrepancy,
                    'reason' => $reason,
                    'summary' => $summary,
                ],
                $userId
            );

            return array_merge($summary, [
                'status' => 'closed',
                'actual_cash' => $actualCash,
                'discrepancy' => $discrepancy,
                'discrepancy_reason' => $reason,
                'closed_at' => $now,
                'closed_by' => $userId,
            ]);
        });
    }

    public static function getSessions(int $branchId, array $filters = []): array
    {
        $pdo = Database::getConnection();
        $sql = '
            SELECT s.*, r.name AS register_name, u.full_name AS user_name, cb.full_name AS closed_by_name
            FROM cash_register_sessions s
            JOIN cash_registers r ON r.id = s.cash_register_id
            JOIN users u ON u.id = s.user_id
            LEFT JOIN users cb ON cb.id = s.closed_by
            WHERE r.branch_id = :b
        ';
        $params = [':b' => $branchId];
        if (!empty($filters['status'])) {
            $sql .= ' AND s.status = :status';
            $params[':status'] = (string)$filters['status'];
        }
        if (!empty($filters['start_date'])) {
            $sql .= ' AND s.opened_at >= :start';
            $params[':start'] = $filters['start_date'] . ' 00:00:00';
        }
        if (!empty($filters['end_date'])) {
            $sql .= ' AND s.opened_at <= :end';
            $params[':end'] = $filters['end_date'] . ' 23:59:59';
        }
        $limit = min(max((int)($filters['limit'] ?? 50), 1), 300);
        $sql .= ' ORDER BY s.id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getSessionMovements(int $sessionId, int $branchId, ?int $ownerUserId = null): array
    {
        $pdo = Database::getConnection();
        self::loadSession($pdo, $sessionId, $branchId, $ownerUserId);
        $stmt = $pdo->prepare('
            SELECT m.*, u.full_name AS user_name
            FROM cash_movements m
            JOIN users u ON u.id = m.created_by
            WHERE m.session_id = :sid AND m.branch_id = :b
            ORDER BY m.id ASC
        ');
        $stmt->execute([':sid' => $sessionId, ':b' => $branchId]);
        return $stmt->fetchAll();
    }

    private static function calculateSession(PDO $pdo, int $sessionId, int $branchId, ?int $ownerUserId = null): array
    {
        $session = self::loadSession($pdo, $sessionId, $branchId, $ownerUserId);

        $movStmt = $pdo->prepare('
            SELECT movement_type, COALESCE(SUM(amount), 0) AS total
            FROM cash_movements
            WHERE session_id = :sid
            GROUP BY movement_type
        ');
        $movStmt->execute([':sid' => $sessionId]);
        $byType = [];
        foreach ($movStmt->fetchAll() as $row) {
            $byType[$row['movement_type']] = (float)$row['total'];
        }

        $opening = (float)$session['opening_cash'];
        $saleIn = (float)($byType['sale_in'] ?? 0);
        $debtCollection = (float)($byType['debt_collection'] ?? 0);
        $manualIn = (float)($byType['manual_in'] ?? 0);
        $purchaseCancelIn = (float)($byType['purchase_cancel_in'] ?? 0);
        $purchaseReturnIn = (float)($byType['purchase_return_in'] ?? 0);
        $refundOut = (float)($byType['refund_out'] ?? 0);
        $cancelOut = (float)($byType['cancel_out'] ?? 0);
        $expenseOut = (float)($byType['expense_out'] ?? 0);
        $purchaseOut = (float)($byType['purchase_out'] ?? 0);
        $supplierPaymentOut = (float)($byType['supplier_payment_out'] ?? 0);
        $manualOut = (float)($byType['manual_out'] ?? 0);

        $expected = round(
            $opening + $saleIn + $debtCollection + $manualIn + $purchaseCancelIn + $purchaseReturnIn
            - $refundOut - $cancelOut - $expenseOut - $purchaseOut - $supplierPaymentOut - $manualOut,
            3
        );

        // Card/transfer/cheque totals for the sales that belong to this shift.
        $cardStmt = $pdo->prepare('
            SELECT p.payment_method, COALESCE(SUM(p.amount_base), 0) AS total
            FROM payments p
            JOIN sales s ON s.id = p.sale_id
            WHERE s.session_id = :sid
              AND p.reversed_at IS NULL
              AND s.status NOT IN (\'cancelled\', \'held\')
            GROUP BY p.payment_method
        ');
        $cardStmt->execute([':sid' => $sessionId]);
        $cardByMethod = [];
        foreach ($cardStmt->fetchAll() as $row) {
            $cardByMethod[$row['payment_method']] = (float)$row['total'];
        }

        $salesStmt = $pdo->prepare('
            SELECT COALESCE(SUM(total_amount), 0) AS total_sales, COUNT(*) AS invoice_count
            FROM sales
            WHERE session_id = :sid AND status NOT IN (\'cancelled\', \'held\')
        ');
        $salesStmt->execute([':sid' => $sessionId]);
        $salesRow = $salesStmt->fetch() ?: ['total_sales' => 0, 'invoice_count' => 0];

        $cashRefunds = round($refundOut + $cancelOut, 3);

        return [
            'session_id' => $sessionId,
            'session_number' => $session['session_number'],
            'register_id' => (int)$session['cash_register_id'],
            'register_name' => $session['register_name'],
            'user_id' => (int)$session['user_id'],
            'user_name' => $session['user_name'],
            'status' => $session['status'],
            'opened_at' => $session['opened_at'],
            'closed_at' => $session['closed_at'],
            'opening_cash' => $opening,
            'cash_sales' => round($saleIn, 3),
            'debt_collection' => round($debtCollection, 3),
            'manual_in' => round($manualIn, 3),
            'purchase_cancel_in' => round($purchaseCancelIn, 3),
            'purchase_return_in' => round($purchaseReturnIn, 3),
            'refund_out' => round($refundOut, 3),
            'cancel_out' => round($cancelOut, 3),
            'expense_out' => round($expenseOut, 3),
            'purchase_out' => round($purchaseOut, 3),
            'supplier_payment_out' => round($supplierPaymentOut, 3),
            'manual_out' => round($manualOut, 3),
            'cash_expenses' => round($expenseOut, 3),
            'cash_refunds' => $cashRefunds,
            'expected_cash' => $expected,
            'actual_cash' => $session['actual_cash'] !== null ? (float)$session['actual_cash'] : null,
            'discrepancy' => $session['discrepancy'] !== null ? (float)$session['discrepancy'] : null,
            'discrepancy_reason' => $session['discrepancy_reason'],
            'total_sales' => round((float)$salesRow['total_sales'], 3),
            'invoice_count' => (int)$salesRow['invoice_count'],
            'card_sales' => round(($cardByMethod['card'] ?? 0) + ($cardByMethod['transfer'] ?? 0) + ($cardByMethod['cheque'] ?? 0), 3),
            'card_breakdown' => [
                'card' => round($cardByMethod['card'] ?? 0, 3),
                'transfer' => round($cardByMethod['transfer'] ?? 0, 3),
                'cheque' => round($cardByMethod['cheque'] ?? 0, 3),
            ],
            'movement_totals' => $byType,
        ];
    }

    private static function loadSession(PDO $pdo, int $sessionId, int $branchId, ?int $ownerUserId = null): array
    {
        $sql = '
            SELECT s.*, r.name AS register_name, r.code AS register_code, r.branch_id, u.full_name AS user_name
            FROM cash_register_sessions s
            JOIN cash_registers r ON r.id = s.cash_register_id
            JOIN users u ON u.id = s.user_id
            WHERE s.id = :id AND r.branch_id = :b
        ';
        $params = [':id' => $sessionId, ':b' => $branchId];
        if ($ownerUserId !== null) {
            $sql .= ' AND s.user_id = :uid';
            $params[':uid'] = $ownerUserId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $session = $stmt->fetch();
        if (!$session) {
            throw new RuntimeException('وردية الصندوق غير موجودة أو لا تنتمي لفرعك.');
        }
        return $session;
    }

    private static function loadOpenSession(PDO $pdo, int $sessionId, int $branchId, ?int $ownerUserId = null): array
    {
        $session = self::loadSession($pdo, $sessionId, $branchId, $ownerUserId);
        if ($session['status'] !== 'open') {
            throw new RuntimeException('وردية الصندوق مغلقة بالفعل.');
        }
        return $session;
    }
}
