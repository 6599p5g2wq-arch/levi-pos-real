<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Customers, credit balances, debt collection and statements.
 */
class CustomerService
{
    public static function listCustomers(array $filters = []): array
    {
        $pdo = Database::getConnection();
        $q = trim((string)($filters['q'] ?? ''));
        $sql = '
            SELECT c.*,
                   (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.id AND s.status IN (\'completed\', \'returned\')) AS invoice_count,
                   (SELECT MAX(s.sale_date) FROM sales s WHERE s.customer_id = c.id AND s.status IN (\'completed\', \'returned\')) AS last_sale
            FROM customers c
            WHERE c.deleted_at IS NULL
        ';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (c.name LIKE :q1 OR c.phone LIKE :q2 OR c.email LIKE :q3)';
            $like = '%' . $q . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $sql .= ' AND c.is_active = :active';
            $params[':active'] = !empty($filters['is_active']) ? 1 : 0;
        }
        if (!empty($filters['has_debt'])) {
            $sql .= ' AND c.current_debt > 0';
        }
        $limit = min(max((int)($filters['limit'] ?? 200), 1), 500);
        $sql .= ' ORDER BY c.current_debt DESC, c.name ASC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getCustomer(int $customerId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            throw new RuntimeException('العميل غير موجود.');
        }
        $salesStmt = $pdo->prepare('
            SELECT id, invoice_number, sale_date, total_amount, paid_amount, remaining_debt, payment_status, status
            FROM sales
            WHERE customer_id = :cid AND status IN (\'completed\', \'returned\')
            ORDER BY id DESC LIMIT 50
        ');
        $salesStmt->execute([':cid' => $customerId]);
        $customer['sales'] = $salesStmt->fetchAll();

        $payStmt = $pdo->prepare('
            SELECT cp.*, u.full_name AS created_by_name
            FROM customer_payments cp
            JOIN users u ON u.id = cp.created_by
            WHERE cp.customer_id = :cid AND cp.reversed_at IS NULL
            ORDER BY cp.id DESC LIMIT 50
        ');
        $payStmt->execute([':cid' => $customerId]);
        $customer['payments'] = $payStmt->fetchAll();
        return $customer;
    }

    public static function saveCustomer(array $data, int $userId): array
    {
        $id = !empty($data['id']) ? Validator::positiveInt($data['id'], 'معرف العميل') : null;
        $name = Validator::requireNonEmpty($data['name'] ?? null, 'اسم العميل', 150);
        $phone = Validator::phone($data['phone'] ?? null, 'رقم هاتف العميل', true);
        $email = Validator::email($data['email'] ?? '', 'البريد الإلكتروني', true);
        $address = Validator::optionalString($data['address'] ?? '', 500);
        $notes = Validator::optionalString($data['notes'] ?? '', 2000);
        $creditLimit = Validator::positiveDecimal($data['credit_limit'] ?? 0, 'حد الائتمان', true);
        $birthDate = !empty($data['birth_date']) ? Validator::date($data['birth_date'], 'تاريخ الميلاد') : null;
        $gender = Validator::enum($data['gender'] ?? '', ['male', 'female', 'other', ''], 'النوع', '');
        $bloodType = Validator::optionalString($data['blood_type'] ?? '', 10);
        $allergies = Validator::optionalString($data['allergies'] ?? '', 2000);
        $drugAllergies = Validator::optionalString($data['drug_allergies'] ?? '', 2000);
        $chronicDiseases = Validator::optionalString($data['chronic_diseases'] ?? '', 2000);
        $medicalNotes = Validator::optionalString($data['medical_notes'] ?? '', 2000);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $pdo = Database::getConnection();
        $now = date('Y-m-d H:i:s');

        $dupStmt = $pdo->prepare('SELECT id FROM customers WHERE phone = :phone AND deleted_at IS NULL LIMIT 1');
        $dupStmt->execute([':phone' => $phone]);
        $dup = $dupStmt->fetchColumn();
        if ($dup !== false && ($id === null || (int)$dup !== $id)) {
            throw new RuntimeException('رقم الهاتف مستخدم مسبقًا لعميل آخر.');
        }

        if ($id !== null) {
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([':id' => $id]);
            $old = $stmt->fetch();
            if (!$old) {
                throw new RuntimeException('العميل غير موجود.');
            }
            $pdo->prepare('
                UPDATE customers SET name = :name, phone = :phone, email = :email, address = :address,
                    credit_limit = :credit_limit, notes = :notes,
                    birth_date = :birth_date, gender = :gender, blood_type = :blood_type,
                    allergies = :allergies, drug_allergies = :drug_allergies,
                    chronic_diseases = :chronic_diseases, medical_notes = :medical_notes,
                    is_active = :active, updated_at = :now
                WHERE id = :id
            ')->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':email' => $email !== '' ? $email : null,
                ':address' => $address,
                ':credit_limit' => $creditLimit,
                ':notes' => $notes,
                ':birth_date' => $birthDate,
                ':gender' => $gender !== '' ? $gender : null,
                ':blood_type' => $bloodType !== '' ? $bloodType : null,
                ':allergies' => $allergies,
                ':drug_allergies' => $drugAllergies,
                ':chronic_diseases' => $chronicDiseases,
                ':medical_notes' => $medicalNotes,
                ':active' => $isActive,
                ':now' => $now,
                ':id' => $id,
            ]);
            AuditService::log('CUSTOMER_UPDATED', 'customers', $id, "تعديل بيانات العميل: {$name}", $old, $data, $userId);
            return ['customer_id' => $id];
        }

        try {
            $pdo->prepare('
                INSERT INTO customers
                    (name, phone, email, address, credit_limit, current_debt, notes,
                     birth_date, gender, blood_type, allergies, drug_allergies, chronic_diseases, medical_notes,
                     is_active, created_by, created_at, updated_at)
                VALUES (:name, :phone, :email, :address, :credit_limit, 0, :notes,
                        :birth_date, :gender, :blood_type, :allergies, :drug_allergies, :chronic_diseases, :medical_notes,
                        :active, :created_by, :now1, :now2)
            ')->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':email' => $email !== '' ? $email : null,
                ':address' => $address,
                ':credit_limit' => $creditLimit,
                ':notes' => $notes,
                ':birth_date' => $birthDate,
                ':gender' => $gender !== '' ? $gender : null,
                ':blood_type' => $bloodType !== '' ? $bloodType : null,
                ':allergies' => $allergies,
                ':drug_allergies' => $drugAllergies,
                ':chronic_diseases' => $chronicDiseases,
                ':medical_notes' => $medicalNotes,
                ':active' => $isActive,
                ':created_by' => $userId,
                ':now1' => $now,
                ':now2' => $now,
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000' || stripos($e->getMessage(), 'unique') !== false) {
                throw new RuntimeException('رقم الهاتف مستخدم مسبقًا لعميل آخر.');
            }
            throw $e;
        }
        $newId = (int)$pdo->lastInsertId();
        AuditService::log('CUSTOMER_CREATED', 'customers', $newId, "إضافة عميل جديد: {$name} - {$phone}", null, $data, $userId);
        return ['customer_id' => $newId];
    }

    public static function deleteCustomer(int $customerId, int $userId): string
    {
        return Database::transaction(function (PDO $pdo) use ($customerId, $userId): string {
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([':id' => $customerId]);
            $customer = $stmt->fetch();
            if (!$customer) {
                throw new RuntimeException('العميل غير موجود.');
            }
            if ((float)$customer['current_debt'] > 0) {
                throw new RuntimeException('لا يمكن حذف عميل عليه ديون مستحقة. يرجى تسوية الرصيد أولاً.');
            }
            $salesStmt = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE customer_id = :id');
            $salesStmt->execute([':id' => $customerId]);
            $hasSales = (int)$salesStmt->fetchColumn() > 0;
            $now = date('Y-m-d H:i:s');
            if ($hasSales) {
                $pdo->prepare('UPDATE customers SET is_active = 0, deleted_at = :now, updated_at = :now2 WHERE id = :id')
                    ->execute([':now' => $now, ':now2' => $now, ':id' => $customerId]);
                AuditService::log('CUSTOMER_ARCHIVED', 'customers', $customerId, "أرشفة العميل المرتبط بفواتير: {$customer['name']}", $customer, ['is_active' => 0], $userId);
                return 'archived';
            }
            $pdo->prepare('UPDATE customers SET is_active = 0, deleted_at = :now, updated_at = :now2 WHERE id = :id')
                ->execute([':now' => $now, ':now2' => $now, ':id' => $customerId]);
            AuditService::log('CUSTOMER_DELETED', 'customers', $customerId, "حذف العميل: {$customer['name']}", $customer, null, $userId);
            return 'deleted';
        });
    }

    /**
     * Collect a debt payment and allocate it to the customer's oldest open invoices.
     */
    public static function collectPayment(array $data, int $userId, int $branchId): array
    {
        return Database::transaction(function (PDO $pdo) use ($data, $userId, $branchId): array {
            $customerId = Validator::positiveInt($data['customer_id'] ?? 0, 'العميل');
            $amount = Validator::positiveDecimal($data['amount'] ?? 0, 'مبلغ التحصيل', false);
            $method = Validator::enum($data['payment_method'] ?? 'cash', ['cash', 'card', 'transfer', 'cheque'], 'طريقة الدفع', 'cash');
            $notes = Validator::optionalString($data['notes'] ?? '', 2000);
            $baseCurrency = strtoupper(trim((string)SettingsService::getValue('base_currency', 'LYD')));
            $currency = strtoupper(trim((string)($data['currency_code'] ?? $baseCurrency)));
            if ($currency !== '' && $currency !== $baseCurrency) {
                throw new RuntimeException('العملة غير مدعومة في إصدار الصيدلية: ' . $currency);
            }
            $currency = $baseCurrency;
            $rate = 1.0;
            $amountBase = round($amount, 3);

            $custStmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $custStmt->execute([':id' => $customerId]);
            $customer = $custStmt->fetch();
            if (!$customer) {
                throw new RuntimeException('العميل غير موجود.');
            }
            if ((float)$customer['current_debt'] <= 0) {
                throw new RuntimeException('لا توجد ديون مستحقة على هذا العميل.');
            }
            if ($amountBase > (float)$customer['current_debt'] + 0.0005) {
                throw new RuntimeException('مبلغ التحصيل يتجاوز إجمالي دين العميل (' . $customer['current_debt'] . ' د.ل).');
            }

            $saleId = !empty($data['sale_id']) ? (int)$data['sale_id'] : null;
            $allocations = [];
            $remaining = $amountBase;

            if ($saleId !== null) {
                $sale = self::loadOpenSale($pdo, $saleId, $customerId);
                $allocate = min($remaining, (float)$sale['remaining_debt']);
                if ($allocate > 0) {
                    self::applyToSale($pdo, $sale, $allocate);
                    $allocations[] = ['sale_id' => $saleId, 'amount' => $allocate];
                    $remaining = round($remaining - $allocate, 3);
                }
            } else {
                $salesStmt = $pdo->prepare('
                    SELECT * FROM sales
                    WHERE customer_id = :cid AND remaining_debt > 0 AND status IN (\'completed\', \'returned\')
                    ORDER BY sale_date ASC, id ASC
                ');
                $salesStmt->execute([':cid' => $customerId]);
                foreach ($salesStmt->fetchAll() as $sale) {
                    if ($remaining <= 0.0005) {
                        break;
                    }
                    $allocate = min($remaining, (float)$sale['remaining_debt']);
                    if ($allocate <= 0) {
                        continue;
                    }
                    self::applyToSale($pdo, $sale, $allocate);
                    $allocations[] = ['sale_id' => (int)$sale['id'], 'amount' => $allocate];
                    $remaining = round($remaining - $allocate, 3);
                }
            }

            // Any leftover should not happen because we rejected overpayment, but keep it safe.
            $allocatedTotal = round($amountBase - $remaining, 3);
            if ($allocatedTotal <= 0) {
                throw new RuntimeException('تعذر توزيع الدفعة على فواتير العميل.');
            }

            $receiptNo = NumberService::generate($pdo, 'customer_payments', 'receipt_number', 'CPAY');
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('
                INSERT INTO customer_payments (
                    branch_id, receipt_number, customer_id, sale_id, amount,
                    currency_code, exchange_rate, amount_in_base, payment_method,
                    payment_date, notes, created_by, created_at
                ) VALUES (
                    :branch_id, :receipt_no, :customer_id, :sale_id, :amount,
                    :currency, :rate, :amount_base, :method,
                    :payment_date, :notes, :created_by, :created_at
                )
            ')->execute([
                ':branch_id' => $branchId,
                ':receipt_no' => $receiptNo,
                ':customer_id' => $customerId,
                ':sale_id' => $saleId,
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

            $pdo->prepare('UPDATE customers SET current_debt = current_debt - :amt, updated_at = :now WHERE id = :id')
                ->execute([':amt' => $amountBase, ':now' => $now, ':id' => $customerId]);

            if ($method === 'cash') {
                $sessionId = self::findOpenSessionId($pdo, $branchId, $userId);
                if ($sessionId === null) {
                    throw new RuntimeException('لا توجد وردية كاشير مفتوحة. يرجى فتح وردية الصندوق لتسجيل التحصيل النقدي.');
                }
                $pdo->prepare('
                    INSERT INTO cash_movements (
                        session_id, branch_id, movement_type, amount, reference_type,
                        reference_id, description, created_by, created_at
                    ) VALUES (
                        :sess, :branch, \'debt_collection\', :amt, \'customer_payment\', :pid, :desc, :uid, :now
                    )
                ')->execute([
                    ':sess' => $sessionId,
                    ':branch' => $branchId,
                    ':amt' => $amountBase,
                    ':pid' => $paymentId,
                    ':desc' => "تحصيل دين من العميل {$customer['name']} - سند {$receiptNo}",
                    ':uid' => $userId,
                    ':now' => $now,
                ]);
            }

            AuditService::log(
                'CUSTOMER_PAYMENT',
                'customer_payments',
                $paymentId,
                "تحصيل دفعة من العميل {$customer['name']} بمبلغ {$amountBase} د.ل - سند {$receiptNo}",
                null,
                ['receipt_number' => $receiptNo, 'customer_id' => $customerId, 'amount_base' => $amountBase, 'method' => $method, 'allocations' => $allocations],
                $userId
            );

            return [
                'payment_id' => $paymentId,
                'receipt_number' => $receiptNo,
                'customer_id' => $customerId,
                'amount' => $amount,
                'currency_code' => $currency,
                'exchange_rate' => $rate,
                'amount_in_base' => $amountBase,
                'payment_method' => $method,
                'allocations' => $allocations,
                'remaining_customer_debt' => round((float)$customer['current_debt'] - $amountBase, 3),
            ];
        });
    }

    public static function getStatement(int $customerId, string $startDate, string $endDate): array
    {
        $pdo = Database::getConnection();
        $customer = self::getCustomer($customerId);
        $start = $startDate . ' 00:00:00';
        $end = $endDate . ' 23:59:59';

        $salesStmt = $pdo->prepare('
            SELECT invoice_number AS reference, sale_date AS entry_date, \'sale\' AS entry_type,
                   total_amount AS debit, paid_amount AS credit, remaining_debt
            FROM sales
            WHERE customer_id = :cid AND status IN (\'completed\', \'returned\')
              AND sale_date BETWEEN :start AND :end
        ');
        $salesStmt->execute([':cid' => $customerId, ':start' => $start, ':end' => $end]);
        $sales = $salesStmt->fetchAll();

        $payStmt = $pdo->prepare('
            SELECT receipt_number AS reference, payment_date AS entry_date, \'payment\' AS entry_type,
                   0 AS debit, amount_in_base AS credit, 0 AS remaining_debt
            FROM customer_payments
            WHERE customer_id = :cid AND reversed_at IS NULL
              AND payment_date BETWEEN :start AND :end
        ');
        $payStmt->execute([':cid' => $customerId, ':start' => $start, ':end' => $end]);
        $payments = $payStmt->fetchAll();

        $retStmt = $pdo->prepare('
            SELECT sr.return_number AS reference, sr.created_at AS entry_date, \'return\' AS entry_type,
                   0 AS debit, sr.total_refund_amount AS credit, 0 AS remaining_debt
            FROM sale_returns sr
            WHERE sr.customer_id = :cid AND sr.created_at BETWEEN :start AND :end
        ');
        $retStmt->execute([':cid' => $customerId, ':start' => $start, ':end' => $end]);
        $returns = $retStmt->fetchAll();

        $entries = array_merge($sales, $payments, $returns);
        usort($entries, static function (array $a, array $b): int {
            return strcmp((string)$a['entry_date'], (string)$b['entry_date']);
        });
        return [
            'customer' => $customer,
            'period' => ['start_date' => $startDate, 'end_date' => $endDate],
            'entries' => $entries,
            'closing_debt' => (float)$customer['current_debt'],
        ];
    }

    private static function loadOpenSale(PDO $pdo, int $saleId, int $customerId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = :id AND customer_id = :cid AND status IN (\'completed\', \'returned\') LIMIT 1');
        $stmt->execute([':id' => $saleId, ':cid' => $customerId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            throw new RuntimeException('الفاتورة غير موجودة أو لا تنتمي لهذا العميل.');
        }
        if ((float)$sale['remaining_debt'] <= 0) {
            throw new RuntimeException('هذه الفاتورة مسددة بالكامل.');
        }
        return $sale;
    }

    private static function applyToSale(PDO $pdo, array $sale, float $amount): void
    {
        $newDebt = max(0.0, round((float)$sale['remaining_debt'] - $amount, 3));
        $newPaid = round((float)$sale['paid_amount'] + $amount, 3);
        $status = $newDebt <= 0 ? 'paid' : 'partial';
        $pdo->prepare('UPDATE sales SET paid_amount = :paid, remaining_debt = :debt, payment_status = :status, updated_at = :now WHERE id = :id')
            ->execute([
                ':paid' => $newPaid,
                ':debt' => $newDebt,
                ':status' => $status,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $sale['id'],
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
        // A shift opened by another user must never receive this collection.
        return null;
    }
}
