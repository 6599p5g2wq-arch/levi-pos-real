<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use PDO;
use RuntimeException;

/**
 * Sales returns & exchanges engine.
 *
 * Refunds are calculated from sale_items.net_total (the amount actually paid
 * after all discounts), never from the pre-discount price. The engine also:
 * - prevents returning more than the remaining quantity
 * - offsets outstanding debt before paying cash/card
 * - restocks only resellable items
 * - writes cash movements through the open register session
 * - keeps the original invoice for audit (never deletes it)
 */
class ReturnService
{
    public static function processSaleReturn(array $data, int $userId, int $branchId = 1): array
    {
        return Database::transaction(function (PDO $pdo) use ($data, $userId, $branchId): array {
            $saleId = Validator::positiveInt($data['sale_id'] ?? 0, 'رقم الفاتورة الأصلية');
            $reason = Validator::optionalString($data['reason'] ?? '', 500);
            if ($reason === '') {
                $reason = 'بدون سبب محدد';
            }
            $returnType = Validator::enum($data['return_type'] ?? 'refund', ['refund', 'exchange'], 'نوع العملية', 'refund');
            $refundMethod = Validator::enum($data['refund_method'] ?? 'cash', ['cash', 'card', 'debt_deduction'], 'طريقة رد المبلغ', 'cash');
            $notes = Validator::optionalString($data['notes'] ?? '', 2000);
            $exchangeSaleId = !empty($data['exchange_sale_id']) ? (int)$data['exchange_sale_id'] : null;

            $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = :id AND branch_id = :b LIMIT 1');
            $stmt->execute([':id' => $saleId, ':b' => $branchId]);
            $sale = $stmt->fetch();
            if (!$sale) {
                throw new RuntimeException('الفاتورة الأصلية غير موجودة أو لا تنتمي لفرعك.');
            }
            if (in_array($sale['status'], ['cancelled', 'held'], true)) {
                throw new RuntimeException('لا يمكن إرجاع فاتورة ملغاة أو معلقة.');
            }

            $returnItems = $data['items'] ?? null;
            if (!is_array($returnItems) || empty($returnItems)) {
                throw new RuntimeException('يجب تحديد منتج واحد على الأقل للإرجاع.');
            }

            $itemsStmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = :sid ORDER BY id ASC');
            $itemsStmt->execute([':sid' => $saleId]);
            $saleItems = [];
            foreach ($itemsStmt->fetchAll() as $row) {
                $saleItems[(int)$row['id']] = $row;
            }
            if (empty($saleItems)) {
                throw new RuntimeException('لا توجد أصناف في الفاتورة الأصلية.');
            }

            // How much was already refunded per sale item (for rounding safety).
            $refundedStmt = $pdo->prepare('
                SELECT sri.sale_item_id, COALESCE(SUM(sri.line_refund_total), 0) AS refunded
                FROM sale_return_items sri
                JOIN sale_returns sr ON sr.id = sri.sale_return_id
                WHERE sr.sale_id = :sid
                GROUP BY sri.sale_item_id
            ');
            $refundedStmt->execute([':sid' => $saleId]);
            $alreadyRefunded = $refundedStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $prepared = [];
            $totalRefund = 0.0;
            $totalReturnedCogs = 0.0;
            $totalRestockedQty = 0;

            foreach ($returnItems as $line) {
                if (!is_array($line)) {
                    throw new RuntimeException('بيانات أحد أسطر الإرجاع غير صالحة.');
                }
                $saleItemId = Validator::positiveInt($line['sale_item_id'] ?? 0, 'معرف صنف الفاتورة');
                if (!isset($saleItems[$saleItemId])) {
                    throw new RuntimeException('أحد الأصناف المحددة لا ينتمي لهذه الفاتورة.');
                }
                $original = $saleItems[$saleItemId];
                $qty = Validator::positiveInt($line['quantity'] ?? 0, 'الكمية المرتجعة', false, 100000);
                $available = (int)$original['quantity'] - (int)$original['returned_quantity'];
                if ($qty > $available) {
                    throw new RuntimeException("الكمية المرتجعة للصنف ({$original['product_name_snapshot']}) هي {$qty} بينما المتبقي {$available} فقط.");
                }

                $condition = Validator::enum(
                    $line['item_condition'] ?? 'resellable',
                    ['resellable', 'damaged', 'needs_inspection', 'non_resellable'],
                    'حالة الصنف المرتجع',
                    'resellable'
                );

                $netTotal = (float)$original['net_total'];
                $origQty = max(1, (int)$original['quantity']);
                $netUnit = round($netTotal / $origQty, 3);
                $lineRefund = round($netUnit * $qty, 3);

                $prevRefunded = (float)($alreadyRefunded[$saleItemId] ?? 0.0);
                $maxRefundable = round($netTotal - $prevRefunded, 3);
                if ((int)$original['returned_quantity'] + $qty >= (int)$original['quantity']) {
                    $lineRefund = $maxRefundable;
                }
                $lineRefund = max(0.0, min($lineRefund, $maxRefundable));

                $unitCost = (float)$original['unit_cost'];
                $lineCogs = round($unitCost * $qty, 3);

                $prepared[] = [
                    'sale_item_id' => $saleItemId,
                    'product_id' => (int)$original['product_id'],
                    'variant_id' => $original['variant_id'] !== null ? (int)$original['variant_id'] : null,
                    'quantity' => $qty,
                    'unit_refund_price' => $netUnit,
                    'unit_cost' => $unitCost,
                    'line_refund_total' => $lineRefund,
                    'item_condition' => $condition,
                    'restocked' => $condition === 'resellable' ? 1 : 0,
                    'line_cogs' => $lineCogs,
                    'product_name' => $original['product_name_snapshot'],
                    'batch_number_snapshot' => $original['batch_number_snapshot'] ?? null,
                    'expiry_date_snapshot' => $original['expiry_date_snapshot'] ?? null,
                ];

                $totalRefund = round($totalRefund + $lineRefund, 3);
                $totalReturnedCogs = round($totalReturnedCogs + $lineCogs, 3);
                if ($condition === 'resellable') {
                    $totalRestockedQty += $qty;
                }
            }

            // Never refund more than the invoice value that has not been refunded yet.
            $maxGlobal = round((float)$sale['total_amount'] - (float)$sale['refunded_amount'], 3);
            if ($totalRefund > $maxGlobal + 0.0005) {
                $scale = $maxGlobal > 0 ? ($maxGlobal / $totalRefund) : 0.0;
                $remaining = $maxGlobal;
                $lastIndex = count($prepared) - 1;
                foreach ($prepared as $i => &$item) {
                    if ($i === $lastIndex) {
                        $item['line_refund_total'] = round(max(0.0, $remaining), 3);
                    } else {
                        $item['line_refund_total'] = round($item['line_refund_total'] * $scale, 3);
                        $remaining = round($remaining - $item['line_refund_total'], 3);
                    }
                }
                unset($item);
                $totalRefund = round(array_sum(array_column($prepared, 'line_refund_total')), 3);
            }

            // Offset outstanding debt before refunding money.
            $outstandingDebt = 0.0;
            if (!empty($sale['customer_id'])) {
                $debtStmt = $pdo->prepare('SELECT current_debt FROM customers WHERE id = :id LIMIT 1');
                $debtStmt->execute([':id' => $sale['customer_id']]);
                $customerDebt = (float)($debtStmt->fetchColumn() ?: 0.0);
                $outstandingDebt = max(0.0, min((float)$sale['remaining_debt'], $customerDebt));
            }

            $debtReduction = min($totalRefund, $outstandingDebt);
            $moneyRefund = round($totalRefund - $debtReduction, 3);
            $cashRefund = 0.0;
            $cardRefund = 0.0;
            if ($moneyRefund > 0) {
                if ($refundMethod === 'card') {
                    $cardRefund = $moneyRefund;
                } else {
                    // cash and debt_deduction both pay any excess in cash
                    $cashRefund = $moneyRefund;
                }
            }

            $now = date('Y-m-d H:i:s');
            $returnNo = NumberService::generate($pdo, 'sale_returns', 'return_number', 'RET');

            $returnStmt = $pdo->prepare('
                INSERT INTO sale_returns (
                    branch_id, return_number, sale_id, customer_id, return_type, exchange_sale_id,
                    total_refund_amount, total_returned_cogs, cash_refund_amount, card_refund_amount,
                    debt_reduction_amount, price_difference, refund_method, reason, notes,
                    created_by, created_at
                ) VALUES (
                    :branch_id, :ret_no, :sale_id, :cust_id, :rtype, :exchange_sale_id,
                    :total_refund, :total_cogs, :cash_refund, :card_refund,
                    :debt_reduction, :price_diff, :refund_method, :reason, :notes,
                    :created_by, :now
                )
            ');
            $returnStmt->execute([
                ':branch_id' => $branchId,
                ':ret_no' => $returnNo,
                ':sale_id' => $saleId,
                ':cust_id' => $sale['customer_id'],
                ':rtype' => $returnType,
                ':exchange_sale_id' => $exchangeSaleId,
                ':total_refund' => $totalRefund,
                ':total_cogs' => $totalReturnedCogs,
                ':cash_refund' => $cashRefund,
                ':card_refund' => $cardRefund,
                ':debt_reduction' => $debtReduction,
                ':price_diff' => 0,
                ':refund_method' => $refundMethod,
                ':reason' => $reason,
                ':notes' => $notes,
                ':created_by' => $userId,
                ':now' => $now,
            ]);
            $returnId = (int)$pdo->lastInsertId();

            $costingMethod = (string)levi_setting('costing_method', 'weighted_average');
            $itemStmt = $pdo->prepare('
                INSERT INTO sale_return_items (
                    sale_return_id, sale_item_id, product_id, variant_id, quantity,
                    unit_refund_price, unit_cost, line_refund_total, item_condition, restocked
                ) VALUES (
                    :return_id, :sale_item_id, :product_id, :variant_id, :qty,
                    :unit_refund, :unit_cost, :line_total, :condition, :restocked
                )
            ');

            foreach ($prepared as $item) {
                $itemStmt->execute([
                    ':return_id' => $returnId,
                    ':sale_item_id' => $item['sale_item_id'],
                    ':product_id' => $item['product_id'],
                    ':variant_id' => $item['variant_id'],
                    ':qty' => $item['quantity'],
                    ':unit_refund' => $item['unit_refund_price'],
                    ':unit_cost' => $item['unit_cost'],
                    ':line_total' => $item['line_refund_total'],
                    ':condition' => $item['item_condition'],
                    ':restocked' => $item['restocked'],
                ]);

                $pdo->prepare('UPDATE sale_items SET returned_quantity = returned_quantity + :qty WHERE id = :id')
                    ->execute([':qty' => $item['quantity'], ':id' => $item['sale_item_id']]);

                if ($item['restocked'] === 1) {
                    InventoryService::addStock(
                        $pdo,
                        $branchId,
                        $item['product_id'],
                        $item['variant_id'],
                        $item['quantity'],
                        $item['unit_cost'],
                        $userId,
                        'sale_return',
                        'sale_return',
                        $returnId,
                        $returnNo,
                        "مرتجع مبيعات بموجب {$returnNo} - فاتورة {$sale['invoice_number']}"
                    );
                    if ($costingMethod === 'fifo') {
                        InventoryService::restoreFifoLayer($pdo, $item['product_id'], $item['variant_id'], $item['quantity']);
                    }
                    // Pharmacy: return the sold lot to its original batch.
                    BatchService::restore(
                        $pdo,
                        (int)$item['product_id'],
                        $branchId,
                        (float)$item['quantity'],
                        $item['batch_number_snapshot'],
                        $item['expiry_date_snapshot']
                    );
                }
            }

            // Cash refund through the open register session.
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
                        :sess, :branch, \'refund_out\', :amt, \'sale_return\', :rid, :desc, :uid, :now
                    )
                ')->execute([
                    ':sess' => $sessionId,
                    ':branch' => $branchId,
                    ':amt' => $cashRefund,
                    ':rid' => $returnId,
                    ':desc' => "رد مبلغ نقدي لمرتجع {$returnNo} - فاتورة {$sale['invoice_number']}",
                    ':uid' => $userId,
                    ':now' => $now,
                ]);
            }

            // Reduce customer debt.
            if ($debtReduction > 0 && !empty($sale['customer_id'])) {
                $pdo->prepare('UPDATE customers SET current_debt = current_debt - :amt, updated_at = :now WHERE id = :id')
                    ->execute([':amt' => $debtReduction, ':now' => $now, ':id' => $sale['customer_id']]);
            }

            // Update the original sale.
            $newRefunded = round((float)$sale['refunded_amount'] + $totalRefund, 3);
            $newPaid = max(0.0, round((float)$sale['paid_amount'] - $cashRefund - $cardRefund, 3));
            $newDebt = max(0.0, round((float)$sale['remaining_debt'] - $debtReduction, 3));

            $remainingQtyStmt = $pdo->prepare('SELECT COUNT(*) FROM sale_items WHERE sale_id = :sid AND returned_quantity < quantity');
            $remainingQtyStmt->execute([':sid' => $saleId]);
            $stillHasItems = (int)$remainingQtyStmt->fetchColumn() > 0;
            $newStatus = $stillHasItems ? 'completed' : 'returned';
            $newPaymentStatus = $newDebt > 0 ? ($newPaid > 0 ? 'partial' : 'unpaid') : 'paid';
            if (!$stillHasItems) {
                $newPaymentStatus = 'refunded';
            }

            $pdo->prepare('
                UPDATE sales
                SET status = :status,
                    payment_status = :pstatus,
                    paid_amount = :paid,
                    remaining_debt = :debt,
                    refunded_amount = :refunded,
                    updated_at = :now
                WHERE id = :id
            ')->execute([
                ':status' => $newStatus,
                ':pstatus' => $newPaymentStatus,
                ':paid' => $newPaid,
                ':debt' => $newDebt,
                ':refunded' => $newRefunded,
                ':now' => $now,
                ':id' => $saleId,
            ]);

            AuditService::log(
                'SALE_RETURNED',
                'sale_returns',
                $returnId,
                "مرتجع مبيعات رقم {$returnNo} للفاتورة {$sale['invoice_number']} بقيمة {$totalRefund} د.ل - السبب: {$reason}",
                null,
                [
                    'return_number' => $returnNo,
                    'sale_id' => $saleId,
                    'total_refund' => $totalRefund,
                    'cash_refund' => $cashRefund,
                    'card_refund' => $cardRefund,
                    'debt_reduction' => $debtReduction,
                    'restocked_quantity' => $totalRestockedQty,
                    'items' => $prepared,
                ],
                $userId
            );

            return [
                'return_id' => $returnId,
                'return_number' => $returnNo,
                'sale_id' => $saleId,
                'invoice_number' => $sale['invoice_number'],
                'total_refund_amount' => $totalRefund,
                'total_returned_cogs' => $totalReturnedCogs,
                'cash_refund_amount' => $cashRefund,
                'card_refund_amount' => $cardRefund,
                'debt_reduction_amount' => $debtReduction,
                'restocked_quantity' => $totalRestockedQty,
                'sale_status' => $newStatus,
                'remaining_debt' => $newDebt,
                'open_cash_drawer' => $cashRefund > 0,
            ];
        });
    }

    public static function getReturnDetails(int $returnId, int $branchId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT sr.*, s.invoice_number, c.name AS customer_name, u.full_name AS created_by_name
            FROM sale_returns sr
            JOIN sales s ON s.id = sr.sale_id
            LEFT JOIN customers c ON c.id = sr.customer_id
            JOIN users u ON u.id = sr.created_by
            WHERE sr.id = :id AND sr.branch_id = :b
            LIMIT 1
        ');
        $stmt->execute([':id' => $returnId, ':b' => $branchId]);
        $return = $stmt->fetch();
        if (!$return) {
            throw new RuntimeException('المرتجع غير موجود.');
        }
        $itemsStmt = $pdo->prepare('
            SELECT sri.*, p.name AS product_name, v.variant_name
            FROM sale_return_items sri
            JOIN products p ON p.id = sri.product_id
            LEFT JOIN product_variants v ON v.id = sri.variant_id
            WHERE sri.sale_return_id = :rid
            ORDER BY sri.id ASC
        ');
        $itemsStmt->execute([':rid' => $returnId]);
        $return['items'] = $itemsStmt->fetchAll();
        return $return;
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
        // A shift opened by another user must never receive this refund.
        return null;
    }
}
