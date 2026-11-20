<?php
declare(strict_types=1);

/**
 * LEVI POS - Main Application Shell
 * RTL, offline-first, permission-aware single page interface.
 * PHP 8.0 compatible.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Core/Database.php';
require_once dirname(__DIR__) . '/app/Core/Session.php';
require_once dirname(__DIR__) . '/app/Core/CSRF.php';
require_once dirname(__DIR__) . '/app/Core/Auth.php';

use App\Core\Auth;
use App\Core\CSRF;
use App\Core\Database;
use App\Core\Session;

Session::start();
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$csrf = CSRF::generateToken();
$branchId = (int)$user['branch_id'];
$isOwner = Auth::isOwner();
$pdo = Database::getConnection();
$settings = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$storeName = $settings['store_name'] ?? 'LEVI POS';
$logoPath = (string)($settings['store_logo'] ?? '');
$logoUrl = levi_logo_url($logoPath);
$hasCustomLogo = levi_has_custom_logo($logoPath);
$clockOffset = (int)($settings['clock_offset_seconds'] ?? 0);
$timezoneName = (string)($settings['timezone'] ?? ($GLOBALS['LEVI_CONFIG']['app']['timezone'] ?? 'Africa/Tripoli'));
$dateFormat = (string)($settings['date_format'] ?? 'Y-m-d H:i');

// Create at most one automatic backup per day when the setting is enabled.
\App\Services\BackupService::maybeRunAutomaticBackup((int)$user['id']);
$activeSession = $pdo->query("
    SELECT s.*, r.name AS register_name
    FROM cash_register_sessions s
    JOIN cash_registers r ON r.id = s.cash_register_id
    WHERE s.status = 'open' AND r.branch_id = " . $branchId . "
      AND s.user_id = " . (int)$user['id'] . "
    ORDER BY s.id DESC LIMIT 1
")->fetch();

$permissions = [
    'dashboard' => Auth::hasPermission('dashboard.view'),
    'pos' => Auth::hasPermission('pos.use'),
    'products' => Auth::hasPermission('products.view'),
    'products_manage' => Auth::hasPermission('products.manage'),
    'products_cost' => Auth::hasPermission('products.view_cost'),
    'inventory' => Auth::hasPermission('inventory.view'),
    'inventory_adjust' => Auth::hasPermission('inventory.adjust'),
    'purchases' => Auth::hasPermission('purchases.manage'),
    'suppliers' => Auth::hasPermission('suppliers.manage'),
    'customers' => Auth::hasPermission('customers.manage'),
    'invoices' => Auth::hasPermission('invoices.view'),
    'returns' => Auth::hasPermission('returns.create'),
    'cancel_invoice' => Auth::hasPermission('pos.cancel_invoice'),
    'expenses' => Auth::hasPermission('expenses.manage'),
    'cash_register' => Auth::hasPermission('cash_register.manage') || Auth::hasPermission('pos.use'),
    'cash_close' => Auth::hasPermission('cash_register.close'),
    'cash_register_manage' => Auth::hasPermission('cash_register.manage'),
    'batches' => Auth::hasPermission('batches.manage') || Auth::hasPermission('inventory.view'),
    'batches_manage' => Auth::hasPermission('batches.manage'),
    'expiry' => Auth::hasPermission('expiry.view') || Auth::hasPermission('batches.manage') || Auth::hasPermission('inventory.view'),
    'doctors' => Auth::hasPermission('doctors.manage') || Auth::hasPermission('prescriptions.manage'),
    'doctors_manage' => Auth::hasPermission('doctors.manage'),
    'prescriptions' => Auth::hasPermission('prescriptions.manage') || Auth::hasPermission('pos.use'),
    'prescriptions_manage' => Auth::hasPermission('prescriptions.manage'),
    'pharmacy' => Auth::hasPermission('inventory.view') || Auth::hasPermission('pharmacy.review')
        || Auth::hasPermission('patients.manage') || Auth::hasPermission('purchases.manage'),
    'pharmacy_review' => Auth::hasPermission('pharmacy.review') || Auth::hasPermission('prescriptions.dispense')
        || Auth::hasPermission('prescriptions.manage'),
    'pharmacy_stocktake' => Auth::hasPermission('inventory.adjust') || Auth::hasPermission('stocktake.approve'),
    'pharmacy_damage' => Auth::hasPermission('damage.manage'),
    'pharmacy_orders' => Auth::hasPermission('purchase_orders.manage') || Auth::hasPermission('purchases.manage'),
    'reports' => Auth::hasPermission('reports.view') || Auth::hasPermission('reports.view_financial'),
    'reports_view_financial' => Auth::hasPermission('reports.view_financial'),
    'reports_export' => Auth::hasPermission('reports.export'),
    'audit' => Auth::hasPermission('audit.view') || Auth::hasPermission('users.manage'),
    'pos_resume_held' => Auth::hasPermission('pos.resume_held'),
    'users' => Auth::hasPermission('users.manage'),
    'backups' => Auth::hasPermission('backups.manage'),
    'settings' => Auth::hasPermission('settings.manage'),
    'store_identity' => Auth::hasPermission('settings.store_identity'),
];
$avatarInitials = mb_strtoupper(mb_substr((string)$user['username'], 0, 2, 'UTF-8'), 'UTF-8');
if ($avatarInitials === '') {
    $avatarInitials = '؟';
}
$topbarRole = (string)$user['role_display_ar'];
if ($topbarRole === (string)$user['full_name'] || $topbarRole === '') {
    $topbarRole = 'مدير الصيدلية';
}

/* Shared page-hero renderer for the pgx- design system. */
$pgxHeroIcons = [
    'products' => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/>',
    'inventory' => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
    'purchases' => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.5 12h11l2-8H6"/><path d="M12 7v6"/><path d="M9.5 9.5 12 12l2.5-2.5"/>',
    'suppliers' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/>',
    'customers' => '<path d="M16 20v-1.6a3.4 3.4 0 0 0-3.4-3.4H6.4A3.4 3.4 0 0 0 3 18.4V20"/><circle cx="9.5" cy="7.5" r="3.5"/><path d="M21 20v-1.6a3.4 3.4 0 0 0-2.6-3.3"/><path d="M15.5 4.2a3.5 3.5 0 0 1 0 6.6"/>',
    'returns' => '<path d="M3 8h12a5 5 0 0 1 0 10H9"/><path d="m7 4-4 4 4 4"/>',
    'held' => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
    'expenses' => '<path d="M3 7a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M3 10h18"/><path d="M16 15h2"/>',
    'cash' => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 10v4M18 10v4"/>',
    'batches' => '<rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><path d="M7 7h.01M7 17h.01"/>',
    'expiry' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2"/>',
    'doctors' => '<path d="M16 20v-1.6a3.4 3.4 0 0 0-3.4-3.4H6.4A3.4 3.4 0 0 0 3 18.4V20"/><circle cx="9.5" cy="7.5" r="3.5"/><path d="M21 20v-1.6a3.4 3.4 0 0 0-2.6-3.3"/><path d="M15.5 4.2a3.5 3.5 0 0 1 0 6.6"/>',
    'prescriptions' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
    'pharmacy' => '<path d="M12 3 4 6.5v5c0 4.6 3.2 8.3 8 9.5 4.8-1.2 8-4.9 8-9.5v-5Z"/><path d="M9 12h6M12 9v6"/>',
    'reports' => '<path d="M3 21h18"/><rect x="5" y="12" width="3.5" height="6" rx="1"/><rect x="10.5" y="7" width="3.5" height="11" rx="1"/><rect x="16" y="10" width="3.5" height="8" rx="1"/>',
    'users' => '<path d="M16 20v-1.6a3.4 3.4 0 0 0-3.4-3.4H6.4A3.4 3.4 0 0 0 3 18.4V20"/><circle cx="9.5" cy="7.5" r="3.5"/><path d="M21 20v-1.6a3.4 3.4 0 0 0-2.6-3.3"/><path d="M15.5 4.2a3.5 3.5 0 0 1 0 6.6"/>',
    'audit' => '<path d="M9 4h9a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M9 2h5v4H9z"/><path d="M9 11h7M9 15h7M9 19h4"/>',
    'backups' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
];
$pgxRenderHero = static function (string $title, string $desc, string $icon, string $meta = '', string $actions = '', bool $withBeat = false) use ($pgxHeroIcons): void {
    ?>
                    <section class="pgx-hero">
                        <?php if ($withBeat): ?>
                        <svg class="pgx-hero-beat" viewBox="0 0 600 92" preserveAspectRatio="none" aria-hidden="true">
                            <path vector-effect="non-scaling-stroke" d="M0 52 H40 L48 48 L56 56 L64 26 L74 72 L82 48 L90 52 H150 L158 48 L166 56 L174 26 L184 72 L192 48 L200 52 H260 L268 48 L276 56 L284 26 L294 72 L302 48 L310 52 H370 L378 48 L386 56 L394 26 L404 72 L412 48 L420 52 H480 L488 48 L496 56 L504 26 L514 72 L522 48 L530 52 H600"/>
                            <circle class="peak" vector-effect="non-scaling-stroke" cx="64" cy="26" r="2.6"/>
                            <circle class="peak" vector-effect="non-scaling-stroke" cx="174" cy="26" r="2.6"/>
                            <circle class="peak" vector-effect="non-scaling-stroke" cx="284" cy="26" r="2.6"/>
                            <circle class="peak" vector-effect="non-scaling-stroke" cx="394" cy="26" r="2.6"/>
                            <circle class="peak" vector-effect="non-scaling-stroke" cx="504" cy="26" r="2.6"/>
                        </svg>
                        <?php endif; ?>
                        <div class="pgx-hero-main">
                            <div class="pgx-crumbs">
                                <span>الرئيسية</span><i>›</i><span><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <h1>
                                <svg class="pgx-title-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons[$icon] ?? $pgxHeroIcons['products'] ?></svg>
                                <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
                            </h1>
                            <p><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="pgx-meta">
                                <span class="pgx-meta-item is-live">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                    بيانات مباشرة
                                </span>
                                <?= $meta ?>
                            </div>
                        </div>
                        <?php if ($actions !== ''): ?>
                        <div class="pgx-hero-actions">
                            <div class="pgx-actions"><?= $actions ?></div>
                        </div>
                        <?php endif; ?>
                    </section>
    <?php
};

