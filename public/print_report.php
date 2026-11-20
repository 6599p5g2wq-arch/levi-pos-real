<?php
declare(strict_types=1);

/**
 * LEVI POS - clean A4 print sheet for a generated report.
 * Keeps the whole application chrome out of the printed page.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\ReportService;

try {
    Auth::requireAnyPermission(['reports.view', 'reports.view_financial']);
} catch (Throwable $e) {
    http_response_code(403);
    exit('يرجى تسجيل الدخول لطباعة التقرير.');
}

$type = trim((string)($_GET['report_type'] ?? ''));
$startDate = trim((string)($_GET['start_date'] ?? date('Y-m-01')));
$endDate = trim((string)($_GET['end_date'] ?? date('Y-m-d')));

if ($type === '') {
    http_response_code(400);
    exit('يرجى تحديد نوع التقرير.');
}

try {
    $requiredPermission = ReportService::requiredPermission($type);
    if ($requiredPermission === 'audit.view') {
        if (!Auth::hasPermission('audit.view') && !Auth::hasPermission('users.manage')) {
            throw new RuntimeException('ليس لديك صلاحية عرض هذا التقرير.');
        }
    } elseif ($requiredPermission !== null) {
        Auth::requirePermission($requiredPermission);
    }

    $report = ReportService::getReport($type, [
        'start_date' => $startDate,
        'end_date' => $endDate,
    ], Auth::branchId());
} catch (Throwable $e) {
    http_response_code(403);
    exit('تعذر إنشاء التقرير للطباعة: ' . e($e->getMessage()));
}

$columns = $report['columns'] ?? [];
$rows = $report['rows'] ?? [];
$summary = $report['summary'] ?? [];
$period = $report['period'] ?? [];
$user = Auth::user() ?? [];
$branchId = Auth::branchId();

$pdo = Database::getConnection();
$branchStmt = $pdo->prepare('SELECT name FROM branches WHERE id = :id LIMIT 1');
$branchStmt->execute([':id' => $branchId]);
$branchName = (string)$branchStmt->fetchColumn();

$storeName = (string)levi_setting('store_name', 'LEVI POS');
$storeAddress = (string)levi_setting('store_address', '');
$storePhone = (string)levi_setting('store_phone', '');
$storeTaxNumber = (string)levi_setting('tax_number', '');
$currency = (string)levi_setting('base_currency', 'LYD');
$logoUrl = levi_logo_url((string)levi_setting('store_logo', 'assets/images/levi-logo.svg'));

/**
 * Format one report cell for print.
 */
function reportPrintValue($value, string $type): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if ($type === 'money') {
        return number_format((float)$value, 2, '.', ',');
    }
    if ($type === 'int') {
        return number_format((float)$value, 0, '.', ',');
    }
    return e($value);
}

