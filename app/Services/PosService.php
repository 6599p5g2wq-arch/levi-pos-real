<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Validator;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Core Point of Sale (POS) transaction engine.
 *
 * Every sale runs inside a single ACID transaction and performs:
 * 1) price validation against the database (no client-controlled prices without permission)
 * 2) stock validation and deduction with FEFO batch allocation and expiry blocking
 * 3) weighted-average or FIFO cost calculation
 * 4) payments, change and credit/debt handling
 * 5) cash register movements
 * 6) batch/expiry snapshots on every sale line
 * 7) audit logging
 *
 * Pharmacy edition: generic warranties and promotional discounts are no longer
 * applied. Legacy tables remain inert for data compatibility only.
 * PHP 8.0 compatible.
 */
class PosService
{
    public static function processSale(array $payload, int $cashierId, int $branchId = 1): array
    {
        return Database::transaction(function (PDO $pdo) use ($payload, $cashierId, $branchId): array {
            $settings = self::loadSettings($pdo);
            $isHold = !empty($payload['is_hold']);
            $clientUuid = self::normalizeClientUuid($payload['client_uuid'] ?? null);

            // Idempotency: return the already-created sale for the same client request.
            if ($clientUuid !== null) {
                $existing = self::findByClientUuid($pdo, $clientUuid, $branchId);
                if ($existing !== null) {
                    $summary = self::summaryForSale($pdo, (int)$existing['id']);
                    $summary['duplicate'] = true;
                    return $summary;
                }
            }

            $items = $payload['items'] ?? null;
            if (!is_array($items) || empty($items)) {
                throw new RuntimeException('سلة المبيعات فارغة. يرجى إضافة منتج واحد على الأقل.');
            }
            if (count($items) > 200) {
                throw new RuntimeException('عدد أسطر الفاتورة يتجاوز الحد الأقصى (200 سطر).');
            }

            $customerId = !empty($payload['customer_id']) ? Validator::positiveInt($payload['customer_id'], 'العميل') : null;
            $customer = null;
            if ($customerId !== null) {
                $customer = self::loadCustomer($pdo, $customerId, $branchId);
            }

            $prescriptionId = !empty($payload['prescription_id']) ? (int)$payload['prescription_id'] : null;
            if (!$isHold && $prescriptionId !== null) {
                if (SettingsService::isEnabled('require_pharmacist_dispense', true)
                    && !Auth::hasPermission('prescriptions.dispense')) {
                    throw new RuntimeException('صرف الروشتات يتطلب صلاحية الصيدلي المعتمد.');
                }
                if (class_exists('App\\Services\\PrescriptionReviewService')) {
                    PrescriptionReviewService::assertDispensable($prescriptionId, $customerId, $branchId);
                }
            }

            $manualInvoiceDiscount = Validator::positiveDecimal($payload['invoice_discount'] ?? 0, 'خصم الفاتورة', true);
            $allowNegativeStock = ((int)($settings['allow_negative_stock_sale'] ?? 0) === 1)
                || Auth::hasPermission('pos.sell_out_of_stock');
            $allowDiscounts = ((int)($settings['allow_discounts'] ?? 1) === 1);
            $openCashDrawerOnCash = ((int)($settings['open_cash_drawer_on_cash'] ?? 1) === 1);
            $maxDiscountPercent = (float)($settings['max_discount_percent'] ?? 20.0);
            $costingMethod = (string)($settings['costing_method'] ?? 'weighted_average');
            $allowPriceOverride = Auth::hasPermission('pos.override_price');
            $allowDiscountOverride = Auth::hasPermission('pos.override_discount');

            $subtotal = 0.0;
            $manualItemDiscountTotal = 0.0;
            $linePromotionDiscount = 0.0;
            $promotionDiscount = 0.0;
            $totalCogs = 0.0;
            $preparedItems = [];

            foreach ($items as $line) {
                if (!is_array($line)) {
                    throw new RuntimeException('بيانات أحد أسطر الفاتورة غير صالحة.');
                }
                $productId = Validator::positiveInt($line['product_id'] ?? 0, 'معرف المنتج');
                $variantId = !empty($line['variant_id']) ? Validator::positiveInt($line['variant_id'], 'معرف الخيار') : null;
                $qty = Validator::positiveInt($line['quantity'] ?? 0, 'الكمية', false, 100000);
                $manualLineDiscount = Validator::positiveDecimal($line['discount_amount'] ?? 0, 'خصم المنتج', true);
                $unitId = !empty($line['unit_id']) ? (int)$line['unit_id'] : null;
                $unitInfo = ['id' => null, 'unit_name' => null, 'conversion_factor' => 1.0, 'selling_price' => 0.0, 'found' => false];
                if ($unitId !== null && class_exists('App\\Services\\ProductUnitService')) {
                    $unitInfo = ProductUnitService::resolve($productId, $unitId);
                }
                $unitFactor = (float)($unitInfo['conversion_factor'] ?? 1.0);
                $baseQty = max(1, (int)round($qty * $unitFactor));

                $prod = self::loadProduct($pdo, $productId, $variantId);
                if ($prod === null) {
                    throw new RuntimeException("المنتج رقم #{$productId} غير موجود أو معطل.");
                }

                $basePrice = (float)($prod['effective_price'] ?? 0);
                $unitSellingPrice = (float)($unitInfo['selling_price'] ?? 0);
                $dbPrice = $unitSellingPrice > 0
                    ? $unitSellingPrice
                    : round($basePrice * $unitFactor, 3);
                if (isset($line['unit_price']) && $line['unit_price'] !== '' && $line['unit_price'] !== null) {
                    $unitPrice = Validator::positiveDecimal($line['unit_price'], 'سعر الوحدة', true);
                    if (abs($unitPrice - $dbPrice) > 0.0005 && !$allowPriceOverride) {
                        throw new RuntimeException("تعديل سعر المنتج ({$prod['name']}) يتطلب صلاحية خاصة. السعر المعتمد هو {$dbPrice} د.ل.");
                    }
                } else {
                    $unitPrice = $dbPrice;
                }

                // Pharmacy rule: flagged medicines require a prescription when the
                // store policy is enabled (default: off).
                if (!$isHold && !empty($settings['require_prescription_for_flagged'])
                    && !empty($prod['requires_prescription']) && empty($payload['prescription_id'])) {
                    throw new RuntimeException("المنتج ({$prod['name']}) يتطلب روشتة طبية لصرفه.");
                }

                $stock = InventoryService::getStockRow($pdo, $branchId, $productId, $variantId);
                $availableQty = $stock !== null ? (int)$stock['quantity'] : 0;

                // FEFO batch allocation. Only products with tracked batches use
                // this path; legacy products keep the aggregate-stock behaviour.
                $batchAllocation = [];
                if (!$isHold) {
                    $batchCheck = $pdo->prepare('SELECT COUNT(*) FROM product_batches WHERE product_id = :p AND branch_id = :b');
                    $batchCheck->execute([':p' => $productId, ':b' => $branchId]);
                    if ((int)$batchCheck->fetchColumn() > 0) {
                        $batchAllocation = BatchService::allocate($pdo, $productId, $branchId, (float)$baseQty);
                    }
                }
                if (!$isHold && empty($batchAllocation) && $baseQty > $availableQty && !$allowNegativeStock) {
                    throw new RuntimeException("الكمية المطلوبة للمنتج ({$prod['name']}) هي {$baseQty} بينما المتوفر في المخزون {$availableQty} فقط.");
                }

                // When FEFO is active the weighted cost of the consumed lots is
                // the authoritative cost for the line.
                if (!empty($batchAllocation)) {
                    $weightedCost = 0.0;
                    foreach ($batchAllocation as $batchLine) {
                        $weightedCost += (float)$batchLine['quantity'] * (float)$batchLine['unit_cost'];
                    }
                    $unitCost = round($weightedCost / max((float)$baseQty, 0.001), 3);
                } else {
                    $unitCost = $stock !== null ? (float)$stock['average_cost'] : 0.0;
                }
                if ($unitCost <= 0) {
                    $unitCost = (float)($prod['effective_cost'] ?? 0);
                }
                if (!$isHold && $costingMethod === 'fifo') {
                    $unitCost = self::consumeFifoCost($pdo, $productId, $variantId, $baseQty, $unitCost);
                }
                $unitCost = round($unitCost, 3);

                if ($unitPrice < $unitCost && !$allowPriceOverride && !$isHold) {
                    throw new RuntimeException("سعر بيع المنتج ({$prod['name']}) أقل من تكلفته. يتطلب ذلك صلاحية تعديل السعر.");
                }

                $grossLine = round($unitPrice * $qty, 3);
                if ($manualLineDiscount > $grossLine) {
                    throw new RuntimeException("خصم المنتج ({$prod['name']}) يتجاوز إجمالي سعره.");
                }
                if ($manualLineDiscount > 0 && !$allowDiscounts) {
                    throw new RuntimeException('الخصومات غير مفعلة في إعدادات النظام.');
                }

                $lineAfterManual = round($grossLine - $manualLineDiscount, 3);
                $preparedItems[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'category_id' => (int)($prod['category_id'] ?? 0),
                    'product_name_snapshot' => (string)$prod['name'],
                    'sku_snapshot' => (string)($prod['effective_sku'] ?? $prod['sku']),
                    'variant_snapshot' => $prod['variant_name'] ?? null,
                    'serial_or_imei' => Validator::optionalString($line['serial_or_imei'] ?? '', 100),
                    'quantity' => $baseQty,
                    'sale_unit_id' => $unitInfo['id'] ?? null,
                    'sale_unit_name' => $unitInfo['unit_name'] ?? null,
                    'sale_unit_factor' => $unitFactor,
                    'sale_unit_quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'unit_price' => round($unitPrice, 3),
                    'manual_discount' => round($manualLineDiscount, 3),
                    'line_after_manual' => $lineAfterManual,
                    'line_total' => $lineAfterManual,
                    'promo_discount' => 0.0,
                    'line_cogs' => round($unitCost * $baseQty, 3),
                    'warranty_days' => (int)($prod['warranty_days'] ?? 0),
                    'batch_allocation' => $batchAllocation,
                    'batch_number_snapshot' => !empty($batchAllocation)
                        ? implode(', ', array_column($batchAllocation, 'batch_number'))
                        : null,
                    'expiry_date_snapshot' => !empty($batchAllocation)
                        ? (string)$batchAllocation[0]['expiry_date']
                        : null,
                ];
                $subtotal = round($subtotal + $grossLine, 3);
                $manualItemDiscountTotal = round($manualItemDiscountTotal + $manualLineDiscount, 3);
                $totalCogs = round($totalCogs + ($unitCost * $baseQty), 3);
            }

            // Pharmacy edition: the promotional-discount module has been
            // removed. Only manual, permission-checked discounts are applied.
            $promoInvoiceDiscount = 0.0;

            if ($manualInvoiceDiscount > 0 && !$allowDiscounts) {
                throw new RuntimeException('الخصومات غير مفعلة في إعدادات النظام.');
            }
            $lineTotalSum = 0.0;
            foreach ($preparedItems as $item) {
                $lineTotalSum = round($lineTotalSum + (float)$item['line_total'], 3);
            }
            if ($manualInvoiceDiscount > $lineTotalSum) {
                throw new RuntimeException('خصم الفاتورة يتجاوز إجمالي أسطر الفاتورة.');
            }

            $invoiceDiscountTotal = round($manualInvoiceDiscount + $promoInvoiceDiscount, 3);
            if ($invoiceDiscountTotal > $lineTotalSum) {
                $invoiceDiscountTotal = $lineTotalSum;
                $promoInvoiceDiscount = max(0.0, round($invoiceDiscountTotal - $manualInvoiceDiscount, 3));
            }
            $promotionDiscount = round($linePromotionDiscount + $promoInvoiceDiscount, 3);
            $totalDiscount = round($manualItemDiscountTotal + $linePromotionDiscount + $manualInvoiceDiscount + $promoInvoiceDiscount, 3);

            if ($subtotal > 0 && (($totalDiscount / $subtotal) * 100) > $maxDiscountPercent && !$allowDiscountOverride) {
                throw new RuntimeException("إجمالي الخصم يتجاوز الحد الأقصى المسموح به ({$maxDiscountPercent}%).");
            }

            $totalAmount = round($lineTotalSum - $invoiceDiscountTotal, 3);
            if ($totalAmount < 0) {
                throw new RuntimeException('الإجمالي النهائي للفاتورة لا يمكن أن يكون سالبًا.');
            }

            // Tax: inclusive tax is extracted from the discounted total, while
            // exclusive tax is added on top of it.
            $taxEnabled = ((int)($settings['enable_tax'] ?? 0) === 1);
            $taxRate = $taxEnabled ? max(0.0, (float)($settings['tax_rate'] ?? 0.0)) : 0.0;
            $taxInclusive = ((int)($settings['tax_inclusive'] ?? 0) === 1);
            $taxAmount = 0.0;
            if ($taxEnabled && $taxRate > 0) {
                if ($taxInclusive) {
                    $taxAmount = round($totalAmount * $taxRate / (100 + $taxRate), 3);
                } else {
                    $taxAmount = round($totalAmount * $taxRate / 100, 3);
                    $totalAmount = round($totalAmount + $taxAmount, 3);
                }
            }

            // Allocate the invoice-level discount across lines proportionally.
            $remainingShare = $invoiceDiscountTotal;
            $lastIndex = count($preparedItems) - 1;
            foreach ($preparedItems as $i => &$item) {
                if ($i === $lastIndex) {
                    $share = round($remainingShare, 3);
                } elseif ($lineTotalSum > 0) {
                    $share = round($invoiceDiscountTotal * ((float)$item['line_total'] / $lineTotalSum), 3);
                } else {
                    $share = 0.0;
                }
                $share = min($share, (float)$item['line_total']);
                $remainingShare = round($remainingShare - $share, 3);
                $item['invoice_discount_share'] = $share;
                $item['net_total'] = round((float)$item['line_total'] - $share, 3);
            }
            unset($item);

            // A real sale requires an open cash-register shift only when the
            // store setting is enabled. Held invoices may always be parked.
            $requireOpenRegister = ((int)($settings['require_open_register'] ?? 1) === 1);
            $sessionId = self::findOpenSessionId($pdo, $branchId, $cashierId);
            if (!$isHold && $requireOpenRegister && $sessionId === null) {
                throw new RuntimeException('لا يمكن تسجيل البيع بدون فتح وردية الصندوق. يرجى فتح وردية أولاً.');
            }

            // Payments / credit.
            $paymentData = $isHold
                ? ['payments' => [], 'paid' => 0.0, 'change' => 0.0, 'debt' => 0.0, 'method' => 'cash', 'status' => 'held']
                : self::buildPayments($pdo, $payload, $totalAmount, $settings, $customer, $branchId);

            $invoicePrefix = $isHold ? 'HOLD' : (string)($settings['invoice_prefix'] ?? 'INV');
            $now = date('Y-m-d H:i:s');
            $saleStatus = $isHold ? 'held' : 'completed';
            $paymentStatus = $isHold ? 'held' : $paymentData['status'];
            $notes = Validator::optionalString($payload['notes'] ?? '', 2000);

            // Invoice numbers are generated with high entropy and the insert is
            // retried on a unique clash, so concurrent checkouts always end up
            // with different numbers instead of failing the second request.
            $invoiceAttempts = 0;
            while (true) {
                $invoiceNo = NumberService::generate($pdo, 'sales', 'invoice_number', preg_replace('/[^A-Za-z0-9\-_]/', '', $invoicePrefix) ?: 'INV');
                try {
                    $saleStmt = $pdo->prepare('
                        INSERT INTO sales (
                            branch_id, session_id, invoice_number, customer_id, cashier_id, sale_date,
                            subtotal, item_discount_total, invoice_discount, total_discount,
                            total_amount, tax_amount, tax_rate, total_cogs, paid_amount, remaining_debt, change_amount,
                            payment_method, payment_status, status, client_uuid, promotion_discount,
                            notes, created_at, updated_at
                        ) VALUES (
                            :branch_id, :session_id, :inv_no, :cust_id, :cashier_id, :sale_date,
                            :subtotal, :item_disc, :inv_disc, :tot_disc,
                            :tot_amt, :tax_amount, :tax_rate, :tot_cogs, :paid, :debt, :change,
                            :pmethod, :pstatus, :status, :client_uuid, :promo_disc,
                            :notes, :created_at, :updated_at
                        )
                    ');
                    $saleStmt->execute([
                        ':branch_id' => $branchId,
                        ':session_id' => $sessionId,
                        ':inv_no' => $invoiceNo,
                        ':cust_id' => $customerId,
                        ':cashier_id' => $cashierId,
                        ':sale_date' => $now,
                        ':subtotal' => round($subtotal, 3),
                        ':item_disc' => round($manualItemDiscountTotal + $linePromotionDiscount, 3),
                        ':inv_disc' => $invoiceDiscountTotal,
                        ':tot_disc' => $totalDiscount,
                        ':tot_amt' => $totalAmount,
                        ':tax_amount' => $taxAmount,
                        ':tax_rate' => $taxRate,
                        ':tot_cogs' => $isHold ? 0.0 : round($totalCogs, 3),
                        ':paid' => $paymentData['paid'],
                        ':debt' => $paymentData['debt'],
                        ':change' => $paymentData['change'],
                        ':pmethod' => $paymentData['method'],
                        ':pstatus' => $paymentStatus,
                        ':status' => $saleStatus,
                        ':client_uuid' => $clientUuid,
                        ':promo_disc' => $promotionDiscount,
                        ':notes' => $notes,
                        ':created_at' => $now,
                        ':updated_at' => $now,
                    ]);
                    break;
                } catch (PDOException $e) {
                    if ($clientUuid !== null && self::isUniqueViolation($e) && stripos($e->getMessage(), 'client_uuid') !== false) {
                        $existing = self::findByClientUuid($pdo, $clientUuid, $branchId);
                        if ($existing !== null) {
                            $summary = self::summaryForSale($pdo, (int)$existing['id']);
                            $summary['duplicate'] = true;
                            return $summary;
                        }
                    }
                    if (self::isUniqueViolation($e) && stripos($e->getMessage(), 'invoice_number') !== false && $invoiceAttempts < 8) {
                        $invoiceAttempts++;
                        continue;
                    }
                    throw $e;
                }
            }
            $saleId = (int)$pdo->lastInsertId();

            foreach ($preparedItems as $item) {
                $siStmt = $pdo->prepare('
                    INSERT INTO sale_items (
                        sale_id, product_id, variant_id, product_name_snapshot, sku_snapshot,
                        variant_snapshot, batch_number_snapshot, expiry_date_snapshot,
                        serial_or_imei, quantity, sale_unit_id, sale_unit_name, sale_unit_factor,
                        sale_unit_quantity, unit_cost, unit_price, discount_amount,
                        line_total, invoice_discount_share, net_total, line_cogs, created_at
                    ) VALUES (
                        :sale_id, :pid, :vid, :pname, :sku, :vname, :batch_no, :expiry,
                        :serial, :qty, :sunit_id, :sunit_name, :sunit_factor, :sunit_qty,
                        :ucost, :uprice,
                        :disc, :ltotal, :inv_share, :net_total, :lcogs, :now
                    )
                ');
                $siStmt->execute([
                    ':sale_id' => $saleId,
                    ':pid' => $item['product_id'],
                    ':vid' => $item['variant_id'],
                    ':pname' => $item['product_name_snapshot'],
                    ':sku' => $item['sku_snapshot'],
                    ':vname' => $item['variant_snapshot'],
                    ':batch_no' => $item['batch_number_snapshot'],
                    ':expiry' => $item['expiry_date_snapshot'],
                    ':serial' => $item['serial_or_imei'] !== '' ? $item['serial_or_imei'] : null,
                    ':qty' => $item['quantity'],
                    ':sunit_id' => $item['sale_unit_id'],
                    ':sunit_name' => $item['sale_unit_name'],
                    ':sunit_factor' => $item['sale_unit_factor'],
                    ':sunit_qty' => $item['sale_unit_quantity'],
                    ':ucost' => $item['unit_cost'],
                    ':uprice' => $item['unit_price'],
                    ':disc' => round((float)$item['manual_discount'] + (float)$item['promo_discount'], 3),
                    ':ltotal' => $item['line_total'],
                    ':inv_share' => $item['invoice_discount_share'],
                    ':net_total' => $item['net_total'],
                    ':lcogs' => $item['line_cogs'],
                    ':now' => $now,
                ]);
                $saleItemId = (int)$pdo->lastInsertId();

                if (!$isHold) {
                    InventoryService::deductStock(
                        $pdo,
                        $branchId,
                        (int)$item['product_id'],
                        $item['variant_id'] !== null ? (int)$item['variant_id'] : null,
                        (int)$item['quantity'],
                        $cashierId,
                        'sale',
                        'sale',
                        $saleId,
                        $invoiceNo,
                        "بيع بموجب فاتورة رقم {$invoiceNo}",
                        $allowNegativeStock
                    );

                    // Consume the reserved FEFO batches after the sale line exists.
                    if (!empty($item['batch_allocation'])) {
                        BatchService::consume($pdo, $item['batch_allocation']);
                    }
                }
                if (!$isHold) {
                    $pdo->prepare('UPDATE products SET last_sale_date = :today WHERE id = :pid')
                        ->execute([':today' => date('Y-m-d'), ':pid' => (int)$item['product_id']]);
                }
            }

            if (!$isHold) {
                foreach ($paymentData['payments'] as $pmt) {
                    if ((float)$pmt['amount_base'] <= 0) {
                        continue;
                    }
                    $pdo->prepare('
                        INSERT INTO payments (
                            sale_id, payment_method, currency_code, exchange_rate,
                            amount_foreign, amount_base, reference_no, paid_at, created_by
                        ) VALUES (
                            :sid, :method, :curr, :rate, :foreign, :base, :ref, :paid_at, :uid
                        )
                    ')->execute([
                        ':sid' => $saleId,
                        ':method' => $pmt['payment_method'],
                        ':curr' => $pmt['currency_code'],
                        ':rate' => $pmt['exchange_rate'],
                        ':foreign' => $pmt['amount_foreign'],
                        ':base' => $pmt['amount_base'],
                        ':ref' => $pmt['reference_no'],
                        ':paid_at' => $now,
                        ':uid' => $cashierId,
                    ]);

                    if ($sessionId !== null && $pmt['payment_method'] === 'cash' && (float)$pmt['amount_base'] > 0) {
                        $pdo->prepare('
                            INSERT INTO cash_movements (
                                session_id, branch_id, movement_type, amount, reference_type,
                                reference_id, description, created_by, created_at
                            ) VALUES (
                                :sess, :branch, \'sale_in\', :amt, \'sale\', :sid, :desc, :uid, :now
                            )
                        ')->execute([
                            ':sess' => $sessionId,
                            ':branch' => $branchId,
                            ':amt' => $pmt['amount_base'],
                            ':sid' => $saleId,
                            ':desc' => "تحصيل نقدي لفاتورة مبيعات {$invoiceNo}",
                            ':uid' => $cashierId,
                            ':now' => $now,
                        ]);
                    }
                }

                if ($paymentData['debt'] > 0 && $customerId !== null) {
                    $pdo->prepare('UPDATE customers SET current_debt = current_debt + :debt, updated_at = :now WHERE id = :id')
                        ->execute([':debt' => $paymentData['debt'], ':now' => $now, ':id' => $customerId]);
                }

                if (!empty($payload['held_sale_id'])) {
                    self::resumeHeldSale($pdo, (int)$payload['held_sale_id'], $branchId, $saleId, $cashierId);
                }

                AuditService::log(
                    'SALE_COMPLETED',
                    'sales',
                    $saleId,
                    "إتمام فاتورة بيع رقم {$invoiceNo} بإجمالي {$totalAmount} د.ل وتكلفة بضاعة " . round($totalCogs, 3) . ' د.ل',
                    null,
                    [
                        'invoice_number' => $invoiceNo,
                        'total_amount' => $totalAmount,
                        'total_cogs' => round($totalCogs, 3),
                        'paid_amount' => $paymentData['paid'],
                        'remaining_debt' => $paymentData['debt'],
                        'payment_method' => $paymentData['method'],
                    ],
                    $cashierId
                );
            } else {
                AuditService::log('SALE_HELD', 'sales', $saleId, "تعليق فاتورة مبيعات رقم {$invoiceNo}", null, ['invoice_number' => $invoiceNo, 'total_amount' => $totalAmount], $cashierId);
            }

            return [
                'sale_id' => $saleId,
                'invoice_number' => $invoiceNo,
                'status' => $saleStatus,
                'subtotal' => round($subtotal, 3),
                'item_discount_total' => round($manualItemDiscountTotal + $linePromotionDiscount, 3),
                'invoice_discount' => $invoiceDiscountTotal,
                'promotion_discount' => $promotionDiscount,
                'total_discount' => $totalDiscount,
                'total_amount' => $totalAmount,
                'tax_amount' => $taxAmount,
                'tax_rate' => $taxRate,
                'total_cogs' => round($totalCogs, 3),
                'paid_amount' => $paymentData['paid'],
                'change_amount' => $paymentData['change'],
                'remaining_debt' => $paymentData['debt'],
                'payment_method' => $paymentData['method'],
                'payment_status' => $paymentStatus,
                'open_cash_drawer' => (!$isHold && $openCashDrawerOnCash && in_array($paymentData['method'], ['cash', 'split'], true)),
                'duplicate' => false,
            ];
        });
    }