// Safe flags for embedding JSON inside an inline <script> block.
$bootJsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/bootstrap.rtl.min.css') ?>">
    <link rel="stylesheet" href="assets/css/levi-pos.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/levi-pos.css') ?>">
    <script>
        window.LEVI_BOOT = {
            csrf: <?= json_encode($csrf, $bootJsonFlags) ?>,
            user: <?= json_encode($user, $bootJsonFlags) ?>,
            branchId: <?= $branchId ?>,
            isOwner: <?= $isOwner ? 'true' : 'false' ?>,
            storeName: <?= json_encode($storeName, $bootJsonFlags) ?>,
            logoUrl: <?= json_encode($logoUrl, $bootJsonFlags) ?>,
            hasCustomLogo: <?= $hasCustomLogo ? 'true' : 'false' ?>,
            baseCurrency: <?= json_encode($settings['base_currency'] ?? 'LYD', $bootJsonFlags) ?>,
            permissions: <?= json_encode($permissions, $bootJsonFlags) ?>,
            allowNegativeStock: <?= ((int)($settings['allow_negative_stock_sale'] ?? 0) === 1 || $isOwner || $user['role_name'] === 'admin') ? 'true' : 'false' ?>,
            allowDiscounts: <?= ((int)($settings['allow_discounts'] ?? 1) === 1) ? 'true' : 'false' ?>,
            enableCreditSales: <?= ((int)($settings['enable_credit_sales'] ?? 1) === 1) ? 'true' : 'false' ?>,
            enablePartialPayment: <?= ((int)($settings['enable_partial_payment'] ?? 1) === 1) ? 'true' : 'false' ?>,
            expiryAlertDays: <?= (int)($settings['expiry_alert_days'] ?? 30) ?>,
            blockExpiredSales: <?= ((int)($settings['block_expired_sales'] ?? 1) === 1) ? 'true' : 'false' ?>,
            fefoEnabled: <?= ((int)($settings['fefo_enabled'] ?? 1) === 1) ? 'true' : 'false' ?>,
            requirePrescription: <?= ((int)($settings['require_prescription_for_flagged'] ?? 0) === 1) ? 'true' : 'false' ?>,
            enableLowStockAlerts: <?= ((int)($settings['enable_low_stock_alerts'] ?? 1) === 1) ? 'true' : 'false' ?>,
            allowStockAdjustments: <?= ((int)($settings['allow_stock_adjustments'] ?? 1) === 1) ? 'true' : 'false' ?>,
            autoPrintReceipt: <?= ((int)($settings['auto_print_receipt'] ?? 1) === 1) ? 'true' : 'false' ?>,
            defaultMinStock: <?= (int)($settings['default_min_stock'] ?? 5) ?>,
            requireOpenRegister: <?= ((int)($settings['require_open_register'] ?? 1) === 1) ? 'true' : 'false' ?>,
            enforceShiftClosing: <?= ((int)($settings['enable_cash_register_closing'] ?? 1) === 1) ? 'true' : 'false' ?>,
            taxEnabled: <?= ((int)($settings['enable_tax'] ?? 0) === 1) ? 'true' : 'false' ?>,
            taxRate: <?= json_encode((float)($settings['tax_rate'] ?? 0), $bootJsonFlags) ?>,
            taxInclusive: <?= ((int)($settings['tax_inclusive'] ?? 0) === 1) ? 'true' : 'false' ?>,
            taxNumber: <?= json_encode((string)($settings['tax_number'] ?? ''), $bootJsonFlags) ?>,
            timezone: <?= json_encode($timezoneName, $bootJsonFlags) ?>,
            dateFormat: <?= json_encode($dateFormat, $bootJsonFlags) ?>,
            clockOffset: <?= $clockOffset ?>,
            serverNowMs: <?= (int)((time() + $clockOffset) * 1000) ?>
        };
    </script>
