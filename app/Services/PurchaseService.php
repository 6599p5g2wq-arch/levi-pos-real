<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use PDO;
use RuntimeException;

/**
 * Purchase invoices, landed costs, supplier payments, purchase returns and
 * purchase cancellation.
 *
 * Supplier balance convention: positive = we owe the supplier,
 * negative = the supplier owes us (advance/credit).
 */
class PurchaseService
{
    public static function createPurchase(array $data, int $userId, int $branchId = 1): array
    {
        return Database::transaction(function (PDO $pdo) use ($data, $userId, $branchId): array {
            $supplierId = Validator::positiveInt($data['supplier_id'] ?? 0, 'المورد');
            $supplier = self::loadSupplier($pdo, $supplierId);
            $items = $data['items'] ?? null;
            if (!is_array($items) || empty($items)) {
                throw new RuntimeException('يجب إضافة منتج واحد على الأقل في فاتورة الشراء.');
            }
            if (count($items) > 300) {
                throw new RuntimeException('عدد أسطر فاتورة الشراء يتجاوز الحد الأقصى (300 سطر).');
            }

            $invoiceDiscount = Validator::positiveDecimal($data['discount_amount'] ?? 0, 'خصم الفاتورة', true);
            $shippingCost = Validator::positiveDecimal($data['shipping_cost'] ?? 0, 'تكلفة الشحن', true);
            $extraCost = Validator::positiveDecimal($data['extra_cost'] ?? 0, 'التكاليف الإضافية', true);
            $allocationMethod = Validator::enum($data['cost_allocation_method'] ?? 'value', ['value', 'quantity'], 'طريقة توزيع التكاليف', 'value');
            $supplierInvoiceNo = Validator::optionalString($data['supplier_invoice_no'] ?? '', 100);
            $notes = Validator::optionalString($data['notes'] ?? '', 2000);
            $purchaseDate = !empty($data['purchase_date'])
                ? Validator::dateTime($data['purchase_date'], 'تاريخ فاتورة الشراء')
                : date('Y-m-d H:i:s');
            $dueDate = !empty($data['due_date'])
                ? Validator::date($data['due_date'], 'تاريخ استحقاق المورد')
                : null;

            $prepared = [];
            $subtotal = 0.0;
            $lineDiscountTotal = 0.0;
            foreach ($items as $line) {
                if (!is_array($line)) {
                    throw new RuntimeException('بيانات أحد أسطر فاتورة الشراء غير صالحة.');
                }
                $productId = Validator::positiveInt($line['product_id'] ?? 0, 'المنتج');
                $variantId = !empty($line['variant_id']) ? Validator::positiveInt($line['variant_id'], 'الخيار') : null;
                $qty = Validator::positiveInt($line['quantity'] ?? 0, 'الكمية', false, 100000);
                $unitCost = Validator::positiveDecimal($line['unit_cost'] ?? 0, 'سعر التكلفة', true);
                $lineDiscount = Validator::positiveDecimal($line['discount_amount'] ?? 0, 'خصم السطر', true);
                $gross = round($qty * $unitCost, 3);
                if ($lineDiscount > $gross) {
                    throw new RuntimeException('خصم أحد أسطر الشراء يتجاوز قيمته الإجمالية.');
                }
                $lineNet = round($gross - $lineDiscount, 3);

                // Pharmacy batch metadata (optional for backward compatibility).
                $batchNumber = Validator::optionalString($line['batch_number'] ?? '', 120);
                $expiryDate = !empty($line['expiry_date'])
                    ? Validator::date($line['expiry_date'], 'تاريخ الانتهاء')
                    : null;
                $manufacturingDate = !empty($line['manufacturing_date'])
                    ? Validator::date($line['manufacturing_date'], 'تاريخ التصنيع')
                    : null;
                if ($expiryDate !== null && $manufacturingDate !== null && $manufacturingDate > $expiryDate) {
                    throw new RuntimeException('تاريخ التصنيع يجب أن يكون قبل تاريخ الانتهاء.');
                }

                $prod = self::loadProduct($pdo, $productId, $variantId);
                $prepared[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'product_name' => $prod['name'],
                    'batch_number' => $batchNumber,
                    'manufacturing_date' => $manufacturingDate,
                    'expiry_date' => $expiryDate,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'line_discount' => $lineDiscount,
                    'line_gross' => $gross,
                    'line_net' => $lineNet,
                    'invoice_discount_share' => 0.0,
                    'line_cost_base' => $lineNet,
                    'allocated_extra' => 0.0,
                    'landed_unit_cost' => $unitCost,
                ];
                $subtotal = round($subtotal + $gross, 3);
                $lineDiscountTotal = round($lineDiscountTotal + $lineDiscount, 3);
            }

            $netGoods = round($subtotal - $lineDiscountTotal, 3);
            if ($invoiceDiscount > $netGoods) {
                throw new RuntimeException('خصم فاتورة الشراء يتجاوز صافي قيمة الأصناف.');
            }

            // Allocate invoice discount proportionally to the net line value.
            $remainingInvDiscount = $invoiceDiscount;
            $lastIndex = count($prepared) - 1;
            foreach ($prepared as $i => &$item) {
                if ($i === $lastIndex) {
                    $share = round($remainingInvDiscount, 3);
                } elseif ($netGoods > 0) {
                    $share = round($invoiceDiscount * ($item['line_net'] / $netGoods), 3);
                } else {
                    $share = 0.0;
                }
                $share = min($share, $item['line_net']);
                $remainingInvDiscount = round($remainingInvDiscount - $share, 3);
                $item['invoice_discount_share'] = $share;
                $item['line_cost_base'] = round($item['line_net'] - $share, 3);
            }
            unset($item);

            $extraCosts = round($shippingCost + $extraCost, 3);
            $goodsNetTotal = round($netGoods - $invoiceDiscount, 3);
            $baseForExtra = $allocationMethod === 'quantity'
                ? array_sum(array_column($prepared, 'quantity'))
                : $goodsNetTotal;

            $remainingExtra = $extraCosts;
            foreach ($prepared as $i => &$item) {
                if ($i === $lastIndex) {
                    $share = round($remainingExtra, 3);
                } elseif ($baseForExtra > 0) {
                    $weight = $allocationMethod === 'quantity' ? (float)$item['quantity'] : (float)$item['line_cost_base'];
                    $share = round($extraCosts * ($weight / $baseForExtra), 3);
                } else {
                    $share = 0.0;
                }
                $remainingExtra = round($remainingExtra - $share, 3);
                $item['allocated_extra'] = $share;
                $item['landed_unit_cost'] = round(($item['line_cost_base'] + $share) / max(1, (int)$item['quantity']), 3);
            }
            unset($item);

            $totalAmount = round($goodsNetTotal + $extraCosts, 3);
            if ($totalAmount < 0) {
                throw new RuntimeException('إجمالي فاتورة الشراء لا يمكن أن يكون سالبًا.');
            }

            $paidAmount = Validator::positiveDecimal($data['paid_amount'] ?? 0, 'المبلغ المدفوع', true);
            if ($paidAmount > $totalAmount + 0.0005) {
                throw new RuntimeException('المبلغ المدفوع للمورد يتجاوز إجمالي فاتورة الشراء.');
            }
            $paidAmount = round(min($paidAmount, $totalAmount), 3);
            $remaining = round($totalAmount - $paidAmount, 3);
            $paymentMethod = strtolower(trim((string)($data['payment_method'] ?? 'cash')));
            // Keep backward compatibility with invoices stored as bank_transfer.
            if ($paymentMethod === 'bank_transfer') {
                $paymentMethod = 'transfer';
            }
            if (!in_array($paymentMethod, ['cash', 'card', 'transfer', 'cheque', 'credit'], true)) {
                throw new RuntimeException('طريقة دفع فاتورة الشراء غير صالحة.');
            }
            if ($paidAmount > 0 && $paymentMethod === 'credit') {
                throw new RuntimeException('لا يمكن اختيار الدفع الآجل مع وجود مبلغ مدفوع.');
            }
            if ($paidAmount <= 0) {
                $paymentMethod = 'credit';
            }

            $paymentStatus = $remaining <= 0 ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid');
            $invoiceNo = NumberService::generate($pdo, 'purchases', 'invoice_number', 'PUR');
            $now = date('Y-m-d H:i:s');

            $pdo->prepare('
                INSERT INTO purchases (
                    branch_id, invoice_number, supplier_invoice_no, supplier_id, purchase_date,
                    subtotal, discount_amount, shipping_cost, extra_cost, cost_allocation_method,
                    total_amount, paid_amount, remaining_amount, payment_status, payment_method,
                    status, due_date, notes, created_by, created_at, updated_at
                ) VALUES (
                    :branch_id, :inv_no, :supplier_inv, :supplier_id, :purchase_date,
                    :subtotal, :discount, :shipping, :extra, :alloc_method,
                    :total, :paid, :remaining, :pay_status, :pay_method,
                    \'completed\', :due_date, :notes, :created_by, :created_at, :updated_at
                )
            ')->execute([
                ':branch_id' => $branchId,
                ':inv_no' => $invoiceNo,
                ':supplier_inv' => $supplierInvoiceNo,
                ':supplier_id' => $supplierId,
                ':purchase_date' => $purchaseDate,
                ':subtotal' => $subtotal,
                ':discount' => round($lineDiscountTotal + $invoiceDiscount, 3),
                ':shipping' => $shippingCost,
                ':extra' => $extraCost,
                ':alloc_method' => $allocationMethod,
                ':total' => $totalAmount,
                ':paid' => $paidAmount,
                ':remaining' => $remaining,
                ':pay_status' => $paymentStatus,
                ':pay_method' => $paymentMethod,
                ':due_date' => $dueDate,
                ':notes' => $notes,
                ':created_by' => $userId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $purchaseId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO purchase_items (
                    purchase_id, product_id, variant_id, batch_number, manufacturing_date, expiry_date,
                    quantity, remaining_fifo_qty,
                    returned_quantity, unit_cost, allocated_extra_cost, landed_unit_cost,
                    discount_amount, line_total, created_at
                ) VALUES (
                    :purchase_id, :product_id, :variant_id, :batch_no, :mfg_date, :expiry,
                    :qty, :fifo_qty,
                    0, :unit_cost, :extra, :landed,
                    :discount, :line_total, :created_at
                )
            ');

            foreach ($prepared as $item) {
                $itemStmt->execute([
                    ':purchase_id' => $purchaseId,
                    ':product_id' => $item['product_id'],
                    ':variant_id' => $item['variant_id'],
                    ':batch_no' => $item['batch_number'] !== '' ? $item['batch_number'] : null,
                    ':mfg_date' => $item['manufacturing_date'],
                    ':expiry' => $item['expiry_date'],
                    ':qty' => $item['quantity'],
                    ':fifo_qty' => $item['quantity'],
                    ':unit_cost' => $item['unit_cost'],
                    ':extra' => $item['allocated_extra'],
                    ':landed' => $item['landed_unit_cost'],
                    ':discount' => round($item['line_discount'] + $item['invoice_discount_share'], 3),
                    ':line_total' => $item['line_cost_base'],
                    ':created_at' => $now,
                ]);
                $purchaseItemId = (int)$pdo->lastInsertId();

                if (class_exists('App\\Services\\PriceHistoryService')) {
                    PriceHistoryService::recordPurchaseItem(
                        (int)$item['product_id'],
                        (int)$supplierId,
                        $purchaseId,
                        substr($purchaseDate, 0, 10),
                        (float)$item['landed_unit_cost'],
                        (float)$item['quantity']
                    );
                }

                InventoryService::addStock(
                    $pdo,
                    $branchId,
                    (int)$item['product_id'],
                    $item['variant_id'],
                    (int)$item['quantity'],
                    (float)$item['landed_unit_cost'],
                    $userId,
                    'purchase',
                    'purchase',
                    $purchaseId,
                    $invoiceNo,
                    "شراء بموجب فاتورة {$invoiceNo} (بند #{$purchaseItemId})"
                );

                // Pharmacy: register/update the received lot so FEFO and
                // expiry alerts have authoritative data.
                if ($item['batch_number'] !== '' && $item['expiry_date'] !== null) {
                    BatchService::upsertFromPurchase(
                        $pdo,
                        (int)$item['product_id'],
                        $branchId,
                        $item['batch_number'],
                        $item['manufacturing_date'],
                        $item['expiry_date'],
                        (float)$item['quantity'],
                        (float)$item['landed_unit_cost']
                    );
                }
            }

            if ($remaining > 0) {
                self::addSupplierBalance($pdo, $supplierId, $remaining);
            }

            if ($paidAmount > 0 && $paymentMethod === 'cash') {
                self::recordCashMovement($pdo, $branchId, $userId, 'purchase_out', $paidAmount, 'purchase', $purchaseId, "دفع نقدي لفاتورة شراء {$invoiceNo}");
            }

            AuditService::log(
                'PURCHASE_CREATED',
                'purchases',
                $purchaseId,
                "إنشاء فاتورة شراء {$invoiceNo} من المورد {$supplier['name']} بإجمالي {$totalAmount} د.ل",
                null,
                [
                    'invoice_number' => $invoiceNo,
                    'supplier_id' => $supplierId,
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'remaining_amount' => $remaining,
                ],
                $userId
            );

            return [
                'purchase_id' => $purchaseId,
                'invoice_number' => $invoiceNo,
                'supplier_id' => $supplierId,
                'supplier_name' => $supplier['name'],
                'subtotal' => $subtotal,
                'discount_amount' => round($lineDiscountTotal + $invoiceDiscount, 3),
                'shipping_cost' => $shippingCost,
                'extra_cost' => $extraCost,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remaining,
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod,
                'items' => $prepared,
            ];
        });
    }

    public static function cancelPurchase(int $purchaseId, string $reason, int $userId, int $branchId): array
    {
        return Database::transaction(function (PDO $pdo) use ($purchaseId, $reason, $userId, $branchId): array {
            $reason = Validator::optionalString($reason, 500);
            if ($reason === '') {
                $reason = 'بدون سبب محدد';
            }
            $purchase = self::loadPurchase($pdo, $purchaseId, $branchId);
            if ($purchase['status'] === 'cancelled') {
                throw new RuntimeException('فاتورة الشراء ملغاة مسبقًا.');
            }

            $returnsStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_returns WHERE purchase_id = :pid');
            $returnsStmt->execute([':pid' => $purchaseId]);
            if ((int)$returnsStmt->fetchColumn() > 0) {
                throw new RuntimeException('لا يمكن إلغاء فاتورة شراء عليها مرتجعات. يرجى إرجاع الكميات المتبقية.');
            }

            $itemsStmt = $pdo->prepare('SELECT * FROM purchase_items WHERE purchase_id = :pid ORDER BY id ASC');
            $itemsStmt->execute([':pid' => $purchaseId]);
            $items = $itemsStmt->fetchAll();
            if (empty($items)) {
                throw new RuntimeException('لا توجد أصناف في فاتورة الشراء.');
            }

            $restored = 0;
            foreach ($items as $item) {
                $qty = (int)$item['quantity'] - (int)$item['returned_quantity'];
                if ($qty <= 0) {
                    continue;
                }
                InventoryService::deductStock(
                    $pdo,
                    $branchId,
                    (int)$item['product_id'],
                    $item['variant_id'] !== null ? (int)$item['variant_id'] : null,
                    $qty,
                    $userId,
                    'purchase_cancel',
                    'purchase_cancel',
                    $purchaseId,
                    $purchase['invoice_number'],
                    "إلغاء فاتورة شراء {$purchase['invoice_number']} - إخراج من المخزون"
                );
                self::reduceFifoLayer($pdo, (int)$item['id'], $qty);
                $restored += $qty;
            }

            // Reverse the remaining supplier debt, never below zero.
            $balanceReduction = 0.0;
            $remaining = (float)$purchase['remaining_amount'];
            if ($remaining > 0) {
                $balStmt = $pdo->prepare('SELECT current_balance FROM suppliers WHERE id = :id LIMIT 1');
                $balStmt->execute([':id' => $purchase['supplier_id']]);
                $currentBalance = (float)($balStmt->fetchColumn() ?: 0.0);
                $balanceReduction = min(max(0.0, $currentBalance), $remaining);
                if ($balanceReduction > 0) {
                    self::addSupplierBalance($pdo, (int)$purchase['supplier_id'], -$balanceReduction);
                }
            }

            // Refund the paid amount. Only cash payments affect the drawer.
            $cashPaid = 0.0;
            if ((float)$purchase['paid_amount'] > 0) {
                if ($purchase['payment_method'] === 'cash') {
                    $cashPaid += (float)$purchase['paid_amount'];
                }
                $payStmt = $pdo->prepare('SELECT * FROM supplier_payments WHERE purchase_id = :pid AND reversed_at IS NULL ORDER BY id ASC');
                $payStmt->execute([':pid' => $purchaseId]);
                foreach ($payStmt->fetchAll() as $sp) {
                    if ($sp['payment_method'] === 'cash') {
                        $cashPaid += (float)$sp['amount_in_base'];
                    }
                }
            }
            $cashPaid = round(min($cashPaid, (float)$purchase['paid_amount']), 3);
            if ($cashPaid > 0) {
                self::recordCashMovement($pdo, $branchId, $userId, 'purchase_cancel_in', $cashPaid, 'purchase_cancel', $purchaseId, "استرداد نقدي من المورد لإلغاء فاتورة شراء {$purchase['invoice_number']}");
            }

            $pdo->prepare('UPDATE supplier_payments SET reversed_at = :now WHERE purchase_id = :pid AND reversed_at IS NULL')
                ->execute([':now' => date('Y-m-d H:i:s'), ':pid' => $purchaseId]);

            $now = date('Y-m-d H:i:s');
            $pdo->prepare('
                UPDATE purchases
                SET status = \'cancelled\', payment_status = \'cancelled\', remaining_amount = 0,
                    cancelled_at = :now1, cancel_reason = :reason, updated_at = :now2
                WHERE id = :id
            ')->execute([
                ':now1' => $now,
                ':reason' => $reason,
                ':now2' => $now,
                ':id' => $purchaseId,
            ]);

            AuditService::log(
                'PURCHASE_CANCELLED',
                'purchases',
                $purchaseId,
                "إلغاء فاتورة شراء {$purchase['invoice_number']} - السبب: {$reason}",
                ['status' => $purchase['status'], 'total_amount' => $purchase['total_amount'], 'remaining_amount' => $purchase['remaining_amount']],
                [
                    'status' => 'cancelled',
                    'removed_quantity' => $restored,
                    'balance_reduction' => $balanceReduction,
                    'cash_refund' => $cashPaid,
                ],
                $userId
            );

            return [
                'purchase_id' => $purchaseId,
                'invoice_number' => $purchase['invoice_number'],
                'removed_quantity' => $restored,
                'balance_reduction' => $balanceReduction,
                'cash_refund' => $cashPaid,
            ];
        });
    }