$summaryLabels = [
    'report_date' => 'التاريخ', 'total_sales' => 'إجمالي المبيعات', 'gross_sales' => 'إجمالي المبيعات',
    'billed_sales' => 'المبيعات المفوترة', 'net_sales' => 'صافي المبيعات', 'discounts' => 'الخصومات',
    'tax_amount' => 'الضريبة', 'returns' => 'المرتجعات', 'sales_returns' => 'المرتجعات',
    'cash_amount' => 'كاش', 'card_amount' => 'بطاقة', 'transfer_amount' => 'تحويل',
    'gross_profit' => 'الربح الإجمالي', 'net_profit' => 'صافي الربح', 'expenses' => 'المصروفات',
    'total_discounts' => 'إجمالي الخصومات', 'total_cogs' => 'إجمالي التكلفة', 'net_cogs' => 'صافي التكلفة',
    'collected_amount' => 'المبلغ المحصّل', 'credit_debt_amount' => 'المبلغ الآجل',
    'inventory_valuation' => 'قيمة المخزون', 'invoice_count' => 'عدد الفواتير',
    'return_count' => 'عدد المرتجعات', 'expense_count' => 'عدد المصروفات',
    'net_revenue' => 'صافي الإيراد', 'qty_sold' => 'الكمية المباعة', 'net_qty' => 'صافي الكمية',
    'total_value' => 'القيمة الإجمالية', 'total_debt' => 'إجمالي الديون', 'total_amount' => 'الإجمالي',
    'paid_amount' => 'المدفوع', 'balance' => 'الرصيد', 'count' => 'العدد', 'total' => 'الإجمالي',
];
foreach ($columns as $col) {
    if (!empty($col['label']) && !empty($col['key'])) {
        $summaryLabels[$col['key']] = $col['label'];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($report['title'] ?? 'تقرير') ?> - LEVI POS</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 11mm 15mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 0;
            background: #eef0f2;
            color: #1f2937;
            font-family: Tahoma, 'Segoe UI', Arial, sans-serif;
            font-size: 11px;
            line-height: 1.5;
        }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 12mm 11mm;
            background: #fffdf9;
            box-shadow: 0 8px 28px rgba(15, 23, 42, .16);
        }
        .no-print-bar {
            width: 210mm;
            margin: 0 auto 12px;
            display: flex;
            justify-content: center;
            gap: 8px;
        }
        .no-print-bar button {
            border: 0;
            border-radius: 6px;
            padding: 9px 18px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-print { background: #047857; color: #fff; }
        .btn-close { background: #b45309; color: #fff; }
        .report-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #047857;
        }
        .brand { display: flex; align-items: center; gap: 10px; }
        .brand img { width: 42px; height: 42px; object-fit: contain; }
        .brand-name { font-size: 17px; font-weight: 800; color: #047857; }
        .brand-meta { font-size: 10px; color: #6b7280; margin-top: 2px; }
        .doc-label { text-align: left; }
        .doc-label strong { display: block; font-size: 13px; color: #b45309; letter-spacing: .5px; }
        .doc-label span { font-size: 10px; color: #6b7280; }
        .report-title {
            text-align: center;
            margin: 16px 0 4px;
            font-size: 18px;
            font-weight: 800;
            color: #1f2937;
        }
        .report-subtitle { text-align: center; font-size: 10.5px; color: #6b7280; margin-bottom: 14px; }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }
        .meta-box {
            background: #fbf7f0;
            border: 1px solid #e8e0d5;
            border-radius: 6px;
            padding: 7px 9px;
        }
        .meta-box span { display: block; font-size: 9.5px; color: #6b7280; }
        .meta-box strong { display: block; font-size: 11px; color: #1f2937; margin-top: 2px; }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }
        .summary-card {
            border: 1px solid #dfe6e2;
            border-right: 3px solid #047857;
            border-radius: 6px;
            padding: 8px 10px;
            background: #fff;
        }
        .summary-card span { display: block; font-size: 9.5px; color: #6b7280; }
        .summary-card strong { display: block; font-size: 14px; color: #047857; margin-top: 3px; }
        .section-title {
            font-size: 12px;
            font-weight: 800;
            color: #b45309;
            margin: 0 0 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e8e0d5;
        }
        table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        th {
            background: #047857;
            color: #fff;
            font-weight: 700;
            text-align: right;
            padding: 7px 6px;
            border: 1px solid #047857;
        }
        td {
            padding: 6px;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }
        tbody tr:nth-child(even) td { background: #fbf7f0; }
        td.num, th.num { text-align: left; direction: ltr; white-space: nowrap; }
        tfoot td {
            background: #fff7ed;
            border: 1px solid #b45309;
            font-weight: 800;
            color: #9a3412;
        }
        .empty {
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            padding: 26px;
            text-align: center;
            color: #6b7280;
        }
        .report-foot {
            margin-top: 26px;
            padding-top: 10px;
            border-top: 1px solid #e8e0d5;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
            font-size: 9.5px;
            color: #6b7280;
        }
        .signature { width: 150px; text-align: center; }
        .signature .line { border-top: 1px solid #374151; margin-bottom: 4px; }
        @media print {
            body { background: #fff; padding: 0; font-size: 10.5px; }
            .no-print-bar { display: none !important; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; background: #fff; }
        }
    </style>
</head>
<body>
<script>
    if (new URLSearchParams(window.location.search).get('preview') !== '1') {
        window.addEventListener('load', function () { window.print(); });
    }
</script>
    <div class="no-print-bar no-print">
        <button type="button" class="btn-print" onclick="window.print()">طباعة التقرير</button>
        <button type="button" class="btn-close" onclick="window.close()">إغلاق</button>
    </div>

    <div class="sheet">
        <div class="report-head">
            <div class="brand">
                <img src="<?= e($logoUrl) ?>" alt="">
                <div>
                    <div class="brand-name"><?= e($storeName) ?></div>
                    <div class="brand-meta">
                        <?= e($branchName) ?>
                        <?php if ($storePhone !== ''): ?> • هاتف: <?= e($storePhone) ?><?php endif; ?>
                        <?php if ($storeAddress !== ''): ?> • <?= e($storeAddress) ?><?php endif; ?>
                        <?php if ($storeTaxNumber !== ''): ?> • ضريبي: <?= e($storeTaxNumber) ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="doc-label">
                <strong>LEVI POS</strong>
                <span>تقرير رسمي — ورقة طباعة A4</span>
            </div>
        </div>

        <div class="report-title"><?= e($report['title'] ?? '') ?></div>
        <div class="report-subtitle">الفترة من <?= e($period['start_date'] ?? '') ?> إلى <?= e($period['end_date'] ?? '') ?></div>

        <div class="meta-grid">
            <div class="meta-box"><span>من تاريخ</span><strong><?= e($period['start_date'] ?? '') ?></strong></div>
            <div class="meta-box"><span>إلى تاريخ</span><strong><?= e($period['end_date'] ?? '') ?></strong></div>
            <div class="meta-box"><span>وقت الإنشاء</span><strong dir="ltr"><?= e($report['generated_at'] ?? date('Y-m-d H:i:s')) ?></strong></div>
            <div class="meta-box"><span>أعدّه</span><strong><?= e($user['full_name'] ?? '') ?></strong></div>
        </div>

        <?php if (!empty($summary)): ?>
            <div class="summary-grid">
                <?php foreach ($summary as $key => $value): ?>
                    <div class="summary-card">
                        <span><?= e($summaryLabels[$key] ?? $key) ?></span>
                        <strong dir="ltr"><?= is_numeric($value) ? number_format((float)$value, 2, '.', ',') : e($value) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="section-title">تفاصيل التقرير (<?= count($rows) ?> سجل)</div>
        <?php if (!count($rows)): ?>
            <div class="empty">لا توجد بيانات في الفترة المحددة.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <th class="<?= ($col['type'] ?? '') === 'money' || ($col['type'] ?? '') === 'int' ? 'num' : '' ?>"><?= e($col['label'] ?? '') ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $col): ?>
                                <?php $isNum = ($col['type'] ?? '') === 'money' || ($col['type'] ?? '') === 'int'; ?>
                                <td class="<?= $isNum ? 'num' : '' ?>"><?= reportPrintValue($row[$col['key']] ?? null, (string)($col['type'] ?? '')) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php
                $totalCells = [];
                foreach ($columns as $col) {
                    $key = (string)($col['key'] ?? '');
                    $totalCells[] = isset($summary[$key]) && is_numeric($summary[$key])
                        ? reportPrintValue($summary[$key], (string)($col['type'] ?? ''))
                        : '';
                }
                $hasTotals = array_filter($totalCells, static function (string $v): bool { return $v !== ''; });
                ?>
                <?php if ($hasTotals): ?>
                    <tfoot>
                        <tr>
                            <?php foreach ($columns as $i => $col): ?>
                                <td class="<?= ($col['type'] ?? '') === 'money' || ($col['type'] ?? '') === 'int' ? 'num' : '' ?>"><?= $i === 0 ? 'الإجمالي' : e($totalCells[$i]) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        <?php endif; ?>

        <div class="report-foot">
            <div>
                <div>العملة: <?= e($currency) ?></div>
                <div>تم إنشاء هذا التقرير آليًا من LEVI POS في <?= e(date('Y-m-d H:i:s')) ?></div>
            </div>
            <div class="signature">
                <div class="line"></div>
                <div>اعتماد المسؤول</div>
            </div>
        </div>
    </div>
</body>
</html>