</head>
<body>
<script>
    (function () {
        try {
            var saved = localStorage.getItem('levi-theme');
            if (saved !== 'dark' && saved !== 'light') {
                saved = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.body.dataset.theme = saved;
            if (localStorage.getItem('levi.sidebarCollapsed') === '1') {
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (e) {
            document.body.dataset.theme = 'light';
        }
    })();
</script>
<?php
$navEmojis = [
    'dashboard' => '🏠',
    'pos' => '🧾',
    'products' => '💊',
    'inventory' => '📦',
    'purchases' => '🛒',
    'suppliers' => '🏢',
    'customers' => '👥',
    'users' => '🛡️',
    'doctors' => '🩺',
    'invoices' => '📄',
    'returns' => '↩️',
    'held' => '📌',
    'expenses' => '💸',
    'cash' => '💰',
    'batches' => '🧪',
    'expiry' => '⏳',
    'prescriptions' => '📋',
    'pharmacy' => '⚕️',
    'reports' => '📈',
    'audit' => '🔍',
    'backups' => '💾',
    'settings' => '⚙️',
];
$navGroups = [
    [
        'label' => 'العمليات',
        'items' => [
            ['target' => 'dashboard', 'perm' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => 'dashboard', 'active' => true],
            ['target' => 'pos', 'perm' => 'pos', 'label' => 'نقطة البيع', 'icon' => 'pos'],
        ],
    ],
    [
        'label' => 'الأدوية والمخزون',
        'items' => [
            ['target' => 'products', 'perm' => 'products', 'label' => 'الأدوية والمنتجات', 'icon' => 'products'],
            ['target' => 'inventory', 'perm' => 'inventory', 'label' => 'المخزون والجرد', 'icon' => 'inventory', 'badge' => 'sidebar-badge-inventory', 'tone' => 'red'],
            ['target' => 'batches', 'perm' => 'batches', 'label' => 'دفعات الأدوية', 'icon' => 'batches'],
            ['target' => 'expiry', 'perm' => 'expiry', 'label' => 'انتهاء الصلاحية', 'icon' => 'expiry', 'badge' => 'sidebar-badge-expiry', 'tone' => 'red'],
            ['target' => 'purchases', 'perm' => 'purchases', 'label' => 'المشتريات', 'icon' => 'purchases'],
            ['target' => 'suppliers', 'perm' => 'suppliers', 'label' => 'شركات الأدوية والموردون', 'icon' => 'suppliers'],
        ],
    ],
    [
        'label' => 'المرضى والمبيعات',
        'items' => [
            ['target' => 'customers', 'perm' => 'customers', 'label' => 'المرضى والعملاء', 'icon' => 'customers'],
            ['target' => 'doctors', 'perm' => 'doctors', 'label' => 'الأطباء', 'icon' => 'doctors'],
            ['target' => 'prescriptions', 'perm' => 'prescriptions', 'label' => 'الروشتات', 'icon' => 'prescriptions'],
            ['target' => 'pharmacy', 'perm' => 'pharmacy', 'label' => 'الصيدلية المتقدمة', 'icon' => 'pharmacy'],
            ['target' => 'invoices', 'perm' => 'invoices', 'label' => 'الفواتير', 'icon' => 'invoices'],
            ['target' => 'returns', 'perm' => 'returns', 'label' => 'المرتجعات', 'icon' => 'returns'],
            ['target' => 'held', 'perm' => 'pos', 'label' => 'الفواتير المعلقة', 'icon' => 'held', 'badge' => 'sidebar-badge-held', 'tone' => 'amber'],
        ],
    ],
    [
        'label' => 'المالية',
        'items' => [
            ['target' => 'cash_register', 'perm' => 'cash_register', 'label' => 'الصندوق والورديات', 'icon' => 'cash'],
            ['target' => 'expenses', 'perm' => 'expenses', 'label' => 'المصروفات', 'icon' => 'expenses'],
        ],
    ],
    [
        'label' => 'التحليلات والإدارة',
        'items' => [
            ['target' => 'reports', 'perm' => 'reports', 'label' => 'التقارير', 'icon' => 'reports'],
            ['target' => 'users', 'perm' => 'users', 'label' => 'المستخدمون والصلاحيات', 'icon' => 'users'],
            ['target' => 'audit', 'perm' => 'audit', 'label' => 'سجل التدقيق', 'icon' => 'audit'],
            ['target' => 'backups', 'perm' => 'backups', 'label' => 'النسخ الاحتياطي', 'icon' => 'backups'],
            ['target' => 'settings', 'perm' => 'settings', 'label' => 'الإعدادات', 'icon' => 'settings'],
        ],
    ],
];
$navDescriptions = [
    'dashboard' => 'نظرة شاملة على الجاهزية، المبيعات، والتنبيهات الحرجة.',
    'pos' => 'ابدأ فاتورة جديدة وأدر الوردية والمدفوعات.',
    'products' => 'الاسم التجاري، المادة الفعالة، والوحدات الصيدلانية.',
    'inventory' => 'الأصناف والنقاط الحرجة والجرد الدوري.',
    'batches' => 'تتبّع دفعات الأدوية وأرقامها وتواريخ الإنتاج.',
    'expiry' => 'الدفعات القريبة من الانتهاء والتنبيهات.',
    'purchases' => 'أوامر الشراء والاستلام من الموردين.',
    'suppliers' => 'شركات الأدوية والموردون وعقودهم.',
    'customers' => 'ملفات المرضى وسجل المشتريات والروشتات.',
    'doctors' => 'أطباء الوصفات وتخصصاتهم.',
    'prescriptions' => 'مراجعة الروشتات وصرفها بعد تحقق الصيدلي.',
    'pharmacy' => 'الجرد والتنبؤات والتداخلات الدوائية.',
    'invoices' => 'الفواتير وعمليات البيع والتحصيل.',
    'returns' => 'المرتجعات وإعادة الكميات إلى المخزون.',
    'held' => 'الفواتير المعلقة التي يمكن استئنافها.',
    'cash_register' => 'النقد المتوقع والحركات وفتح الوردية وإغلاقها.',
    'expenses' => 'المصروفات اليومية وتصنيفاتها.',
    'reports' => 'المبيعات وحركة الأدوية والتنبؤ بنقص المخزون.',
    'users' => 'المستخدمون والصلاحيات وتجاوزاتها.',
    'audit' => 'سجل التدقيق والأحداث.',
    'backups' => 'النسخ الاحتياطي واستعادة البيانات.',
    'settings' => 'هوية الصيدلية والتنبيهات وسياسات الصرف.',
];
?>
<div class="app-layout" id="app-layout">
    <aside class="sidebar" id="app-sidebar" aria-label="القائمة الرئيسية">
        <div class="sidebar-brand">
            <div class="appsb-logo">
                <img class="sidebar-logo" src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="طي القائمة" aria-expanded="true" title="طي القائمة">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        </button>

        <nav class="sidebar-nav" aria-label="صفحات النظام">
            <?php
            $renderNavItem = static function (array $item, bool $drawer = false) use ($navEmojis, $navDescriptions): void {
                $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
                $target = htmlspecialchars($item['target'], ENT_QUOTES, 'UTF-8');
                $perm = htmlspecialchars($item['perm'], ENT_QUOTES, 'UTF-8');
                $desc = htmlspecialchars($navDescriptions[$item['target']] ?? '', ENT_QUOTES, 'UTF-8');
                $badge = '';
                if (!empty($item['badge'])) {
                    $tone = !empty($item['tone']) ? ' is-' . htmlspecialchars($item['tone'], ENT_QUOTES, 'UTF-8') : '';
                    $badgeId = htmlspecialchars($item['badge'], ENT_QUOTES, 'UTF-8');
                    $badge = $drawer
                        ? '<span class="appsb-badge drawer-badge' . $tone . '" data-badge-for="' . $badgeId . '" hidden></span>'
                        : '<span class="appsb-badge' . $tone . '" id="' . $badgeId . '" hidden></span>';
                }
                $linkClass = $drawer ? 'drawer-link' : 'sidebar-link appsb-link';
                ?>
                <li>
                    <button type="button"
                            class="<?= $linkClass ?><?= !empty($item['active']) ? ' active' : '' ?>"
                            aria-label="<?= $label ?>"
                            data-nav-target="<?= $target ?>"
                            data-perm="<?= $perm ?>"
                            data-tip="<?= $label ?>"
                            data-desc="<?= $desc ?>">
                        <span class="appsb-ic" aria-hidden="true"><?= $navEmojis[$item['icon']] ?? '' ?></span>
                        <span class="drawer-link-label"><?= $label ?></span>
                        <?= $badge ?>
                    </button>
                </li>
                <?php
            };
            $renderNavGroup = static function (array $group, bool $drawer = false) use ($renderNavItem): void {
                if (empty($group['items'])) return;
                ?>
                <div class="appsb-group<?= $drawer ? ' drawer-group' : '' ?>">
                    <div class="appsb-group-label"><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></div>
                    <ul class="appsb-list">
                        <?php foreach ($group['items'] as $item): ?>
                            <?php $renderNavItem($item, $drawer); ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php
            };
            $pinnedTop = ['label' => 'العمليات', 'items' => []];
            $pinnedBottom = ['label' => 'الإدارة', 'items' => []];
            $scrollingGroups = [];
            foreach ($navGroups as $group) {
                $normal = ['label' => $group['label'], 'items' => []];
                foreach ($group['items'] as $item) {
                    if ($item['target'] === 'dashboard' || $item['target'] === 'pos') {
                        $pinnedTop['items'][] = $item;
                    } elseif ($item['target'] === 'settings') {
                        $pinnedBottom['items'][] = $item;
                    } else {
                        $normal['items'][] = $item;
                    }
                }
                if ($normal['items']) $scrollingGroups[] = $normal;
            }
            ?>
            <div class="appsb-pin appsb-pin-top">
                <?php $renderNavGroup($pinnedTop); ?>
            </div>

            <div class="appsb-scroll-wrap">
                <div class="appsb-scroll" id="sidebar-scroll">
                    <?php foreach ($scrollingGroups as $group): ?>
                        <?php $renderNavGroup($group); ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="appsb-pin appsb-pin-bottom">
                <?php $renderNavGroup($pinnedBottom); ?>
            </div>
        </nav>
    </aside>

    <div class="nav-drawer-backdrop" id="nav-drawer-backdrop" hidden></div>
    <aside class="nav-drawer" id="nav-drawer" aria-label="قائمة الصفحات" aria-hidden="true">
        <div class="nav-drawer-head">
            <div class="nav-drawer-logo">
                <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="nav-drawer-head-text">
                <strong>قائمة التنقل</strong>
                <small>ابحث أو اختر قسمًا</small>
            </div>
            <button type="button" class="nav-drawer-close" id="nav-drawer-close" aria-label="إغلاق القائمة" title="إغلاق القائمة">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <label class="nav-drawer-search" for="nav-drawer-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
            <input type="search" id="nav-drawer-search" placeholder="ابحث عن صفحة…" autocomplete="off">
        </label>
        <nav class="nav-drawer-nav" aria-label="صفحات النظام">
            <?php foreach ($navGroups as $group): ?>
                <?php $renderNavGroup($group, true); ?>
            <?php endforeach; ?>
        </nav>
        <div class="nav-drawer-foot">
            <span class="nav-drawer-avatar" aria-hidden="true"><?= htmlspecialchars($avatarInitials, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="nav-drawer-user">
                <b><?= htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') ?></b>
                <small><?= htmlspecialchars($topbarRole, ENT_QUOTES, 'UTF-8') ?></small>
            </span>
            <button type="button" class="nav-drawer-logout" onclick="LeviPOS.logout()" aria-label="تسجيل الخروج" title="تسجيل الخروج">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg>
            </button>
        </div>
    </aside>

    <div class="main-wrapper">
        <header class="topbar topbar-care <?= $activeSession ? 'has-session' : 'no-session' ?>">
            <div class="topbar-id">
                <button type="button" id="nav-drawer-open" class="nav-drawer-open" aria-label="فتح القائمة" aria-expanded="false" aria-controls="nav-drawer" title="فتح القائمة">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
                <div class="topbar-care-brand">
                    <span class="topbar-care-brand-text">
                        <strong class="topbar-store"><?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?></strong>
                        <small><?= htmlspecialchars((string)$user['branch_name'], ENT_QUOTES, 'UTF-8') ?></small>
                    </span>
                </div>
                <span class="topbar-pill topbar-branch" title="الفرع الحالي">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>
                    <?= htmlspecialchars((string)$user['branch_name'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span id="topbar-session-badge" class="topbar-pill topbar-session <?= $activeSession ? 'is-open' : 'is-closed' ?>" title="<?= $activeSession ? htmlspecialchars('وردية مفتوحة: ' . (string)$activeSession['session_number'], ENT_QUOTES, 'UTF-8') : 'لا توجد وردية مفتوحة' ?>">
                    <span class="topbar-dot" aria-hidden="true"></span>
                    <span class="topbar-session-text"><?= $activeSession ? 'وردية مفتوحة' : 'لا توجد وردية مفتوحة' ?></span>
                </span>
                <button type="button" class="topbar-cta" data-perm="cash_register" onclick="LeviPOS.navigate('cash_register')">
                    <?= $activeSession ? 'إدارة الوردية' : 'ابدأ الوردية ←' ?>
                </button>
            </div>
            <button type="button" id="topbar-command-btn" class="topbar-command" onclick="LeviPOS.openCommandPalette()" aria-label="فتح شريط الأوامر">
                <span class="topbar-command-ic" aria-hidden="true">⌕</span>
                <span class="topbar-command-label">ابحث عن دواء أو نفّذ أمرًا…</span>
                <kbd>Ctrl K</kbd>
            </button>
            <div class="topbar-actions">
                <button type="button" class="topbar-clock" id="topbar-clock" title="اضغط لضبط التاريخ والوقت" onclick="LeviPOS.openClockModal()">
                    <svg class="clock-calendar" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    <span id="local-clock-date" class="clock-date"></span>
                    <span class="clock-sep" aria-hidden="true"></span>
                    <svg class="clock-time-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <span id="local-clock-time" class="clock-time"></span>
                </button>
                <button type="button" id="theme-toggle" class="topbar-icon-btn" aria-label="التبديل إلى الوضع الليلي" title="التبديل إلى الوضع الليلي" aria-pressed="false">
                    <svg class="icon-moon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>
                    <svg class="icon-sun" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>
                <div class="topbar-user-wrap" id="topbar-user-wrap">
                    <button type="button" id="topbar-user-btn" class="topbar-user" aria-haspopup="menu" aria-expanded="false" title="<?= htmlspecialchars((string)$user['full_name'] . ' — ' . $topbarRole, ENT_QUOTES, 'UTF-8') ?>">
                        <span class="topbar-avatar" aria-hidden="true"><?= htmlspecialchars($avatarInitials, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="topbar-user-meta">
                            <span class="topbar-user-name"><?= htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="topbar-user-role"><?= htmlspecialchars($topbarRole, ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <svg class="topbar-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="topbar-menu" id="topbar-menu" role="menu" aria-label="إجراءات الحساب">
                        <button type="button" class="topbar-menu-item" role="menuitem" onclick="LeviPOS.openChangePassword()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 8.8-8.8M17 6l2 2M14 9l2 2"/></svg>
                            تغيير كلمة المرور
                        </button>
                        <div class="topbar-menu-sep" aria-hidden="true"></div>
                        <button type="button" class="topbar-menu-item is-danger" role="menuitem" onclick="LeviPOS.logout()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg>
                            تسجيل الخروج
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main class="content-area">
            <!-- DASHBOARD -->
            <section id="view-dashboard" class="view-section active">
                <div class="pgx-page">

                    <!-- Page hero -->
                    <section class="pgx-hero">
                        <svg class="pgx-hero-beat" viewBox="0 0 600 92" preserveAspectRatio="none" aria-hidden="true">
                            <path vector-effect="non-scaling-stroke" d="M0 52 H40 L48 48 L56 56 L64 26 L74 72 L82 48 L90 52 H150 L158 48 L166 56 L174 26 L184 72 L192 48 L200 52 H260 L268 48 L276 56 L284 26 L294 72 L302 48 L310 52 H370 L378 48 L386 56 L394 26 L404 72 L412 48 L420 52 H480 L488 48 L496 56 L504 26 L514 72 L522 48 L530 52 H600"/>
                            <circle class="peak" vector-effect="non-scaling-stroke" cx="64" cy="26" r="2.6"/>
                            <circle class="peak" vector-effect="non-scaling-stroke" cx="174" cy="26" r="2.6"/>
                            <circle class="peak" vector-effect="non-scaling-stroke" cx="284" cy="26" r="2.6"/>
                            <circle class="peak" vector-effect="non-scaling-stroke" cx="394" cy="26" r="2.6"/>
                            <circle class="peak" vector-effect="non-scaling-stroke" cx="504" cy="26" r="2.6"/>
                        </svg>
                        <div class="pgx-hero-main">
                            <div class="pgx-crumbs">
                                <span>الرئيسية</span><i>›</i><span>لوحة التحكم</span>
                            </div>
                            <h1>
                                <svg class="pgx-title-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                                <?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?>
                            </h1>
                            <p id="dashboard-welcome">صيدليتك جاهزة لخدمة المرضى اليوم — راجع الروشتات، الدفعات القريبة من الانتهاء، وحالة المخزون في مكان واحد.</p>
                            <div class="pgx-meta">
                                <span class="pgx-meta-item">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                    آخر تحديث: <span id="dashboard-updated">—</span>
                                </span>
                                <span class="pgx-meta-item is-live">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                    بيانات مباشرة
                                </span>
                            </div>
                        </div>
                        <div class="pgx-hero-actions">
                            <div id="dashboard-hero-readiness" class="pgx-hero-readiness" aria-label="جاهزية الصيدلية">
                                <span id="dashboard-hero-readiness-ring" class="pgx-hero-readiness-ring" aria-hidden="true"><b>—</b></span>
                                <span>جاهزية الصيدلية اليوم</span>
                            </div>
                            <div class="pgx-actions">
                                <button type="button" class="pgx-btn" onclick="LeviPOS.loadDashboard()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
                                    تحديث
                                </button>
                                <button type="button" class="pgx-btn is-warm" data-nav-target="prescriptions">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/></svg>
                                    مراجعة الروشتات
                                </button>
                                <button type="button" class="pgx-btn is-primary" data-nav-target="pos">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.5 12h11l2-8H6"/></svg>
                                    فتح نقطة البيع
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- Pharmacy quick actions -->
                    <section class="pgx-quick" aria-label="إجراءات سريعة">
                        <button type="button" id="dashboard-quick-forecast" class="pgx-quick-tile is-red" data-perm="pharmacy" onclick="LeviPOS.openPharmacyForecastTab()">
                            <span class="pgx-quick-ic" aria-hidden="true">📉</span>
                            <span><strong>توقع نقص الأدوية</strong><small id="dashboard-quick-forecast-detail">—</small></span>
                        </button>
                        <button type="button" class="pgx-quick-tile is-green" data-nav-target="products">
                            <span class="pgx-quick-ic" aria-hidden="true">🔍</span>
                            <span><strong>بحث عن دواء</strong><small>بالاسم أو المادة الفعالة</small></span>
                        </button>
                        <button type="button" class="pgx-quick-tile is-red" data-nav-target="expiry">
                            <span class="pgx-quick-ic" aria-hidden="true">⚠</span>
                            <span><strong>انتهاء الصلاحية</strong><small id="dashboard-quick-expiry">— دفعة تحتاج صرفًا أولًا</small></span>
                        </button>
                    </section>

                    <!-- Pharmacy care additions -->
                    <section class="pgx-enhanced" aria-label="مؤشرات الرعاية وحركة اليوم">
                        <section class="pgx-panel pgx-enhanced-card" id="dashboard-readiness">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                    جاهزية الصيدلية
                                </h2>
                                <span class="pgx-panel-tag" id="dashboard-readiness-tag">—</span>
                            </div>
                            <div id="dashboard-readiness-body"></div>
                        </section>

                        <section class="pgx-panel pgx-enhanced-card" id="dashboard-interactions">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2.8 19a1.5 1.5 0 0 0 1.3 2.2h15.8A1.5 1.5 0 0 0 21.2 19Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                                    التداخلات الدوائية
                                </h2>
                                <span class="pgx-panel-tag is-alert" id="dashboard-interaction-tag">—</span>
                            </div>
                            <div id="dashboard-interaction-list"></div>
                        </section>

                        <section class="pgx-panel pgx-enhanced-card" id="dashboard-heatmap">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                    خريطة حركة اليوم
                                </h2>
                                <span class="pgx-panel-tag" id="dashboard-heatmap-peak">—</span>
                            </div>
                            <div id="dashboard-heatmap-body"></div>
                        </section>
                    </section>

                    <!-- Alerts strip -->
                    <section class="pgx-alerts" aria-label="تنبيهات المخزون والنظام">
                        <div class="pgx-alerts-head">
                            <span class="pgx-alerts-ic" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2.8 19a1.5 1.5 0 0 0 1.3 2.2h15.8A1.5 1.5 0 0 0 21.2 19Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                            </span>
                            <span class="pgx-alerts-title">تنبيهات الرعاية والمخزون</span>
                        </div>
                        <div id="dashboard-alerts" class="pgx-alerts-list"></div>
                    </section>

                    <!-- KPI groups (filled by loadDashboard) -->
                    <div id="dashboard-stats" class="pgx-kpis"></div>

                    <!-- Pharmacy focus -->
                    <div class="pgx-grid pgx-pharma-grid">
                        <section class="pgx-panel pgx-pharma-focus">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                    أدوية تحتاج انتباهك
                                </h2>
                                <span class="pgx-panel-tag" id="dashboard-focus-count">—</span>
                            </div>
                            <div id="dashboard-expiry-focus"></div>
                        </section>

                        <section class="pgx-panel pgx-pharma-timeline">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 7v5l3 2"/><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>
                                    اليوم في الصيدلية
                                </h2>
                                <span class="pgx-panel-tag">آخر النشاطات</span>
                            </div>
                            <div id="dashboard-timeline"></div>
                        </section>
                    </div>

                    <!-- Charts -->
                    <div class="pgx-grid">
                        <section class="pgx-panel pgx-chart">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><rect x="5" y="12" width="3.5" height="6" rx="1"/><rect x="10.5" y="7" width="3.5" height="11" rx="1"/><rect x="16" y="10" width="3.5" height="8" rx="1"/></svg>
                                    أكثر المنتجات مبيعًا
                                </h2>
                                <span class="pgx-panel-tag">حسب الكمية</span>
                            </div>
                            <div id="chart-top-products" class="mini-bar-list" role="img" aria-label="أكثر المنتجات مبيعًا"></div>
                        </section>

                        <section class="pgx-panel pgx-chart">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.2 15.9A10 10 0 1 1 8.1 2.8"/><path d="M22 12A10 10 0 0 0 12 2v10Z"/></svg>
                                    المبيعات حسب التصنيف
                                </h2>
                                <span class="pgx-panel-tag">نسبة من الإجمالي</span>
                            </div>
                            <div id="chart-category-sales" class="category-chart" role="img" aria-label="المبيعات حسب التصنيف"></div>
                        </section>
                    </div>

                    <!-- Recent invoices -->
                    <section class="pgx-panel pgx-recent">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg>
                                أحدث الفواتير
                            </h2>
                            <div class="pgx-recent-meta">
                                <span class="pgx-count-chip" id="dashboard-recent-count">—</span>
                                <button type="button" class="pgx-link-btn" data-nav-target="invoices">
                                    كل الفواتير
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                                </button>
                            </div>
                        </div>
                        <div id="dashboard-recent-sales"></div>
                    </section>

                </div>
            </section>

            <!-- POS -->
            <section id="view-pos" class="view-section">
                <div class="posx">
                    <div class="posx-scanbar">
                        <div class="posx-search">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2"></path><path d="M17 3h2a2 2 0 0 1 2 2v2"></path><path d="M21 17v2a2 2 0 0 1-2 2h-2"></path><path d="M7 21H5a2 2 0 0 1-2-2v-2"></path><path d="M7 8v8M10 8v8M13 8v8M17 8v8"></path></svg>
                            <input type="text" id="pos-search-input" class="posx-search-input" placeholder="امسح الباركود لإضافته للسلة مباشرة، أو ابحث بالدواء / الكود / المادة الفعالة…" autocomplete="off">
                            <span class="posx-kbd">F2</span>
                        </div>
                        <div class="posx-scanbar-side">
                            <span class="posx-scan-tag"><i></i> القارئ جاهز</span>
                            <span id="pos-session-badge" class="pill pill-gray">جارٍ التحقق من الوردية...</span>
                            <button type="button" class="btn btn-sm btn-outline" data-nav-target="held">الفواتير المعلقة</button>
                        </div>
                    </div>

                    <div class="posx-grid">
                        <div class="card posx-card posx-products">
                            <div class="posx-card-head">
                                <div class="posx-card-title">
                                    <span class="posx-emoji" aria-hidden="true">💊</span>
                                    <h2>المنتجات</h2>
                                    <span class="posx-count" id="pos-catalog-count">0</span>
                                </div>
                                <div class="posx-head-filters">
                                    <select id="pos-category-filter" class="form-select posx-mini-select" title="تصفية حسب التصنيف">
                                        <option value="">كل التصنيفات</option>
                                    </select>
                                    <select id="pos-brand-filter" class="form-select posx-mini-select" title="تصفية حسب شركة الدواء">
                                        <option value="">كل الشركات</option>
                                    </select>
                                </div>
                            </div>
                            <div class="posx-products-body">
                                <div class="posx-pills" id="posx-category-pills"></div>
                                <div id="pos-catalog-grid" class="posx-catalog"></div>
                            </div>
                        </div>

                        <div class="card posx-card posx-cart">
                            <div class="posx-card-head">
                                <div class="posx-card-title">
                                    <span class="posx-emoji" aria-hidden="true">🧾</span>
                                    <h2>سلة المبيعات</h2>
                                    <span class="posx-count" id="pos-cart-count">0</span>
                                </div>
                                <button type="button" class="posx-ghost-btn" onclick="LeviPOS.clearCart()">مسح السلة</button>
                            </div>
                            <div class="posx-cart-body" id="pos-cart-items"></div>
                            <div class="posx-cart-foot">
                                <div class="posx-sum-row"><span>إجمالي المنتجات</span><strong id="pos-summary-subtotal">0.00 د.ل</strong></div>
                                <div class="posx-sum-row is-discount"><span>إجمالي الخصومات</span><strong id="pos-summary-discount">0.00 د.ل</strong></div>
                                <div class="posx-sum-row" id="pos-summary-tax-row" style="display:none;"><span id="pos-summary-tax-label">الضريبة</span><strong id="pos-summary-tax">0.00 د.ل</strong></div>
                                <div class="posx-sum-row is-total"><span>الإجمالي النهائي</span><strong id="pos-summary-total">0.00 د.ل</strong></div>
                            </div>
                        </div>

                        <div class="card posx-card posx-pay">
                            <div class="posx-card-head">
                                <div class="posx-card-title">
                                    <span class="posx-emoji" aria-hidden="true">💰</span>
                                    <h2>الدفع والفاتورة</h2>
                                </div>
                            </div>
                            <div class="posx-pay-body">
                                <div class="posx-field pos-customer-field">
                                    <label for="pos-customer-search">المريض (للبيع الآجل)</label>
                                    <div class="posx-customer-row">
                                        <div class="pos-customer-box">
                                            <input type="text" id="pos-customer-search" class="form-control" placeholder="ابحث بالاسم أو الهاتف..." autocomplete="off">
                                            <div id="pos-customer-suggest" class="pos-suggest" style="display:none;"></div>
                                        </div>
                                        <input type="hidden" id="pos-customer-id" value="">
                                        <button type="button" class="posx-mini-btn" title="تفريغ المريض المحدد" onclick="LeviPOS.clearPosCustomer()">تفريغ</button>
                                        <button type="button" class="posx-mini-btn" title="إضافة عميل جديد" onclick="LeviPOS.openCustomerModal()">+ عميل</button>
                                    </div>
                                </div>

                                <div class="posx-field">
                                    <label>طريقة الدفع</label>
                                    <div class="posx-pay-methods" id="posx-pay-methods">
                                        <button type="button" class="posx-pay-method is-active" data-posx-pay="cash">
                                            <span class="posx-pay-emoji" aria-hidden="true">💰</span>
                                            <span>نقدي</span>
                                        </button>
                                        <button type="button" class="posx-pay-method" data-posx-pay="card">
                                            <span class="posx-pay-emoji" aria-hidden="true">💳</span>
                                            <span>بطاقة</span>
                                        </button>
                                        <button type="button" class="posx-pay-method" data-posx-pay="transfer">
                                            <span class="posx-pay-emoji" aria-hidden="true">🔁</span>
                                            <span>تحويل</span>
                                        </button>
                                        <button type="button" class="posx-pay-method" data-posx-pay="split">
                                            <span class="posx-pay-emoji" aria-hidden="true">🧮</span>
                                            <span>مختلط</span>
                                        </button>
                                        <button type="button" class="posx-pay-method" data-posx-pay="credit">
                                            <span class="posx-pay-emoji" aria-hidden="true">⏳</span>
                                            <span>آجل</span>
                                        </button>
                                    </div>
                                    <select id="pos-payment-method" class="posx-hidden-select" tabindex="-1" aria-hidden="true">
                                        <option value="cash">نقدي</option>
                                        <option value="card">بطاقة مصرفية</option>
                                        <option value="transfer">تحويل مصرفي</option>
                                        <option value="split">دفع مختلط</option>
                                        <option value="credit">آجل / جزئي</option>
                                    </select>
                                </div>

                                <div class="posx-mini-grid">
                                    <div class="posx-field">
                                        <label for="pos-invoice-discount">خصم إضافي (د.ل)</label>
                                        <input type="number" step="0.5" min="0" id="pos-invoice-discount" class="form-control" value="0">
                                    </div>
                                    <div class="posx-field">
                                        <label for="pos-prescription">روشتة مرتبطة (اختياري)</label>
                                        <select id="pos-prescription" class="form-select"><option value="">بدون روشتة</option></select>
                                    </div>
                                </div>

                                <div class="posx-field" id="pos-simple-payment-wrap">
                                    <label for="pos-paid-amount">المبلغ المدفوع</label>
                                    <div class="posx-amount-wrap">
                                        <input type="number" step="0.5" min="0" id="pos-paid-amount" class="posx-amount-input" value="0">
                                        <span class="posx-amount-cur" id="posx-amount-cur">LYD</span>
                                    </div>
                                    <div class="posx-quick">
                                        <button type="button" data-posx-cash="exact">المبلغ بالضبط</button>
                                        <button type="button" data-posx-cash="5">+5</button>
                                        <button type="button" data-posx-cash="10">+10</button>
                                        <button type="button" data-posx-cash="50">+50</button>
                                    </div>
                                    <div class="posx-keypad" id="posx-keypad">
                                        <button type="button" data-posx-key="7">7</button>
                                        <button type="button" data-posx-key="8">8</button>
                                        <button type="button" data-posx-key="9">9</button>
                                        <button type="button" class="is-fn" data-posx-key="clear">C</button>
                                        <button type="button" data-posx-key="4">4</button>
                                        <button type="button" data-posx-key="5">5</button>
                                        <button type="button" data-posx-key="6">6</button>
                                        <button type="button" data-posx-key="00">00</button>
                                        <button type="button" data-posx-key="1">1</button>
                                        <button type="button" data-posx-key="2">2</button>
                                        <button type="button" data-posx-key="3">3</button>
                                        <button type="button" data-posx-key="000">000</button>
                                        <button type="button" class="is-zero" data-posx-key="0">0</button>
                                        <button type="button" data-posx-key=".">.</button>
                                        <button type="button" class="is-fn" data-posx-key="back">⌫</button>
                                    </div>
                                </div>

                                <div class="posx-field" id="pos-split-payment-wrap" style="display:none;">
                                    <div class="posx-split-head">
                                        <label>تفاصيل الدفع المختلط</label>
                                        <button type="button" class="btn btn-sm btn-outline" onclick="LeviPOS.addSplitRow()">+ سطر</button>
                                    </div>
                                    <div id="pos-split-rows"></div>
                                </div>
                            </div>

                            <div class="posx-pay-foot">
                                <div class="posx-calc">
                                    <div class="posx-calc-row is-change"><span>الباقي للعميل</span><strong id="pos-summary-change">0.00 د.ل</strong></div>
                                    <div class="posx-calc-row is-remaining"><span>المتبقي (دين)</span><strong id="pos-summary-remaining">0.00 د.ل</strong></div>
                                </div>

                                <label class="posx-print-toggle"><input type="checkbox" id="pos-auto-print" <?= ((int)($settings['auto_print_receipt'] ?? 1) === 1) ? 'checked' : '' ?>><span>طباعة الفاتورة الحرارية تلقائيًا</span></label>

                                <div class="posx-pay-actions">
                                    <button type="button" id="btn-pos-checkout" class="posx-checkout" onclick="LeviPOS.submitPosSale(false)">
                                        <span>تأكيد البيع وإصدار الفاتورة</span><span class="posx-kbd">F8</span>
                                    </button>
                                    <button type="button" class="posx-hold" onclick="LeviPOS.submitPosSale(true)">تعليق الفاتورة مؤقتًا <span class="posx-kbd">F4</span></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- PRODUCTS -->
            <section id="view-products" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'الأدوية والمنتجات',
                        'أضف الأدوية وعدّل الأسعار والباركود والمادة الفعالة، وتابع الأصناف المؤرشفة والمخزون المرتبط بها.',
                        'products',
                        '',
                        '<button type="button" class="pgx-btn is-primary" data-perm-action="products.manage" onclick="LeviPOS.openProductModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> إضافة دواء</button>'
                        . '<button type="button" class="pgx-btn" data-perm-action="products.manage" onclick="LeviPOS.openImportModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4 21h16"/></svg> استيراد CSV</button>',
                        true
                    ); ?>

                    <section class="prodx-stats" aria-label="إحصاء الأدوية">
                        <div class="prodx-stat is-green"><span class="prodx-stat-em" aria-hidden="true">💊</span><small>إجمالي الأدوية</small><b id="products-stat-total">—</b></div>
                        <div class="prodx-stat is-amber"><span class="prodx-stat-em" aria-hidden="true">📉</span><small>مخزون منخفض</small><b id="products-stat-low">—</b></div>
                        <div class="prodx-stat is-red"><span class="prodx-stat-em" aria-hidden="true">🗓️</span><small>قريبة الانتهاء</small><b id="products-stat-expiry">—</b></div>
                        <div class="prodx-stat"><span class="prodx-stat-em" aria-hidden="true">📦</span><small>مؤرشفة</small><b id="products-stat-archived">—</b></div>
                    </section>

                    <section class="pgx-panel pgx-filters">
                        <div class="pgx-filter-grid">
                            <label class="pgx-field is-wide" for="products-search"><span>بحث</span>
                                <input type="text" id="products-search" class="form-control" placeholder="بحث بالدواء أو المادة الفعالة أو SKU أو الباركود...">
                            </label>
                        </div>
                        <div class="pgx-filter-actions">
                            <button type="button" class="pgx-btn" id="products-archived-toggle" onclick="LeviPOS.toggleArchivedProducts()">🗄️ الأدوية المؤرشفة</button>
                            <?php if ($isOwner): ?>
                            <button type="button" class="pgx-btn" onclick="LeviPOS.toggleAllProductChecks()">☑️ تحديد الكل</button>
                            <button type="button" class="pgx-btn is-danger" onclick="LeviPOS.bulkDeleteProducts()">🗑️ حذف المحدد</button>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['products'] ?></svg>
                                قائمة الأدوية والمنتجات
                            </h2>
                        </div>
                        <div id="products-table"></div>
                    </section>
                </div>
            </section>

            <!-- INVENTORY -->
            <section id="view-inventory" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'المخزون والجرد',
                        'تابع أرصدة المخزون والمخزون المنخفض وحركات الأصناف، وسجّل الجرد والتسويات والتالف والمفقود.',
                        'inventory',
                        '',
                        '<button type="button" class="pgx-btn" onclick="LeviPOS.loadInventory()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg> تحديث</button>',
                        true
                    ); ?>

                    <section class="invx-stats" aria-label="إحصاء المخزون">
                        <div class="invx-stat is-green"><span class="invx-stat-em" aria-hidden="true">💰</span><small>قيمة المخزون</small><b id="inventory-stat-value" class="is-money">—</b></div>
                        <div class="invx-stat is-amber"><span class="invx-stat-em" aria-hidden="true">📉</span><small>أصناف منخفضة</small><b id="inventory-stat-low">—</b></div>
                        <div class="invx-stat is-red"><span class="invx-stat-em" aria-hidden="true">🚫</span><small>أصناف نافدة</small><b id="inventory-stat-out">—</b></div>
                        <div class="invx-stat"><span class="invx-stat-em" aria-hidden="true">📦</span><small>إجمالي الأصناف</small><b id="inventory-stat-total">—</b></div>
                    </section>

                    <div class="tabs pgx-tabs">
                        <button class="tab-btn active" data-tab-group="inventory" data-tab="stock">📦 أرصدة المخزون</button>
                        <button class="tab-btn" data-tab-group="inventory" data-tab="low">📉 مخزون منخفض</button>
                        <button class="tab-btn" data-tab-group="inventory" data-tab="movements">🔄 حركات المخزون</button>
                        <?php if ((int)($settings['allow_stock_adjustments'] ?? 1) === 1): ?>
                        <button class="tab-btn" data-tab-group="inventory" data-tab="adjust">✏️ تسوية/جرد</button>
                        <?php endif; ?>
                        <?php if (!empty($permissions['inventory_adjust'])): ?>
                        <button class="tab-btn" data-tab-group="inventory" data-tab="transfer">↔️ تحويل بين الفروع</button>
                        <?php endif; ?>
                    </div>

                    <div class="tab-panel active" id="tab-inventory-stock">
                        <section class="pgx-panel pgx-filters">
                            <div class="pgx-filter-grid">
                                <label class="pgx-field is-wide" for="inventory-search"><span>بحث</span>
                                    <input type="text" id="inventory-search" class="form-control" placeholder="بحث...">
                                </label>
                            </div>
                            <div class="pgx-filter-actions">
                                <span id="inventory-valuation" class="pgx-count-chip"></span>
                                <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.loadInventory()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
                                    تحديث
                                </button>
                            </div>
                        </section>
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['inventory'] ?></svg>
                                    أرصدة المخزون
                                </h2>
                            </div>
                            <div id="inventory-table"></div>
                        </section>
                    </div>

                    <div class="tab-panel" id="tab-inventory-low">
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2.8 19a1.5 1.5 0 0 0 1.3 2.2h15.8A1.5 1.5 0 0 0 21.2 19Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                                    مخزون منخفض
                                </h2>
                            </div>
                            <div id="inventory-low-table"></div>
                        </section>
                    </div>

                    <div class="tab-panel" id="tab-inventory-movements">
                        <section class="pgx-panel pgx-filters">
                            <div class="pgx-filter-grid">
                                <label class="pgx-field" for="inv-mov-start"><span>من تاريخ</span>
                                    <input type="date" id="inv-mov-start" class="form-control">
                                </label>
                                <label class="pgx-field" for="inv-mov-end"><span>إلى تاريخ</span>
                                    <input type="date" id="inv-mov-end" class="form-control">
                                </label>
                                <label class="pgx-field" for="inv-mov-type"><span>نوع الحركة</span>
                                    <select id="inv-mov-type" class="form-select"><option value="">كل الحركات</option><option value="sale">بيع</option><option value="purchase">شراء</option><option value="return">مرتجع</option><option value="adjustment">تسوية</option><option value="damaged">تالف</option><option value="lost">مفقود</option><option value="opening">افتتاحي</option></select>
                                </label>
                            </div>
                            <div class="pgx-filter-actions">
                                <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.loadInventoryMovements()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
                                    عرض
                                </button>
                            </div>
                        </section>
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h4l2 5 4-10 2 5h6"/></svg>
                                    حركات المخزون
                                </h2>
                            </div>
                            <div id="inventory-movements-table"></div>
                        </section>
                    </div>

                    <?php if ((int)($settings['allow_stock_adjustments'] ?? 1) === 1): ?>
                    <div class="tab-panel" id="tab-inventory-adjust">
                        <section class="pgx-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    تسجيل جرد / تسوية / تالف / مفقود
                                </h2>
                            </div>
                            <form onsubmit="return LeviPOS.submitInventoryAdjustment(event)">
                                <div class="form-grid">
                                    <div><label>المنتج *</label><select id="adj-product-id" class="form-select" required></select></div>
                                    <div><label>نوع الحركة *</label><select id="adj-type" class="form-select"><option value="stocktake">جرد فعلي (تحديد الرصيد)</option><option value="damaged">تالف</option><option value="lost">مفقود/عجز</option><option value="adjustment">تسوية كمية</option></select></div>
                                    <div><label>الكمية *</label><input type="number" id="adj-qty" class="form-control" value="1" required></div>
                                    <div><label>السبب (اختياري)</label><input type="text" id="adj-reason" class="form-control" placeholder="سبب التوثيق (اختياري)"></div>
                                </div>
                                <button type="submit" class="pgx-btn is-primary mt-3">اعتماد الحركة</button>
                            </form>
                        </section>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($permissions['inventory_adjust'])): ?>
                    <div class="tab-panel" id="tab-inventory-transfer">
                        <section class="pgx-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h13"/><path d="m13 3 4 4-4 4"/><path d="M20 17H7"/><path d="m11 21-4-4 4-4"/></svg>
                                    تحويل مخزون إلى فرع آخر
                                </h2>
                            </div>
                            <form onsubmit="return LeviPOS.submitStockTransfer(event)">
                                <div class="form-grid">
                                    <div><label>المنتج *</label><select id="trf-product-id" class="form-select" required></select></div>
                                    <div><label>الفرع الوجهة *</label><select id="trf-branch-id" class="form-select" required></select></div>
                                    <div><label>الكمية *</label><input type="number" min="1" step="1" id="trf-qty" class="form-control" value="1" required></div>
                                    <div><label>ملاحظات</label><input type="text" id="trf-notes" class="form-control" maxlength="1000" placeholder="ملاحظات التحويل (اختياري)"></div>
                                </div>
                                <button type="submit" class="pgx-btn is-primary mt-3">تنفيذ التحويل</button>
                            </form>
                        </section>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- PURCHASES -->
            <section id="view-purchases" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'المشتريات',
                        'سجّل فواتير شراء الأدوية من شركات الأدوية وتابع الدفعات والتكاليف وتأثيرها على المخزون.',
                        'purchases',
                        '',
                        '<button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openPurchaseModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> فاتورة مشتريات</button>'
                    ); ?>

                    <section class="pgx-panel pgx-filters">
                        <div class="pgx-filter-grid">
                            <label class="pgx-field" for="purchases-start"><span>من تاريخ</span>
                                <input type="date" id="purchases-start" class="form-control">
                            </label>
                            <label class="pgx-field" for="purchases-end"><span>إلى تاريخ</span>
                                <input type="date" id="purchases-end" class="form-control">
                            </label>
                        </div>
                        <div class="pgx-filter-actions">
                            <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.loadPurchases()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
                                عرض
                            </button>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['purchases'] ?></svg>
                                سجل المشتريات
                            </h2>
                        </div>
                        <div id="purchases-table"></div>
                    </section>
                </div>
            </section>

            <!-- SUPPLIERS -->
            <section id="view-suppliers" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'شركات الأدوية والموردون',
                        'أضف بيانات شركات الأدوية والموردين وتواصل معهم، وتابع الأرصدة والمستحقات المرتبطة بفواتير الشراء.',
                        'suppliers',
                        '',
                        '<button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openSupplierModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> إضافة مورد</button>'
                    ); ?>

                    <section class="pgx-panel pgx-filters">
                        <div class="pgx-filter-grid">
                            <label class="pgx-field is-wide" for="suppliers-search"><span>بحث</span>
                                <input type="text" id="suppliers-search" class="form-control" placeholder="بحث بالاسم أو الهاتف...">
                            </label>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['suppliers'] ?></svg>
                                قائمة شركات الأدوية والموردين
                            </h2>
                        </div>
                        <div id="suppliers-table"></div>
                    </section>
                </div>
            </section>

            <!-- CUSTOMERS -->
            <section id="view-customers" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'المرضى والعملاء والديون',
                        'أضف ملفات المرضى وتابع الأرصدة والديون والمدفوعات، مع كشف حساب تفصيلي لكل مريض.',
                        'customers',
                        '',
                        '<button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openCustomerModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> إضافة عميل</button>'
                    ); ?>

                    <section class="pgx-panel pgx-filters">
                        <div class="pgx-filter-grid">
                            <label class="pgx-field is-wide" for="customers-search"><span>بحث</span>
                                <input type="text" id="customers-search" class="form-control" placeholder="بحث بالاسم أو الهاتف...">
                            </label>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['customers'] ?></svg>
                                قائمة المرضى والعملاء
                            </h2>
                        </div>
                        <div id="customers-table"></div>
                    </section>
                </div>
            </section>

            <!-- INVOICES -->
            <section id="view-invoices" class="view-section">
                <div class="pgx-page">

                    <section class="pgx-hero">
                        <div class="pgx-hero-main">
                            <div class="pgx-crumbs">
                                <span>الرئيسية</span><i>›</i><span>الفواتير</span>
                            </div>
                            <h1>
                                <svg class="pgx-title-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg>
                                الفواتير
                            </h1>
                            <p>استعرض فواتير البيع وسجّل المدفوعات والمتبقي، مع تصفية حسب الفترة والحالة والمريض.</p>
                            <div class="pgx-meta">
                                <span class="pgx-meta-item">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18l-7 8v5l-4 2v-7Z"/></svg>
                                    تصفية حسب الفترة والحالة والمريض
                                </span>
                                <span class="pgx-meta-item is-live">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                    بيانات مباشرة
                                </span>
                            </div>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-filters">
                        <div class="pgx-filter-grid">
                            <label class="pgx-field" for="invoices-start"><span>من تاريخ</span>
                                <input type="date" id="invoices-start" class="form-control">
                            </label>
                            <label class="pgx-field" for="invoices-end"><span>إلى تاريخ</span>
                                <input type="date" id="invoices-end" class="form-control">
                            </label>
                            <label class="pgx-field" for="invoices-status"><span>الحالة</span>
                                <select id="invoices-status" class="form-select"><option value="">كل الحالات</option><option value="completed">مكتملة</option><option value="cancelled">ملغاة</option><option value="returned">مرتجعة</option><option value="held">معلقة</option></select>
                            </label>
                            <label class="pgx-field is-wide" for="invoices-q"><span>بحث</span>
                                <input type="text" id="invoices-q" class="form-control" placeholder="رقم الفاتورة/المريض">
                            </label>
                        </div>
                        <div class="pgx-filter-actions">
                            <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.loadInvoices()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
                                عرض
                            </button>
                            <?php if ($isOwner): ?>
                            <button type="button" class="pgx-btn" onclick="LeviPOS.toggleAllTableChecks('invoice')">تحديد الكل</button>
                            <button type="button" class="pgx-btn is-danger" onclick="LeviPOS.bulkDeleteInvoices()">حذف المحدد</button>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg>
                                سجل الفواتير
                            </h2>
                            <span class="pgx-count-chip" id="invoices-count">—</span>
                        </div>
                        <div id="invoices-table"></div>
                    </section>

                </div>
            </section>

            <!-- RETURNS -->
            <section id="view-returns" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'المرتجعات والاستبدال',
                        'أنشئ مرتجعًا من فاتورة سابقة، واستعرض سجل المرتجعات والاستبدالات مع إمكانية الحذف للمالك.',
                        'returns'
                    ); ?>

                    <section class="pgx-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                إنشاء مرتجع من فاتورة
                            </h2>
                        </div>
                        <div class="pgx-filter-grid">
                            <label class="pgx-field is-wide" for="return-invoice-search"><span>بحث</span>
                                <input type="text" id="return-invoice-search" class="form-control" placeholder="رقم الفاتورة أو باركود/اسم المنتج...">
                            </label>
                        </div>
                        <div id="return-invoice-result"></div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['returns'] ?></svg>
                                سجل المرتجعات
                            </h2>
                            <?php if ($isOwner): ?>
                            <div class="pgx-filter-actions">
                                <button type="button" class="pgx-btn" onclick="LeviPOS.toggleAllTableChecks('return')">تحديد الكل</button>
                                <button type="button" class="pgx-btn is-danger" onclick="LeviPOS.bulkDeleteReturns()">حذف المحدد</button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div id="returns-table"></div>
                    </section>
                </div>
            </section>

            <!-- HELD INVOICES -->
            <section id="view-held" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'الفواتير المعلقة',
                        'استعرض الفواتير المحفوظة مؤقتًا وأكملها أو احذفها حسب الحاجة.',
                        'held',
                        '',
                        '<button type="button" class="pgx-btn" onclick="LeviPOS.loadHeldInvoices()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg> تحديث</button>'
                    ); ?>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['held'] ?></svg>
                                الفواتير المحفوظة
                            </h2>
                        </div>
                        <div id="held-table"></div>
                    </section>
                </div>
            </section>

            <!-- EXPENSES -->
            <section id="view-expenses" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'المصروفات',
                        'سجّل المصروفات اليومية وصنّفها، وتابع إجمالي المصروفات خلال الفترة المحددة.',
                        'expenses',
                        '',
                        '<button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openExpenseModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> تسجيل مصروف</button>'
                        . '<button type="button" class="pgx-btn" onclick="LeviPOS.openExpenseCategoryModal()">إدارة التصنيفات</button>'
                    ); ?>

                    <section class="pgx-panel pgx-filters">
                        <div class="pgx-filter-grid">
                            <label class="pgx-field" for="expenses-start"><span>من تاريخ</span>
                                <input type="date" id="expenses-start" class="form-control">
                            </label>
                            <label class="pgx-field" for="expenses-end"><span>إلى تاريخ</span>
                                <input type="date" id="expenses-end" class="form-control">
                            </label>
                        </div>
                        <div class="pgx-filter-actions">
                            <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.loadExpenses()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
                                عرض
                            </button>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['expenses'] ?></svg>
                                سجل المصروفات
                            </h2>
                        </div>
                        <div id="expenses-table"></div>
                    </section>
                </div>
            </section>

            <!-- CASH REGISTER -->
            <section id="view-cash_register" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'الصندوق والورديات',
                        'افتح الوردية وأغلقها، وسجّل الحركات النقدية وتابع النقد المتوقع في الدرج وسجل الورديات.',
                        'cash',
                        '',
                        '<button type="button" class="pgx-btn" onclick="LeviPOS.loadCashRegister()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg> تحديث</button>'
                    ); ?>

                    <div id="cash-register-status" class="stat-grid"></div>

                    <section class="pgx-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['cash'] ?></svg>
                                إدارة الوردية
                            </h2>
                        </div>
                        <div class="pgx-filter-actions">
                            <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openShiftModal()">فتح وردية</button>
                            <button type="button" class="pgx-btn" onclick="LeviPOS.closeShiftModal()">إغلاق الوردية</button>
                            <button type="button" class="pgx-btn" data-perm-action="cash_register.manage" onclick="LeviPOS.manualCashMovementModal()">حركة نقدية يدوية</button>
                        </div>
                        <div id="cash-register-summary" style="margin-top:14px;"></div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h4l2 5 4-10 2 5h6"/></svg>
                                حركات الوردية الحالية
                            </h2>
                        </div>
                        <div id="cash-register-movements"></div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel" data-perm-action="cash_register.manage">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                سجل الورديات
                            </h2>
                        </div>
                        <div id="cash-register-sessions"></div>
                    </section>
                </div>
            </section>

            <!-- BATCHES -->
            <section id="view-batches" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'دفعات الأدوية',
                        'تابع دفعات الأدوية وأرقامها وتواريخ انتهائها، مع صرف FEFO تلقائي عند البيع.',
                        'batches',
                        '',
                        '<button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openBatchModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> إضافة دفعة</button>'
                    ); ?>

                    <section class="pgx-panel pgx-filters">
                        <div class="pgx-filter-grid">
                            <label class="pgx-field is-wide" for="batches-search"><span>بحث</span>
                                <input type="text" id="batches-search" class="form-control" placeholder="بحث بالدواء/الكود/رقم الدفعة/المادة الفعالة...">
                            </label>
                            <label class="pgx-field" for="batches-status"><span>الحالة</span>
                                <select id="batches-status" class="form-select" onchange="LeviPOS.loadBatches()"><option value="">كل الحالات</option><option value="valid">سارية</option><option value="near_expiry">قريبة الانتهاء</option><option value="expired">منتهية</option><option value="empty">نفدت</option></select>
                            </label>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['batches'] ?></svg>
                                قائمة الدفعات
                            </h2>
                        </div>
                        <div id="batches-table"></div>
                    </section>
                </div>
            </section>

            <!-- EXPIRY -->
            <section id="view-expiry" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'تنبيهات انتهاء الصلاحية',
                        'الأدوية المنتهية والقريبة من الانتهاء، مع إجراءات سريعة لضبط المخزون.',
                        'expiry'
                    ); ?>

                    <div id="expiry-summary" class="pgx-kpis"></div>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['expiry'] ?></svg>
                                الدفعات المنتهية والقريبة من الانتهاء
                            </h2>
                        </div>
                        <div id="expiry-table"></div>
                    </section>
                </div>
            </section>

            <!-- DOCTORS -->
            <section id="view-doctors" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'الأطباء',
                        'سجل الأطباء والتخصصات المرتبطة بالروشتات.',
                        'doctors',
                        '',
                        '<button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openDoctorModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> إضافة طبيب</button>'
                    ); ?>

                    <section class="pgx-panel pgx-filters">
                        <div class="pgx-filter-grid">
                            <label class="pgx-field is-wide" for="doctors-search"><span>بحث</span>
                                <input type="text" id="doctors-search" class="form-control" placeholder="بحث بالاسم/التخصص/الهاتف/العيادة...">
                            </label>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['doctors'] ?></svg>
                                قائمة الأطباء
                            </h2>
                        </div>
                        <div id="doctors-table"></div>
                    </section>
                </div>
            </section>

            <!-- PRESCRIPTIONS -->
            <section id="view-prescriptions" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'الروشتات',
                        'سجل الروشتات الطبية وصرفها وربطها بفواتير المبيعات.',
                        'prescriptions',
                        '',
                        '<button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openPrescriptionModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> إضافة روشتة</button>'
                    ); ?>

                    <section class="pgx-panel pgx-filters">
                        <div class="pgx-filter-grid">
                            <label class="pgx-field is-wide" for="prescriptions-search"><span>بحث</span>
                                <input type="text" id="prescriptions-search" class="form-control" placeholder="بحث برقم الروشتة/المريض/الطبيب...">
                            </label>
                            <label class="pgx-field" for="prescriptions-status"><span>الحالة</span>
                                <select id="prescriptions-status" class="form-select" onchange="LeviPOS.loadPrescriptions()"><option value="">كل الحالات</option><option value="issued">صادرة</option><option value="dispensed">مصروفة</option><option value="cancelled">ملغاة</option></select>
                            </label>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['prescriptions'] ?></svg>
                                قائمة الروشتات
                            </h2>
                        </div>
                        <div id="prescriptions-table"></div>
                    </section>
                </div>
            </section>

            <!-- ADVANCED PHARMACY -->
            <section id="view-pharmacy" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'الصيدلية المتقدمة',
                        'الجرد الفعلي، الأدوية التالفة، وحدات البيع، ملفات المرضى، مراجعة الروشتات، وتنبؤات التوريد.',
                        'pharmacy',
                        '',
                        '<button type="button" class="pgx-btn" onclick="LeviPOS.loadPharmacy()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg> تحديث</button>'
                    ); ?>

                    <div class="tabs pgx-tabs">
                        <button class="tab-btn active" data-tab-group="pharmacy" data-tab="stocktake">الجرد الفعلي</button>
                        <button class="tab-btn" data-tab-group="pharmacy" data-tab="damage">الأدوية التالفة</button>
                        <button class="tab-btn" data-tab-group="pharmacy" data-tab="units">وحدات الدواء</button>
                        <button class="tab-btn" data-tab-group="pharmacy" data-tab="patients">ملف المريض</button>
                        <button class="tab-btn" data-tab-group="pharmacy" data-tab="review">مراجعة الروشتة</button>
                        <button class="tab-btn" data-tab-group="pharmacy" data-tab="supply">التوريد والتنبؤ</button>
                    </div>

                    <div class="tab-panel active" id="tab-pharmacy-stocktake">
                        <section class="pgx-panel pgx-filters">
                            <div class="pgx-filter-actions">
                                <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openStocktakeModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> جرد جديد</button>
                                <button type="button" class="pgx-btn" onclick="LeviPOS.loadPharmacyStocktake()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg> تحديث</button>
                            </div>
                        </section>
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head"><h2 class="pgx-panel-title">جلسات الجرد الفعلي</h2></div>
                            <div id="pharmacy-stocktake-table"></div>
                        </section>
                    </div>

                    <div class="tab-panel" id="tab-pharmacy-damage">
                        <section class="pgx-panel pgx-filters">
                            <div class="pgx-filter-actions">
                                <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openDamageModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> تسجيل تلف</button>
                                <button type="button" class="pgx-btn" onclick="LeviPOS.loadPharmacyDamage()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg> تحديث</button>
                            </div>
                        </section>
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head"><h2 class="pgx-panel-title">سجل الأدوية التالفة</h2></div>
                            <div id="pharmacy-damage-table"></div>
                        </section>
                    </div>

                    <div class="tab-panel" id="tab-pharmacy-units">
                        <section class="pgx-panel pgx-filters">
                            <div class="pgx-filter-grid">
                                <label class="pgx-field is-wide" for="pharmacy-unit-product"><span>الدواء</span>
                                    <select id="pharmacy-unit-product" class="form-select" onchange="LeviPOS.loadPharmacyUnits()"><option value="">اختر الدواء...</option></select>
                                </label>
                            </div>
                            <div class="pgx-filter-actions">
                                <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openUnitModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> حفظ الوحدات</button>
                            </div>
                        </section>
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head"><h2 class="pgx-panel-title">وحدات البيع والتغليف</h2></div>
                            <div id="pharmacy-units-table"></div>
                        </section>
                    </div>

                    <div class="tab-panel" id="tab-pharmacy-patients">
                        <section class="pgx-panel pgx-filters">
                            <div class="pgx-filter-grid">
                                <label class="pgx-field is-wide" for="pharmacy-patient-search"><span>بحث المريض</span>
                                    <input type="text" id="pharmacy-patient-search" class="form-control" placeholder="الاسم أو الهاتف...">
                                </label>
                            </div>
                            <div class="pgx-filter-actions">
                                <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.loadPharmacyPatients()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg> بحث</button>
                            </div>
                        </section>
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head"><h2 class="pgx-panel-title">ملفات المرضى</h2></div>
                            <div id="pharmacy-patients-table"></div>
                        </section>
                    </div>

                    <div class="tab-panel" id="tab-pharmacy-review">
                        <section class="pgx-panel pgx-filters">
                            <div class="pgx-filter-grid">
                                <label class="pgx-field" for="pharmacy-review-id"><span>رقم الروشتة</span>
                                    <input type="number" id="pharmacy-review-id" class="form-control" min="1" placeholder="مثال: 12">
                                </label>
                                <label class="pgx-field is-wide" for="pharmacy-rx-image"><span>صورة الروشتة (اختياري)</span>
                                    <input type="file" id="pharmacy-rx-image" class="form-control" accept="image/png,image/jpeg,image/webp">
                                </label>
                            </div>
                            <div class="pgx-filter-actions">
                                <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.reviewPrescription()">مراجعة الروشتة</button>
                                <button type="button" class="pgx-btn" onclick="LeviPOS.posPrescriptionSummary()">ملخص للصرف</button>
                                <button type="button" class="pgx-btn" onclick="LeviPOS.uploadPrescriptionImage()">رفع الصورة</button>
                                <button type="button" class="pgx-btn" onclick="LeviPOS.ocrPrescription()">قراءة الصورة (OCR)</button>
                            </div>
                        </section>
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head"><h2 class="pgx-panel-title">نتيجة المراجعة الصيدلانية</h2></div>
                            <div id="pharmacy-review-result" class="pgx-panel-body"><div class="empty-state">أدخل رقم الروشتة ثم اضغط «مراجعة الروشتة».</div></div>
                        </section>
                    </div>

                    <div class="tab-panel" id="tab-pharmacy-supply">
                        <section class="pgx-panel pgx-filters">
                            <div class="pgx-filter-grid">
                                <label class="pgx-field is-wide" for="pharmacy-price-product"><span>الدواء لمقارنة الأسعار</span>
                                    <select id="pharmacy-price-product" class="form-select" onchange="LeviPOS.loadPharmacySupply()"><option value="">اختر الدواء...</option></select>
                                </label>
                            </div>
                            <div class="pgx-filter-actions">
                                <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openSupplyOrderModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> طلب توريد جديد</button>
                                <button type="button" class="pgx-btn" onclick="LeviPOS.loadPharmacySupply()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg> تحديث</button>
                            </div>
                        </section>
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head"><h2 class="pgx-panel-title">مقارنة أسعار الموردين</h2></div>
                            <div id="pharmacy-prices-table"></div>
                        </section>
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head"><h2 class="pgx-panel-title">طلبيات التوريد</h2></div>
                            <div id="pharmacy-orders-table"></div>
                        </section>
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head"><h2 class="pgx-panel-title">توقع نقص الأدوية</h2></div>
                            <div id="pharmacy-forecast-table"></div>
                        </section>
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head"><h2 class="pgx-panel-title">تنبيهات الموردين</h2></div>
                            <div id="pharmacy-supplier-alerts"></div>
                        </section>
                    </div>
                </div>
            </section>

            <!-- REPORTS -->
            <section id="view-reports" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'التقارير',
                        'أنشئ تقارير المبيعات والأرباح والمخزون والمصروفات، وحدد الفترة ثم اطبع التقرير.',
                        'reports'
                    ); ?>

                    <section class="pgx-panel pgx-filters">
                        <div class="pgx-filter-grid">
                            <label class="pgx-field is-wide" for="report-type"><span>نوع التقرير</span>
                                <select id="report-type" class="form-select"></select>
                            </label>
                            <label class="pgx-field" for="report-start"><span>من تاريخ</span>
                                <input type="date" id="report-start" class="form-control">
                            </label>
                            <label class="pgx-field" for="report-end"><span>إلى تاريخ</span>
                                <input type="date" id="report-end" class="form-control">
                            </label>
                        </div>
                        <div class="pgx-filter-actions">
                            <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.generateReport()">إنشاء التقرير</button>
                            <button type="button" class="pgx-btn" onclick="LeviPOS.printReport()">طباعة التقرير</button>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['reports'] ?></svg>
                                نتيجة التقرير
                            </h2>
                        </div>
                        <div id="report-output"></div>
                    </section>
                </div>
            </section>

            <!-- USERS -->
            <section id="view-users" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'المستخدمون والصلاحيات',
                        'أضف مستخدمي النظام وحدد أدوارهم وصلاحياتهم، مع حماية الأدوار الأساسية من التعديل.',
                        'users',
                        '',
                        '<button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openUserModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> إضافة مستخدم</button>'
                    ); ?>

                    <div class="tabs pgx-tabs">
                        <button class="tab-btn active" data-tab-group="users" data-tab="list">المستخدمون</button>
                        <button class="tab-btn" data-tab-group="users" data-tab="roles">الأدوار والصلاحيات</button>
                    </div>
                    <div class="tab-panel active" id="tab-users-list">
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['users'] ?></svg>
                                    المستخدمون
                                </h2>
                            </div>
                            <div id="users-table"></div>
                        </section>
                    </div>
                    <div class="tab-panel" id="tab-users-roles">
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 4.3 3.2 7.9 8 9 4.8-1.1 8-4.7 8-9V6l-8-3Z"/><path d="m8.5 12 2.4 2.4 4.6-4.6"/></svg>
                                    الأدوار والصلاحيات
                                </h2>
                            </div>
                            <div id="roles-table"></div>
                        </section>
                    </div>
                </div>
            </section>

            <!-- AUDIT -->
            <section id="view-audit" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'سجل التدقيق',
                        'راجع كل العمليات الحساسة في النظام وحدد الفترة أو ابحث في السجل حسب النوع والمستخدم.',
                        'audit'
                    ); ?>

                    <section class="pgx-panel pgx-filters">
                        <div class="pgx-filter-grid">
                            <label class="pgx-field" for="audit-start"><span>من تاريخ</span>
                                <input type="date" id="audit-start" class="form-control">
                            </label>
                            <label class="pgx-field" for="audit-end"><span>إلى تاريخ</span>
                                <input type="date" id="audit-end" class="form-control">
                            </label>
                            <label class="pgx-field is-wide" for="audit-q"><span>بحث</span>
                                <input type="text" id="audit-q" class="form-control" placeholder="بحث...">
                            </label>
                        </div>
                        <div class="pgx-filter-actions">
                            <button type="button" class="pgx-btn is-primary" onclick="LeviPOS.loadAuditLogs()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
                                عرض
                            </button>
                        </div>
                    </section>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['audit'] ?></svg>
                                العمليات المسجلة
                            </h2>
                        </div>
                        <div id="audit-table"></div>
                    </section>
                </div>
            </section>

            <!-- BACKUPS -->
            <section id="view-backups" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'النسخ الاحتياطي والاستعادة',
                        'أنشئ نسخة احتياطية كاملة من البيانات، ونزّل أو استعد النسخ السابقة عند الحاجة.',
                        'backups',
                        '',
                        '<button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openBackupModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> إنشاء نسخة احتياطية</button>'
                        . '<button type="button" class="pgx-btn" onclick="LeviPOS.loadBackups()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg> تحديث</button>'
                    ); ?>

                    <section class="pgx-panel pgx-recent pgx-table-panel">
                        <div class="pgx-panel-head">
                            <h2 class="pgx-panel-title">
                                <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['backups'] ?></svg>
                                النسخ المحفوظة
                            </h2>
                        </div>
                        <div id="backups-table"></div>
                    </section>
                </div>
            </section>

            <!-- SETTINGS -->
            <section id="view-settings" class="view-section">
                <div class="pgx-page">
                    <?php $pgxRenderHero(
                        'الإعدادات',
                        'اضبط بيانات الصيدلية والتصنيفات العلاجية وشركات الأدوية والفروع والصناديق، وأدوات المدير العام.',
                        'settings',
                        '',
                        '<button type="button" class="pgx-btn" onclick="LeviPOS.loadSettings()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg> تحديث</button>'
                    ); ?>

                    <div class="tabs pgx-tabs">
                        <button class="tab-btn active" data-tab-group="settings" data-tab="pharmacy">الصيدلية</button>
                        <button class="tab-btn" data-tab-group="settings" data-tab="categories">التصنيفات العلاجية</button>
                        <button class="tab-btn" data-tab-group="settings" data-tab="brands">شركات الأدوية</button>
                        <button class="tab-btn" data-tab-group="settings" data-tab="branches">الفروع</button>
                        <button class="tab-btn" data-tab-group="settings" data-tab="registers">الصناديق</button>
                        <button class="tab-btn" data-tab-group="settings" data-tab="expense_categories">تصنيفات المصروفات</button>
                        <?php if ($isOwner): ?>
                        <button class="tab-btn" data-tab-group="settings" data-tab="owner">أدوات المدير العام</button>
                        <?php endif; ?>
                    </div>
                    <div class="tab-panel active" id="tab-settings-pharmacy">
                        <section class="pgx-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">
                                    <svg class="pgx-panel-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pgxHeroIcons['settings'] ?></svg>
                                    بيانات الصيدلية والإعدادات العامة
                                </h2>
                            </div>
                            <div id="settings-store-form"></div>
                        </section>
                        <section class="pgx-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">إعدادات الصيدلية والانتهاء</h2>
                            </div>
                            <div id="settings-pharmacy-form"></div>
                        </section>
                    </div>
                    <div class="tab-panel" id="tab-settings-categories">
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">التصنيفات العلاجية</h2>
                                <div class="pgx-filter-actions"><button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openCategoryModal()">إضافة تصنيف</button></div>
                            </div>
                            <div id="settings-categories-table"></div>
                        </section>
                    </div>
                    <div class="tab-panel" id="tab-settings-brands">
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">شركات الأدوية</h2>
                                <div class="pgx-filter-actions"><button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openBrandModal()">إضافة شركة</button></div>
                            </div>
                            <div id="settings-brands-table"></div>
                        </section>
                    </div>
                    <div class="tab-panel" id="tab-settings-branches">
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">الفروع</h2>
                                <div class="pgx-filter-actions"><button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openBranchModal()">إضافة فرع</button></div>
                            </div>
                            <div id="settings-branches-table"></div>
                        </section>
                    </div>
                    <div class="tab-panel" id="tab-settings-registers">
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">الصناديق</h2>
                                <div class="pgx-filter-actions"><button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openRegisterModal()">إضافة صندوق</button></div>
                            </div>
                            <div id="settings-registers-table"></div>
                        </section>
                    </div>
                    <div class="tab-panel" id="tab-settings-expense_categories">
                        <section class="pgx-panel pgx-recent pgx-table-panel">
                            <div class="pgx-panel-head">
                                <h2 class="pgx-panel-title">تصنيفات المصروفات</h2>
                                <div class="pgx-filter-actions"><button type="button" class="pgx-btn is-primary" onclick="LeviPOS.openExpenseCategoryModal()">إضافة تصنيف مصروف</button></div>
                            </div>
                            <div id="settings-expense-categories-table"></div>
                        </section>
                    </div>
                    <?php if ($isOwner): ?>
                    <div class="tab-panel" id="tab-settings-owner"><div id="owner-cleanup-panel"></div></div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<div id="toast-container" class="toast-container"></div>

