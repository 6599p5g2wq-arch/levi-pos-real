<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use PDO;
use RuntimeException;

/**
 * Cancels a completed sale and reverses every financial/inventory effect:
 * - restores the remaining (not-yet-returned) quantities to stock
 * - reverses cash payments through the open register session
 * - marks card/transfer payments as reversed
 * - removes the unpaid debt from the customer balance
 * - marks the invoice as cancelled (never deletes it)
 */
class SaleCancelService
{
    public static function cancelSale(int $saleId, string $reason, int $userId, int $branchId): array
    {
        return Database::transaction(function (PDO $pdo) use ($saleId, $reason, $userId, $branchId): array {
            $reason = Validator::optionalString($reason, 500);
            if ($reason === '') {
                $reason = 'بدون سبب محدد';
            }

            $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = :id AND branch_id = :b LIMIT 1');
            $stmt->execute([':id' => $saleId, ':b' => $branchId]);
            $sale = $stmt->fetch();
            if (!$sale) {
                throw new RuntimeException('الفاتورة غير موجودة أو لا تنتمي لفرعك.');
            }
            if ($sale['status'] === 'cancelled') {
                throw new RuntimeException('هذه الفاتورة ملغاة مسبقًا.');
            }
            if ($sale['status'] === 'held') {
                throw new RuntimeException('الفاتورة معلقة وليست مكتملة. استخدم خيار حذف الفاتورة المعلقة.');
            }
            if ($sale['status'] === 'returned' || (float)$sale['refunded_amount'] > 0) {
                throw new RuntimeException('لا يمكن إلغاء فاتورة عليها مرتجعات. يرجى إرجاع الكميات المتبقية بدلًا من الإلغاء.');
            }

            $now = date('Y-m-d H:i:s');
            $itemsStmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = :sid ORDER BY id ASC');
            $itemsStmt->execute([':sid' => $saleId]);
            $items = $itemsStmt->fetchAll();

            $restoredQty = 0;
            $restoredValue = 0.0;
            foreach ($items as $item) {
                $remaining = (int)$item['quantity'] - (int)$item['returned_quantity'];
                if ($remaining <= 0) {
                    continue;
                }
                InventoryService::addStock(
                    $pdo,
                    $branchId,
                    (int)$item['product_id'],
                    $item['variant_id'] !== null ? (int)$item['variant_id'] : null,
                    $remaining,
                    (float)$item['unit_cost'],
                    $userId,
                    'sale_cancel',
                    'sale_cancel',
                    $saleId,
                    $sale['invoice_number'],
                    "إلغاء فاتورة مبيعات رقم {$sale['invoice_number']} - إعادة للمخزون"
                );
                BatchService::restore(
                    $pdo,
                    (int)$item['product_id'],
                    $branchId,
                    (float)$remaining,
                    $item['batch_number_snapshot'] ?? null,
                    $item['expiry_date_snapshot'] ?? null
                );
                $restoredQty += $remaining;
                $restoredValue += $remaining * (float)$item['unit_cost'];
            }

            // Reverse payments and refund cash only.
            $payStmt = $pdo->prepare('SELECT * FROM payments WHERE sale_id = :sid AND reversed_at IS NULL ORDER BY id ASC');
            $payStmt->execute([':sid' => $saleId]);
            $payments = $payStmt->fetchAll();

            $cashRefund = 0.0;
            $nonCashReversed = 0.0;
            foreach ($payments as $pmt) {
                $amount = (float)$pmt['amount_base'];
                if ($amount <= 0) {
                    continue;
                }
                if ($pmt['payment_method'] === 'cash') {
                    $cashRefund = round($cashRefund + $amount, 3);
                } else {
                    $nonCashReversed = round($nonCashReversed + $amount, 3);
                }
                $pdo->prepare('UPDATE payments SET reversed_at = :now WHERE id = :id')
                    ->execute([':now' => $now, ':id' => $pmt['id']]);
            }

            if ($cashRefund > 0) {
                $sessionId = self::findOpenSessionId($pdo, $branchId, $userId);
                if ($sessionId === null) {
                    throw new RuntimeException('لا توجد وردية كاشير مفتوحة. يرجى فتح وردية الصندوق لتسجيل رد المبلغ النقدي.');
                }
                $pdo->prepare('
                    INSERT INTO cash_movements (
                        session_id, branch_id, movement_type, amount, reference_type,
                        reference_id, description, created_by, created_at
                    ) VALUES (
                        :sess, :branch, \'cancel_out\', :amt, \'sale_cancel\', :sid, :desc, :uid, :now
                    )
                ')->execute([
                    ':sess' => $sessionId,
                    ':branch' => $branchId,
                    ':amt' => $cashRefund,
                    ':sid' => $saleId,
                    ':desc' => "رد مبلغ نقدي لإلغاء فاتورة {$sale['invoice_number']}",
                    ':uid' => $userId,
                    ':now' => $now,
                ]);
            }

            // Remove unpaid debt from the customer balance (never below zero).
            $debtRemoved = 0.0;
            $debt = (float)$sale['remaining_debt'];
            if ($debt > 0 && !empty($sale['customer_id'])) {
                $custStmt = $pdo->prepare('SELECT current_debt FROM customers WHERE id = :id LIMIT 1');
                $custStmt->execute([':id' => $sale['customer_id']]);
                $currentDebt = (float)($custStmt->fetchColumn() ?: 0.0);
                $debtRemoved = min($currentDebt, $debt);
                if ($debtRemoved > 0) {
                    $pdo->prepare('UPDATE customers SET current_debt = current_debt - :amt, updated_at = :now WHERE id = :id')
                        ->execute([':amt' => $debtRemoved, ':now' => $now, ':id' => $sale['customer_id']]);
                }
            }

            $pdo->prepare('
                UPDATE sales
                SET status = \'cancelled\',
                    payment_status = \'cancelled\',
                    remaining_debt = 0,
                    cancelled_by = :uid,
                    cancel_reason = :reason,
                    cancelled_at = :now,
                    updated_at = :now2
                WHERE id = :id
            ')->execute([
                ':uid' => $userId,
                ':reason' => $reason,
                ':now' => $now,
                ':now2' => $now,
                ':id' => $saleId,
            ]);

            AuditService::log(
                'SALE_CANCELLED',
                'sales',
                $saleId,
                "إلغاء فاتورة مبيعات رقم {$sale['invoice_number']} - السبب: {$reason}",
                ['status' => $sale['status'], 'total_amount' => $sale['total_amount'], 'remaining_debt' => $sale['remaining_debt']],
                [
                    'status' => 'cancelled',
                    'cash_refund' => $cashRefund,
                    'non_cash_reversed' => $nonCashReversed,
                    'debt_removed' => $debtRemoved,
                    'restored_quantity' => $restoredQty,
                ],
                $userId
            );

            return [
                'sale_id' => $saleId,
                'invoice_number' => $sale['invoice_number'],
                'cash_refund' => $cashRefund,
                'non_cash_reversed' => $nonCashReversed,
                'debt_removed' => $debtRemoved,
                'restored_quantity' => $restoredQty,
                'restored_value' => round($restoredValue, 3),
            ];
        });
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
        // A shift opened by another user must never receive this movement.
        return null;
    }
}