    public static function paySupplier(array $data, int $userId, int $branchId): array
    {
        return Database::transaction(function (PDO $pdo) use ($data, $userId, $branchId): array {
            $supplierId = Validator::positiveInt($data['supplier_id'] ?? 0, 'المورد');
            $supplier = self::loadSupplier($pdo, $supplierId);
            $amount = Validator::positiveDecimal($data['amount'] ?? 0, 'مبلغ الدفعة', false);
            $rawMethod = strtolower(trim((string)($data['payment_method'] ?? 'cash')));
            if ($rawMethod === 'bank_transfer') {
                $rawMethod = 'transfer';
            }
            $method = Validator::enum($rawMethod, ['cash', 'card', 'transfer', 'cheque'], 'طريقة الدفع', 'cash');
            $purchaseId = !empty($data['purchase_id']) ? (int)$data['purchase_id'] : null;
            $notes = Validator::optionalString($data['notes'] ?? '', 2000);
            $baseCurrency = strtoupper(trim((string)SettingsService::getValue('base_currency', 'LYD')));
            $currency = strtoupper(trim((string)($data['currency_code'] ?? $baseCurrency)));
            if ($currency !== '' && $currency !== $baseCurrency) {
                throw new RuntimeException('العملة غير مدعومة في إصدار الصيدلية: ' . $currency);
            }
            $currency = $baseCurrency;
            $rate = 1.0;
            $amountBase = round($amount, 3);

            if ($purchaseId !== null) {
                $purchase = self::loadPurchase($pdo, $purchaseId, $branchId);
                if ((int)$purchase['supplier_id'] !== $supplierId) {
                    throw new RuntimeException('فاتورة الشراء لا تنتمي لهذا المورد.');
                }
                if ($purchase['status'] === 'cancelled') {
                    throw new RuntimeException('لا يمكن الدفع لفاتورة شراء ملغاة.');
                }
                if ($amountBase > (float)$purchase['remaining_amount'] + 0.0005) {
                    throw new RuntimeException('مبلغ الدفعة يتجاوز المتبقي على فاتورة الشراء (' . $purchase['remaining_amount'] . ' د.ل).');
                }
                $newPaid = round((float)$purchase['paid_amount'] + $amountBase, 3);
                $newRemaining = max(0.0, round((float)$purchase['remaining_amount'] - $amountBase, 3));
                $newStatus = $newRemaining <= 0 ? 'paid' : 'partial';
                $pdo->prepare('UPDATE purchases SET paid_amount = :paid, remaining_amount = :remaining, payment_status = :status, updated_at = :now WHERE id = :id')
                    ->execute([
                        ':paid' => $newPaid,
                        ':remaining' => $newRemaining,
                        ':status' => $newStatus,
                        ':now' => date('Y-m-d H:i:s'),
                        ':id' => $purchaseId,
                    ]);
            }

            $receiptNo = NumberService::generate($pdo, 'supplier_payments', 'receipt_number', 'SPAY');
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('
                INSERT INTO supplier_payments (
                    branch_id, receipt_number, supplier_id, purchase_id, amount,
                    currency_code, exchange_rate, amount_in_base, payment_method,
                    payment_date, notes, created_by, created_at
                ) VALUES (
                    :branch_id, :receipt_no, :supplier_id, :purchase_id, :amount,
                    :currency, :rate, :amount_base, :method,
                    :payment_date, :notes, :created_by, :created_at
                )
            ')->execute([
                ':branch_id' => $branchId,
                ':receipt_no' => $receiptNo,
                ':supplier_id' => $supplierId,
                ':purchase_id' => $purchaseId,
                ':amount' => $amount,
                ':currency' => $currency,
                ':rate' => $rate,
                ':amount_base' => $amountBase,
                ':method' => $method,
                ':payment_date' => $now,
                ':notes' => $notes,
                ':created_by' => $userId,
                ':created_at' => $now,
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            self::addSupplierBalance($pdo, $supplierId, -$amountBase);

            if ($method === 'cash') {
                self::recordCashMovement($pdo, $branchId, $userId, 'supplier_payment_out', $amountBase, 'supplier_payment', $paymentId, "دفعة نقدية للمورد {$supplier['name']} - سند {$receiptNo}");
            }

            AuditService::log(
                'SUPPLIER_PAYMENT',
                'supplier_payments',
                $paymentId,
                "دفعة للمورد {$supplier['name']} بمبلغ {$amountBase} د.ل - سند {$receiptNo}",
                null,
                ['receipt_number' => $receiptNo, 'supplier_id' => $supplierId, 'purchase_id' => $purchaseId, 'amount_base' => $amountBase, 'method' => $method],
                $userId
            );

            return [
                'payment_id' => $paymentId,
                'receipt_number' => $receiptNo,
                'supplier_id' => $supplierId,
                'purchase_id' => $purchaseId,
                'amount' => $amount,
                'currency_code' => $currency,
                'exchange_rate' => $rate,
                'amount_in_base' => $amountBase,
                'payment_method' => $method,
            ];
        });
    }

    public static function createPurchaseReturn(array $data, int $userId, int $branchId): array
    {
        return Database::transaction(function (PDO $pdo) use ($data, $userId, $branchId): array {
            $purchaseId = Validator::positiveInt($data['purchase_id'] ?? 0, 'فاتورة الشراء');
            $purchase = self::loadPurchase($pdo, $purchaseId, $branchId);
            if ($purchase['status'] === 'cancelled') {
                throw new RuntimeException('لا يمكن الإرجاع من فاتورة شراء ملغاة.');
            }
            $reason = Validator::optionalString($data['reason'] ?? '', 500);
            if ($reason === '') {
                $reason = 'بدون سبب محدد';
            }
            $refundMethod = Validator::enum($data['refund_method'] ?? 'balance_deduction', ['balance_deduction', 'cash_refund'], 'طريقة التسوية', 'balance_deduction');
            $items = $data['items'] ?? null;
            if (!is_array($items) || empty($items)) {
                throw new RuntimeException('يجب تحديد صنف واحد على الأقل لإرجاعه للمورد.');
            }

            $purchaseItemsStmt = $pdo->prepare('SELECT * FROM purchase_items WHERE purchase_id = :pid ORDER BY id ASC');
            $purchaseItemsStmt->execute([':pid' => $purchaseId]);
            $purchaseItems = [];
            foreach ($purchaseItemsStmt->fetchAll() as $row) {
                $purchaseItems[(int)$row['id']] = $row;
            }

            $prepared = [];
            $totalRefund = 0.0;
            $totalQty = 0;
            foreach ($items as $line) {
                if (!is_array($line)) {
                    throw new RuntimeException('بيانات أحد أسطر الإرجاع غير صالحة.');
                }
                $itemId = Validator::positiveInt($line['purchase_item_id'] ?? 0, 'معرف صنف الشراء');
                if (!isset($purchaseItems[$itemId])) {
                    throw new RuntimeException('أحد الأصناف المحددة لا ينتمي لفاتورة الشراء.');
                }
                $orig = $purchaseItems[$itemId];
                $qty = Validator::positiveInt($line['quantity'] ?? 0, 'الكمية المرتجعة', false, 100000);
                $available = (int)$orig['quantity'] - (int)$orig['returned_quantity'];
                if ($qty > $available) {
                    throw new RuntimeException("الكمية المرتجعة ({$qty}) أكبر من المتبقي من صنف الشراء ({$available}).");
                }
                $unitCost = (float)$orig['landed_unit_cost'];
                $lineTotal = round($unitCost * $qty, 3);
                $prepared[] = [
                    'purchase_item_id' => $itemId,
                    'product_id' => (int)$orig['product_id'],
                    'variant_id' => $orig['variant_id'] !== null ? (int)$orig['variant_id'] : null,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'total_amount' => $lineTotal,
                ];
                $totalRefund = round($totalRefund + $lineTotal, 3);
                $totalQty += $qty;
            }

            $returnStmt = $pdo->prepare('
                INSERT INTO purchase_returns (
                    branch_id, return_number, purchase_id, supplier_id, product_id, variant_id,
                    quantity, unit_cost, total_amount, refund_method, reason, created_by, created_at
                ) VALUES (
                    :branch_id, :return_no, :purchase_id, :supplier_id, :product_id, :variant_id,
                    :qty, :unit_cost, :total_amount, :refund_method, :reason, :created_by, :created_at
                )
            ');

            $returnIds = [];
            $now = date('Y-m-d H:i:s');
            foreach ($prepared as $item) {
                $returnNo = NumberService::generate($pdo, 'purchase_returns', 'return_number', 'PRET');
                InventoryService::deductStock(
                    $pdo,
                    $branchId,
                    $item['product_id'],
                    $item['variant_id'],
                    $item['quantity'],
                    $userId,
                    'purchase_return',
                    'purchase_return',
                    $purchaseId,
                    $purchase['invoice_number'],
                    "إرجاع للمورد من فاتورة الشراء {$purchase['invoice_number']}"
                );
                self::reduceFifoLayer($pdo, $item['purchase_item_id'], $item['quantity']);
                $pdo->prepare('UPDATE purchase_items SET returned_quantity = returned_quantity + :qty WHERE id = :id')
                    ->execute([':qty' => $item['quantity'], ':id' => $item['purchase_item_id']]);

                $returnStmt->execute([
                    ':branch_id' => $branchId,
                    ':return_no' => $returnNo,
                    ':purchase_id' => $purchaseId,
                    ':supplier_id' => $purchase['supplier_id'],
                    ':product_id' => $item['product_id'],
                    ':variant_id' => $item['variant_id'],
                    ':qty' => $item['quantity'],
                    ':unit_cost' => $item['unit_cost'],
                    ':total_amount' => $item['total_amount'],
                    ':refund_method' => $refundMethod,
                    ':reason' => $reason,
                    ':created_by' => $userId,
                    ':created_at' => $now,
                ]);
                $returnIds[] = (int)$pdo->lastInsertId();
            }

            if ($refundMethod === 'balance_deduction') {
                self::addSupplierBalance($pdo, (int)$purchase['supplier_id'], -$totalRefund);
            } else {
                self::recordCashMovement($pdo, $branchId, $userId, 'purchase_return_in', $totalRefund, 'purchase_return', $returnIds[0] ?? $purchaseId, "استرداد نقدي من المورد لإرجاع مشتريات - فاتورة {$purchase['invoice_number']}");
            }

            // Update the original purchase balances.
            $newRemaining = (float)$purchase['remaining_amount'];
            $newPaid = (float)$purchase['paid_amount'];
            if ($refundMethod === 'balance_deduction') {
                $newRemaining = max(0.0, round($newRemaining - $totalRefund, 3));
            } else {
                $newPaid = max(0.0, round($newPaid - $totalRefund, 3));
            }
            $newStatus = $newRemaining <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
            $pdo->prepare('UPDATE purchases SET paid_amount = :paid, remaining_amount = :remaining, payment_status = :status, updated_at = :now WHERE id = :id')
                ->execute([
                    ':paid' => $newPaid,
                    ':remaining' => $newRemaining,
                    ':status' => $newStatus,
                    ':now' => $now,
                    ':id' => $purchaseId,
                ]);

            AuditService::log(
                'PURCHASE_RETURNED',
                'purchase_returns',
                $returnIds[0] ?? 0,
                "إرجاع مشتريات للفاتورة {$purchase['invoice_number']} بقيمة {$totalRefund} د.ل - السبب: {$reason}",
                null,
                [
                    'purchase_id' => $purchaseId,
                    'supplier_id' => $purchase['supplier_id'],
                    'total_refund' => $totalRefund,
                    'quantity' => $totalQty,
                    'refund_method' => $refundMethod,
                ],
                $userId
            );

            return [
                'purchase_id' => $purchaseId,
                'invoice_number' => $purchase['invoice_number'],
                'return_ids' => $returnIds,
                'total_refund' => $totalRefund,
                'quantity' => $totalQty,
                'refund_method' => $refundMethod,
                'purchase_remaining' => $newRemaining,
                'purchase_paid' => $newPaid,
            ];
        });
    }

    public static function getPurchases(int $branchId, array $filters = []): array
    {
        $pdo = Database::getConnection();
        $sql = '
            SELECT pu.*, s.name AS supplier_name, u.full_name AS created_by_name,
                   (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id = pu.id) AS items_count
            FROM purchases pu
            JOIN suppliers s ON s.id = pu.supplier_id
            JOIN users u ON u.id = pu.created_by
            WHERE pu.branch_id = :b
        ';
        $params = [':b' => $branchId];
        if (!empty($filters['supplier_id'])) {
            $sql .= ' AND pu.supplier_id = :supplier';
            $params[':supplier'] = (int)$filters['supplier_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND pu.status = :status';
            $params[':status'] = (string)$filters['status'];
        }
        if (!empty($filters['start_date'])) {
            $sql .= ' AND pu.purchase_date >= :start';
            $params[':start'] = $filters['start_date'] . ' 00:00:00';
        }
        if (!empty($filters['end_date'])) {
            $sql .= ' AND pu.purchase_date <= :end';
            $params[':end'] = $filters['end_date'] . ' 23:59:59';
        }
        $limit = min(max((int)($filters['limit'] ?? 100), 1), 500);
        $sql .= ' ORDER BY pu.id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getPurchaseDetails(int $purchaseId, int $branchId): array
    {
        $pdo = Database::getConnection();
        $purchase = self::loadPurchase($pdo, $purchaseId, $branchId);
        $itemsStmt = $pdo->prepare('
            SELECT pi.*, p.name AS product_name, p.sku, v.variant_name
            FROM purchase_items pi
            JOIN products p ON p.id = pi.product_id
            LEFT JOIN product_variants v ON v.id = pi.variant_id
            WHERE pi.purchase_id = :pid
            ORDER BY pi.id ASC
        ');
        $itemsStmt->execute([':pid' => $purchaseId]);
        $purchase['items'] = $itemsStmt->fetchAll();
        return $purchase;
    }

    private static function loadPurchase(PDO $pdo, int $purchaseId, int $branchId): array
    {
        $stmt = $pdo->prepare('
            SELECT pu.*, s.name AS supplier_name, s.phone AS supplier_phone
            FROM purchases pu
            LEFT JOIN suppliers s ON s.id = pu.supplier_id
            WHERE pu.id = :id AND pu.branch_id = :b
            LIMIT 1
        ');
        $stmt->execute([':id' => $purchaseId, ':b' => $branchId]);
        $purchase = $stmt->fetch();
        if (!$purchase) {
            throw new RuntimeException('فاتورة الشراء غير موجودة أو لا تنتمي لفرعك.');
        }
        return $purchase;
    }

    private static function loadSupplier(PDO $pdo, int $supplierId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $supplierId]);
        $supplier = $stmt->fetch();
        if (!$supplier) {
            throw new RuntimeException('المورد غير موجود.');
        }
        if ((int)$supplier['is_active'] !== 1) {
            throw new RuntimeException('حساب المورد معطل.');
        }
        return $supplier;
    }

    private static function loadProduct(PDO $pdo, int $productId, ?int $variantId): array
    {
        $sql = '
            SELECT p.id, p.name, p.has_variants, v.id AS variant_id, v.variant_name
            FROM products p
            LEFT JOIN product_variants v ON v.id = :vid AND v.product_id = p.id AND v.deleted_at IS NULL
            WHERE p.id = :pid AND p.deleted_at IS NULL
            LIMIT 1
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pid' => $productId, ':vid' => $variantId]);
        $product = $stmt->fetch();
        if (!$product) {
            throw new RuntimeException("المنتج رقم #{$productId} غير موجود.");
        }
        if ((int)$product['has_variants'] === 1 && $variantId === null) {
            throw new RuntimeException("المنتج ({$product['name']}) له خيارات. يرجى تحديد الخيار.");
        }
        if ($variantId !== null && $product['variant_id'] === null) {
            throw new RuntimeException('الخيار المحدد لا ينتمي للمنتج.');
        }
        return $product;
    }

    private static function addSupplierBalance(PDO $pdo, int $supplierId, float $delta): void
    {
        $pdo->prepare('UPDATE suppliers SET current_balance = current_balance + :delta, updated_at = :now WHERE id = :id')
            ->execute([':delta' => $delta, ':now' => date('Y-m-d H:i:s'), ':id' => $supplierId]);
    }

    private static function reduceFifoLayer(PDO $pdo, int $purchaseItemId, int $qty): void
    {
        $stmt = $pdo->prepare('SELECT remaining_fifo_qty FROM purchase_items WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $purchaseItemId]);
        $current = (int)($stmt->fetchColumn() ?: 0);
        $newValue = max(0, $current - $qty);
        $pdo->prepare('UPDATE purchase_items SET remaining_fifo_qty = :qty WHERE id = :id')
            ->execute([':qty' => $newValue, ':id' => $purchaseItemId]);
    }

    private static function recordCashMovement(PDO $pdo, int $branchId, int $userId, string $type, float $amount, string $refType, int $refId, string $description): void
    {
        if ($amount <= 0) {
            return;
        }
        $sessionId = self::findOpenSessionId($pdo, $branchId, $userId);
        if ($sessionId === null) {
            throw new RuntimeException('لا توجد وردية كاشير مفتوحة. يرجى فتح وردية الصندوق لتسجيل الحركة النقدية.');
        }
        $pdo->prepare('
            INSERT INTO cash_movements (
                session_id, branch_id, movement_type, amount, reference_type,
                reference_id, description, created_by, created_at
            ) VALUES (
                :sess, :branch, :type, :amt, :ref_type,
                :ref_id, :desc, :uid, :now
            )
        ')->execute([
            ':sess' => $sessionId,
            ':branch' => $branchId,
            ':type' => $type,
            ':amt' => $amount,
            ':ref_type' => $refType,
            ':ref_id' => $refId,
            ':desc' => $description,
            ':uid' => $userId,
            ':now' => date('Y-m-d H:i:s'),
        ]);
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
        // A shift opened by another user must never receive this payment.
        return null;
    }
}
