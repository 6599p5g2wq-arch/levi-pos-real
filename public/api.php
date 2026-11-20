<?php
declare(strict_types=1);

/**
 * LEVI POS - Main Backend API Dispatcher (public/api.php)
 *
 * Enforces authentication, role-based access control, CSRF protection,
 * strict server-side validation, branch scoping and safe error messages.
 *
 * PHP 8.0 compatible.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Core/Database.php';
require_once dirname(__DIR__) . '/app/Core/Session.php';
require_once dirname(__DIR__) . '/app/Core/CSRF.php';
require_once dirname(__DIR__) . '/app/Core/Auth.php';
require_once dirname(__DIR__) . '/app/Core/Validator.php';
require_once dirname(__DIR__) . '/app/Core/Response.php';
require_once dirname(__DIR__) . '/app/Services/AuditService.php';
require_once dirname(__DIR__) . '/app/Services/NumberService.php';
require_once dirname(__DIR__) . '/app/Services/ProductService.php';
require_once dirname(__DIR__) . '/app/Services/InventoryService.php';
require_once dirname(__DIR__) . '/app/Services/PurchaseService.php';
require_once dirname(__DIR__) . '/app/Services/PosService.php';
require_once dirname(__DIR__) . '/app/Services/ReturnService.php';
require_once dirname(__DIR__) . '/app/Services/SaleCancelService.php';
require_once dirname(__DIR__) . '/app/Services/ReportService.php';
require_once dirname(__DIR__) . '/app/Services/BackupService.php';
require_once dirname(__DIR__) . '/app/Services/CashRegisterService.php';
require_once dirname(__DIR__) . '/app/Services/CustomerService.php';
require_once dirname(__DIR__) . '/app/Services/SupplierService.php';
require_once dirname(__DIR__) . '/app/Services/ExpenseService.php';
require_once dirname(__DIR__) . '/app/Services/UserService.php';
require_once dirname(__DIR__) . '/app/Services/SettingsService.php';
require_once dirname(__DIR__) . '/app/Services/BatchService.php';
require_once dirname(__DIR__) . '/app/Services/DoctorService.php';
require_once dirname(__DIR__) . '/app/Services/PrescriptionService.php';
require_once dirname(__DIR__) . '/app/Services/ImportService.php';
require_once dirname(__DIR__) . '/app/Services/OwnerCleanupService.php';
require_once dirname(__DIR__) . '/app/Services/PrinterService.php';
require_once dirname(__DIR__) . '/app/Services/PharmacyInventoryService.php';
require_once dirname(__DIR__) . '/app/Services/ProductUnitService.php';
require_once dirname(__DIR__) . '/app/Services/PriceHistoryService.php';
require_once dirname(__DIR__) . '/app/Services/PatientRecordService.php';
require_once dirname(__DIR__) . '/app/Services/PrescriptionReviewService.php';
require_once dirname(__DIR__) . '/app/Services/DrugInteractionService.php';
require_once dirname(__DIR__) . '/app/Services/PrescriptionOcrService.php';
require_once dirname(__DIR__) . '/app/Services/PurchaseOrderService.php';
require_once dirname(__DIR__) . '/app/Services/DemandForecastService.php';
require_once dirname(__DIR__) . '/app/Services/SupplierAlertService.php';
require_once dirname(__DIR__) . '/app/Services/PharmacyReportService.php';

use App\Core\Auth;
use App\Core\CSRF;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\BatchService;
use App\Services\CashRegisterService;
use App\Services\CustomerService;
use App\Services\DoctorService;
use App\Services\ExpenseService;
use App\Services\ImportService;
use App\Services\InventoryService;
use App\Services\DemandForecastService;
use App\Services\DrugInteractionService;
use App\Services\OwnerCleanupService;
use App\Services\PatientRecordService;
use App\Services\PharmacyInventoryService;
use App\Services\PosService;
use App\Services\PrescriptionOcrService;
use App\Services\PrescriptionReviewService;
use App\Services\PriceHistoryService;
use App\Services\ProductUnitService;
use App\Services\PurchaseOrderService;
use App\Services\PrinterService;
use App\Services\PrescriptionService;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\SaleCancelService;
use App\Services\SettingsService;
use App\Services\SupplierAlertService;
use App\Services\SupplierService;
use App\Services\UserService;

Session::start();

$action = trim((string)($_GET['action'] ?? ''));
$rawInput = file_get_contents('php://input');
$jsonBody = [];
if ($rawInput !== false && $rawInput !== '') {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $jsonBody = $decoded;
    }
}
$payload = array_merge($_POST, $jsonBody);

/**
 * Fetch a filter value from GET first then payload.
 */
function apiFilter(string $key, $default = null)
{
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    global $payload;
    return array_key_exists($key, $payload) ? $payload[$key] : $default;
}

function apiIntFilter(string $key, int $default = 0): int
{
    $value = apiFilter($key, null);
    return $value === null || $value === '' ? $default : (int)$value;
}

function apiDateRange(): array
{
    $start = (string)apiFilter('start_date', date('Y-m-01'));
    $end = (string)apiFilter('end_date', date('Y-m-d'));
    return [
        Validator::date($start, 'تاريخ البداية'),
        Validator::date($end, 'تاريخ النهاية'),
    ];
}

/**
 * Whether the current user may see product purchase/average cost.
 */
function apiCanViewCost(): bool
{
    return Auth::hasPermission('products.view_cost');
}

/**
 * Remove cost-related keys from a product/inventory row. The interface
 * already hides these columns for users without "products.view_cost";
 * stripping them from the JSON keeps the raw API response consistent.
 */
function apiStripCostKeys(array $row): array
{
    foreach (['purchase_cost', 'average_cost', 'stock_value'] as $key) {
        unset($row[$key]);
    }
    return $row;
}

/**
 * Strip cost keys from a product payload, including nested variants/stock.
 */
function apiStripProductCosts(array $product): array
{
    $product = apiStripCostKeys($product);
    if (isset($product['variants']) && is_array($product['variants'])) {
        $product['variants'] = array_map('apiStripCostKeys', $product['variants']);
    }
    if (isset($product['stock']) && is_array($product['stock'])) {
        $product['stock'] = apiStripCostKeys($product['stock']);
    }
    return $product;
}

/**
 * Save a scanned prescription image under public/assets/uploads/prescriptions.
 */
function apiSavePrescriptionImage(array $file): string
{
    if (empty($file) || !isset($file['tmp_name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('يرجى اختيار صورة الروشتة.');
    }
    if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('فشل رفع الصورة. رمز الخطأ: ' . (int)$file['error']);
    }
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('حجم صورة الروشتة يجب أن يكون بين 1 بايت و 8 ميغابايت.');
    }
    $tmp = (string)$file['tmp_name'];
    $info = @getimagesize($tmp);
    if ($info === false || empty($info['mime'])) {
        throw new RuntimeException('الملف المرفوع ليس صورة صالحة.');
    }
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $mime = strtolower((string)$info['mime']);
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('صيغة الصورة غير مدعومة. المسموح: PNG, JPG, WEBP.');
    }
    $uploadDir = dirname(__DIR__) . '/public/assets/uploads/prescriptions';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('تعذر إنشاء مجلد صور الروشتات.');
    }
    $filename = 'rx-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $extensions[$mime];
    $destination = $uploadDir . '/' . $filename;
    $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $destination) : @rename($tmp, $destination);
    if (!$moved) {
        throw new RuntimeException('تعذر حفظ صورة الروشتة على القرص.');
    }
    @chmod($destination, 0644);
    return 'assets/uploads/prescriptions/' . $filename;
}