    private static function loadSettings(PDO $pdo): array
    {
        $settings = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        return is_array($settings) ? $settings : [];
    }

    private static function normalizeClientUuid($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = preg_replace('/[^A-Za-z0-9\-_]/', '', (string)$value);
        if ($clean === '' || strlen($clean) < 8 || strlen($clean) > 64) {
            throw new RuntimeException('معرف الطلب (client_uuid) غير صالح.');
        }
        return $clean;
    }

    private static function findByClientUuid(PDO $pdo, string $uuid, int $branchId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM sales WHERE client_uuid = :u AND branch_id = :b LIMIT 1');
        $stmt->execute([':u' => $uuid, ':b' => $branchId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private static function isUniqueViolation(PDOException $e): bool
    {
        $code = (string)$e->getCode();
        $message = strtolower($e->getMessage());
        return $code === '23000' || strpos($message, 'unique') !== false || strpos($message, 'duplicate') !== false;
    }

    private static function loadProduct(PDO $pdo, int $productId, ?int $variantId): ?array
    {
        $sql = '
            SELECT
                p.id AS product_id, p.category_id, p.name, p.sku, p.selling_price, p.purchase_cost,
                p.warranty_days, p.is_active, p.has_variants, p.requires_prescription,
                v.id AS variant_id, v.variant_name, v.sku AS v_sku,
                v.selling_price AS v_price, v.purchase_cost AS v_cost
            FROM products p
            LEFT JOIN product_variants v ON v.id = :vid AND v.product_id = p.id AND v.deleted_at IS NULL
            WHERE p.id = :pid AND p.deleted_at IS NULL
            LIMIT 1
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pid' => $productId, ':vid' => $variantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        if ((int)$row['is_active'] !== 1) {
            return null;
        }
        if ((int)($row['has_variants'] ?? 0) === 1 && $variantId === null) {
            throw new RuntimeException('هذا المنتج له خيارات (لون/موديل/حجم). يرجى اختيار الخيار المطلوب.');
        }
        if ($variantId !== null && $row['variant_id'] === null) {
            throw new RuntimeException('الخيار المحدد لا ينتمي لهذا المنتج.');
        }
        $row['effective_price'] = $row['variant_id'] !== null ? (float)$row['v_price'] : (float)$row['selling_price'];
        $row['effective_cost'] = $row['variant_id'] !== null ? (float)$row['v_cost'] : (float)$row['purchase_cost'];
        $row['effective_sku'] = $row['variant_id'] !== null ? $row['v_sku'] : $row['sku'];
        return $row;
    }

    private static function loadCustomer(PDO $pdo, int $customerId, int $branchId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            throw new RuntimeException('العميل المحدد غير موجود.');
        }
        if ((int)$customer['is_active'] !== 1) {
            throw new RuntimeException('حساب العميل معطل.');
        }
        return $customer;
    }

    private static function findOpenSessionId(PDO $pdo, int $branchId, int $userId): ?int
    {
        // Prefer the cashier's own open shift so a sale is never attributed to
        // another user's register when several shifts are open in the branch.
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

        // Fallback: a shared register opened by a colleague in the same branch.
        // A shift opened by another user must never receive this sale.
        return null;
    }

    private static function buildPayments(PDO $pdo, array $payload, float $totalAmount, array $settings, ?array $customer, int $branchId): array
    {
        $paymentsInput = $payload['payments'] ?? null;
        $rawMethod = strtolower(trim((string)($payload['payment_method'] ?? 'cash')));
        $allowedSummary = ['cash', 'card', 'transfer', 'cheque', 'credit', 'split'];
        if (!in_array($rawMethod, $allowedSummary, true)) {
            throw new RuntimeException('طريقة الدفع غير صالحة: ' . $rawMethod);
        }
        if (!is_array($paymentsInput) || empty($paymentsInput)) {
            $paymentsInput = [];
            $method = $rawMethod;
            if ($method === 'credit') {
                $paymentsInput = [];
            } elseif ($method === 'split') {
                throw new RuntimeException('الدفع المختلط يتطلب إدخال تفاصيل الدفعات.');
            } elseif (isset($payload['paid_amount']) && $payload['paid_amount'] !== '') {
                $paymentsInput = [[
                    'payment_method' => $method,
                    'currency_code' => $payload['currency_code'] ?? 'LYD',
                    'amount_foreign' => $payload['paid_amount'],
                    'reference_no' => $payload['reference_no'] ?? '',
                ]];
            }
        }

        $prepared = [];
        $paidBase = 0.0;
        // Pharmacy edition: single base currency. Exchange-rate tables are
        // no longer consulted; all amounts are recorded 1:1 in the base currency.
        $baseCurrency = (string)($settings['base_currency'] ?? 'LYD');
        $enabledMethods = ['cash', 'card', 'transfer', 'cheque'];

        foreach ($paymentsInput as $pmt) {
            if (!is_array($pmt)) {
                throw new RuntimeException('بيانات إحدى الدفعات غير صالحة.');
            }
            $method = strtolower(trim((string)($pmt['payment_method'] ?? 'cash')));
            if (!in_array($method, $enabledMethods, true)) {
                throw new RuntimeException('طريقة الدفع غير صالحة: ' . $method);
            }
            $currency = $baseCurrency;
            $rate = 1.0;
            $amountForeign = Validator::positiveDecimal($pmt['amount_foreign'] ?? 0, 'المبلغ المدفوع', true);
            if ($amountForeign <= 0) {
                continue;
            }
            $amountBase = $amountForeign;
            $paidBase = round($paidBase + $amountBase, 3);
            $prepared[] = [
                'payment_method' => $method,
                'currency_code' => $currency,
                'exchange_rate' => $rate,
                'amount_foreign' => $amountForeign,
                'amount_base' => $amountBase,
                'reference_no' => Validator::optionalString($pmt['reference_no'] ?? '', 100),
            ];
        }

        $change = 0.0;
        $effectivePaid = $paidBase;
        $debt = 0.0;

        if ($paidBase > $totalAmount + 0.0005) {
            $over = round($paidBase - $totalAmount, 3);
            $cashAvailable = 0.0;
            foreach ($prepared as $pmt) {
                if ($pmt['payment_method'] === 'cash') {
                    $cashAvailable = round($cashAvailable + $pmt['amount_base'], 3);
                }
            }
            if ($cashAvailable + 0.0005 < $over) {
                throw new RuntimeException('المبلغ المدفوع يتجاوز إجمالي الفاتورة. الباقي (الصرف) مسموح فقط للدفع النقدي.');
            }
            $change = $over;
            $effectivePaid = $totalAmount;

            // Deduct change from cash payments, starting with the last one.
            $remainingChange = $change;
            for ($i = count($prepared) - 1; $i >= 0 && $remainingChange > 0.0005; $i--) {
                if ($prepared[$i]['payment_method'] !== 'cash') {
                    continue;
                }
                $deduct = min($prepared[$i]['amount_base'], $remainingChange);
                $prepared[$i]['amount_base'] = round($prepared[$i]['amount_base'] - $deduct, 3);
                $rate = (float)$prepared[$i]['exchange_rate'];
                if ($rate > 0) {
                    $prepared[$i]['amount_foreign'] = round($prepared[$i]['amount_foreign'] - ($deduct / $rate), 3);
                }
                $remainingChange = round($remainingChange - $deduct, 3);
            }
        } elseif ($paidBase < $totalAmount - 0.0005) {
            $debt = round($totalAmount - $paidBase, 3);
            if ((int)($settings['enable_credit_sales'] ?? 1) !== 1) {
                throw new RuntimeException('المبلغ المدفوع أقل من إجمالي الفاتورة، والبيع الآجل غير مفعل.');
            }
            if ($customer === null) {
                throw new RuntimeException('يرجى تحديد العميل لتسجيل المبلغ المتبقي كدين آجل.');
            }
            if ($paidBase > 0 && (int)($settings['enable_partial_payment'] ?? 1) !== 1) {
                throw new RuntimeException('الدفع الجزئي غير مفعل. يجب دفع كامل المبلغ أو تسجيله آجلًا بالكامل.');
            }
            $limit = 0.0;
            if ((int)($settings['enable_customer_credit_limit'] ?? 0) === 1) {
                $limit = (float)($customer['credit_limit'] ?? 0);
                if ($limit <= 0) {
                    $limit = (float)($settings['max_credit_per_customer'] ?? 0);
                }
            }
            if ($limit > 0 && ((float)$customer['current_debt'] + $debt) > $limit + 0.0005) {
                throw new RuntimeException('عملية البيع تتجاوز حد الائتمان المسموح للعميل. الحد: ' . $limit . ' د.ل.');
            }
        }

        $methodSummary = 'cash';
        if (count($prepared) > 1) {
            $methodSummary = 'split';
        } elseif (count($prepared) === 1) {
            $methodSummary = $prepared[0]['payment_method'];
        } elseif ($debt > 0) {
            $methodSummary = 'credit';
        }

        $status = 'paid';
        if ($debt > 0) {
            $status = $effectivePaid > 0 ? 'partial' : 'unpaid';
        }

        return [
            'payments' => $prepared,
            'paid' => round($effectivePaid, 3),
            'change' => $change,
            'debt' => $debt,
            'method' => $methodSummary,
            'status' => $status,
        ];
    }

    private static function resumeHeldSale(PDO $pdo, int $heldSaleId, int $branchId, int $newSaleId, int $userId): void
    {
        $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = :id AND branch_id = :b LIMIT 1');
        $stmt->execute([':id' => $heldSaleId, ':b' => $branchId]);
        $held = $stmt->fetch();
        if (!$held || $held['status'] !== 'held') {
            throw new RuntimeException('الفاتورة المعلقة غير موجودة أو تم استئنافها مسبقًا.');
        }
        $pdo->prepare('UPDATE sales SET status = \'resumed\', notes = :notes, updated_at = :now WHERE id = :id')
            ->execute([
                ':notes' => trim((string)$held['notes'] . " | تم استئنافها في الفاتورة #{$newSaleId}"),
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $heldSaleId,
            ]);
        AuditService::log('SALE_RESUMED', 'sales', $heldSaleId, "استئناف فاتورة معلقة رقم {$held['invoice_number']} وتحويلها للفاتورة #{$newSaleId}", null, ['new_sale_id' => $newSaleId], $userId);
    }

    private static function summaryForSale(PDO $pdo, int $saleId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $saleId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            throw new RuntimeException('الفاتورة غير موجودة.');
        }
        return [
            'sale_id' => (int)$sale['id'],
            'invoice_number' => $sale['invoice_number'],
            'status' => $sale['status'],
            'subtotal' => (float)$sale['subtotal'],
            'item_discount_total' => (float)$sale['item_discount_total'],
            'invoice_discount' => (float)$sale['invoice_discount'],
            'promotion_discount' => (float)($sale['promotion_discount'] ?? 0),
            'total_discount' => (float)$sale['total_discount'],
            'total_amount' => (float)$sale['total_amount'],
            'total_cogs' => (float)$sale['total_cogs'],
            'paid_amount' => (float)$sale['paid_amount'],
            'change_amount' => (float)$sale['change_amount'],
            'remaining_debt' => (float)$sale['remaining_debt'],
            'payment_method' => $sale['payment_method'],
            'payment_status' => $sale['payment_status'],
            'warranties' => [],
            'open_cash_drawer' => false,
        ];
    }