<div id="command-palette" class="command-palette" role="dialog" aria-modal="true" aria-label="شريط الأوامر" hidden>
    <div class="command-palette-box">
        <div class="command-palette-search">
            <span class="command-palette-ic" aria-hidden="true">⌕</span>
            <input id="cmd-search" type="search" autocomplete="off" aria-label="ابحث عن دواء أو مريض أو روشتة أو أمر"
                   placeholder="ابحث عن دواء، مريض، روشتة، أو نفّذ أمرًا…">
            <kbd>Ctrl K</kbd>
        </div>
        <div id="cmd-results" class="command-palette-results" role="listbox" aria-label="نتائج البحث"></div>
        <div class="command-palette-foot">↑ ↓ للتنقل · Enter للتنفيذ · Esc للإغلاق</div>
    </div>
</div>

<div id="modal-overlay" class="modal-overlay">
    <div id="modal-box" class="modal-box">
        <div class="modal-head">
            <h3 id="modal-title"></h3>
            <button type="button" class="modal-close" onclick="LeviPOS.closeModal()">×</button>
        </div>
        <div id="modal-body"></div>
        <div id="modal-footer" class="toolbar" style="margin-top:16px;justify-content:flex-end;"></div>
    </div>
</div>

<script src="assets/js/chart.local.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/chart.local.js') ?>"></script>
<script src="assets/js/levi-pos.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/levi-pos.js') ?>"></script>
</body>
</html>