try {
    // ------------------------------------------------------------------
    // Public authentication endpoints
    // ------------------------------------------------------------------
    if ($action === 'auth.login') {
        $user = Auth::attemptLogin((string)($payload['username'] ?? ''), (string)($payload['password'] ?? ''));
        Response::success('تم تسجيل الدخول بنجاح.', [
            'user' => $user,
            'csrf_token' => CSRF::generateToken(),
        ]);
    }

    if ($action === 'auth.logout') {
        CSRF::enforce();
        $currentUser = Auth::user();
        if ($currentUser !== null && CashRegisterService::logoutBlockedByOpenShift((int)$currentUser['branch_id'], (int)$currentUser['id'])) {
            $openShift = CashRegisterService::getOpenSession((int)$currentUser['branch_id'], (int)$currentUser['id']);
            Response::error(
                'لا يمكن تسجيل الخروج والوردية مفتوحة. يرجى إغلاق وردية الصندوق أولاً (' . ($openShift['session_number'] ?? '') . ').',
                409
            );
        }
        Auth::logout();
        Response::success('تم تسجيل الخروج بنجاح.');
    }

    if (!Auth::check()) {
        Response::error('انتهت الجلسة أو لم يتم تسجيل الدخول.', 401);
    }

    CSRF::enforce();

    $currentUser = Auth::user();
    $userId = (int)$currentUser['id'];
    $branchId = (int)$currentUser['branch_id'];
    $pdo = Database::getConnection();

    switch ($action) {
        // ------------------------------------------------------------------
        // Session / profile
        // ------------------------------------------------------------------
        case 'auth.me':
            Response::success('بيانات الجلسة الحالية.', [
                'user' => $currentUser,
                'csrf_token' => CSRF::generateToken(),
            ]);

        case 'auth.change_password':
            Auth::changePassword(
                $userId,
                (string)($payload['current_password'] ?? ''),
                (string)($payload['new_password'] ?? '')
            );
            Response::success('تم تغيير كلمة المرور بنجاح.');

        // ------------------------------------------------------------------
        // Dashboard
        // ------------------------------------------------------------------
        case 'dashboard.metrics':
            Auth::requirePermission('dashboard.view');
            Response::success('تم جلب إحصائيات لوحة التحكم.', ReportService::getDashboardMetrics($branchId));

        // ------------------------------------------------------------------
        // POS
        // ------------------------------------------------------------------
        case 'pos.search_products':
            Auth::requireAnyPermission(['pos.use', 'products.view']);
            $items = ProductService::search([
                'q' => (string)apiFilter('q', ''),
                'category_id' => apiFilter('category_id', null),
                'brand_id' => apiFilter('brand_id', null),
                'branch_id' => $branchId,
                'limit' => apiIntFilter('limit', 50),
                'offset' => apiIntFilter('offset', 0),
            ]);
            if (!apiCanViewCost()) {
                $items = array_map('apiStripCostKeys', $items);
            }
            Response::success('نتائج البحث عن المنتجات.', ['items' => $items]);

        case 'pos.categories':
            Auth::requireAnyPermission(['pos.use', 'products.view', 'inventory.view', 'purchases.manage']);
            Response::success('التصنيفات العلاجية المتاحة.', ['categories' => SettingsService::getCategories(true)]);

        case 'pos.brands':
            Auth::requireAnyPermission(['pos.use', 'products.view', 'inventory.view', 'purchases.manage']);
            Response::success('شركات الأدوية المتاحة.', ['brands' => SettingsService::getBrands(true)]);

        case 'pos.customers_search':
            Auth::requirePermission('pos.use');
            $q = (string)apiFilter('q', '');
            $customers = CustomerService::listCustomers(['q' => $q, 'limit' => 30]);
            Response::success('نتائج البحث عن العملاء.', ['customers' => $customers]);

        case 'pos.session_status':
            $mySession = CashRegisterService::getOpenSession($branchId, $userId);
            $busyRegisters = CashRegisterService::getBusyRegisterIds($branchId);
            $registers = array_map(static function (array $register) use ($busyRegisters): array {
                $register['in_use'] = in_array((int)($register['id'] ?? 0), $busyRegisters, true);
                return $register;
            }, SettingsService::getCashRegisters($branchId));
            Response::success('حالة وردية الصندوق.', [
                'session' => $mySession,
                'my_session' => $mySession,
                'registers' => $registers,
                'busy_registers' => $busyRegisters,
            ]);

        case 'pos.checkout':
            Auth::requirePermission('pos.use');
            $result = PosService::processSale($payload, $userId, $branchId);
            $isHeld = isset($result['status']) && $result['status'] === 'held';
            Response::success($isHeld ? 'تم تعليق الفاتورة بنجاح.' : 'تم تسجيل عملية البيع بنجاح.', $result);

        case 'pos.held_invoices':
            Auth::requirePermission('pos.use');
            Response::success('الفواتير المعلقة.', ['held_invoices' => PosService::getHeldSales($branchId)]);

        case 'pos.held_details':
            Auth::requirePermission('pos.resume_held');
            $saleId = Validator::positiveInt($payload['sale_id'] ?? apiFilter('sale_id', 0), 'رقم الفاتورة');
            Response::success('تفاصيل الفاتورة المعلقة.', PosService::getHeldSaleDetails($saleId, $branchId));

        case 'pos.delete_held':
            Auth::requirePermission('pos.use');
            $saleId = Validator::positiveInt($payload['sale_id'] ?? 0, 'رقم الفاتورة');
            PosService::deleteHeldSale($saleId, $branchId, $userId);
            Response::success('تم حذف الفاتورة المعلقة.');

        case 'pos.cancel_invoice':
            Auth::requirePermission('pos.cancel_invoice');
            $saleId = Validator::positiveInt($payload['sale_id'] ?? 0, 'رقم الفاتورة');
            $reason = Validator::optionalString($payload['reason'] ?? '', 500);
            Response::success('تم إلغاء الفاتورة وعكس آثارها المالية والمخزنية.', SaleCancelService::cancelSale($saleId, $reason, $userId, $branchId));

        // ------------------------------------------------------------------
        // Sales / invoices
        // ------------------------------------------------------------------
        case 'sales.list':
            Auth::requirePermission('invoices.view');
            $start = (string)apiFilter('start_date', date('Y-m-01'));
            $end = (string)apiFilter('end_date', date('Y-m-d'));
            $start = Validator::date($start, 'تاريخ البداية');
            $end = Validator::date($end, 'تاريخ النهاية');
            $status = trim((string)apiFilter('status', ''));
            $q = trim((string)apiFilter('q', ''));
            $sql = '
                SELECT s.*, c.name AS customer_name, c.phone AS customer_phone,
                       u.full_name AS cashier_name,
                       (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS items_count
                FROM sales s
                LEFT JOIN customers c ON c.id = s.customer_id
                JOIN users u ON u.id = s.cashier_id
                WHERE s.branch_id = :b AND s.deleted_at IS NULL
                  AND s.sale_date BETWEEN :start AND :end
            ';
            $params = [':b' => $branchId, ':start' => $start . ' 00:00:00', ':end' => $end . ' 23:59:59'];
            if ($status !== '' && in_array($status, ['completed', 'held', 'cancelled', 'returned'], true)) {
                $sql .= ' AND s.status = :status';
                $params[':status'] = $status;
            }
            if ($q !== '') {
                $sql .= ' AND (s.invoice_number LIKE :q1 OR c.name LIKE :q2 OR c.phone LIKE :q3)';
                $like = '%' . $q . '%';
                $params[':q1'] = $like;
                $params[':q2'] = $like;
                $params[':q3'] = $like;
            }
            $limit = min(max(apiIntFilter('limit', 200), 1), 1000);
            $sql .= ' ORDER BY s.id DESC LIMIT ' . $limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            Response::success('قائمة فواتير المبيعات.', ['sales' => $stmt->fetchAll()]);

        case 'sales.get':
            Auth::requirePermission('invoices.view');
            $saleId = Validator::positiveInt($payload['sale_id'] ?? apiFilter('sale_id', 0), 'رقم الفاتورة');
            $stmt = $pdo->prepare('
                SELECT s.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
                       u.full_name AS cashier_name, b.name AS branch_name
                FROM sales s
                LEFT JOIN customers c ON c.id = s.customer_id
                JOIN users u ON u.id = s.cashier_id
                JOIN branches b ON b.id = s.branch_id
                WHERE s.id = :id AND s.branch_id = :b AND s.deleted_at IS NULL
                LIMIT 1
            ');
            $stmt->execute([':id' => $saleId, ':b' => $branchId]);
            $sale = $stmt->fetch();
            if (!$sale) {
                Response::error('الفاتورة غير موجودة أو لا تنتمي لفرعك.', 404);
            }
            $itemsStmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = :id ORDER BY id ASC');
            $itemsStmt->execute([':id' => $saleId]);
            $sale['items'] = $itemsStmt->fetchAll();
            $payStmt = $pdo->prepare('SELECT * FROM payments WHERE sale_id = :id ORDER BY id ASC');
            $payStmt->execute([':id' => $saleId]);
            $sale['payments'] = $payStmt->fetchAll();
            $retStmt = $pdo->prepare('SELECT * FROM sale_returns WHERE sale_id = :id ORDER BY id ASC');
            $retStmt->execute([':id' => $saleId]);
            $sale['returns'] = $retStmt->fetchAll();
            Response::success('تفاصيل الفاتورة.', $sale);

        // ------------------------------------------------------------------
        // Products
        // ------------------------------------------------------------------
        case 'products.list':
            Auth::requirePermission('products.view');
            $productItems = ProductService::search([
                'q' => (string)apiFilter('q', ''),
                'category_id' => apiFilter('category_id', null),
                'brand_id' => apiFilter('brand_id', null),
                'dosage_form' => apiFilter('dosage_form', null),
                'strength' => apiFilter('strength', null),
                'active_ingredient' => apiFilter('active_ingredient', null),
                'manufacturer' => apiFilter('manufacturer', null),
                'storage_condition_code' => apiFilter('storage_condition_code', null),
                'archived' => apiFilter('archived', 0),
                'branch_id' => $branchId,
                'limit' => apiIntFilter('limit', 200),
                'offset' => apiIntFilter('offset', 0),
            ]);
            if (!apiCanViewCost()) {
                $productItems = array_map('apiStripCostKeys', $productItems);
            }
            Response::success('قائمة المنتجات.', ['items' => $productItems]);

        case 'products.get':
            Auth::requirePermission('products.view');
            $productId = Validator::positiveInt($payload['product_id'] ?? apiFilter('product_id', 0), 'معرف المنتج');
            $product = ProductService::getProduct($productId, $branchId);
            if (!apiCanViewCost()) {
                $product = apiStripProductCosts($product);
            }
            Response::success('بيانات المنتج.', $product);

        case 'products.create':
            Auth::requirePermission('products.manage');
            $productId = ProductService::create($payload, $userId, $branchId);
            Response::success('تمت إضافة المنتج بنجاح.', ['product_id' => $productId]);

        case 'products.update':
            Auth::requirePermission('products.manage');
            $touchesStock = array_key_exists('stock_quantity', $payload);
            if (!$touchesStock && !empty($payload['variants']) && is_array($payload['variants'])) {
                foreach ($payload['variants'] as $variantRow) {
                    if (is_array($variantRow) && array_key_exists('stock_quantity', $variantRow)) {
                        $touchesStock = true;
                        break;
                    }
                }
            }
            if ($touchesStock) {
                Auth::requirePermission('inventory.adjust');
            }
            $productId = Validator::positiveInt($payload['product_id'] ?? 0, 'معرف المنتج');
            Response::success('تم تحديث المنتج بنجاح.', ProductService::update($productId, $payload, $userId, $branchId));

        case 'products.image_upload':
            Auth::requirePermission('products.manage');
            $productId = Validator::positiveInt($payload['product_id'] ?? 0, 'معرف المنتج');
            Response::success('تم رفع صورة المنتج بنجاح.', ProductService::saveImage($_FILES['image'] ?? [], $productId, $userId));

        case 'products.archive':
            Auth::requirePermission('products.manage');
            $productId = Validator::positiveInt($payload['product_id'] ?? 0, 'معرف المنتج');
            $mode = ProductService::archiveOrDelete($productId, $userId);
            Response::success(
                $mode === 'archived'
                    ? 'المنتج مرتبط بفواتير سابقة؛ تمت أرشفته بأمان دون حذفه نهائيًا. يمكنك استعادته من «المنتجات المؤرشفة».'
                    : 'تم حذف المنتج بنجاح. يمكنك استعادته من «المنتجات المؤرشفة».',
                ['mode' => $mode]
            );

        case 'products.bulk_delete':
            Auth::requireOwner();
            $productIds = $payload['product_ids'] ?? [];
            if (!is_array($productIds)) {
                throw new RuntimeException('قائمة المنتجات المحددة غير صالحة.');
            }
            $bulkResult = ProductService::bulkDelete($productIds, $userId);
            Response::success(
                'تم تنفيذ الحذف الجماعي: '
                . (int)$bulkResult['permanently_deleted'] . ' حذف نهائي من الأرشيف، '
                . (int)$bulkResult['soft_deleted'] . ' محذوف، '
                . (int)$bulkResult['archived'] . ' مؤرشف (مرتبط بفواتير)'
                . ((int)$bulkResult['blocked'] > 0 ? '، ' . (int)$bulkResult['blocked'] . ' لم يُحذف لارتباطه بفواتير.' : '.'),
                $bulkResult
            );

        case 'products.purge_archived':
            Auth::requireOwner();
            $archivedIds = $payload['product_ids'] ?? [];
            if (!is_array($archivedIds)) {
                throw new RuntimeException('قائمة المنتجات المحددة غير صالحة.');
            }
            $purgeResult = ProductService::purgeArchived($archivedIds, $userId);
            Response::success(
                'تم الحذف النهائي من الأرشيف: ' . (int)$purgeResult['deleted'] . ' منتج'
                . ((int)$purgeResult['blocked'] > 0 ? '، وتجاوز ' . (int)$purgeResult['blocked'] . ' منتج لارتباطه بفواتير.' : '.'),
                $purgeResult
            );

        case 'products.restore':
            Auth::requirePermission('products.manage');
            $productId = Validator::positiveInt($payload['product_id'] ?? 0, 'معرف المنتج');
            Response::success('تمت استعادة المنتج بنجاح.', ProductService::restore($productId, $userId));

        // ------------------------------------------------------------------
        // Inventory
        // ------------------------------------------------------------------
        case 'inventory.list':
            Auth::requirePermission('inventory.view');
            $inventoryItems = InventoryService::getInventoryList($branchId, [
                'q' => (string)apiFilter('q', ''),
                'low_stock' => apiFilter('low_stock', null),
                'limit' => apiIntFilter('limit', 300),
            ]);
            if (!apiCanViewCost()) {
                $inventoryItems = array_map('apiStripCostKeys', $inventoryItems);
            }
            Response::success('قائمة المخزون.', ['items' => $inventoryItems]);

        case 'inventory.movements':
            Auth::requirePermission('inventory.view');
            Response::success('سجل حركة المخزون.', ['movements' => InventoryService::getMovements($branchId, [
                'product_id' => apiFilter('product_id', null),
                'movement_type' => apiFilter('movement_type', null),
                'start_date' => apiFilter('start_date', null),
                'end_date' => apiFilter('end_date', null),
                'limit' => apiIntFilter('limit', 300),
            ])]);

        case 'inventory.low_stock':
            Auth::requirePermission('inventory.view');
            Response::success('الأصناف منخفضة المخزون.', ['items' => InventoryService::getLowStock($branchId, apiIntFilter('limit', 100))]);

        case 'inventory.valuation':
            Auth::requirePermission('inventory.view');
            $valuation = InventoryService::getValuation($branchId);
            if (!apiCanViewCost()) {
                unset($valuation['total_value']);
            }
            Response::success('تقييم المخزون.', $valuation);

        case 'inventory.transfer_branches':
            Auth::requirePermission('inventory.adjust');
            $branchStmt = Database::getConnection()->query('SELECT id, name, code, is_main FROM branches WHERE is_active = 1 ORDER BY is_main DESC, id ASC');
            Response::success('الفروع المتاحة.', ['branches' => $branchStmt->fetchAll()]);

        case 'inventory.transfer':
            Auth::requirePermission('inventory.adjust');
            Response::success('تم تحويل المخزون بين الفرعين بنجاح.', InventoryService::transferStock($payload, $userId, $branchId));

        case 'inventory.adjust':
            Auth::requirePermission('inventory.adjust');
            Response::success('تم تسجيل حركة/تسوية المخزون بنجاح.', InventoryService::recordAdjustment($payload, $userId, $branchId));

        // ------------------------------------------------------------------
        // Customers
        // ------------------------------------------------------------------
        case 'customers.list':
            Auth::requirePermission('customers.manage');
            Response::success('قائمة العملاء.', ['customers' => CustomerService::listCustomers([
                'q' => (string)apiFilter('q', ''),
                'has_debt' => apiFilter('has_debt', null),
                'is_active' => apiFilter('is_active', null),
                'limit' => apiIntFilter('limit', 300),
            ])]);

        case 'customers.get':
            Auth::requirePermission('customers.manage');
            $customerId = Validator::positiveInt($payload['customer_id'] ?? apiFilter('customer_id', 0), 'معرف العميل');
            Response::success('بيانات العميل.', CustomerService::getCustomer($customerId));

        case 'customers.save':
            Auth::requirePermission('customers.manage');
            Response::success('تم حفظ بيانات العميل بنجاح.', CustomerService::saveCustomer($payload, $userId));

        case 'customers.delete':
            Auth::requirePermission('customers.manage');
            $customerId = Validator::positiveInt($payload['customer_id'] ?? 0, 'معرف العميل');
            $mode = CustomerService::deleteCustomer($customerId, $userId);
            Response::success($mode === 'archived' ? 'العميل لديه فواتير؛ تمت أرشفته بأمان.' : 'تم حذف العميل.', ['mode' => $mode]);

        case 'customers.collect_payment':
            Auth::requirePermission('customers.manage');
            Response::success('تم تسجيل تحصيل الدين بنجاح.', CustomerService::collectPayment($payload, $userId, $branchId));

        case 'customers.statement':
            Auth::requirePermission('customers.manage');
            $customerId = Validator::positiveInt($payload['customer_id'] ?? apiFilter('customer_id', 0), 'معرف العميل');
            [$start, $end] = apiDateRange();
            Response::success('كشف حساب العميل.', CustomerService::getStatement($customerId, $start, $end));

        // ------------------------------------------------------------------
        // Suppliers
        // ------------------------------------------------------------------
        case 'suppliers.list':
            Auth::requirePermission('suppliers.manage');
            Response::success('قائمة الموردين.', ['suppliers' => SupplierService::listSuppliers([
                'q' => (string)apiFilter('q', ''),
                'is_active' => apiFilter('is_active', null),
                'has_balance' => apiFilter('has_balance', null),
                'limit' => apiIntFilter('limit', 300),
            ])]);

        case 'suppliers.get':
            Auth::requirePermission('suppliers.manage');
            $supplierId = Validator::positiveInt($payload['supplier_id'] ?? apiFilter('supplier_id', 0), 'معرف المورد');
            Response::success('بيانات المورد.', SupplierService::getSupplier($supplierId));

        case 'suppliers.save':
            Auth::requirePermission('suppliers.manage');
            Response::success('تم حفظ بيانات المورد بنجاح.', SupplierService::saveSupplier($payload, $userId));

        case 'suppliers.delete':
            Auth::requirePermission('suppliers.manage');
            $supplierId = Validator::positiveInt($payload['supplier_id'] ?? 0, 'معرف المورد');
            $mode = SupplierService::deleteSupplier($supplierId, $userId);
            Response::success($mode === 'archived' ? 'المورد لديه مشتريات؛ تمت أرشفته بأمان.' : 'تم حذف المورد.', ['mode' => $mode]);

        case 'suppliers.payments':
            Auth::requirePermission('suppliers.manage');
            Response::success('دفعات الموردين.', ['payments' => SupplierService::listPayments($branchId, [
                'supplier_id' => apiFilter('supplier_id', null),
                'start_date' => apiFilter('start_date', null),
                'end_date' => apiFilter('end_date', null),
                'limit' => apiIntFilter('limit', 300),
            ])]);

        case 'suppliers.pay':
            Auth::requirePermission('suppliers.manage');
            Response::success('تم تسجيل دفعة المورد بنجاح.', PurchaseService::paySupplier($payload, $userId, $branchId));

        case 'suppliers.statement':
            Auth::requirePermission('suppliers.manage');
            $supplierId = Validator::positiveInt($payload['supplier_id'] ?? apiFilter('supplier_id', 0), 'معرف المورد');
            [$start, $end] = apiDateRange();
            Response::success('كشف حساب المورد.', SupplierService::getStatement($supplierId, $start, $end));

        // ------------------------------------------------------------------
        // Purchases
        // ------------------------------------------------------------------
        case 'purchases.list':
            Auth::requirePermission('purchases.manage');
            Response::success('قائمة فواتير المشتريات.', ['purchases' => PurchaseService::getPurchases($branchId, [
                'supplier_id' => apiFilter('supplier_id', null),
                'start_date' => apiFilter('start_date', null),
                'end_date' => apiFilter('end_date', null),
                'status' => apiFilter('status', null),
                'q' => (string)apiFilter('q', ''),
                'limit' => apiIntFilter('limit', 300),
            ])]);

        case 'purchases.get':
            Auth::requirePermission('purchases.manage');
            $purchaseId = Validator::positiveInt($payload['purchase_id'] ?? apiFilter('purchase_id', 0), 'معرف فاتورة الشراء');
            Response::success('تفاصيل فاتورة الشراء.', PurchaseService::getPurchaseDetails($purchaseId, $branchId));

        case 'purchases.create':
            Auth::requirePermission('purchases.manage');
            Response::success('تم حفظ فاتورة المشتريات وتحديث المخزون والتكلفة بنجاح.', PurchaseService::createPurchase($payload, $userId, $branchId));

        case 'purchases.cancel':
            Auth::requirePermission('purchases.manage');
            $purchaseId = Validator::positiveInt($payload['purchase_id'] ?? 0, 'معرف فاتورة الشراء');
            $reason = Validator::optionalString($payload['reason'] ?? '', 500);
            Response::success('تم إلغاء فاتورة الشراء وعكس آثارها.', PurchaseService::cancelPurchase($purchaseId, $reason, $userId, $branchId));

        case 'purchases.return_create':
            Auth::requirePermission('purchases.return');
            Response::success('تم تسجيل مرتجع المشتريات بنجاح.', PurchaseService::createPurchaseReturn($payload, $userId, $branchId));

        // ------------------------------------------------------------------
        // Sales returns
        // ------------------------------------------------------------------
        case 'returns.create':
            Auth::requirePermission('returns.create');
            Response::success('تم تسجيل المرتجع وتحديث المخزون والحسابات المالية بنجاح.', ReturnService::processSaleReturn($payload, $userId, $branchId));

        case 'returns.get':
            Auth::requirePermission('returns.create');
            $returnId = Validator::positiveInt($payload['return_id'] ?? apiFilter('return_id', 0), 'معرف المرتجع');
            Response::success('تفاصيل المرتجع.', ReturnService::getReturnDetails($returnId, $branchId));

        case 'returns.list':
            Auth::requirePermission('returns.create');
            [$start, $end] = apiDateRange();
            $stmt = $pdo->prepare('
                SELECT sr.*, s.invoice_number, c.name AS customer_name, u.full_name AS created_by_name
                FROM sale_returns sr
                JOIN sales s ON s.id = sr.sale_id
                LEFT JOIN customers c ON c.id = sr.customer_id
                JOIN users u ON u.id = sr.created_by
                WHERE sr.branch_id = :b AND sr.created_at BETWEEN :start AND :end
                ORDER BY sr.id DESC LIMIT 300
            ');
            $stmt->execute([':b' => $branchId, ':start' => $start . ' 00:00:00', ':end' => $end . ' 23:59:59']);
            Response::success('قائمة المرتجعات.', ['returns' => $stmt->fetchAll()]);

        case 'returns.search_invoice':
            Auth::requirePermission('returns.create');
            $q = trim((string)apiFilter('q', ''));
            if ($q === '') {
                throw new RuntimeException('يرجى إدخال رقم الفاتورة أو اسم/باركود المنتج للبحث.');
            }
            $like = '%' . $q . '%';
            $stmt = $pdo->prepare('
                SELECT DISTINCT s.id, s.invoice_number, s.sale_date, s.total_amount,
                       s.paid_amount, s.remaining_debt, s.status, c.name AS customer_name, c.phone AS customer_phone
                FROM sales s
                LEFT JOIN customers c ON c.id = s.customer_id
                LEFT JOIN sale_items si ON si.sale_id = s.id
                WHERE s.branch_id = :b
                  AND s.status IN (\'completed\', \'returned\')
                  AND s.deleted_at IS NULL
                  AND (s.invoice_number LIKE :q1 OR c.name LIKE :q2 OR c.phone LIKE :q3
                       OR si.product_name_snapshot LIKE :q4 OR si.sku_snapshot LIKE :q5)
                ORDER BY s.id DESC LIMIT 30
            ');
            $stmt->execute([
                ':b' => $branchId, ':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like,
            ]);
            Response::success('نتائج البحث عن الفواتير القابلة للارتجاع.', ['sales' => $stmt->fetchAll()]);

        // ------------------------------------------------------------------
        // Expenses
        // ------------------------------------------------------------------
        case 'expenses.list':
            Auth::requirePermission('expenses.manage');
            Response::success('قائمة المصروفات.', ['expenses' => ExpenseService::listExpenses($branchId, [
                'category_id' => apiFilter('category_id', null),
                'start_date' => apiFilter('start_date', null),
                'end_date' => apiFilter('end_date', null),
                'q' => (string)apiFilter('q', ''),
                'limit' => apiIntFilter('limit', 300),
            ])]);

        case 'expenses.create':
            Auth::requirePermission('expenses.manage');
            Response::success('تم تسجيل المصروف بنجاح.', ExpenseService::createExpense($payload, $userId, $branchId));

        case 'expenses.update':
            Auth::requirePermission('expenses.manage');
            $expenseId = Validator::positiveInt($payload['expense_id'] ?? 0, 'معرف المصروف');
            Response::success('تم تحديث بيانات المصروف.', ExpenseService::updateExpense($expenseId, $payload, $userId, $branchId));

        case 'expenses.archive':
            Auth::requirePermission('expenses.manage');
            $expenseId = Validator::positiveInt($payload['expense_id'] ?? 0, 'معرف المصروف');
            $reason = Validator::requireNonEmpty($payload['reason'] ?? null, 'سبب الإلغاء', 500);
            Response::success('تم إلغاء المصروف وإرجاع مبلغه للصندوق إن كان نقديًا.', ExpenseService::archiveExpense($expenseId, $reason, $userId, $branchId));

        case 'expenses.categories':
            Auth::requirePermission('expenses.manage');
            $onlyActive = !Validator::bool(apiFilter('include_inactive', 0));
            Response::success('تصنيفات المصروفات.', ['categories' => ExpenseService::listCategories($onlyActive)]);

        case 'expenses.category_save':
            Auth::requirePermission('expenses.manage');
            Response::success('تم حفظ تصنيف المصروف.', ExpenseService::saveCategory($payload, $userId));

        // ------------------------------------------------------------------
        // Cash register
        // ------------------------------------------------------------------
        case 'cash_register.status':
            Auth::requireAnyPermission(['pos.use', 'cash_register.manage']);
            $mySession = CashRegisterService::getOpenSession($branchId, $userId);
            $busyRegisters = CashRegisterService::getBusyRegisterIds($branchId);
            $registers = array_map(static function (array $register) use ($busyRegisters): array {
                $register['in_use'] = in_array((int)($register['id'] ?? 0), $busyRegisters, true);
                return $register;
            }, SettingsService::getCashRegisters($branchId));
            Response::success('حالة وردية الصندوق.', [
                'session' => $mySession,
                'my_session' => $mySession,
                'registers' => $registers,
                'busy_registers' => $busyRegisters,
            ]);

        case 'cash_register.open':
            Auth::requirePermission('pos.use');
            $registerId = Validator::positiveInt($payload['register_id'] ?? 0, 'الصندوق');
            $openingCash = Validator::positiveDecimal($payload['opening_cash'] ?? 0, 'الرصيد الافتتاحي', true);
            Response::success('تم فتح وردية الكاشير بنجاح.', CashRegisterService::openSession($openingCash, $userId, $registerId, $branchId));

        case 'cash_register.summary':
            Auth::requirePermission('pos.use');
            $sessionId = Validator::positiveInt($payload['session_id'] ?? apiFilter('session_id', 0), 'رقم الوردية');
            Response::success('ملخص الوردية.', CashRegisterService::getSessionSummary($sessionId, $branchId, $userId));

        case 'cash_register.close':
            Auth::requirePermission('cash_register.close');
            $sessionId = Validator::positiveInt($payload['session_id'] ?? 0, 'رقم الوردية');
            $actualCash = Validator::positiveDecimal($payload['actual_cash'] ?? 0, 'النقد الفعلي بالدرج', true);
            $reason = isset($payload['discrepancy_reason']) ? (string)$payload['discrepancy_reason'] : null;
            Response::success('تم إغلاق يومية الكاشير بنجاح.', CashRegisterService::closeSession($sessionId, $actualCash, $reason, $userId, $branchId, $userId));

        case 'cash_register.sessions':
            Auth::requirePermission('cash_register.manage');
            Response::success('ورديات الصندوق.', ['sessions' => CashRegisterService::getSessions($branchId, [
                'start_date' => apiFilter('start_date', null),
                'end_date' => apiFilter('end_date', null),
                'status' => apiFilter('status', null),
                'limit' => apiIntFilter('limit', 200),
            ])]);

        case 'cash_register.movements':
            Auth::requirePermission('pos.use');
            $sessionId = Validator::positiveInt($payload['session_id'] ?? apiFilter('session_id', 0), 'رقم الوردية');
            Response::success('حركات الوردية.', ['movements' => CashRegisterService::getSessionMovements($sessionId, $branchId, $userId)]);

        case 'cash_register.manual_movement':
            Auth::requirePermission('cash_register.manage');
            $sessionId = Validator::positiveInt($payload['session_id'] ?? 0, 'رقم الوردية');
            $type = Validator::enum($payload['movement_type'] ?? '', ['manual_in', 'manual_out'], 'نوع الحركة');
            $amount = Validator::positiveDecimal($payload['amount'] ?? 0, 'المبلغ', false);
            $description = Validator::requireNonEmpty($payload['description'] ?? null, 'وصف الحركة', 500);
            Response::success('تم تسجيل الحركة النقدية.', CashRegisterService::addManualMovement($sessionId, $type, $amount, $description, $userId, $branchId, $userId));

        // ------------------------------------------------------------------
        // Pharmacy: batches & expiry
        // ------------------------------------------------------------------
        case 'batches.list':
            Auth::requireAnyPermission(['batches.manage', 'products.view', 'inventory.view', 'expiry.view']);
            Response::success('قائمة دفعات الأدوية.', ['batches' => BatchService::list([
                'branch_id' => $branchId,
                'q' => (string)apiFilter('q', ''),
                'product_id' => apiFilter('product_id', null),
                'status' => apiFilter('status', null),
                'limit' => apiIntFilter('limit', 300),
            ])]);

        case 'batches.for_product':
            Auth::requireAnyPermission(['batches.manage', 'products.view', 'pos.use']);
            $productId = Validator::positiveInt(apiFilter('product_id', 0), 'الدواء');
            Response::success('دفعات الدواء.', ['batches' => BatchService::forProduct($productId, $branchId)]);

        case 'batches.save':
            Auth::requirePermission('batches.manage');
            Response::success('تم حفظ الدفعة بنجاح.', BatchService::save($payload, $userId, $branchId));

        case 'batches.delete':
            Auth::requirePermission('batches.manage');
            BatchService::delete(Validator::positiveInt($payload['batch_id'] ?? 0, 'معرف الدفعة'), $userId, $branchId);
            Response::success('تم حذف الدفعة بنجاح.');

        case 'expiry.alerts':
            Auth::requireAnyPermission(['expiry.view', 'batches.manage', 'inventory.view']);
            $days = apiFilter('days', null);
            Response::success('تنبيهات انتهاء الصلاحية.', [
                'alerts' => BatchService::alerts($branchId, $days !== null && $days !== '' ? (int)$days : null),
                'counts' => BatchService::alertCounts($branchId),
                'alert_days' => BatchService::alertDays(),
                'alert_windows' => array_values(array_filter(array_map('trim', explode(',', (string)SettingsService::getValue('expiry_alert_windows', '90,60,30'))), 'strlen')),
            ]);

        // ------------------------------------------------------------------
        // Pharmacy: doctors
        // ------------------------------------------------------------------
        case 'doctors.list':
            Auth::requireAnyPermission(['doctors.manage', 'prescriptions.manage', 'pos.use']);
            Response::success('قائمة الأطباء.', ['doctors' => DoctorService::list([
                'q' => (string)apiFilter('q', ''),
                'is_active' => apiFilter('is_active', null),
            ])]);

        case 'doctors.save':
            Auth::requirePermission('doctors.manage');
            Response::success('تم حفظ بيانات الطبيب بنجاح.', DoctorService::save($payload, $userId));

        case 'doctors.delete':
            Auth::requirePermission('doctors.manage');
            DoctorService::delete(Validator::positiveInt($payload['doctor_id'] ?? 0, 'معرف الطبيب'), $userId);
            Response::success('تم حذف الطبيب بنجاح.');

        // ------------------------------------------------------------------
        // Pharmacy: prescriptions
        // ------------------------------------------------------------------
        case 'prescriptions.list':
            Auth::requireAnyPermission(['prescriptions.manage', 'pos.use', 'patients.manage']);
            Response::success('قائمة الروشتات.', ['prescriptions' => PrescriptionService::list([
                'q' => (string)apiFilter('q', ''),
                'patient_id' => apiFilter('patient_id', null),
                'doctor_id' => apiFilter('doctor_id', null),
                'status' => apiFilter('status', null),
                'start_date' => apiFilter('start_date', null),
                'end_date' => apiFilter('end_date', null),
            ])]);

        case 'prescriptions.get':
            Auth::requireAnyPermission(['prescriptions.manage', 'pos.use', 'patients.manage']);
            Response::success('بيانات الروشتة.', PrescriptionService::get(
                Validator::positiveInt(apiFilter('prescription_id', 0), 'معرف الروشتة')
            ));

        case 'prescriptions.save':
            Auth::requirePermission('prescriptions.manage');
            Response::success('تم حفظ الروشتة بنجاح.', PrescriptionService::save($payload, $userId));

        case 'prescriptions.dispense':
            Auth::requirePermission('prescriptions.dispense');
            $prescriptionId = Validator::positiveInt($payload['prescription_id'] ?? 0, 'معرف الروشتة');
            $saleId = Validator::positiveInt($payload['sale_id'] ?? 0, 'معرف الفاتورة');
            if (!SettingsService::isEnabled('require_pharmacist_dispense', true)) {
                Auth::requirePermission('prescriptions.manage');
            }
            PrescriptionReviewService::assertDispensable($prescriptionId, !empty($payload['customer_id']) ? (int)$payload['customer_id'] : null, $branchId);
            if (!empty($payload['review_notes'])) {
                PrescriptionService::recordReview($prescriptionId, (string)$payload['review_notes'], $userId);
            }
            PrescriptionService::dispense($prescriptionId, $saleId, $userId);
            Response::success('تم صرف الروشتة بنجاح.');

        case 'prescriptions.delete':
            Auth::requirePermission('prescriptions.manage');
            PrescriptionService::delete(Validator::positiveInt($payload['prescription_id'] ?? 0, 'معرف الروشتة'), $userId);
            Response::success('تم حذف الروشتة بنجاح.');

        // ------------------------------------------------------------------
        // Reports
        // ------------------------------------------------------------------
        case 'reports.types':
            Auth::requireAnyPermission(['reports.view', 'reports.view_financial']);
            $reportTypes = ReportService::filterReportTypes(
                ReportService::listReportTypes(),
                Auth::hasPermission('reports.view_financial'),
                Auth::hasPermission('audit.view') || Auth::hasPermission('users.manage')
            );
            Response::success('أنواع التقارير المتاحة.', ['types' => $reportTypes]);

        case 'reports.get':
            Auth::requireAnyPermission(['reports.view', 'reports.view_financial']);
            $type = Validator::requireNonEmpty($payload['report_type'] ?? apiFilter('report_type', ''), 'نوع التقرير', 100);
            $requiredReportPermission = ReportService::requiredPermission($type);
            if ($requiredReportPermission === 'audit.view') {
                if (!Auth::hasPermission('audit.view') && !Auth::hasPermission('users.manage')) {
                    throw new RuntimeException('ليس لديك صلاحية عرض هذا التقرير.');
                }
            } elseif ($requiredReportPermission !== null) {
                Auth::requirePermission($requiredReportPermission);
            }
            Response::success('تم إنشاء التقرير.', ReportService::getReport($type, [
                'start_date' => apiFilter('start_date', date('Y-m-01')),
                'end_date' => apiFilter('end_date', date('Y-m-d')),
                'category_id' => apiFilter('category_id', null),
                'customer_id' => apiFilter('customer_id', null),
                'supplier_id' => apiFilter('supplier_id', null),
                'product_id' => apiFilter('product_id', null),
                'user_id' => apiFilter('user_id', null),
            ], $branchId));

        case 'reports.financial_summary':
            Auth::requirePermission('reports.view_financial');
            [$start, $end] = apiDateRange();
            Response::success('التقرير المالي الشامل.', ReportService::getFinancialSummary($start, $end, $branchId));

        // ------------------------------------------------------------------
        // Users / roles / audit
        // ------------------------------------------------------------------
        case 'users.list':
            Auth::requirePermission('users.manage');
            Response::success('قائمة المستخدمين.', ['users' => UserService::listUsers([
                'q' => (string)apiFilter('q', ''),
                'is_active' => apiFilter('is_active', null),
                'role_id' => apiFilter('role_id', null),
            ], $userId)]);

        case 'users.create':
            Auth::requirePermission('users.manage');
            Response::success('تم إنشاء حساب المستخدم بنجاح.', UserService::createUser($payload, $userId, $branchId));

        case 'users.update':
            Auth::requirePermission('users.manage');
            $targetUserId = Validator::positiveInt($payload['user_id'] ?? 0, 'معرف المستخدم');
            Response::success('تم تحديث بيانات المستخدم.', UserService::updateUser($targetUserId, $payload, $userId));

        case 'users.reset_password':
            Auth::requirePermission('users.manage');
            $targetUserId = Validator::positiveInt($payload['user_id'] ?? 0, 'معرف المستخدم');
            Response::success('تمت إعادة تعيين كلمة المرور. سيُطلب من المستخدم تغييرها عند أول دخول.', UserService::resetPassword(
                $targetUserId,
                (string)($payload['new_password'] ?? ''),
                $userId
            ));

        case 'users.unlock':
            Auth::requirePermission('users.manage');
            $targetUserId = Validator::positiveInt($payload['user_id'] ?? 0, 'معرف المستخدم');
            Response::success('تم فتح قفل الحساب.', UserService::unlockUser($targetUserId, $userId));

        case 'users.delete':
            Auth::requirePermission('users.manage');
            $targetUserId = Validator::positiveInt($payload['user_id'] ?? 0, 'معرف المستخدم');
            Response::success('تم حذف حساب المستخدم.', ['mode' => UserService::deleteUser($targetUserId, $userId)]);

        case 'users.roles':
            Auth::requirePermission('users.manage');
            Response::success('الأدوار الوظيفية.', ['roles' => UserService::listRoles($userId)]);

        case 'users.branches':
            Auth::requirePermission('users.manage');
            Response::success('الفروع المتاحة.', ['branches' => SettingsService::getBranches()]);

        case 'users.permissions':
            Auth::requirePermission('users.manage');
            Response::success('قائمة الصلاحيات.', ['permissions' => UserService::listPermissions($userId)]);

        case 'users.role_permissions':
            Auth::requirePermission('users.manage');
            $roleId = Validator::positiveInt($payload['role_id'] ?? apiFilter('role_id', 0), 'معرف الدور');
            Response::success('صلاحيات الدور.', [
                'role_id' => $roleId,
                'permissions' => UserService::getRolePermissions($roleId, $userId),
            ]);

        case 'users.update_role_permissions':
            Auth::requirePermission('users.manage');
            $roleId = Validator::positiveInt($payload['role_id'] ?? 0, 'معرف الدور');
            $codes = $payload['permissions'] ?? [];
            if (!is_array($codes)) {
                throw new RuntimeException('قائمة الصلاحيات غير صالحة.');
            }
            Response::success('تم تحديث صلاحيات الدور.', UserService::updateRolePermissions($roleId, $codes, $userId));

        case 'users.audit_logs':
            Auth::requireAnyPermission(['audit.view', 'users.manage']);
            Response::success('سجل التدقيق.', ['logs' => UserService::getAuditLogs([
                'start_date' => apiFilter('start_date', null),
                'end_date' => apiFilter('end_date', null),
                'action_type' => apiFilter('action_type', null),
                'user_id' => apiFilter('user_id', null),
                'q' => (string)apiFilter('q', ''),
                'limit' => apiIntFilter('limit', 300),
            ], $userId)]);

        // ------------------------------------------------------------------
        // Settings
        // ------------------------------------------------------------------
        case 'settings.get':
            Auth::requirePermission('settings.manage');
            Response::success('إعدادات النظام.', [
                'is_owner' => Auth::isOwner(),
                'settings' => SettingsService::getSettings(),
                'categories' => SettingsService::getCategories(false),
                'brands' => SettingsService::getBrands(false),
                'branches' => SettingsService::getBranches(),
                'cash_registers' => SettingsService::getCashRegisters($branchId),
                'expense_categories' => ExpenseService::listCategories(false),
            ]);

        case 'settings.update':
            Auth::requirePermission('settings.manage');
            $settingsMap = $payload['settings'] ?? null;
            if (!is_array($settingsMap)) {
                throw new RuntimeException('بيانات الإعدادات غير صالحة.');
            }
            Response::success('تم حفظ الإعدادات بنجاح.', SettingsService::updateSettings($settingsMap, $userId));

        case 'printers.detect':
            Auth::requirePermission('settings.manage');
            Response::success('نتيجة كشف الطابعات المحلية.', PrinterService::detect());

        case 'settings.clock_adjust':
            Auth::requireOwner();
            Response::success('تم ضبط ساعة العرض بنجاح.', SettingsService::adjustClock($payload, $userId));

        case 'settings.logo_upload':
            Auth::requireOwner();
            Response::success('تم رفع شعار الصيدلية وتطبيقه بنجاح.', SettingsService::saveLogo($_FILES['logo'] ?? [], $userId));

        case 'settings.logo_reset':
            Auth::requireOwner();
            Response::success('تمت استعادة الشعار الافتراضي.', SettingsService::resetLogo($userId));

        // ------------------------------------------------------------------
        // Owner maintenance (destructive bulk cleanup)
        // ------------------------------------------------------------------
        case 'owner.cleanup_summary':
            Auth::requireOwner();
            Response::success('ملخص السجلات القابلة للحذف.', OwnerCleanupService::summary($branchId, [
                'all_branches' => Validator::bool(apiFilter('all_branches', 0)),
                'start_date' => apiFilter('start_date', ''),
                'end_date' => apiFilter('end_date', ''),
            ]));

        case 'owner.cleanup_purge':
            Auth::requireOwner();
            $cleanupResult = OwnerCleanupService::purge($payload, $userId, $branchId);
            Response::success('تم تنفيذ الحذف النهائي للسجلات المحددة.', $cleanupResult);

        case 'settings.categories':
            Auth::requirePermission('settings.manage');
            Response::success('التصنيفات.', ['categories' => SettingsService::getCategories(false)]);

        case 'settings.category_save':
            Auth::requirePermission('settings.manage');
            Response::success('تم حفظ التصنيف.', SettingsService::saveCategory($payload, $userId));

        case 'settings.category_delete':
            Auth::requirePermission('settings.manage');
            $categoryId = Validator::positiveInt($payload['id'] ?? 0, 'معرف التصنيف');
            Response::success('تم حذف التصنيف نهائيًا.', SettingsService::deleteReference('category', $categoryId, $userId));

        case 'settings.brands':
            Auth::requirePermission('settings.manage');
            Response::success('الماركات.', ['brands' => SettingsService::getBrands(false)]);

        case 'settings.brand_save':
            Auth::requirePermission('settings.manage');
            Response::success('تم حفظ الماركة.', SettingsService::saveBrand($payload, $userId));

        case 'settings.brand_delete':
            Auth::requirePermission('settings.manage');
            $brandId = Validator::positiveInt($payload['id'] ?? 0, 'معرف الماركة');
            Response::success('تم حذف الماركة نهائيًا.', SettingsService::deleteReference('brand', $brandId, $userId));

        case 'settings.branches':
            Auth::requirePermission('settings.manage');
            Response::success('الفروع.', ['branches' => SettingsService::getBranches()]);

        case 'settings.branch_save':
            Auth::requirePermission('settings.manage');
            Response::success('تم حفظ الفرع.', SettingsService::saveBranch($payload, $userId));

        case 'settings.branch_delete':
            Auth::requirePermission('settings.manage');
            $branchDeleteId = Validator::positiveInt($payload['id'] ?? 0, 'معرف الفرع');
            Response::success('تم حذف الفرع نهائيًا.', SettingsService::deleteReference('branch', $branchDeleteId, $userId));

        case 'settings.cash_registers':
            Auth::requirePermission('settings.manage');
            Response::success('صناديق الفرع.', ['registers' => SettingsService::getCashRegisters($branchId)]);

        case 'settings.cash_register_save':
            Auth::requirePermission('settings.manage');
            Response::success('تم حفظ الصندوق.', SettingsService::saveCashRegister($payload, $userId, $branchId));

        case 'settings.cash_register_delete':
            Auth::requirePermission('settings.manage');
            $registerId = Validator::positiveInt($payload['id'] ?? 0, 'معرف الصندوق');
            Response::success('تم حذف الصندوق نهائيًا.', SettingsService::deleteReference('cash_register', $registerId, $userId));

        case 'settings.expense_category_delete':
            Auth::requirePermission('settings.manage');
            $expenseCategoryId = Validator::positiveInt($payload['id'] ?? 0, 'معرف تصنيف المصروف');
            Response::success('تم حذف تصنيف المصروف نهائيًا.', SettingsService::deleteReference('expense_category', $expenseCategoryId, $userId));

        // ------------------------------------------------------------------
        // Backups
        // ------------------------------------------------------------------
        case 'backups.list':
            Auth::requirePermission('backups.manage');
            Response::success('قائمة النسخ الاحتياطية.', ['backups' => BackupService::listBackups(apiIntFilter('limit', 100))]);

        case 'backups.create':
            Auth::requirePermission('backups.manage');
            $location = Validator::enum($payload['storage_location'] ?? 'local', ['local', 'usb', 'external_disk'], 'موقع التخزين', 'local');
            $customDir = !empty($payload['custom_path']) ? (string)$payload['custom_path'] : null;
            $notes = isset($payload['notes']) ? (string)$payload['notes'] : null;
            Response::success('تم إنشاء النسخة الاحتياطية والتحقق من سلامتها بنجاح.', BackupService::createBackup($userId, 'manual', $location, $customDir, $notes));

        case 'backups.restore':
            Auth::requirePermission('backups.manage');
            $backupId = Validator::positiveInt($payload['backup_id'] ?? 0, 'معرف النسخة الاحتياطية');
            Response::success('تمت استعادة قاعدة البيانات بنجاح بعد أخذ نسخة وقائية.', BackupService::restoreBackup($backupId, $userId));

        case 'backups.delete':
            Auth::requirePermission('backups.manage');
            $backupId = Validator::positiveInt($payload['backup_id'] ?? 0, 'معرف النسخة الاحتياطية');
            Response::success('تم حذف النسخة الاحتياطية.', BackupService::deleteBackup($backupId, $userId));

        // ------------------------------------------------------------------
        // CSV import
        // ------------------------------------------------------------------
        case 'import.template':
            Auth::requirePermission('products.manage');
            Response::success('قالب استيراد المنتجات.', ['csv' => ImportService::templateCsv()]);

        case 'import.validate':
            Auth::requirePermission('products.manage');
            $rows = $payload['rows'] ?? null;
            if (!is_array($rows)) {
                throw new RuntimeException('صفوف الاستيراد غير صالحة.');
            }
            Response::success('نتيجة التحقق من ملف الاستيراد.', ImportService::validateProducts($rows));

        case 'import.products':
            Auth::requirePermission('products.manage');
            $rows = $payload['rows'] ?? null;
            if (!is_array($rows)) {
                throw new RuntimeException('صفوف الاستيراد غير صالحة.');
            }
            $createRefs = array_key_exists('create_missing', $payload) ? Validator::bool($payload['create_missing']) : true;
            Response::success('تمت عملية الاستيراد.', ImportService::importProducts($rows, $userId, $branchId, $createRefs));

        // ------------------------------------------------------------------
        // Pharmacy: units, dosage forms, storage and physical counts
        // ------------------------------------------------------------------
        case 'pharmacy.meta':
            Auth::requireAnyPermission(['products.view', 'inventory.view', 'pos.use', 'pharmacy.review']);
            Response::success('بيانات الصيدلية الأساسية.', [
                'dosage_forms' => PharmacyInventoryService::dosageForms(),
                'storage_conditions' => PharmacyInventoryService::storageConditions(),
                'expiry_windows' => array_values(array_filter(array_map('trim', explode(',', (string)SettingsService::getValue('expiry_alert_windows', '90,60,30'))), 'strlen')),
                'settings' => [
                    'block_expired_sales' => SettingsService::isEnabled('block_expired_sales', true),
                    'fefo_enabled' => SettingsService::isEnabled('fefo_enabled', true),
                    'require_pharmacist_dispense' => SettingsService::isEnabled('require_pharmacist_dispense', true),
                    'enable_interaction_alerts' => SettingsService::isEnabled('enable_interaction_alerts', true),
                    'enable_forecasting' => SettingsService::isEnabled('enable_forecasting', true),
                    'storage_alert_enabled' => SettingsService::isEnabled('storage_alert_enabled', true),
                ],
            ]);

        case 'pharmacy.storage_alerts':
            Auth::requireAnyPermission(['inventory.view', 'expiry.view', 'pharmacy.review']);
            Response::success('تنبيهات شروط التخزين.', ['alerts' => PharmacyInventoryService::storageAlerts($branchId)]);

        case 'product_units.list':
            Auth::requireAnyPermission(['products.view', 'pos.use']);
            Response::success('وحدات الدواء.', ['units' => ProductUnitService::listForProduct(
                Validator::positiveInt(apiFilter('product_id', 0), 'معرف الدواء')
            )]);

        case 'product_units.save':
            Auth::requirePermission('products.manage');
            $productId = Validator::positiveInt($payload['product_id'] ?? 0, 'معرف الدواء');
            $units = $payload['units'] ?? (isset($payload['unit_name']) ? [$payload] : []);
            if (!is_array($units)) {
                throw new RuntimeException('بيانات الوحدات غير صالحة.');
            }
            Response::success('تم حفظ وحدات الدواء بنجاح.', ProductUnitService::saveForProduct($productId, $units, $userId, $branchId));

        case 'product_units.resolve':
            Auth::requireAnyPermission(['products.view', 'pos.use']);
            Response::success('بيانات الوحدة.', ProductUnitService::resolve(
                Validator::positiveInt(apiFilter('product_id', 0), 'معرف الدواء'),
                Validator::positiveInt(apiFilter('unit_id', 0), 'معرف الوحدة')
            ));

        case 'stocktake.list':
            Auth::requirePermission('inventory.view');
            Response::success('جلسات الجرد.', ['sessions' => PharmacyInventoryService::listStocktakes($branchId)]);

        case 'stocktake.get':
            Auth::requirePermission('inventory.view');
            Response::success('بيانات جلسة الجرد.', PharmacyInventoryService::getStocktake(
                Validator::positiveInt(apiFilter('session_id', 0), 'معرف جلسة الجرد'), $branchId
            ));

        case 'stocktake.create':
            Auth::requirePermission('inventory.adjust');
            Response::success('تم إنشاء جلسة الجرد.', PharmacyInventoryService::createStocktake($payload, $userId, $branchId));

        case 'stocktake.count':
            Auth::requirePermission('inventory.adjust');
            Response::success('تم تسجيل العد الفعلي.', PharmacyInventoryService::recordStocktakeCounts(
                Validator::positiveInt($payload['session_id'] ?? 0, 'معرف جلسة الجرد'),
                $payload,
                $userId,
                $branchId
            ));

        case 'stocktake.approve':
            Auth::requirePermission('stocktake.approve');
            Response::success('تم اعتماد جلسة الجرد وتطبيق الفروق.', PharmacyInventoryService::approveStocktake(
                Validator::positiveInt($payload['session_id'] ?? 0, 'معرف جلسة الجرد'), $userId, $branchId
            ));

        case 'damage.list':
            Auth::requirePermission('inventory.view');
            Response::success('سجل الأدوية التالفة.', ['records' => PharmacyInventoryService::listDamage($branchId, apiIntFilter('limit', 200))]);

        case 'damage.create':
            Auth::requirePermission('damage.manage');
            Response::success('تم تسجيل الدواء التالف.', PharmacyInventoryService::createDamage($payload, $userId, $branchId));

        case 'damage.status':
            Auth::requirePermission('damage.manage');
            Response::success('تم تحديث حالة سجل التلف.', PharmacyInventoryService::updateDamageStatus(
                Validator::positiveInt($payload['record_id'] ?? 0, 'معرف سجل التلف'),
                Validator::requireNonEmpty($payload['status'] ?? '', 'الحالة', 50),
                $userId
            ));

        // ------------------------------------------------------------------
        // Pharmacy: purchase price history and supply orders
        // ------------------------------------------------------------------
        case 'prices.history':
            Auth::requireAnyPermission(['purchases.manage', 'products.view_cost', 'products.manage']);
            Response::success('سجل أسعار الشراء.', ['history' => PriceHistoryService::history(
                Validator::positiveInt(apiFilter('product_id', 0), 'معرف الدواء'),
                $branchId,
                apiIntFilter('limit', 100)
            )]);

        case 'prices.compare_suppliers':
            Auth::requireAnyPermission(['purchases.manage', 'products.view_cost', 'products.manage']);
            Response::success('مقارنة أسعار الموردين.', ['suppliers' => PriceHistoryService::compareSuppliers(
                Validator::positiveInt(apiFilter('product_id', 0), 'معرف الدواء'),
                $branchId,
                apiIntFilter('days', 365)
            )]);

        case 'purchase_orders.list':
            Auth::requireAnyPermission(['purchase_orders.manage', 'purchases.manage', 'inventory.view']);
            Response::success('طلبيات التوريد.', ['orders' => PurchaseOrderService::listOrders($branchId, apiIntFilter('limit', 200))]);

        case 'purchase_orders.get':
            Auth::requireAnyPermission(['purchase_orders.manage', 'purchases.manage']);
            Response::success('بيانات طلب التوريد.', PurchaseOrderService::getOrder(
                Validator::positiveInt(apiFilter('order_id', 0), 'معرف طلب التوريد'), $branchId
            ));

        case 'purchase_orders.create':
            Auth::requirePermission('purchase_orders.manage');
            Response::success('تم إنشاء طلب التوريد.', PurchaseOrderService::createOrder($payload, $userId, $branchId));

        case 'purchase_orders.status':
            Auth::requirePermission('purchase_orders.manage');
            Response::success('تم تحديث حالة طلب التوريد.', PurchaseOrderService::updateOrderStatus(
                Validator::positiveInt($payload['order_id'] ?? 0, 'معرف طلب التوريد'),
                Validator::requireNonEmpty($payload['status'] ?? '', 'الحالة', 50),
                $userId,
                $branchId
            ));

        case 'purchase_orders.receive':
            Auth::requirePermission('purchase_orders.manage');
            $receivedLines = $payload['items'] ?? $payload['lines'] ?? $payload;
            if (!is_array($receivedLines)) {
                throw new RuntimeException('بيانات الاستلام غير صالحة.');
            }
            Response::success('تم تسجيل الاستلام.', PurchaseOrderService::receiveOrder(
                Validator::positiveInt($payload['order_id'] ?? 0, 'معرف طلب التوريد'),
                $receivedLines,
                $userId,
                $branchId
            ));

        case 'supplier_alerts.list':
            Auth::requireAnyPermission(['purchases.manage', 'suppliers.manage', 'dashboard.view']);
            Response::success('تنبيهات الموردين.', ['alerts' => SupplierAlertService::alerts($branchId)]);

        case 'supplier_alerts.summary':
            Auth::requireAnyPermission(['purchases.manage', 'suppliers.manage', 'dashboard.view']);
            Response::success('ملخص مستحقات الموردين.', SupplierAlertService::summary($branchId));

        // ------------------------------------------------------------------
        // Pharmacy: patients, prescription review, OCR and interactions
        // ------------------------------------------------------------------
        case 'patients.search':
            Auth::requireAnyPermission(['patients.manage', 'customers.manage', 'prescriptions.manage', 'pos.use']);
            Response::success('نتائج بحث المرضى.', ['patients' => PatientRecordService::searchPatients(
                (string)apiFilter('q', ''), apiIntFilter('limit', 50)
            )]);

        case 'patients.file':
            Auth::requireAnyPermission(['patients.manage', 'customers.manage', 'prescriptions.manage']);
            Response::success('ملف المريض.', PatientRecordService::patientFile(
                Validator::positiveInt(apiFilter('patient_id', 0), 'معرف المريض'), $branchId
            ));

        case 'patients.medication_history':
            Auth::requireAnyPermission(['patients.manage', 'customers.manage', 'prescriptions.manage']);
            Response::success('سجل الأدوية المصروفة للمريض.', ['history' => PatientRecordService::medicationHistory(
                Validator::positiveInt(apiFilter('patient_id', 0), 'معرف المريض'),
                $branchId,
                apiIntFilter('limit', 200)
            )]);

        case 'patients.update_medical':
            Auth::requirePermission('patients.manage');
            $patientId = Validator::positiveInt($payload['patient_id'] ?? 0, 'معرف المريض');
            Response::success('تم تحديث الملف الطبي للمريض.', PatientRecordService::updatePatientMedical($patientId, $payload, $userId, $branchId));

        case 'prescriptions.review':
            Auth::requireAnyPermission(['pharmacy.review', 'prescriptions.manage', 'prescriptions.dispense']);
            Response::success('مراجعة الروشتة.', PrescriptionReviewService::review(
                Validator::positiveInt(apiFilter('prescription_id', 0), 'معرف الروشتة'), $branchId
            ));

        case 'prescriptions.pos_summary':
            Auth::requireAnyPermission(['pos.use', 'pharmacy.review', 'prescriptions.manage']);
            Response::success('ملخص الروشتة للصرف.', PrescriptionReviewService::summaryForPos(
                Validator::positiveInt(apiFilter('prescription_id', 0), 'معرف الروشتة'), $branchId
            ));

        case 'prescriptions.record_review':
            Auth::requireAnyPermission(['pharmacy.review', 'prescriptions.dispense']);
            Response::success('تم تسجيل المراجعة الصيدلانية.', PrescriptionService::recordReview(
                Validator::positiveInt($payload['prescription_id'] ?? 0, 'معرف الروشتة'),
                (string)($payload['review_notes'] ?? ''),
                $userId
            ));

        case 'prescriptions.image_upload':
            Auth::requirePermission('prescriptions.manage');
            $prescriptionId = Validator::positiveInt($payload['prescription_id'] ?? 0, 'معرف الروشتة');
            $imagePath = apiSavePrescriptionImage($_FILES['image'] ?? []);
            Response::success('تم رفع صورة الروشتة.', PrescriptionService::attachImage($prescriptionId, $imagePath, $userId));

        case 'prescriptions.ocr':
            Auth::requireAnyPermission(['prescriptions.manage', 'pharmacy.review']);
            Response::success('نتيجة قراءة صورة الروشتة.', PrescriptionOcrService::parseImage($_FILES['image'] ?? []));

        case 'prescriptions.ocr_available':
            Auth::requireAnyPermission(['prescriptions.manage', 'pharmacy.review']);
            Response::success('حالة محرك القراءة.', ['available' => PrescriptionOcrService::isAvailable()]);

        case 'interactions.list':
            Auth::requireAnyPermission(['pharmacy.review', 'prescriptions.manage', 'products.view']);
            Response::success('قواعد التداخلات الدوائية.', ['interactions' => DrugInteractionService::listInteractions()]);

        case 'interactions.check':
            Auth::requireAnyPermission(['pharmacy.review', 'prescriptions.manage', 'pos.use']);
            $productIds = $payload['product_ids'] ?? [];
            if (!is_array($productIds)) {
                $productIds = [];
            }
            Response::success('تحذيرات التداخل الدوائي.', ['warnings' => DrugInteractionService::check($productIds)]);

        // ------------------------------------------------------------------
        // Pharmacy: forecasting and per-user permissions
        // ------------------------------------------------------------------
        case 'pharmacy.forecast':
            Auth::requireAnyPermission(['forecasting.view', 'reports.view', 'inventory.view']);
            Response::success('توقع نقص الأدوية.', ['items' => DemandForecastService::forecast(
                $branchId, apiIntFilter('days', 30)
            )]);

        case 'pharmacy.slow_moving':
            Auth::requireAnyPermission(['forecasting.view', 'reports.view', 'inventory.view']);
            Response::success('الأدوية بطيئة الحركة.', ['items' => DemandForecastService::slowMoving(
                $branchId, apiIntFilter('days', 90)
            )]);

        case 'pharmacy.best_selling':
            Auth::requireAnyPermission(['forecasting.view', 'reports.view', 'inventory.view']);
            Response::success('الأدوية الأكثر مبيعًا.', ['items' => DemandForecastService::bestSelling(
                $branchId, apiIntFilter('days', 30), apiIntFilter('limit', 20)
            )]);

        case 'users.permissions_get':
            Auth::requirePermission('users.manage');
            Response::success('صلاحيات المستخدم.', UserService::getUserPermissions(
                Validator::positiveInt(apiFilter('user_id', 0), 'معرف المستخدم'), $userId
            ));

        case 'users.permissions_save':
            Auth::requirePermission('users.manage');
            $targetUserId = Validator::positiveInt($payload['user_id'] ?? 0, 'معرف المستخدم');
            $overrides = $payload['permissions'] ?? [];
            if (!is_array($overrides)) {
                throw new RuntimeException('بيانات الصلاحيات غير صالحة.');
            }
            $result = UserService::updateUserPermissions($targetUserId, $overrides, $userId);
            if ($targetUserId === $userId) {
                Auth::refreshPermissions($userId);
            }
            Response::success('تم تحديث صلاحيات المستخدم وتسريها على الجلسات المفتوحة.', $result);

        default:
            Response::error('العملية المطلوبة غير معرفة: ' . $action, 404);
    }
} catch (\InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    $message = $e->getMessage();
    $status = 400;
    if (strpos($message, 'تسجيل الدخول') !== false) {
        $status = 401;
    } elseif (strpos($message, 'صلاحية') !== false || strpos($message, 'فرع آخر') !== false) {
        $status = 403;
    } elseif (strpos($message, 'غير موجود') !== false) {
        $status = 404;
    }
    Response::error($message, $status);
} catch (\Throwable $e) {
    $config = require dirname(__DIR__) . '/app/Config/config.php';
    if (($config['app']['env'] ?? 'production') !== 'production') {
        Response::error('خطأ غير متوقع: ' . $e->getMessage(), 500);
    }
    error_log('LEVI POS API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::error('حدث خطأ غير متوقع في الخادم. يرجى المحاولة مرة أخرى أو مراجعة السجل.', 500);
}
