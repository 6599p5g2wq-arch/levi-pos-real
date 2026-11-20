<?php
declare(strict_types=1);

/**
 * LEVI POS - Integration test runner (CLI only).
 *
 * Runs against a throwaway SQLite database in the system temp directory so it
 * never touches the live data. Usage:
 *   php tests/run.php
 *
 * PHP 8.0 compatible.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This test runner can only be executed from the command line.');
}

$tempDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'levi_test_' . bin2hex(random_bytes(5)) . '.sqlite';
putenv('LEVI_SQLITE_PATH=' . $tempDb);
putenv('LEVI_ENV=testing');
putenv('LEVI_TIMEZONE=Africa/Tripoli');
putenv('LEVI_ADMIN_PASSWORD=admin123');

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\CSRF;
use App\Core\Database;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\BatchService;
use App\Services\CashRegisterService;
use App\Services\CustomerService;
use App\Services\DoctorService;
use App\Services\ExpenseService;
use App\Services\InstallerService;
use App\Services\InventoryService;
use App\Services\OwnerCleanupService;
use App\Services\PatientRecordService;
use App\Services\PharmacyInventoryService;
use App\Services\PosService;
use App\Services\PrescriptionService;
use App\Services\PriceHistoryService;
use App\Services\PrinterService;
use App\Services\ProductService;
use App\Services\ProductUnitService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\SettingsService;
use App\Services\SupplierService;
use App\Services\UserService;

// Initialise the (in-memory for CLI) session before any output so audit logs
// and auth helpers can run without "headers already sent" warnings.
\App\Core\Session::start();

$passed = 0;
$failed = 0;
$failures = [];

function check(string $name, callable $callback): void
{
    global $passed, $failed, $failures;
    try {
        $callback();
        $passed++;
        echo "PASS  {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        $failures[] = $name . ' :: ' . $e->getMessage();
        echo "FAIL  {$name} :: {$e->getMessage()}\n";
    }
}

function assertTrue($condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual, string $message = 'values differ'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

function assertGreaterThan($threshold, $actual, string $message = 'value not greater than threshold'): void
{
    if (!($actual > $threshold)) {
        throw new RuntimeException($message . ' (' . var_export($actual, true) . ' <= ' . var_export($threshold, true) . ')');
    }
}

function assertThrowsMessage(callable $callback, string $expectedPart): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), $expectedPart) !== false) {
            return;
        }
        throw new RuntimeException('unexpected error message: ' . $e->getMessage());
    }
    throw new RuntimeException('expected an exception containing: ' . $expectedPart);
}

try {
    $install = InstallerService::install(false);
    $pdo = Database::getConnection();

    $branchId = 1;
    $userId = (int)$pdo->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetchColumn();
    $ownerRow = $pdo->query("SELECT u.id, u.role_id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'owner' LIMIT 1")->fetch();
    $ownerId = $ownerRow ? (int)$ownerRow['id'] : 0;
    $ownerRoleId = $ownerRow ? (int)$ownerRow['role_id'] : 0;
    $registerId = (int)$pdo->query('SELECT id FROM cash_registers ORDER BY id ASC LIMIT 1')->fetchColumn();
    $categoryId = (int)$pdo->query('SELECT id FROM categories ORDER BY id ASC LIMIT 1')->fetchColumn();
    $expenseCategoryId = (int)$pdo->query('SELECT id FROM expense_categories ORDER BY id ASC LIMIT 1')->fetchColumn();

    echo "=== LEVI POS integration tests ===\n";
    echo 'temp database: ' . $tempDb . "\n\n";

    check('installer created schema', function () use ($install, $pdo) {
        assertTrue($install['schema_created'], 'schema was not created');
        assertGreaterThan(0, (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        assertGreaterThan(0, (int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn());
    });

    check('admin credentials', function () use ($pdo, $userId) {
        $hash = $pdo->query("SELECT password_hash FROM users WHERE id = " . $userId)->fetchColumn();
        assertTrue(password_verify('admin123', (string)$hash), 'admin123 does not verify');
    });

    check('CSRF token validation rejects missing or invalid tokens', function () {
        $token = CSRF::generateToken();
        assertTrue($token !== '', 'generated CSRF token is empty');
        assertTrue(CSRF::verify($token), 'valid CSRF token was rejected');
        assertTrue(!CSRF::verify(null), 'missing CSRF token was accepted');
        assertTrue(!CSRF::verify('invalid-token'), 'invalid CSRF token was accepted');
    });

    check('settings read and update', function () use ($ownerId) {
        $settings = SettingsService::getSettings();
        assertTrue(isset($settings['store']['store_name']), 'store settings missing');
        assertTrue(isset($settings['store']['login_title']), 'login title setting missing');
        assertTrue(isset($settings['store']['login_subtitle']), 'login subtitle setting missing');
        assertTrue(isset($settings['inventory']['expiry_alert_days']), 'expiry alert window setting missing');
        assertTrue(isset($settings['inventory']['block_expired_sales']), 'expired-sale block setting missing');
        assertTrue(isset($settings['inventory']['fefo_enabled']), 'FEFO setting missing');
        assertTrue(!isset($settings['sales']['enable_multi_currency']), 'legacy multi-currency setting must be removed');
        assertTrue(!isset($settings['sales']['enable_promotions']), 'legacy promotions setting must be removed');
        assertTrue(!isset($settings['sales']['enable_warranties']), 'legacy warranties setting must be removed');
        SettingsService::updateSettings(['store_name' => 'LEVI Test Store'], $ownerId);
        $again = SettingsService::getSettings();
        assertSame('LEVI Test Store', $again['store']['store_name']);
    });

    check('shift requirement setting can be turned off', function () use ($ownerId) {
        SettingsService::updateSettings(['require_open_register' => '0'], $ownerId);
        $settings = SettingsService::getSettings();
        assertSame('0', (string)$settings['sales']['require_open_register']);
        SettingsService::updateSettings(['require_open_register' => '1'], $ownerId);
        $again = SettingsService::getSettings();
        assertSame('1', (string)$again['sales']['require_open_register']);
    });

    check('owner account is seeded with a protected hidden role', function () use ($pdo, $ownerId) {
        assertGreaterThan(0, $ownerId, 'owner account was not created');
        $row = $pdo->query("
            SELECT u.username, u.password_hash, u.must_change_password, u.is_active, r.name AS role_name, r.is_hidden
            FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.id = " . $ownerId . "
        ")->fetch();
        assertSame(InstallerService::OWNER_USERNAME, $row['username']);
        assertTrue(password_verify('AFARUQ1965@ahmed2026', (string)$row['password_hash']), 'owner password does not verify');
        assertSame('owner', $row['role_name']);
        assertSame(1, (int)$row['is_hidden'], 'owner role must be hidden');
        assertSame(1, (int)$row['is_active']);
        assertSame(0, (int)$row['must_change_password']);
    });

    check('store manager cannot see the owner account or role', function () use ($userId, $ownerId) {
        $adminUsers = UserService::listUsers([], $userId);
        foreach ($adminUsers as $row) {
            assertTrue($row['username'] !== InstallerService::OWNER_USERNAME, 'owner leaked into the user list');
        }
        $adminRoles = UserService::listRoles($userId);
        foreach ($adminRoles as $row) {
            assertTrue($row['name'] !== 'owner', 'owner role leaked into the role list');
        }
        $ownerRoles = UserService::listRoles($ownerId);
        $names = array_map(static function (array $row): string { return (string)$row['name']; }, $ownerRoles);
        assertTrue(in_array('owner', $names, true), 'owner cannot see the owner role');
        $adminPermissions = UserService::listPermissions($userId);
        foreach ($adminPermissions as $row) {
            assertTrue($row['code'] !== 'settings.store_identity', 'owner-only permission leaked to the manager');
        }
    });

    check('store identity is editable by the owner only', function () use ($ownerId, $userId) {
        SettingsService::updateSettings(['store_name' => 'Owner Store', 'login_title' => 'بوابة المالك'], $ownerId);
        $settings = SettingsService::getSettings();
        assertSame('Owner Store', $settings['store']['store_name']);
        assertSame('بوابة المالك', $settings['store']['login_title']);
        assertThrowsMessage(function () use ($userId) {
            SettingsService::updateSettings(['store_name' => 'Manager Store'], $userId);
        }, 'مخصص للمالك فقط');
    });

    check('store manager cannot modify the owner account', function () use ($ownerId, $ownerRoleId, $userId, $branchId) {
        assertThrowsMessage(function () use ($ownerId, $userId) {
            UserService::updateUser($ownerId, ['full_name' => 'محاولة تعديل', 'role_id' => 1, 'branch_id' => 1, 'is_active' => 1], $userId);
        }, 'محمي');
        assertThrowsMessage(function () use ($ownerId, $userId) {
            UserService::resetPassword($ownerId, 'Hacked12345', $userId);
        }, 'محمي');
        assertThrowsMessage(function () use ($ownerId, $userId) {
            UserService::deleteUser($ownerId, $userId);
        }, 'محمي');
        assertThrowsMessage(function () use ($ownerRoleId, $userId) {
            UserService::createUser([
                'username' => 'intruder_' . bin2hex(random_bytes(3)),
                'full_name' => 'محاولة إسناد',
                'password' => 'Test12345',
                'role_id' => $ownerRoleId,
                'branch_id' => 1,
                'is_active' => 1,
            ], $userId, 1);
        }, 'دور المالك');
        assertThrowsMessage(function () use ($ownerRoleId, $userId) {
            UserService::updateRolePermissions($ownerRoleId, ['dashboard.view'], $userId);
        }, 'دور المالك محمي');
    });

    check('audit trail hides the owner identity from the store manager', function () use ($userId, $ownerId) {
        AuditService::log('TEST_OWNER_ACTION', 'security', null, 'إجراء تجريبي بواسطة المدير العام faruq040', null, null, $ownerId, 'faruq040');
        $adminLogs = UserService::getAuditLogs(['limit' => 10], $userId);
        $adminRow = null;
        foreach ($adminLogs as $row) {
            if ($row['action_type'] === 'TEST_OWNER_ACTION') {
                $adminRow = $row;
                break;
            }
        }
        assertTrue($adminRow !== null, 'owner audit row not found');
        assertSame('إدارة النظام', $adminRow['user_name']);
        assertTrue(strpos((string)$adminRow['description'], 'المدير العام') === false, 'owner name leaked in audit description');
        assertTrue(strpos((string)$adminRow['description'], 'faruq040') === false, 'owner username leaked in audit description');

        $ownerLogs = UserService::getAuditLogs(['limit' => 10], $ownerId);
        $ownerRow = null;
        foreach ($ownerLogs as $row) {
            if ($row['action_type'] === 'TEST_OWNER_ACTION') {
                $ownerRow = $row;
                break;
            }
        }
        assertTrue($ownerRow !== null, 'owner cannot see own audit row');
        assertSame('المدير العام', $ownerRow['user_name']);
        assertTrue(strpos((string)$ownerRow['description'], 'faruq040') !== false, 'owner audit details were altered');

        AuditService::log('TEST_OWNER_FAILED_LOGIN', 'users', null, 'محاولة دخول فاشلة لاسم مستخدم غير موجود: faruq040', null, null, null, 'faruq040');
        $failedLogs = UserService::getAuditLogs(['limit' => 10], $userId);
        $failedRow = null;
        foreach ($failedLogs as $row) {
            if ($row['action_type'] === 'TEST_OWNER_FAILED_LOGIN') {
                $failedRow = $row;
                break;
            }
        }
        assertTrue($failedRow !== null, 'failed-login audit row not found');
        assertSame('إدارة النظام', $failedRow['user_name']);
        assertSame('إدارة النظام', $failedRow['username_snapshot']);
        assertTrue(strpos((string)$failedRow['description'], 'faruq040') === false, 'owner username leaked in failed-login audit');
    });

    $productId = 0;
    $sku = 'TEST-' . bin2hex(random_bytes(3));
    check('create product with opening stock', function () use (&$productId, $userId, $branchId, $categoryId, $sku) {
        $productId = ProductService::create([
            'name' => 'Test Product ' . $sku,
            'sku' => $sku,
            'barcode' => 'BC' . $sku,
            'category_id' => $categoryId,
            'purchase_cost' => 10,
            'selling_price' => 15,
            'wholesale_price' => 14,
            'min_stock_level' => 2,
            'active_ingredient' => 'Paracetamol',
            'strength' => '500 mg',
            'dosage_form' => 'أقراص',
            'manufacturer' => 'شركة الدواء_TEST',
            'registration_number' => 'REG-' . $sku,
            'country_of_origin' => 'ليبيا',
            'storage_conditions' => 'درجة حرارة الغرفة',
            'requires_prescription' => 0,
            'initial_quantity' => 20,
        ], $userId, $branchId);
        assertGreaterThan(0, $productId);
        $product = ProductService::getProduct($productId, $branchId);
        assertSame($sku, $product['sku']);
        assertSame('Paracetamol', (string)$product['active_ingredient']);
        assertSame('500 mg', (string)$product['strength']);
        assertSame('أقراص', (string)$product['dosage_form']);
    });

    check('inventory adjustment', function () use ($productId, $userId, $branchId) {
        $result = InventoryService::recordAdjustment([
            'product_id' => $productId,
            'adjustment_type' => 'adjustment',
            'quantity_change' => 5,
            'reason' => 'integration test',
        ], $userId, $branchId);
        assertTrue(isset($result['adjustment_id']));

        $unitResult = ProductUnitService::saveForProduct($productId, [
            ['unit_name' => 'علبة', 'base_unit' => 'piece', 'conversion_factor' => 10,
                'selling_price' => 100, 'is_default' => 1],
        ], $userId, $branchId);
        assertSame($productId, (int)$unitResult['product_id']);
        assertTrue((int)$unitResult['saved'] > 0, 'product unit was not saved');
        $units = ProductUnitService::listForProduct($productId);
        assertTrue(count($units) >= 1, 'product unit list is empty');
        $unitId = (int)($units[0]['id'] ?? 0);
        assertGreaterThan(0, $unitId);
        $resolved = ProductUnitService::resolve($productId, $unitId);
        assertTrue((bool)($resolved['found'] ?? false), 'unit resolve failed');

        $stock = PharmacyInventoryService::createStocktake([
            'notes' => 'integration test stocktake',
            'items' => [['product_id' => $productId, 'expected_qty' => 5]],
        ], $userId, $branchId);
        $stockId = (int)($stock['id'] ?? 0);
        assertGreaterThan(0, $stockId);
        $stockItems = $stock['items'] ?? [];
        assertTrue(count($stockItems) >= 1, 'stocktake items missing');
        $itemId = (int)($stockItems[0]['id'] ?? 0);
        assertGreaterThan(0, $itemId);
        PharmacyInventoryService::recordStocktakeCounts($stockId, [
            'items' => [['item_id' => $itemId, 'counted_qty' => 6]],
        ], $userId, $branchId);
        $approved = PharmacyInventoryService::approveStocktake($stockId, $userId, $branchId);
        assertSame('approved', (string)($approved['status'] ?? ''));
        $again = PharmacyInventoryService::approveStocktake($stockId, $userId, $branchId);
        assertTrue(!empty($again['already_approved']), 'approval is not idempotent');

        $damage = PharmacyInventoryService::createDamage([
            'product_id' => $productId,
            'quantity' => 1,
            'reason' => 'integration test damage',
        ], $userId, $branchId);
        assertGreaterThan(0, (int)($damage['id'] ?? 0));
        assertTrue(count(PharmacyInventoryService::listDamage($branchId)) >= 1, 'damage list is empty');
    });

    $customerId = 0;
    check('create customer', function () use (&$customerId, $userId, $branchId) {
        $result = CustomerService::saveCustomer([
            'name' => 'مريض اختبار',
            'phone' => '0910000001',
            'birth_date' => '1990-05-12',
            'gender' => 'female',
            'blood_type' => 'O+',
            'allergies' => 'بينسلين',
            'medical_notes' => 'ضغط دم مرتفع',
            'credit_limit' => 1000,
            'is_active' => 1,
        ], $userId);
        $customerId = (int)$result['customer_id'];
        assertGreaterThan(0, $customerId);
        $patient = CustomerService::getCustomer($customerId);
        assertSame('O+', (string)$patient['blood_type']);
        assertSame('بينسلين', (string)$patient['allergies']);
        assertSame('ضغط دم مرتفع', (string)$patient['medical_notes']);

        $file = PatientRecordService::patientFile($customerId, $branchId);
        assertSame($customerId, (int)($file['id'] ?? 0));
        assertTrue(is_array($file['prescriptions'] ?? null), 'patient prescriptions missing');
        assertTrue(is_array($file['medication_history'] ?? null), 'medication history missing');
        $history = PatientRecordService::medicationHistory($customerId, $branchId);
        assertTrue(is_array($history), 'medicationHistory did not return an array');
        $found = PatientRecordService::searchPatients('اختبار', 20);
        assertTrue(count($found) >= 1, 'patient search returned no results');
    });

    $supplierId = 0;
    check('create supplier', function () use (&$supplierId, $userId) {
        $result = SupplierService::saveSupplier([
            'name' => 'Test Supplier',
            'phone' => '0910000002',
            'is_active' => 1,
        ], $userId);
        $supplierId = (int)$result['supplier_id'];
        assertGreaterThan(0, $supplierId);
    });

    $saleId = 0;
    $clientUuid = 'test-' . bin2hex(random_bytes(8));
    $salePayload = [
        'items' => [
            ['product_id' => $productId, 'variant_id' => null, 'quantity' => 2, 'unit_price' => 15, 'discount_amount' => 0],
        ],
        'customer_id' => $customerId,
        'invoice_discount' => 0,
        'payment_method' => 'cash',
        'currency_code' => 'LYD',
        'paid_amount' => 30,
        'payments' => [
            ['payment_method' => 'cash', 'currency_code' => 'LYD', 'amount_foreign' => 30, 'reference_no' => ''],
        ],
        'is_hold' => 0,
        'held_sale_id' => null,
        'client_uuid' => $clientUuid,
        'notes' => 'integration test',
    ];

    check('POS sale is blocked without an open shift', function () use ($salePayload, $userId, $branchId) {
        assertThrowsMessage(function () use ($salePayload, $userId, $branchId) {
            PosService::processSale($salePayload, $userId, $branchId);
        }, 'بدون فتح وردية');
    });

    $sessionId = 0;
    check('open cash register session', function () use (&$sessionId, $userId, $registerId, $branchId) {
        $result = CashRegisterService::openSession(200.0, $userId, $registerId, $branchId);
        $sessionId = (int)$result['session_id'];
        assertGreaterThan(0, $sessionId);
    });

    check('POS cash sale', function () use (&$saleId, $salePayload, $userId, $branchId) {
        $result = PosService::processSale($salePayload, $userId, $branchId);
        $saleId = (int)$result['sale_id'];
        assertGreaterThan(0, $saleId);
        $daily = ReportService::getReport('sales_summary', [
            'start_date' => '2000-01-01', 'end_date' => '2100-12-31',
        ], $branchId);
        assertTrue((float)$daily['summary']['cash_amount'] >= 30.0, 'cash column must include cash payments');
    });

    check('POS sale idempotency by client_uuid', function () use ($salePayload, $userId, $branchId, $saleId) {
        $again = PosService::processSale($salePayload, $userId, $branchId);
        assertSame($saleId, (int)$again['sale_id'], 'duplicate sale was created');
    });

    check('sale return with cash refund', function () use ($pdo, $saleId, $userId, $branchId) {
        $saleItemId = (int)$pdo->query('SELECT id FROM sale_items WHERE sale_id = ' . $saleId . ' ORDER BY id ASC LIMIT 1')->fetchColumn();
        $result = ReturnService::processSaleReturn([
            'sale_id' => $saleId,
            'reason' => 'integration test return',
            'return_type' => 'refund',
            'refund_method' => 'cash',
            'notes' => '',
            'items' => [
                ['sale_item_id' => $saleItemId, 'quantity' => 1, 'item_condition' => 'good'],
            ],
        ], $userId, $branchId);
        assertTrue(isset($result['return_id']) || isset($result['id']), 'return id missing');
    });

    check('held sale and delete', function () use ($productId, $userId, $branchId) {
        $held = PosService::processSale([
            'items' => [
                ['product_id' => $productId, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 15, 'discount_amount' => 0],
            ],
            'customer_id' => null,
            'invoice_discount' => 0,
            'payment_method' => 'cash',
            'currency_code' => 'LYD',
            'paid_amount' => 0,
            'payments' => [],
            'is_hold' => 1,
            'held_sale_id' => null,
            'client_uuid' => 'held-' . bin2hex(random_bytes(6)),
            'notes' => 'held test',
        ], $userId, $branchId);
        $heldId = (int)$held['sale_id'];
        assertGreaterThan(0, $heldId);
        PosService::deleteHeldSale($heldId, $branchId, $userId);
    });

    $purchaseId = 0;
    check('purchase create, read and cancel', function () use (&$purchaseId, $supplierId, $productId, $userId, $branchId) {
        $result = PurchaseService::createPurchase([
            'supplier_id' => $supplierId,
            'supplier_invoice_no' => 'SUP-TEST-' . bin2hex(random_bytes(3)),
            'purchase_date' => date('Y-m-d'),
            'payment_method' => 'cash',
            'paid_amount' => 50,
            'currency_code' => 'LYD',
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'extra_cost' => 0,
            'cost_allocation_method' => 'quantity',
            'notes' => 'integration test',
            'items' => [
                ['product_id' => $productId, 'variant_id' => null, 'quantity' => 5, 'unit_cost' => 10, 'discount_amount' => 0],
            ],
        ], $userId, $branchId);
        $purchaseId = (int)$result['purchase_id'];
        assertGreaterThan(0, $purchaseId);
        $details = PurchaseService::getPurchaseDetails($purchaseId, $branchId);
        assertTrue(!empty($details['supplier_name']), 'supplier_name missing from purchase details');

        $history = PriceHistoryService::history($productId, $branchId, 10);
        assertTrue(count($history) >= 1, 'purchase price history is empty');
        $supplierPrices = PriceHistoryService::compareSuppliers($productId, $branchId, 365);
        assertTrue(count($supplierPrices) >= 1, 'supplier price comparison is empty');

        $order = PurchaseOrderService::createOrder([
            'supplier_id' => $supplierId,
            'order_date' => date('Y-m-d H:i:s'),
            'expected_date' => date('Y-m-d H:i:s', strtotime('+3 days')),
            'notes' => 'integration test order',
            'items' => [['product_id' => $productId, 'quantity' => 2, 'unit_cost' => 10]],
        ], $userId, $branchId);
        $orderId = (int)($order['id'] ?? 0);
        assertGreaterThan(0, $orderId);
        $loadedOrder = PurchaseOrderService::getOrder($orderId, $branchId);
        assertSame($orderId, (int)($loadedOrder['id'] ?? 0));
        PurchaseOrderService::updateOrderStatus($orderId, 'sent', $userId, $branchId);
        $orderItemId = (int)($loadedOrder['items'][0]['id'] ?? 0);
        assertGreaterThan(0, $orderItemId);
        $received = PurchaseOrderService::receiveOrder($orderId, [
            ['purchase_order_item_id' => $orderItemId, 'received_quantity' => 2],
        ], $userId, $branchId);
        assertSame('received', (string)($received['status'] ?? ''));

        PurchaseService::cancelPurchase($purchaseId, 'integration test cancel', $userId, $branchId);
    });

    check('purchase and supplier payment accept transfer, cheque and legacy bank_transfer', function () use ($supplierId, $productId, $userId, $branchId) {
        $basePayload = [
            'supplier_id' => $supplierId,
            'supplier_invoice_no' => 'SUP-METHOD-' . bin2hex(random_bytes(3)),
            'purchase_date' => date('Y-m-d'),
            'paid_amount' => 5,
            'currency_code' => 'LYD',
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'extra_cost' => 0,
            'cost_allocation_method' => 'quantity',
            'notes' => 'payment method integration test',
            'items' => [
                ['product_id' => $productId, 'variant_id' => null, 'quantity' => 2, 'unit_cost' => 10, 'discount_amount' => 0],
            ],
        ];

        $transferPurchase = PurchaseService::createPurchase(
            ['payment_method' => 'transfer'] + $basePayload,
            $userId,
            $branchId
        );
        assertSame('transfer', $transferPurchase['payment_method']);

        $chequePayment = PurchaseService::paySupplier([
            'supplier_id' => $supplierId,
            'purchase_id' => $transferPurchase['purchase_id'],
            'amount' => 5,
            'currency_code' => 'LYD',
            'payment_method' => 'cheque',
            'notes' => 'cheque integration test',
        ], $userId, $branchId);
        assertSame('cheque', $chequePayment['payment_method']);

        $legacyPurchase = PurchaseService::createPurchase(
            ['payment_method' => 'bank_transfer'] + $basePayload,
            $userId,
            $branchId
        );
        assertSame('transfer', $legacyPurchase['payment_method']);

        $legacyPayment = PurchaseService::paySupplier([
            'supplier_id' => $supplierId,
            'purchase_id' => $legacyPurchase['purchase_id'],
            'amount' => 2,
            'currency_code' => 'LYD',
            'payment_method' => 'bank_transfer',
            'notes' => 'legacy method integration test',
        ], $userId, $branchId);
        assertSame('transfer', $legacyPayment['payment_method']);

        PurchaseService::cancelPurchase((int)$transferPurchase['purchase_id'], 'cleanup transfer purchase', $userId, $branchId);
        PurchaseService::cancelPurchase((int)$legacyPurchase['purchase_id'], 'cleanup legacy purchase', $userId, $branchId);
    });

    check('expense create and archive', function () use ($expenseCategoryId, $userId, $branchId) {
        $result = ExpenseService::createExpense([
            'category_id' => $expenseCategoryId,
            'amount' => 12,
            'currency_code' => 'LYD',
            'payment_method' => 'cash',
            'expense_date' => date('Y-m-d'),
            'description' => 'integration test expense',
            'notes' => '',
        ], $userId, $branchId);
        $expenseId = (int)$result['expense_id'];
        assertGreaterThan(0, $expenseId);
        ExpenseService::archiveExpense($expenseId, 'integration test archive', $userId, $branchId);
    });

    check('batch save, expiry alerts and FEFO allocation', function () use ($pdo, $userId, $branchId) {
        $sku = 'BAT-' . strtoupper(bin2hex(random_bytes(4)));
        $pid = ProductService::create([
            'name' => 'Batch Medicine ' . $sku,
            'sku' => $sku,
            'purchase_cost' => 10,
            'selling_price' => 20,
            'active_ingredient' => 'Amoxicillin',
            'strength' => '500 mg',
            'dosage_form' => 'كبسولات',
            'initial_quantity' => 0,
        ], $userId, $branchId);
        assertGreaterThan(0, $pid);

        $nearExpiry = date('Y-m-d', strtotime('+5 days'));
        $farExpiry = date('Y-m-d', strtotime('+90 days'));
        $nearBatch = BatchService::save([
            'product_id' => $pid,
            'batch_number' => 'NEAR-' . $sku,
            'expiry_date' => $nearExpiry,
            'quantity' => 2,
            'purchase_cost' => 8,
        ], $userId, $branchId);
        $farBatch = BatchService::save([
            'product_id' => $pid,
            'batch_number' => 'FAR-' . $sku,
            'expiry_date' => $farExpiry,
            'quantity' => 3,
            'purchase_cost' => 10,
        ], $userId, $branchId);
        assertGreaterThan(0, (int)$nearBatch['batch_id']);
        assertGreaterThan(0, (int)$farBatch['batch_id']);

        $rows = BatchService::list(['branch_id' => $branchId, 'product_id' => $pid]);
        assertSame(2, count($rows));
        assertSame('NEAR-' . $sku, (string)$rows[0]['batch_number']);
        assertTrue((int)$rows[0]['days_left'] <= BatchService::alertDays(), 'near-expiry batch was not flagged');

        $alerts = BatchService::alerts($branchId, 30);
        $alertFound = false;
        foreach ($alerts as $alert) {
            if ((int)$alert['product_id'] === $pid && (string)$alert['batch_number'] === 'NEAR-' . $sku) {
                $alertFound = true;
            }
        }
        assertTrue($alertFound, 'near-expiry batch missing from expiry alerts');

        $allocated = BatchService::allocate($pdo, $pid, $branchId, 3.0);
        assertSame(2, count($allocated));
        assertSame('NEAR-' . $sku, (string)$allocated[0]['batch_number']);
        assertSame(2.0, (float)$allocated[0]['quantity']);
        assertSame('FAR-' . $sku, (string)$allocated[1]['batch_number']);
        assertSame(1.0, (float)$allocated[1]['quantity']);
        BatchService::consume($pdo, $allocated);

        $oldBatchNumber = 'OLD-' . $sku;
        BatchService::save([
            'product_id' => $pid,
            'batch_number' => $oldBatchNumber,
            'expiry_date' => date('Y-m-d', strtotime('-2 days')),
            'quantity' => 1,
            'purchase_cost' => 8,
        ], $userId, $branchId);
        $originalBlock = SettingsService::getValue('block_expired_sales', '1');
        try {
            SettingsService::updateSettings(['block_expired_sales' => '1'], $userId);
            assertThrowsMessage(function () use ($pdo, $pid, $branchId) {
                BatchService::allocate($pdo, $pid, $branchId, 3.0);
            }, 'غير كافية');
            SettingsService::updateSettings(['block_expired_sales' => '0'], $userId);
            $withExpired = BatchService::allocate($pdo, $pid, $branchId, 3.0);
            assertSame($oldBatchNumber, (string)$withExpired[0]['batch_number'], 'expired batch was not first in FEFO order');
        } finally {
            SettingsService::updateSettings(['block_expired_sales' => $originalBlock], $userId);
        }
    });

    check('all report types render', function () use ($branchId) {
        $types = ReportService::listReportTypes();
        assertGreaterThan(15, count($types));
        foreach ([
            'expired_products', 'near_expiry', 'batches_by_product', 'sales_by_ingredient',
            'dispensed_by_prescription', 'stocktake_damage', 'storage_conditions',
            'supplier_price_comparison', 'demand_forecast', 'slow_moving', 'best_selling',
        ] as $pharmaType) {
            assertTrue(isset($types[$pharmaType]), 'pharmacy report type missing: ' . $pharmaType);
        }
        foreach (array_keys($types) as $type) {
            $report = ReportService::getReport($type, [
                'start_date' => '2000-01-01',
                'end_date' => '2100-12-31',
            ], $branchId);
            assertTrue(isset($report['columns']) && is_array($report['columns']), 'report ' . $type . ' has no columns');
        }
        $daily = ReportService::getReport('sales_summary', [
            'start_date' => '2000-01-01', 'end_date' => '2100-12-31',
        ], $branchId);
        $keys = array_column($daily['columns'], 'key');
        foreach (['cash_amount', 'card_amount', 'transfer_amount'] as $key) {
            assertTrue(in_array($key, $keys, true), 'daily sales report is missing column: ' . $key);
            assertTrue(array_key_exists($key, $daily['summary']), 'daily sales report is missing total: ' . $key);
        }
    });

    $runSuffix = strtoupper(bin2hex(random_bytes(3)));
    $editProductId = 0;
    $editSku = 'EDIT-' . $runSuffix;
    check('product update keeps its own sku and barcode', function () use (&$editProductId, $editSku, $userId, $branchId, $categoryId) {
        $editProductId = ProductService::create([
            'name' => 'Editable ' . $editSku,
            'sku' => $editSku,
            'barcode' => 'EBC-' . $editSku,
            'category_id' => $categoryId,
            'purchase_cost' => 4,
            'selling_price' => 8,
            'initial_quantity' => 5,
        ], $userId, $branchId);
        $result = ProductService::update($editProductId, [
            'name' => 'Editable Updated ' . $editSku,
            'sku' => $editSku,
            'barcode' => 'EBC-' . $editSku,
        ], $userId, $branchId);
        assertSame($editProductId, (int)$result['product_id']);
    });

    check('product stock editable from update', function () use ($editProductId, $pdo, $userId, $branchId) {
        ProductService::update($editProductId, ['stock_quantity' => 12], $userId, $branchId);
        $qty = (int)$pdo->query('SELECT quantity FROM inventory WHERE product_id = ' . $editProductId . ' AND variant_id IS NULL')->fetchColumn();
        assertSame(12, $qty);
        $adjustments = (int)$pdo->query('SELECT COUNT(*) FROM stock_adjustments WHERE product_id = ' . $editProductId)->fetchColumn();
        assertGreaterThan(0, $adjustments);
    });

    $variantProductId = 0;
    $variantSku = 'VAR-' . $runSuffix;
    check('adding a variant syncs product flags and stock', function () use (&$variantProductId, $variantSku, $pdo, $userId, $branchId, $categoryId) {
        $variantProductId = ProductService::create([
            'name' => 'Variant Product ' . $variantSku,
            'sku' => 'VP-' . $variantSku,
            'category_id' => $categoryId,
            'purchase_cost' => 5,
            'selling_price' => 9,
            'initial_quantity' => 0,
        ], $userId, $branchId);
        ProductService::update($variantProductId, [
            'variants' => [[
                'variant_name' => 'Red',
                'sku' => $variantSku,
                'barcode' => 'VBC-' . $variantSku,
                'purchase_cost' => 5,
                'selling_price' => 9,
                'stock_quantity' => 9,
            ]],
        ], $userId, $branchId);
        $row = $pdo->query('SELECT has_variants, product_type FROM products WHERE id = ' . $variantProductId)->fetch();
        assertSame(1, (int)$row['has_variants']);
        assertSame('variant_parent', $row['product_type']);
        $variantId = (int)$pdo->query('SELECT id FROM product_variants WHERE product_id = ' . $variantProductId)->fetchColumn();
        $qty = (int)$pdo->query('SELECT quantity FROM inventory WHERE product_id = ' . $variantProductId . ' AND variant_id = ' . $variantId)->fetchColumn();
        assertSame(9, $qty);
    });

    check('inventory adjustment rejects a variant from another product', function () use ($variantProductId, $editProductId, $pdo, $userId, $branchId) {
        $foreignVariant = (int)$pdo->query('SELECT id FROM product_variants WHERE product_id = ' . $variantProductId)->fetchColumn();
        assertThrowsMessage(function () use ($editProductId, $foreignVariant, $userId, $branchId) {
            InventoryService::recordAdjustment([
                'product_id' => $editProductId,
                'variant_id' => $foreignVariant,
                'adjustment_type' => 'stocktake',
                'actual_quantity' => 3,
                'reason' => 'mismatched variant test',
            ], $userId, $branchId);
        }, 'لا ينتمي لهذا المنتج');
    });

    check('product delete is recoverable from the archive', function () use ($runSuffix, $userId, $branchId) {
        $sku = 'ARC-' . bin2hex(random_bytes(3));
        $id = ProductService::create([
            'name' => 'Archive Test ' . $runSuffix,
            'sku' => $sku,
            'purchase_cost' => 1,
            'selling_price' => 2,
            'initial_quantity' => 3,
        ], $userId, $branchId);

        $mode = ProductService::archiveOrDelete($id, $userId);
        assertSame('soft_deleted', $mode);

        $active = ProductService::search(['q' => $sku, 'branch_id' => $branchId]);
        assertSame(0, count($active));

        $archived = ProductService::search(['q' => $sku, 'archived' => 1, 'branch_id' => $branchId]);
        assertSame(1, count($archived));
        assertSame($id, (int)$archived[0]['product_id']);

        ProductService::restore($id, $userId);
        $activeAfter = ProductService::search(['q' => $sku, 'branch_id' => $branchId]);
        assertSame(1, count($activeAfter));
    });

    check('duplicate category name is rejected', function () use ($runSuffix, $userId) {
        $name = 'Dup Category ' . $runSuffix;
        SettingsService::saveCategory(['name' => $name, 'is_active' => 1], $userId);
        assertThrowsMessage(function () use ($name, $userId) {
            SettingsService::saveCategory(['name' => $name, 'is_active' => 1], $userId);
        }, 'اسم التصنيف مستخدم مسبقًا');
    });

    check('duplicate brand name is rejected on update', function () use ($runSuffix, $userId) {
        $first = SettingsService::saveBrand(['name' => 'Brand A ' . $runSuffix, 'is_active' => 1], $userId);
        SettingsService::saveBrand(['name' => 'Brand B ' . $runSuffix, 'is_active' => 1], $userId);
        assertThrowsMessage(function () use ($first, $runSuffix, $userId) {
            SettingsService::saveBrand(['id' => $first['brand_id'], 'name' => 'Brand B ' . $runSuffix, 'is_active' => 1], $userId);
        }, 'اسم الماركة مستخدم مسبقًا');
    });

    check('duplicate branch code is rejected', function () use ($runSuffix, $userId) {
        $code = 'BR' . substr($runSuffix, 0, 4);
        SettingsService::saveBranch(['name' => 'Branch A ' . $runSuffix, 'code' => $code, 'is_active' => 1], $userId);
        assertThrowsMessage(function () use ($code, $runSuffix, $userId) {
            SettingsService::saveBranch(['name' => 'Branch B ' . $runSuffix, 'code' => $code, 'is_active' => 1], $userId);
        }, 'رمز الفرع مستخدم مسبقًا');
    });

    check('duplicate cash register code is rejected', function () use ($runSuffix, $userId, $branchId) {
        $code = 'RG' . substr($runSuffix, 0, 4);
        SettingsService::saveCashRegister(['name' => 'Register A ' . $runSuffix, 'code' => $code, 'is_active' => 1], $userId, $branchId);
        assertThrowsMessage(function () use ($code, $runSuffix, $userId, $branchId) {
            SettingsService::saveCashRegister(['name' => 'Register B ' . $runSuffix, 'code' => $code, 'is_active' => 1], $userId, $branchId);
        }, 'رمز الصندوق مستخدم مسبقًا');
    });

    check('manual clock adjustment is stored and reset', function () use ($pdo, $userId) {
        $target = date('Y-m-d\TH:i', time() + 3600);
        $result = SettingsService::adjustClock(['local_datetime' => $target], $userId);
        $stored = (int)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'clock_offset_seconds'")->fetchColumn();
        assertSame((int)$result['offset_seconds'], $stored);
        SettingsService::adjustClock(['reset' => 1], $userId);
        $reset = (int)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'clock_offset_seconds'")->fetchColumn();
        assertSame(0, $reset);
    });

    check('cash register close', function () use (&$sessionId, $userId, $branchId) {
        $summary = CashRegisterService::getSessionSummary($sessionId, $branchId);
        CashRegisterService::closeSession($sessionId, (float)$summary['expected_cash'], null, $userId, $branchId);
    });

    check('POS sale is blocked again after the shift closes', function () use ($productId, $userId, $branchId) {
        assertThrowsMessage(function () use ($productId, $userId, $branchId) {
            PosService::processSale([
                'items' => [
                    ['product_id' => $productId, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 15, 'discount_amount' => 0],
                ],
                'customer_id' => null,
                'invoice_discount' => 0,
                'payment_method' => 'cash',
                'currency_code' => 'LYD',
                'paid_amount' => 15,
                'payments' => [
                    ['payment_method' => 'cash', 'currency_code' => 'LYD', 'amount_foreign' => 15, 'reference_no' => ''],
                ],
                'is_hold' => 0,
                'held_sale_id' => null,
                'client_uuid' => 'after-close-' . bin2hex(random_bytes(6)),
            ], $userId, $branchId);
        }, 'بدون فتح وردية');
    });

    check('POS sale is allowed without a shift when the requirement is disabled', function () use ($productId, $userId, $branchId) {
        SettingsService::updateSettings(['require_open_register' => '0'], $userId);
        try {
            $result = PosService::processSale([
                'items' => [
                    ['product_id' => $productId, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 15, 'discount_amount' => 0],
                ],
                'customer_id' => null,
                'invoice_discount' => 0,
                'payment_method' => 'cash',
                'currency_code' => 'LYD',
                'paid_amount' => 15,
                'payments' => [
                    ['payment_method' => 'cash', 'currency_code' => 'LYD', 'amount_foreign' => 15, 'reference_no' => ''],
                ],
                'is_hold' => 0,
                'held_sale_id' => null,
                'client_uuid' => 'no-shift-' . bin2hex(random_bytes(6)),
            ], $userId, $branchId);
            assertGreaterThan(0, (int)$result['sale_id']);
        } finally {
            SettingsService::updateSettings(['require_open_register' => '1'], $userId);
        }
    });

    check('bulk product delete archives products with previous sales', function () use ($productId, $runSuffix, $userId, $branchId) {
        $cleanSku = 'BULK-' . bin2hex(random_bytes(3));
        $cleanId = ProductService::create([
            'name' => 'Bulk Clean ' . $runSuffix,
            'sku' => $cleanSku,
            'purchase_cost' => 1,
            'selling_price' => 2,
            'initial_quantity' => 1,
        ], $userId, $branchId);

        $result = ProductService::bulkDelete([$productId, $cleanId], $userId);
        assertSame(1, (int)$result['archived']);
        assertSame(1, (int)$result['soft_deleted']);
        assertSame(0, count(ProductService::search(['q' => $cleanSku, 'branch_id' => $branchId])));
    });

    check('archived products can be permanently purged', function () use ($pdo, $runSuffix, $userId, $branchId) {
        $sku = 'PURGE-' . bin2hex(random_bytes(3));
        $id = ProductService::create([
            'name' => 'Purge Me ' . $runSuffix,
            'sku' => $sku,
            'purchase_cost' => 1,
            'selling_price' => 2,
            'initial_quantity' => 1,
        ], $userId, $branchId);

        $deleted = ProductService::bulkDelete([$id], $userId);
        assertSame(1, (int)$deleted['soft_deleted']);

        $purged = ProductService::purgeArchived([$id], $userId);
        assertSame(1, (int)$purged['deleted']);
        assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM products WHERE id = ' . (int)$id)->fetchColumn());
        assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM inventory WHERE product_id = ' . (int)$id)->fetchColumn());
    });

    check('settings reference deletion guards real references', function () use ($pdo, $userId, $runSuffix, $categoryId) {
        // A category already used by products must never be deleted.
        assertThrowsMessage(function () use ($categoryId, $userId) {
            SettingsService::deleteReference('category', $categoryId, $userId);
        }, 'لا يمكن حذف التصنيف');

        // A fresh, unused category can be permanently deleted.
        $created = SettingsService::saveCategory([
            'name' => 'Del Cat ' . $runSuffix,
            'is_active' => 1,
        ], $userId);
        $newId = (int)$created['category_id'];
        $result = SettingsService::deleteReference('category', $newId, $userId);
        assertTrue($result['deleted'], 'unused category was not deleted');
        assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM categories WHERE id = ' . $newId)->fetchColumn());
    });

    check('owner cleanup summary and confirmation guard', function () use ($userId, $branchId) {
        $summary = OwnerCleanupService::summary($branchId, []);
        assertGreaterThan(0, (int)$summary['groups']['sales']);
        assertSame(false, $summary['audit_logs_protected']);
        assertTrue(isset($summary['labels']['audit_logs']), 'audit log cleanup group is missing');
        assertTrue(isset($summary['labels']['shift_logs']), 'shift log cleanup group is missing');
        assertThrowsMessage(function () use ($userId, $branchId) {
            OwnerCleanupService::purge(['groups' => ['sales'], 'confirmation' => 'wrong phrase'], $userId, $branchId);
        }, 'عبارة التأكيد غير صحيحة');
    });

    check('owner purge removes selected invoices, returns and resets debts', function () use ($pdo, $saleId, $customerId, $userId, $branchId) {
        assertGreaterThan(0, (int)$pdo->query('SELECT COUNT(*) FROM sale_returns')->fetchColumn());

        $result = OwnerCleanupService::purge([
            'groups' => [],
            'sale_ids' => [$saleId],
            'confirmation' => OwnerCleanupService::CONFIRMATION_PHRASE,
        ], $userId, $branchId);

        assertSame(1, (int)$result['deleted']['sales']);
        assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM sales WHERE id = ' . (int)$saleId)->fetchColumn());
        assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM sale_returns')->fetchColumn());
        assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM sale_items WHERE sale_id = ' . (int)$saleId)->fetchColumn());

        $debtStmt = $pdo->prepare('SELECT current_debt FROM customers WHERE id = :id');
        $debtStmt->execute([':id' => $customerId]);
        assertSame(0.0, (float)$debtStmt->fetchColumn());

        $audit = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type = 'OWNER_PURGE'")->fetchColumn();
        assertGreaterThan(0, $audit);
    });

    check('owner can upload and reset the store logo', function () use ($ownerId, $pdo) {
        $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'levi_logo_' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($tmp, base64_decode($pngBase64));
        try {
            $result = SettingsService::saveLogo([
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
                'name' => 'logo.png',
            ], $ownerId);

            assertTrue(strpos($result['logo_url'], 'assets/uploads/') === 0, 'logo path was not stored');
            $file = dirname(__DIR__) . '/public/' . $result['logo_url'];
            assertTrue(is_file($file), 'uploaded logo file is missing');
            $stored = (string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'store_logo'")->fetchColumn();
            assertSame($result['logo_url'], $stored);

            SettingsService::resetLogo($ownerId);
            assertTrue(!is_file($file), 'custom logo file was not removed on reset');
            $storedAfter = (string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'store_logo'")->fetchColumn();
            assertTrue(strpos($storedAfter, 'assets/uploads/') !== 0, 'reset did not restore the default logo');
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    });

    check('automatic backup can be disabled', function () use ($userId) {
        SettingsService::updateSettings(['auto_backup_enabled' => '0'], $userId);
        try {
            $before = (int)Database::getConnection()->query("SELECT COUNT(*) FROM backups WHERE backup_type = 'auto'")->fetchColumn();
            BackupService::maybeRunAutomaticBackup($userId);
            $after = (int)Database::getConnection()->query("SELECT COUNT(*) FROM backups WHERE backup_type = 'auto'")->fetchColumn();
            assertSame($before, $after, 'automatic backup ran while disabled');
        } finally {
            SettingsService::updateSettings(['auto_backup_enabled' => '1'], $userId);
        }
    });

    check('automatic backup retention respects the saved setting', function () use ($userId) {
        $original = SettingsService::getValue('backup_retention_count', '10');
        SettingsService::updateSettings(['backup_retention_count' => '1'], $userId);
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'levi_backup_' . bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0775, true);
        $created = [];
        try {
            $created[] = BackupService::createBackup($userId, 'manual', 'local', $tmpDir, 'retention test 1');
            $created[] = BackupService::createBackup($userId, 'manual', 'local', $tmpDir, 'retention test 2');
            $count = (int)Database::getConnection()->query("SELECT COUNT(*) FROM backups WHERE backup_type = 'manual'")->fetchColumn();
            assertSame(1, $count, 'retention setting did not trim old backups');
        } finally {
            SettingsService::updateSettings(['backup_retention_count' => (string)$original], $userId);
            foreach ($created as $backup) {
                try {
                    BackupService::deleteBackup((int)$backup['backup_id'], $userId);
                } catch (Throwable $e) {
                    // Already removed by retention.
                }
            }
            foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    });

    check('default minimum stock applies to new products', function () use ($userId, $branchId) {
        $original = SettingsService::getValue('default_min_stock', '5');
        SettingsService::updateSettings(['default_min_stock' => '7'], $userId);
        try {
            $sku = 'MIN-' . bin2hex(random_bytes(4));
            $pid = ProductService::create([
                'name' => 'Min Stock ' . $sku,
                'sku' => $sku,
                'purchase_cost' => 1,
                'selling_price' => 2,
                'initial_quantity' => 0,
            ], $userId, $branchId);
            $minStock = (int)Database::getConnection()->query('SELECT min_stock_level FROM products WHERE id = ' . $pid)->fetchColumn();
            assertSame(7, $minStock, 'default_min_stock was not applied');
        } finally {
            SettingsService::updateSettings(['default_min_stock' => (string)$original], $userId);
        }
    });

    check('stock adjustments can be disabled by settings', function () use ($userId, $branchId) {
        $sku = 'ADJ-' . bin2hex(random_bytes(4));
        $pid = ProductService::create([
            'name' => 'Adjustment Product ' . $sku,
            'sku' => $sku,
            'purchase_cost' => 1,
            'selling_price' => 2,
            'initial_quantity' => 5,
        ], $userId, $branchId);
        SettingsService::updateSettings(['allow_stock_adjustments' => '0'], $userId);
        try {
            assertThrowsMessage(function () use ($pid, $userId, $branchId) {
                InventoryService::recordAdjustment([
                    'product_id' => $pid,
                    'adjustment_type' => 'adjustment',
                    'quantity_change' => 1,
                    'reason' => 'disabled setting test',
                ], $userId, $branchId);
            }, 'تسويات المخزون معطلة');
        } finally {
            SettingsService::updateSettings(['allow_stock_adjustments' => '1'], $userId);
        }
        $result = InventoryService::recordAdjustment([
            'product_id' => $pid,
            'adjustment_type' => 'adjustment',
            'quantity_change' => 1,
            'reason' => 'enabled setting test',
        ], $userId, $branchId);
        assertTrue(isset($result['adjustment_id']), 'adjustment failed after re-enabling the setting');
    });

    check('expired sale is blocked and prescriptions link to dispensed sales', function () use ($registerId, $userId, $branchId, $customerId) {
        $priorSessionUser = $_SESSION['user'] ?? null;
        $_SESSION['user'] = [
            'id' => $userId,
            'branch_id' => $branchId,
            'role_id' => 0,
            'role_name' => 'pharmacist',
            'username' => 'pharmacist-test',
            'full_name' => 'Pharmacist Test',
            'permissions' => ['prescriptions.dispense', 'pos.use', 'products.view', 'inventory.view'],
        ];
        $rxSku = 'RX-' . strtoupper(bin2hex(random_bytes(4)));
        $rxPid = ProductService::create([
            'name' => 'Prescription Medicine ' . $rxSku,
            'sku' => $rxSku,
            'purchase_cost' => 10,
            'selling_price' => 15,
            'initial_quantity' => 5,
            'requires_prescription' => 1,
        ], $userId, $branchId);
        assertGreaterThan(0, $rxPid);

        $doctor = DoctorService::save([
            'name' => 'د. اختبار ' . strtoupper(bin2hex(random_bytes(3))),
            'specialty' => 'طب عام',
            'phone' => '0910000009',
            'clinic' => 'عيادة الاختبار',
            'is_active' => 1,
        ], $userId);
        $doctorId = (int)$doctor['doctor_id'];
        assertGreaterThan(0, $doctorId);
        $doctors = DoctorService::list(['q' => 'اختبار']);
        assertTrue(count($doctors) >= 1, 'doctor registry search failed');

        $prescription = PrescriptionService::save([
            'patient_id' => $customerId,
            'doctor_id' => $doctorId,
            'prescription_date' => date('Y-m-d'),
            'notes' => 'روشتة اختبار',
            'status' => 'issued',
            'items' => [
                ['product_id' => $rxPid, 'quantity' => 1, 'dosage_instructions' => 'قرص واحد ثلاث مرات يوميًا'],
            ],
        ], $userId);
        $prescriptionId = (int)$prescription['prescription_id'];
        assertGreaterThan(0, $prescriptionId);
        $loaded = PrescriptionService::get($prescriptionId);
        assertSame('issued', (string)$loaded['status']);
        assertSame(1, count($loaded['items']));

        $sku = 'EXPBLK-' . strtoupper(bin2hex(random_bytes(3)));
        $expiredPid = ProductService::create([
            'name' => 'Expired Medicine ' . $sku,
            'sku' => $sku,
            'purchase_cost' => 10,
            'selling_price' => 15,
            'initial_quantity' => 2,
        ], $userId, $branchId);
        $expiredBatch = BatchService::save([
            'product_id' => $expiredPid,
            'batch_number' => 'OLD-' . $sku,
            'expiry_date' => date('Y-m-d', strtotime('-1 day')),
            'quantity' => 2,
            'purchase_cost' => 10,
        ], $userId, $branchId);
        assertGreaterThan(0, (int)$expiredBatch['batch_id']);

        $originalBlock = SettingsService::getValue('block_expired_sales', '1');
        $originalDrawer = SettingsService::getValue('open_cash_drawer_on_cash', '1');
        SettingsService::updateSettings(['block_expired_sales' => '1', 'open_cash_drawer_on_cash' => '1'], $userId);
        $session = CashRegisterService::openSession(0.0, $userId, $registerId, $branchId);
        $sessionId = (int)$session['session_id'];

        $payload = function (int $pid, string $uuid, ?int $rxId = null) {
            return [
                'items' => [
                    ['product_id' => $pid, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 15, 'discount_amount' => 0],
                ],
                'customer_id' => null,
                'invoice_discount' => 0,
                'payment_method' => 'cash',
                'currency_code' => 'LYD',
                'paid_amount' => 15,
                'payments' => [
                    ['payment_method' => 'cash', 'currency_code' => 'LYD', 'amount_foreign' => 15, 'reference_no' => ''],
                ],
                'is_hold' => 0,
                'held_sale_id' => null,
                'prescription_id' => $rxId,
                'client_uuid' => $uuid,
            ];
        };

        try {
            assertThrowsMessage(function () use ($payload, $expiredPid, $userId, $branchId) {
                PosService::processSale($payload($expiredPid, 'exp-' . bin2hex(random_bytes(6))), $userId, $branchId);
            }, 'غير كافية');

            SettingsService::updateSettings(['open_cash_drawer_on_cash' => '0'], $userId);
            $result = PosService::processSale($payload($rxPid, 'rx-' . bin2hex(random_bytes(6)), $prescriptionId), $userId, $branchId);
            assertSame(false, (bool)$result['open_cash_drawer'], 'drawer flag was not disabled for cash');

            PrescriptionService::dispense($prescriptionId, (int)$result['sale_id'], $userId);
            $dispensed = PrescriptionService::get($prescriptionId);
            assertSame('dispensed', (string)$dispensed['status']);
            assertSame((int)$result['sale_id'], (int)$dispensed['dispensed_sale_id']);
        } finally {
            SettingsService::updateSettings([
                'block_expired_sales' => $originalBlock,
                'open_cash_drawer_on_cash' => $originalDrawer,
            ], $userId);
            if ($priorSessionUser === null) {
                unset($_SESSION['user']);
            } else {
                $_SESSION['user'] = $priorSessionUser;
            }
            $summary = CashRegisterService::getSessionSummary($sessionId, $branchId);
            CashRegisterService::closeSession($sessionId, (float)$summary['expected_cash'], null, $userId, $branchId);
        }
    });

    check('login attempt limit and lockout duration come from settings', function () use ($pdo, $userId) {
        $originalAttempts = SettingsService::getValue('max_login_attempts', '5');
        $originalLockout = SettingsService::getValue('lockout_minutes', '15');
        $resetUser = function () use ($pdo, $userId) {
            $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id')
                ->execute([':id' => $userId]);
        };
        SettingsService::updateSettings(['max_login_attempts' => '2', 'lockout_minutes' => '1'], $userId);
        $resetUser();
        try {
            assertThrowsMessage(function () {
                Auth::attemptLogin('admin', 'wrong-password-1');
            }, 'بيانات الدخول غير صحيحة');
            assertThrowsMessage(function () {
                Auth::attemptLogin('admin', 'wrong-password-2');
            }, 'بيانات الدخول غير صحيحة');

            $row = $pdo->query('SELECT failed_login_attempts, locked_until FROM users WHERE id = ' . $userId)->fetch();
            assertSame(2, (int)$row['failed_login_attempts']);
            $remaining = strtotime((string)$row['locked_until']) - time();
            assertTrue($remaining >= 30 && $remaining <= 90, 'lockout duration does not match the setting');

            assertThrowsMessage(function () {
                Auth::attemptLogin('admin', 'admin123');
            }, 'مقفل');
        } finally {
            $resetUser();
            SettingsService::updateSettings([
                'max_login_attempts' => (string)$originalAttempts,
                'lockout_minutes' => (string)$originalLockout,
            ], $userId);
        }
    });

    check('each user has an isolated open shift and busy registers are blocked', function () use ($pdo, $registerId, $userId, $branchId) {
        $code = 'RG' . strtoupper(bin2hex(random_bytes(3)));
        $created = SettingsService::saveCashRegister(['name' => 'Second ' . $code, 'code' => $code, 'is_active' => 1], $userId, $branchId);
        $secondRegisterId = (int)$created['register_id'];

        SettingsService::updateSettings(['enable_cash_register_closing' => '0'], $userId);
        $first = CashRegisterService::openSession(0.0, $userId, $registerId, $branchId);
        $firstId = (int)$first['session_id'];
        assertTrue(!CashRegisterService::logoutBlockedByOpenShift($branchId, $userId), 'logout must stay allowed while the closing setting is off');

        try {
            assertThrowsMessage(function () use ($secondRegisterId, $userId, $branchId) {
                CashRegisterService::openSession(0.0, $userId, $secondRegisterId, $branchId);
            }, 'لديك وردية مفتوحة');

            $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'cashier' LIMIT 1")->fetchColumn();
            if ($roleId === 0) {
                $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'admin' LIMIT 1")->fetchColumn();
            }
            $otherUsername = 'cashier' . strtolower(bin2hex(random_bytes(4)));
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('INSERT INTO users (branch_id, role_id, username, full_name, password_hash, is_active, must_change_password, created_at, updated_at) VALUES (:b, :r, :u, :f, :h, 1, 0, :now, :now2)')
                ->execute([
                    ':b' => $branchId,
                    ':r' => $roleId,
                    ':u' => $otherUsername,
                    ':f' => 'كاشير الاختبار',
                    ':h' => password_hash('test123', PASSWORD_DEFAULT),
                    ':now' => $now,
                    ':now2' => $now,
                ]);
            $otherUserId = (int)$pdo->lastInsertId();

            assertThrowsMessage(function () use ($registerId, $otherUserId, $branchId) {
                CashRegisterService::openSession(0.0, $otherUserId, $registerId, $branchId);
            }, 'الصندوق يتم استخدامه حاليا');

            $other = CashRegisterService::openSession(0.0, $otherUserId, $secondRegisterId, $branchId);
            $otherSessionId = (int)$other['session_id'];
            assertGreaterThan(0, $otherSessionId, 'another user could not open their own shift');

            $myOpen = CashRegisterService::getOpenSession($branchId, $userId);
            $otherOpen = CashRegisterService::getOpenSession($branchId, $otherUserId);
            assertSame($firstId, (int)$myOpen['id'], 'my open shift changed');
            assertSame($otherSessionId, (int)$otherOpen['id'], 'the other user cannot see their own shift');
            assertTrue($otherSessionId !== $firstId, 'two users shared the same shift');

            assertThrowsMessage(function () use ($otherSessionId, $branchId, $userId) {
                CashRegisterService::getSessionSummary($otherSessionId, $branchId, $userId);
            }, 'غير موجودة');
        } finally {
            SettingsService::updateSettings(['enable_cash_register_closing' => '1'], $userId);
            if (isset($otherSessionId) && $otherSessionId > 0) {
                $summary = CashRegisterService::getSessionSummary($otherSessionId, $branchId);
                CashRegisterService::closeSession($otherSessionId, (float)$summary['expected_cash'], null, (int)$otherUserId, $branchId);
            }
            $summary = CashRegisterService::getSessionSummary($firstId, $branchId);
            CashRegisterService::closeSession($firstId, (float)$summary['expected_cash'], null, $userId, $branchId);
        }
    });

    check('RBAC: report types are split between view and view_financial', function () {
        assertSame('reports.view_financial', ReportService::requiredPermission('sales_summary'));
        assertSame('reports.view_financial', ReportService::requiredPermission('profit_report'));
        assertSame('reports.view', ReportService::requiredPermission('low_stock'));
        assertSame('reports.view', ReportService::requiredPermission('inventory_movements'));
        assertSame('reports.view', ReportService::requiredPermission('purchases_report'));
        assertSame('audit.view', ReportService::requiredPermission('audit_log'));

        $allTypes = ReportService::listReportTypes();
        $operational = ReportService::filterReportTypes($allTypes, false, false);
        assertTrue(!isset($operational['sales_summary']), 'financial report leaked without view_financial');
        assertTrue(!isset($operational['audit_log']), 'audit report leaked without audit.view');
        assertTrue(isset($operational['low_stock']), 'operational report missing for reports.view');
        assertTrue(isset($operational['purchases_report']), 'purchase report missing for reports.view');

        $full = ReportService::filterReportTypes($allTypes, true, true);
        assertTrue(isset($full['sales_summary']), 'financial report missing for view_financial');
        assertTrue(isset($full['audit_log']), 'audit report missing for audit.view');
    });

    check('RBAC: seeded roles grant the intended permissions', function () use ($pdo, $userId) {
        $roleHasPermission = function (string $roleName, string $permissionCode) use ($pdo): bool {
            $stmt = $pdo->prepare('
                SELECT COUNT(*)
                FROM role_permissions rp
                JOIN roles r ON r.id = rp.role_id
                JOIN permissions p ON p.id = rp.permission_id
                WHERE r.name = :role AND p.code = :code
            ');
            $stmt->execute([':role' => $roleName, ':code' => $permissionCode]);
            return (int)$stmt->fetchColumn() > 0;
        };

        assertTrue($roleHasPermission('accountant', 'audit.view'), 'accountant lost audit.view');
        assertTrue($roleHasPermission('accountant', 'cash_register.manage'), 'accountant lost cash_register.manage');
        assertTrue($roleHasPermission('inventory_manager', 'reports.view'), 'inventory manager lost reports.view');
        assertTrue(!$roleHasPermission('inventory_manager', 'reports.view_financial'), 'inventory manager must not see financial reports');
        assertTrue($roleHasPermission('inventory_manager', 'reports.export'), 'inventory manager lost reports.export');
        assertTrue($roleHasPermission('inventory_manager', 'batches.manage'), 'inventory manager lost batches.manage');
        assertTrue(!$roleHasPermission('inventory_manager', 'promotions.manage'), 'legacy promotions permission must stay removed');
        assertTrue(!$roleHasPermission('inventory_manager', 'warranties.manage'), 'legacy warranties permission must stay removed');
        assertTrue($roleHasPermission('cashier', 'pos.resume_held'), 'cashier lost pos.resume_held');

        // Individual per-user overrides must win over the role and honour
        // deny-before-allow precedence, without changing the number of checks.
        $deniedCode = 'reports.export';
        $allowedCode = 'prescriptions.dispense';
        $baseline = UserService::getUserPermissions($userId, $userId);
        assertTrue(is_array($baseline['all_permissions'] ?? null), 'permission catalogue missing');

        UserService::updateUserPermissions($userId, [
            $deniedCode => 'deny',
            $allowedCode => 'allow',
        ], $userId);
        $overridden = UserService::getUserPermissions($userId, $userId);
        assertSame('deny', (string)($overridden['overrides'][$deniedCode] ?? ''), 'deny override not stored');
        assertSame('allow', (string)($overridden['overrides'][$allowedCode] ?? ''), 'allow override not stored');
        assertTrue(!in_array($deniedCode, $overridden['effective_permissions'], true), 'deny override did not remove permission');
        assertTrue(in_array($allowedCode, $overridden['effective_permissions'], true), 'allow override did not grant permission');
        assertTrue($roleHasPermission('admin', $deniedCode), 'role permission should still exist in role_permissions');

        // Switching the same code from deny to allow must take effect, and a
        // deny always removes the code even though the admin role grants it.
        UserService::updateUserPermissions($userId, [
            $deniedCode => ['code' => $deniedCode, 'effect' => 'allow'],
        ], $userId);
        $allowWins = UserService::getUserPermissions($userId, $userId);
        assertSame('allow', (string)($allowWins['overrides'][$deniedCode] ?? ''), 'deny was not replaced by allow');
        assertTrue(in_array($deniedCode, $allowWins['effective_permissions'], true), 'allow override did not restore permission');

        UserService::updateUserPermissions($userId, [], $userId);
        $restored = UserService::getUserPermissions($userId, $userId);
        assertTrue(empty($restored['overrides']), 'overrides were not cleared after the test');
        assertTrue(in_array($deniedCode, $restored['effective_permissions'], true), 'role permission was not restored');
    });

    check('UI labels cover raw backup, movement and payment values', function () {
        $js = file_get_contents(dirname(__DIR__) . '/public/assets/js/levi-pos.js');
        assertTrue(is_string($js) && $js !== '', 'levi-pos.js could not be read');
        $needles = [
            'manual:', 'auto:', 'pre_restore:', 'local:', 'usb:', 'external_disk:',
            'purchase:', 'sale_return:', 'purchase_return:', 'adjustment:', 'stocktake:',
            'lost:', 'initial:', 'sale_cancel:', 'purchase_cancel:', 'bank_transfer:',
            'BATCH_CREATED:', 'DOCTOR_CREATED:', 'PRESCRIPTION_DISPENSED:',
        ];
        foreach ($needles as $needle) {
            assertTrue(strpos($js, $needle) !== false, 'missing UI label mapping for ' . $needle);
        }
    });

    check('inventory movement report renders Arabic movement labels', function () use ($branchId) {
        $report = ReportService::getReport('inventory_movements', [
            'start_date' => '2000-01-01',
            'end_date' => '2100-12-31',
        ], $branchId);
        assertTrue(!empty($report['rows']), 'no inventory movement rows in report');
        foreach ($report['rows'] as $row) {
            assertTrue(isset($row['movement_type_label']) && $row['movement_type_label'] !== '', 'movement label missing from report row');
        }
    });

    check('batch snapshot and serial are stored on sale items', function () use ($pdo, $registerId, $userId, $branchId) {
        $sku = 'SNB-' . strtoupper(bin2hex(random_bytes(4)));
        $pid = ProductService::create([
            'name' => 'Serial Batch Medicine ' . $sku,
            'sku' => $sku,
            'purchase_cost' => 40,
            'selling_price' => 100,
            'initial_quantity' => 3,
        ], $userId, $branchId);
        $expiry = date('Y-m-d', strtotime('+45 days'));
        $batchNumber = 'SNBATCH-' . $sku;
        BatchService::save([
            'product_id' => $pid,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiry,
            'quantity' => 3,
            'purchase_cost' => 40,
        ], $userId, $branchId);
        $session = CashRegisterService::openSession(0.0, $userId, $registerId, $branchId);
        $sessionId = (int)$session['session_id'];
        try {
            $serial = 'IMEI-' . strtoupper(bin2hex(random_bytes(5)));
            $result = PosService::processSale([
                'items' => [[
                    'product_id' => $pid,
                    'variant_id' => null,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'discount_amount' => 0,
                    'serial_or_imei' => $serial,
                ]],
                'customer_id' => null,
                'invoice_discount' => 0,
                'payment_method' => 'cash',
                'currency_code' => 'LYD',
                'paid_amount' => 100,
                'payments' => [
                    ['payment_method' => 'cash', 'currency_code' => 'LYD', 'amount_foreign' => 100, 'reference_no' => ''],
                ],
                'is_hold' => 0,
                'held_sale_id' => null,
                'client_uuid' => 'serial-' . bin2hex(random_bytes(6)),
            ], $userId, $branchId);
            $saleId = (int)$result['sale_id'];

            $item = $pdo->query('SELECT serial_or_imei, batch_number_snapshot, expiry_date_snapshot FROM sale_items WHERE sale_id = ' . $saleId . ' LIMIT 1')->fetch();
            assertSame($serial, (string)$item['serial_or_imei'], 'serial was not stored on the sale item');
            assertSame($batchNumber, (string)$item['batch_number_snapshot'], 'batch snapshot missing from sale item');
            assertSame($expiry, (string)$item['expiry_date_snapshot'], 'expiry snapshot missing from sale item');

            $remaining = (float)$pdo->query('SELECT quantity FROM product_batches WHERE batch_number = ' . $pdo->quote($batchNumber) . ' LIMIT 1')->fetchColumn();
            assertSame(2.0, $remaining, 'FEFO batch quantity was not consumed');
        } finally {
            $summary = CashRegisterService::getSessionSummary($sessionId, $branchId);
            CashRegisterService::closeSession($sessionId, (float)$summary['expected_cash'], null, $userId, $branchId);
        }
    });

    check('product image upload stores a real image and primary record', function () use ($pdo, $userId, $branchId) {
        $sku = 'IMG-' . strtoupper(bin2hex(random_bytes(4)));
        $pid = ProductService::create([
            'name' => 'Image Product ' . $sku,
            'sku' => $sku,
            'purchase_cost' => 1,
            'selling_price' => 2,
            'initial_quantity' => 1,
        ], $userId, $branchId);

        $tmp = tempnam(sys_get_temp_dir(), 'levi_img_');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        file_put_contents($tmp, $png);
        $absolute = null;
        try {
            $result = ProductService::saveImage([
                'name' => 'test.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
            ], $pid, $userId);
            assertTrue(isset($result['image_path']) && $result['image_path'] !== '', 'image path was not returned');
            $absolute = dirname(__DIR__) . '/public/' . $result['image_path'];
            assertTrue(is_file($absolute), 'uploaded image file was not written to disk');
            assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM product_images WHERE product_id = ' . $pid . ' AND is_primary = 1')->fetchColumn(), 'primary image row missing');
            $stored = $pdo->query('SELECT image_path FROM products WHERE id = ' . $pid)->fetchColumn();
            assertSame($result['image_path'], (string)$stored, 'product image_path was not updated');
        } finally {
            @unlink($tmp);
            if ($absolute !== null) {
                @unlink($absolute);
            }
            $pdo->prepare('DELETE FROM product_images WHERE product_id = :pid')->execute([':pid' => $pid]);
            $pdo->prepare('UPDATE products SET image_path = NULL WHERE id = :pid')->execute([':pid' => $pid]);
        }
    });

    check('backup download metadata points at a readable file', function () use ($userId) {
        $backup = BackupService::createBackup($userId, 'manual', 'local', null, 'download test');
        $backupId = (int)$backup['backup_id'];
        try {
            $info = BackupService::getBackupForDownload($backupId);
            assertTrue(is_file($info['path']) && is_readable($info['path']), 'backup file is missing or unreadable');
            assertGreaterThan(0, (int)$info['size'], 'backup file is empty');
        } finally {
            BackupService::deleteBackup($backupId, $userId);
        }
    });

    check('stock transfer moves inventory between branches with movements and audit', function () use ($pdo, $userId, $branchId) {
        $code = 'TB' . strtoupper(bin2hex(random_bytes(3)));
        $branch = SettingsService::saveBranch([
            'name' => 'Transfer Branch ' . $code,
            'code' => $code,
            'is_active' => 1,
        ], $userId);
        $toBranchId = (int)$branch['branch_id'];

        $sku = 'TRF-' . strtoupper(bin2hex(random_bytes(4)));
        $pid = ProductService::create([
            'name' => 'Transfer Product ' . $sku,
            'sku' => $sku,
            'purchase_cost' => 4,
            'selling_price' => 9,
            'initial_quantity' => 5,
        ], $userId, $branchId);

        $result = InventoryService::transferStock([
            'product_id' => $pid,
            'to_branch_id' => $toBranchId,
            'quantity' => 2,
            'notes' => 'integration transfer',
        ], $userId, $branchId);

        $sourceQty = (int)$pdo->query('SELECT quantity FROM inventory WHERE branch_id = ' . $branchId . ' AND product_id = ' . $pid . ' AND variant_id IS NULL')->fetchColumn();
        $destQty = (int)$pdo->query('SELECT quantity FROM inventory WHERE branch_id = ' . $toBranchId . ' AND product_id = ' . $pid . ' AND variant_id IS NULL')->fetchColumn();
        assertSame(3, $sourceQty, 'source branch quantity did not decrease');
        assertSame(2, $destQty, 'destination branch quantity did not increase');

        $types = $pdo->query('SELECT movement_type, quantity_change FROM inventory_movements WHERE reference_type = \'stock_transfer\' AND reference_id = ' . (int)$result['transfer_id'] . ' ORDER BY id ASC')->fetchAll();
        assertSame(2, count($types), 'expected two movement rows for a transfer');
        assertSame('transfer_out', (string)$types[0]['movement_type']);
        assertSame('transfer_in', (string)$types[1]['movement_type']);

        $auditCount = (int)$pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action_type = \'STOCK_TRANSFERRED\' AND entity_id = ' . (int)$result['transfer_id'])->fetchColumn();
        assertGreaterThan(0, $auditCount, 'stock transfer audit row missing');
    });

    check('audit search matches Arabic action and entity labels', function () {
        $actionCodes = AuditService::actionCodesMatching('تحويل مخزون');
        assertTrue(in_array('STOCK_TRANSFERRED', $actionCodes, true), 'Arabic action search did not map to STOCK_TRANSFERRED');
        $entityCodes = AuditService::entityCodesMatching('الفروع');
        assertTrue(in_array('branches', $entityCodes, true), 'Arabic entity search did not map to branches');
        $logs = UserService::getAuditLogs(['q' => 'تحويل مخزون', 'limit' => 50]);
        assertTrue(!empty($logs), 'Arabic audit search returned no rows');
        $found = false;
        foreach ($logs as $log) {
            if ((string)$log['action_type'] === 'STOCK_TRANSFERRED') {
                $found = true;
                break;
            }
        }
        assertTrue($found, 'Arabic audit search did not return the transfer log');
    });

    check('tax is calculated, stored and reported', function () use ($pdo, $registerId, $userId, $branchId) {
        $createProduct = function (string $label, float $price) use ($userId, $branchId): int {
            $sku = 'TAX-' . strtoupper(bin2hex(random_bytes(4)));
            return ProductService::create([
                'name' => $label . ' ' . $sku,
                'sku' => $sku,
                'purchase_cost' => 1,
                'selling_price' => $price,
                'initial_quantity' => 5,
            ], $userId, $branchId);
        };
        $exclusivePid = $createProduct('Tax Exclusive', 15);
        $inclusivePid = $createProduct('Tax Inclusive', 115);

        SettingsService::updateSettings(['enable_tax' => '1', 'tax_rate' => '10', 'tax_inclusive' => '0'], $userId);
        $session = CashRegisterService::openSession(0.0, $userId, $registerId, $branchId);
        $sessionId = (int)$session['session_id'];
        try {
            $sale = function (int $pid, float $paid, string $prefix, ?float $unitPrice = null) use ($userId, $branchId) {
                $price = $unitPrice === null ? $paid : $unitPrice;
                return PosService::processSale([
                    'items' => [[
                        'product_id' => $pid,
                        'variant_id' => null,
                        'quantity' => 1,
                        'unit_price' => $price,
                        'discount_amount' => 0,
                    ]],
                    'customer_id' => null,
                    'invoice_discount' => 0,
                    'payment_method' => 'cash',
                    'currency_code' => 'LYD',
                    'paid_amount' => $paid,
                    'payments' => [
                        ['payment_method' => 'cash', 'currency_code' => 'LYD', 'amount_foreign' => $paid, 'reference_no' => ''],
                    ],
                    'is_hold' => 0,
                    'held_sale_id' => null,
                    'client_uuid' => $prefix . '-' . bin2hex(random_bytes(6)),
                ], $userId, $branchId);
            };

            $exclusive = $sale($exclusivePid, 16.5, 'tax-exclusive', 15);
            assertSame(1.5, round((float)$exclusive['tax_amount'], 3), 'exclusive tax amount is wrong');
            assertSame(16.5, round((float)$exclusive['total_amount'], 3), 'exclusive final total is wrong');
            $row = $pdo->query('SELECT tax_amount, tax_rate, total_amount FROM sales WHERE id = ' . (int)$exclusive['sale_id'])->fetch();
            assertSame(1.5, round((float)$row['tax_amount'], 3), 'stored tax amount is wrong');
            assertSame(10.0, round((float)$row['tax_rate'], 3), 'stored tax rate is wrong');

            SettingsService::updateSettings(['enable_tax' => '1', 'tax_rate' => '15', 'tax_inclusive' => '1'], $userId);
            $inclusive = $sale($inclusivePid, 115, 'tax-inclusive');
            assertSame(15.0, round((float)$inclusive['tax_amount'], 3), 'inclusive tax amount is wrong');
            assertSame(115.0, round((float)$inclusive['total_amount'], 3), 'inclusive total must not change');

            $report = ReportService::getReport('sales_summary', [
                'start_date' => date('Y-m-d'),
                'end_date' => date('Y-m-d'),
            ], $branchId);
            assertTrue(isset($report['summary']['tax_amount']), 'tax amount missing from the sales summary');
            assertGreaterThan(0, (float)$report['summary']['tax_amount'], 'sales summary tax total is zero');
        } finally {
            SettingsService::updateSettings(['enable_tax' => '0', 'tax_rate' => '0', 'tax_inclusive' => '0'], $userId);
            $summary = CashRegisterService::getSessionSummary($sessionId, $branchId);
            CashRegisterService::closeSession($sessionId, (float)$summary['expected_cash'], null, $userId, $branchId);
        }
    });

    check('printer service reads receipt widths from Windows paper labels', function () {
        assertSame(80, PrinterService::widthFromPaperLabel('80(72.1) x 297 mm'), 'thermal 80mm label was not detected');
        assertSame(58, PrinterService::widthFromPaperLabel('58mm'), 'thermal 58mm label was not detected');
        assertSame(210, PrinterService::widthFromPaperLabel('A4'), 'A4 label was not detected');
        assertSame(210, PrinterService::widthFromPaperLabel('9'), 'Windows PaperKind A4 was not detected');
        assertSame(216, PrinterService::widthFromPaperLabel('Letter'), 'Letter paper was not detected');
        assertSame(null, PrinterService::widthFromPaperLabel(''), 'empty label must return null');
        assertSame(null, PrinterService::widthFromPaperLabel(null), 'null label must return null');
        assertSame(null, PrinterService::widthFromPaperLabel('Custom'), 'unknown label must return null');
    });

    check('owner can purge the audit log and the delete guard is restored', function () use ($pdo, $userId, $branchId) {
        $guardSql = "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'trg_prevent_audit_delete'";
        assertTrue($pdo->query($guardSql)->fetchColumn() !== false, 'audit delete guard was missing before purge');
        assertGreaterThan(0, (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());

        $result = OwnerCleanupService::purge([
            'groups' => ['audit_logs'],
            'confirmation' => OwnerCleanupService::CONFIRMATION_PHRASE,
        ], $userId, $branchId);

        assertGreaterThan(0, (int)$result['deleted']['audit_logs']);
        assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
        assertTrue($pdo->query($guardSql)->fetchColumn() !== false, 'audit delete guard was not restored after purge');

        // The guard must still block a direct delete attempt.
        $pdo->exec("INSERT INTO audit_logs (action_type, entity_type, description) VALUES ('TEST_GUARD', 'test', 'guard check')");
        assertThrowsMessage(function () use ($pdo) {
            $pdo->exec('DELETE FROM audit_logs');
        }, 'immutable');
        assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
    });

    echo "\nRESULT: PASS={$passed} FAIL={$failed}\n";
    if ($failed > 0) {
        echo "\nFailures:\n";
        foreach ($failures as $failure) {
            echo ' - ' . $failure . "\n";
        }
    }
} catch (Throwable $e) {
    echo 'FATAL: ' . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
    $failed++;
} finally {
    $pdo = null;
    Database::reset();
    if (is_file($tempDb)) {
        @unlink($tempDb);
    }
    @unlink($tempDb . '-wal');
    @unlink($tempDb . '-shm');
}

exit($failed === 0 ? 0 : 1);