    public static function getHeldSales(int $branchId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT s.id, s.invoice_number, s.sale_date, s.total_amount, s.notes,
                   u.full_name AS cashier_name, c.name AS customer_name,
                   (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS items_count
            FROM sales s
            JOIN users u ON u.id = s.cashier_id
            LEFT JOIN customers c ON c.id = s.customer_id
            WHERE s.branch_id = :b AND s.status = \'held\'
            ORDER BY s.id DESC
            LIMIT 100
        ');
        $stmt->execute([':b' => $branchId]);
        return $stmt->fetchAll();
    }

    public static function getHeldSaleDetails(int $saleId, int $branchId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = :id AND branch_id = :b AND status = \'held\' LIMIT 1');
        $stmt->execute([':id' => $saleId, ':b' => $branchId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            throw new RuntimeException('الفاتورة المعلقة غير موجودة.');
        }
        $itemsStmt = $pdo->prepare('
            SELECT si.*, p.selling_price, p.has_variants, p.warranty_days, p.purchase_cost
            FROM sale_items si
            JOIN products p ON p.id = si.product_id
            WHERE si.sale_id = :sid
            ORDER BY si.id ASC
        ');
        $itemsStmt->execute([':sid' => $saleId]);
        $sale['items'] = $itemsStmt->fetchAll();
        return $sale;
    }

    public static function deleteHeldSale(int $saleId, int $branchId, int $userId): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = :id AND branch_id = :b AND status = \'held\' LIMIT 1');
        $stmt->execute([':id' => $saleId, ':b' => $branchId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            throw new RuntimeException('الفاتورة المعلقة غير موجودة أو ليست معلقة.');
        }
        $pdo->prepare('UPDATE sales SET status = \'cancelled\', cancel_reason = :reason, cancelled_by = :uid, cancelled_at = :now, updated_at = :now2 WHERE id = :id')
            ->execute([
                ':reason' => 'حذف فاتورة معلقة',
                ':uid' => $userId,
                ':now' => date('Y-m-d H:i:s'),
                ':now2' => date('Y-m-d H:i:s'),
                ':id' => $saleId,
            ]);
        AuditService::log('HELD_SALE_DELETED', 'sales', $saleId, "حذف فاتورة معلقة رقم {$sale['invoice_number']}", null, null, $userId);
    }

    private static function consumeFifoCost(PDO $pdo, int $productId, ?int $variantId, int $neededQty, float $fallbackCost): float
    {
        $sql = 'SELECT id, remaining_fifo_qty, landed_unit_cost FROM purchase_items WHERE product_id = :p AND remaining_fifo_qty > 0';
        $params = [':p' => $productId];
        if ($variantId !== null) {
            $sql .= ' AND variant_id = :v';
            $params[':v'] = $variantId;
        } else {
            $sql .= ' AND variant_id IS NULL';
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $layers = $stmt->fetchAll();
        if (empty($layers)) {
            return $fallbackCost;
        }

        $remaining = $neededQty;
        $totalCost = 0.0;
        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int)$layer['remaining_fifo_qty']);
            $totalCost += $take * (float)$layer['landed_unit_cost'];
            $remaining -= $take;
            $pdo->prepare('UPDATE purchase_items SET remaining_fifo_qty = remaining_fifo_qty - :take WHERE id = :id')
                ->execute([':take' => $take, ':id' => $layer['id']]);
        }
        if ($remaining > 0) {
            $totalCost += $remaining * $fallbackCost;
        }
        return round($totalCost / max(1, $neededQty), 3);
    }
}
