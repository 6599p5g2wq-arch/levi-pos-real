<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Financial & operational reporting engine.
 *
 * Accounting rules used everywhere:
 * - cancelled/held invoices are excluded from all sales reports
 * - returns are subtracted from revenue and COGS
 * - gross profit = net sales - net COGS
 * - net profit = gross profit - operating expenses
 */
class ReportService
{
    /**
     * Reports that expose financial figures and require reports.view_financial.
     * All other reports only require reports.view, and the audit log additionally
     * requires audit.view.
     */
    private const FINANCIAL_REPORT_TYPES = [
        'sales_summary' => true,
        'sales_by_product' => true,
        'sales_by_category' => true,
        'sales_by_customer' => true,
        'sales_by_cashier' => true,
        'sales_by_payment_method' => true,
        'sales_by_ingredient' => true,
        'profit_report' => true,
        'returns_report' => true,
        'expenses_report' => true,
        'cash_sessions' => true,
        'discounts_report' => true,
        'supplier_balances' => true,
        'customer_debts' => true,
        'supplier_price_comparison' => true,
        'best_selling' => true,
    ];

    public static function requiredPermission(string $type): ?string
    {
        if ($type === 'audit_log') {
            return 'audit.view';
        }
        return isset(self::FINANCIAL_REPORT_TYPES[$type]) ? 'reports.view_financial' : 'reports.view';
    }

    public static function filterReportTypes(array $types, bool $canViewFinancial, bool $canViewAudit): array
    {
        $filtered = [];
        foreach ($types as $key => $label) {
            $required = self::requiredPermission((string)$key);
            if ($required === 'reports.view_financial' && !$canViewFinancial) {
                continue;
            }
            if ($required === 'audit.view' && !$canViewAudit) {
                continue;
            }
            $filtered[$key] = $label;
        }
        return $filtered;
    }

    public static function getFinancialSummary(string $startDate, string $endDate, int $branchId = 1): array
    {
        $pdo = Database::getConnection();
        $start = $startDate . ' 00:00:00';
        $end = $endDate . ' 23:59:59';

        $salesStmt = $pdo->prepare('
            SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(subtotal), 0) AS gross_sales,
                COALESCE(SUM(total_discount), 0) AS total_discounts,
                COALESCE(SUM(total_amount), 0) AS billed_sales,
                COALESCE(SUM(total_cogs), 0) AS gross_cogs,
                COALESCE(SUM(paid_amount), 0) AS collected_amount,
                COALESCE(SUM(remaining_debt), 0) AS credit_debt_amount
            FROM sales
            WHERE branch_id = :b
              AND status IN (\'completed\', \'returned\')
              AND sale_date BETWEEN :start AND :end
              AND deleted_at IS NULL
        ');
        $salesStmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
        $s = $salesStmt->fetch();

        $retStmt = $pdo->prepare('
            SELECT
                COUNT(*) AS return_count,
                COALESCE(SUM(total_refund_amount), 0) AS sales_returns_amount,
                COALESCE(SUM(total_returned_cogs), 0) AS returned_cogs_amount
            FROM sale_returns
            WHERE branch_id = :b
              AND created_at BETWEEN :start AND :end
        ');
        $retStmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
        $r = $retStmt->fetch();

        $expStmt = $pdo->prepare('
            SELECT
                COUNT(*) AS expense_count,
                COALESCE(SUM(amount_in_base), 0) AS operating_expenses
            FROM expenses
            WHERE branch_id = :b
              AND is_archived = 0
              AND deleted_at IS NULL
              AND expense_date BETWEEN :start_date AND :end_date
        ');
        $expStmt->execute([':b' => $branchId, ':start_date' => $startDate, ':end_date' => $endDate]);
        $e = $expStmt->fetch();

        $billedSales = round((float)$s['billed_sales'], 3);
        $salesReturns = round((float)$r['sales_returns_amount'], 3);
        $netSales = round($billedSales - $salesReturns, 3);
        $netCogs = round((float)$s['gross_cogs'] - (float)$r['returned_cogs_amount'], 3);
        $grossProfit = round($netSales - $netCogs, 3);
        $operatingExpenses = round((float)$e['operating_expenses'], 3);
        $netProfit = round($grossProfit - $operatingExpenses, 3);

        $payStmt = $pdo->prepare('
            SELECT p.payment_method, COALESCE(SUM(p.amount_base), 0) AS total, COUNT(DISTINCT p.sale_id) AS invoices
            FROM payments p
            JOIN sales s ON s.id = p.sale_id
            WHERE s.branch_id = :b
              AND s.status IN (\'completed\', \'returned\')
              AND s.sale_date BETWEEN :start AND :end
              AND p.reversed_at IS NULL
            GROUP BY p.payment_method
            ORDER BY total DESC
        ');
        $payStmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
        $payments = $payStmt->fetchAll();

        return [
            'period' => ['start_date' => $startDate, 'end_date' => $endDate],
            'invoice_count' => (int)$s['invoice_count'],
            'return_count' => (int)$r['return_count'],
            'expense_count' => (int)$e['expense_count'],
            'gross_sales' => round((float)$s['gross_sales'], 3),
            'billed_sales' => $billedSales,
            'total_discounts' => round((float)$s['total_discounts'], 3),
            'sales_returns' => $salesReturns,
            'net_sales' => $netSales,
            'gross_cogs' => round((float)$s['gross_cogs'], 3),
            'returned_cogs' => round((float)$r['returned_cogs_amount'], 3),
            'net_cogs' => $netCogs,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingExpenses,
            'net_profit' => $netProfit,
            'collected_amount' => round((float)$s['collected_amount'], 3),
            'credit_debt_amount' => round((float)$s['credit_debt_amount'], 3),
            'payments' => $payments,
        ];
    }

    public static function getDashboardMetrics(int $branchId = 1): array
    {
        $pdo = Database::getConnection();
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');

        $todayFin = self::getFinancialSummary($today, $today, $branchId);
        $monthFin = self::getFinancialSummary($monthStart, $today, $branchId);

        $invStmt = $pdo->prepare('
            SELECT
                COUNT(DISTINCT p.id) AS total_products,
                COALESCE(SUM(CASE WHEN inv.quantity > 0 THEN inv.quantity * inv.average_cost ELSE 0 END), 0) AS inventory_valuation,
                COALESCE(SUM(CASE WHEN inv.quantity > 0 AND inv.quantity <= COALESCE(v.min_stock_level, p.min_stock_level) THEN 1 ELSE 0 END), 0) AS low_stock_count,
                COALESCE(SUM(CASE WHEN COALESCE(inv.quantity, 0) <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_count
            FROM products p
            LEFT JOIN product_variants v ON v.product_id = p.id AND v.deleted_at IS NULL AND v.is_active = 1
            LEFT JOIN inventory inv ON inv.product_id = p.id
                AND ((v.id IS NOT NULL AND inv.variant_id = v.id) OR (v.id IS NULL AND inv.variant_id IS NULL))
                AND inv.branch_id = :b
            WHERE p.deleted_at IS NULL AND p.is_archived = 0
        ');
        $invStmt->execute([':b' => $branchId]);
        $inv = $invStmt->fetch();

        $topStmt = $pdo->prepare('
            SELECT
                si.product_id,
                si.product_name_snapshot AS product_name,
                si.sku_snapshot AS sku,
                SUM(si.quantity - si.returned_quantity) AS net_qty_sold,
                SUM(si.net_total * (si.quantity - si.returned_quantity) / NULLIF(si.quantity, 0)) AS total_revenue,
                SUM((si.net_total - si.line_cogs) * (si.quantity - si.returned_quantity) / NULLIF(si.quantity, 0)) AS total_profit
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            WHERE s.branch_id = :b
              AND s.status IN (\'completed\', \'returned\')
              AND s.deleted_at IS NULL
            GROUP BY si.product_id, si.product_name_snapshot, si.sku_snapshot
            HAVING SUM(si.quantity - si.returned_quantity) > 0
            ORDER BY net_qty_sold DESC
            LIMIT 8
        ');
        $topStmt->execute([':b' => $branchId]);
        $topProducts = $topStmt->fetchAll();

        $catStmt = $pdo->prepare('
            SELECT
                c.name AS category_name,
                COALESCE(SUM(si.net_total * (si.quantity - si.returned_quantity) / NULLIF(si.quantity, 0)), 0) AS total_sales
            FROM categories c
            LEFT JOIN products p ON p.category_id = c.id AND p.deleted_at IS NULL
            LEFT JOIN sale_items si ON si.product_id = p.id
            LEFT JOIN sales s ON s.id = si.sale_id
                AND s.status IN (\'completed\', \'returned\')
                AND s.branch_id = :b
            GROUP BY c.id, c.name
            ORDER BY total_sales DESC
        ');
        $catStmt->execute([':b' => $branchId]);
        $salesByCategory = $catStmt->fetchAll();

        $recSalesStmt = $pdo->prepare('SELECT id, invoice_number, sale_date, total_amount, payment_method, payment_status FROM sales WHERE branch_id = :b AND status IN (\'completed\', \'returned\') ORDER BY id DESC LIMIT 5');
        $recSalesStmt->execute([':b' => $branchId]);
        $recSales = $recSalesStmt->fetchAll();

        $recPurchasesStmt = $pdo->prepare('SELECT p.id, p.invoice_number, p.purchase_date, p.total_amount, s.name AS supplier_name FROM purchases p JOIN suppliers s ON s.id = p.supplier_id WHERE p.branch_id = :b AND p.status = \'completed\' ORDER BY p.id DESC LIMIT 5');
        $recPurchasesStmt->execute([':b' => $branchId]);
        $recPurchases = $recPurchasesStmt->fetchAll();

        $recExpensesStmt = $pdo->prepare('SELECT e.id, e.expense_number, e.expense_date, e.amount_in_base, c.name AS category_name, e.description FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE e.branch_id = :b AND e.deleted_at IS NULL ORDER BY e.id DESC LIMIT 5');
        $recExpensesStmt->execute([':b' => $branchId]);
        $recExpenses = $recExpensesStmt->fetchAll();

        $pharmacy = [
            'expiry' => ['expired' => 0, 'near_expiry' => 0, 'alert_days' => 30],
            'storage_alerts' => 0,
            'supplier_alerts' => ['total_due' => 0, 'overdue_count' => 0],
            'forecast' => ['critical' => 0, 'low' => 0, 'items' => []],
            'interaction_alerts' => 0,
            'interaction_details' => [],
            'hourly_sales' => [],
            'prescriptions' => ['pending' => 0, 'dispensed_today' => 0],
            'patients' => ['new_today' => 0],
        ];
        try {
            $rxStmt = $pdo->prepare(
                "SELECT
                    SUM(CASE WHEN status = 'issued' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'dispensed' AND updated_at LIKE :today_dispensed THEN 1 ELSE 0 END) AS dispensed_today
                 FROM prescriptions"
            );
            $rxStmt->execute([':today_dispensed' => $today . '%']);
            $rx = $rxStmt->fetch();
            $pharmacy['prescriptions'] = [
                'pending' => (int)($rx['pending'] ?? 0),
                'dispensed_today' => (int)($rx['dispensed_today'] ?? 0),
            ];
        } catch (\Throwable $e) {
            // Keep the zero defaults when prescriptions are unavailable.
        }
        try {
            $patientStmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND created_at LIKE :today_created");
            $patientStmt->execute([':today_created' => $today . '%']);
            $pharmacy['patients'] = ['new_today' => (int)$patientStmt->fetchColumn()];
        } catch (\Throwable $e) {
            // Keep the zero defaults when patients are unavailable.
        }
        try {
            if (class_exists('App\\Services\\BatchService')) {
                $pharmacy['expiry'] = BatchService::alertCounts($branchId);
                $pharmacy['expiry']['alert_days'] = BatchService::alertDays();
            }
        } catch (\Throwable $e) {
            // Keep the zero defaults when the batches module is unavailable.
        }
        try {
            if (class_exists('App\\Services\\PharmacyInventoryService')) {
                $pharmacy['storage_alerts'] = count(PharmacyInventoryService::storageAlerts($branchId));
            }
        } catch (\Throwable $e) {
            // Optional module.
        }
        try {
            if (class_exists('App\\Services\\SupplierAlertService')) {
                $pharmacy['supplier_alerts'] = SupplierAlertService::summary($branchId);
            }
        } catch (\Throwable $e) {
            // Optional module.
        }
        try {
            if (class_exists('App\\Services\\DemandForecastService')) {
                $forecast = DemandForecastService::forecast($branchId, 30);
                $critical = 0;
                $low = 0;
                foreach ($forecast as $row) {
                    if (($row['status'] ?? '') === 'critical') {
                        $critical++;
                    } elseif (($row['status'] ?? '') === 'low') {
                        $low++;
                    }
                }
                $pharmacy['forecast'] = [
                    'critical' => $critical,
                    'low' => $low,
                    'items' => array_slice($forecast, 0, 6),
                ];
            }
        } catch (\Throwable $e) {
            // Optional module.
        }
        try {
            $hourStmt = $pdo->prepare(
                "SELECT CAST(substr(sale_date, 12, 2) AS INTEGER) AS sale_hour,
                        COUNT(*) AS invoice_count,
                        COALESCE(SUM(total_amount), 0) AS total_amount
                 FROM sales
                 WHERE branch_id = :b
                   AND status IN ('completed', 'returned')
                   AND deleted_at IS NULL
                   AND sale_date LIKE :today
                 GROUP BY sale_hour"
            );
            $hourStmt->execute([':b' => $branchId, ':today' => $today . '%']);
            $hourMap = [];
            foreach ($hourStmt->fetchAll() as $row) {
                $hour = (int)$row['sale_hour'];
                if ($hour < 8 || $hour > 19) {
                    continue;
                }
                $hourMap[$hour] = [
                    'hour' => $hour,
                    'invoice_count' => (int)$row['invoice_count'],
                    'total_amount' => round((float)$row['total_amount'], 3),
                ];
            }
            $hourly = [];
            for ($h = 8; $h <= 19; $h++) {
                $hourly[] = isset($hourMap[$h])
                    ? $hourMap[$h]
                    : ['hour' => $h, 'invoice_count' => 0, 'total_amount' => 0.0];
            }
            $pharmacy['hourly_sales'] = $hourly;
        } catch (\Throwable $e) {
            // Keep the hourly map empty when sales data is unavailable.
        }
        try {
            if (class_exists('App\\Services\\DrugInteractionService')) {
                $rxItemsStmt = $pdo->prepare(
                    "SELECT pr.id, pr.prescription_number, c.name AS patient_name, pi.product_id
                     FROM prescriptions pr
                     JOIN prescription_items pi ON pi.prescription_id = pr.id
                     LEFT JOIN customers c ON c.id = pr.patient_id
                     WHERE pr.status = 'issued'
                       AND pr.id IN (SELECT id FROM prescriptions WHERE status = 'issued' ORDER BY id DESC LIMIT 25)
                     ORDER BY pr.id DESC"
                );
                $rxItemsStmt->execute();
                $byPrescription = [];
                foreach ($rxItemsStmt->fetchAll() as $row) {
                    $rxId = (int)$row['id'];
                    if (!isset($byPrescription[$rxId])) {
                        $byPrescription[$rxId] = [
                            'id' => $rxId,
                            'prescription_number' => (string)$row['prescription_number'],
                            'patient_name' => (string)($row['patient_name'] ?? ''),
                            'product_ids' => [],
                        ];
                    }
                    $byPrescription[$rxId]['product_ids'][] = (int)$row['product_id'];
                }
                $interactionDetails = [];
                foreach ($byPrescription as $rx) {
                    $warnings = DrugInteractionService::check($rx['product_ids']);
                    if ($warnings === []) {
                        continue;
                    }
                    $first = $warnings[0];
                    $interactionDetails[] = [
                        'id' => $rx['id'],
                        'prescription_number' => $rx['prescription_number'],
                        'patient_name' => $rx['patient_name'],
                        'warning_count' => count($warnings),
                        'severity' => (string)($first['severity'] ?? 'moderate'),
                        'severity_label' => (string)($first['severity_label'] ?? 'متوسط'),
                        'message' => (string)($first['message'] ?? ''),
                    ];
                    if (count($interactionDetails) >= 5) {
                        break;
                    }
                }
                $pharmacy['interaction_details'] = $interactionDetails;
                $pharmacy['interaction_alerts'] = count($interactionDetails);
            }
        } catch (\Throwable $e) {
            // Keep zero interaction defaults when the module is unavailable.
        }

        return [
            'today' => $todayFin,
            'month' => $monthFin,
            'inventory' => [
                'total_products' => (int)$inv['total_products'],
                'inventory_valuation' => round((float)$inv['inventory_valuation'], 3),
                'low_stock_count' => (int)$inv['low_stock_count'],
                'out_of_stock_count' => (int)$inv['out_of_stock_count'],
            ],
            'top_products' => $topProducts,
            'sales_by_category' => $salesByCategory,
            'recent_sales' => $recSales,
            'recent_purchases' => $recPurchases,
            'recent_expenses' => $recExpenses,
            'pharmacy' => $pharmacy,
        ];
    }

    /**
     * Generic report dispatcher used by the API/UI.
     *
     * @return array{type:string,title:string,columns:array,rows:array,summary:array,period:array}
     */
    public static function getReport(string $type, array $filters, int $branchId): array
    {
        $startDate = self::validDate($filters['start_date'] ?? date('Y-m-01'), date('Y-m-01'));
        $endDate = self::validDate($filters['end_date'] ?? date('Y-m-d'), date('Y-m-d'));
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }
        $period = ['start_date' => $startDate, 'end_date' => $endDate];
        $start = $startDate . ' 00:00:00';
        $end = $endDate . ' 23:59:59';
        $pdo = Database::getConnection();

        switch ($type) {
            case 'sales_summary':
                $data = self::dailySales($pdo, $branchId, $start, $end, $startDate, $endDate);
                return self::pack('sales_summary', 'ملخص المبيعات اليومي', [
                    ['key' => 'report_date', 'label' => 'التاريخ', 'type' => 'date'],
                    ['key' => 'invoice_count', 'label' => 'الفواتير', 'type' => 'int'],
                    ['key' => 'gross_sales', 'label' => 'إجمالي المبيعات', 'type' => 'money'],
                    ['key' => 'discounts', 'label' => 'الخصومات', 'type' => 'money'],
                    ['key' => 'tax_amount', 'label' => 'الضريبة', 'type' => 'money'],
                    ['key' => 'cash_amount', 'label' => 'كاش', 'type' => 'money'],
                    ['key' => 'card_amount', 'label' => 'بطاقة', 'type' => 'money'],
                    ['key' => 'transfer_amount', 'label' => 'تحويل', 'type' => 'money'],
                    ['key' => 'returns', 'label' => 'المرتجعات', 'type' => 'money'],
                    ['key' => 'net_sales', 'label' => 'صافي المبيعات', 'type' => 'money'],
                    ['key' => 'net_cogs', 'label' => 'تكلفة البضاعة', 'type' => 'money'],
                    ['key' => 'gross_profit', 'label' => 'الربح الإجمالي', 'type' => 'money'],
                ], $data['rows'], $data['totals'], $period);

            case 'sales_by_product':
                $stmt = $pdo->prepare('
                    SELECT si.product_id, si.product_name_snapshot AS product_name, si.sku_snapshot AS sku,
                           SUM(si.quantity) AS qty_sold,
                           SUM(si.returned_quantity) AS qty_returned,
                           SUM(si.quantity - si.returned_quantity) AS net_qty,
                           SUM(si.net_total * (si.quantity - si.returned_quantity) / NULLIF(si.quantity, 0)) AS net_revenue,
                           SUM(si.line_cogs * (si.quantity - si.returned_quantity) / NULLIF(si.quantity, 0)) AS net_cogs
                    FROM sale_items si
                    JOIN sales s ON s.id = si.sale_id
                    WHERE s.branch_id = :b AND s.status IN (\'completed\', \'returned\')
                      AND s.sale_date BETWEEN :start AND :end AND s.deleted_at IS NULL
                    GROUP BY si.product_id, si.product_name_snapshot, si.sku_snapshot
                    ORDER BY net_revenue DESC
                ');
                $stmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
                $rows = $stmt->fetchAll();
                $totals = ['net_qty' => 0, 'net_revenue' => 0.0, 'net_cogs' => 0.0, 'gross_profit' => 0.0];
                foreach ($rows as &$row) {
                    $row['gross_profit'] = round((float)$row['net_revenue'] - (float)$row['net_cogs'], 3);
                    $totals['net_qty'] += (int)$row['net_qty'];
                    $totals['net_revenue'] += (float)$row['net_revenue'];
                    $totals['net_cogs'] += (float)$row['net_cogs'];
                    $totals['gross_profit'] += (float)$row['gross_profit'];
                }
                unset($row);
                return self::pack('sales_by_product', 'المبيعات حسب المنتج', [
                    ['key' => 'product_name', 'label' => 'المنتج', 'type' => 'text'],
                    ['key' => 'sku', 'label' => 'الرمز', 'type' => 'text'],
                    ['key' => 'qty_sold', 'label' => 'الكمية المباعة', 'type' => 'int'],
                    ['key' => 'qty_returned', 'label' => 'المرتجع', 'type' => 'int'],
                    ['key' => 'net_qty', 'label' => 'الصافي', 'type' => 'int'],
                    ['key' => 'net_revenue', 'label' => 'صافي الإيراد', 'type' => 'money'],
                    ['key' => 'net_cogs', 'label' => 'التكلفة', 'type' => 'money'],
                    ['key' => 'gross_profit', 'label' => 'الربح', 'type' => 'money'],
                ], $rows, $totals, $period);

            case 'sales_by_category':
                $stmt = $pdo->prepare('
                    SELECT c.name AS category_name,
                           SUM(si.quantity - si.returned_quantity) AS net_qty,
                           SUM(si.net_total * (si.quantity - si.returned_quantity) / NULLIF(si.quantity, 0)) AS net_revenue,
                           SUM(si.line_cogs * (si.quantity - si.returned_quantity) / NULLIF(si.quantity, 0)) AS net_cogs
                    FROM sale_items si
                    JOIN sales s ON s.id = si.sale_id
                    JOIN products p ON p.id = si.product_id
                    JOIN categories c ON c.id = p.category_id
                    WHERE s.branch_id = :b AND s.status IN (\'completed\', \'returned\')
                      AND s.sale_date BETWEEN :start AND :end AND s.deleted_at IS NULL
                    GROUP BY c.id, c.name
                    ORDER BY net_revenue DESC
                ');
                $stmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
                $rows = $stmt->fetchAll();
                $totals = ['net_qty' => 0, 'net_revenue' => 0.0, 'gross_profit' => 0.0];
                foreach ($rows as &$row) {
                    $row['gross_profit'] = round((float)$row['net_revenue'] - (float)$row['net_cogs'], 3);
                    $totals['net_qty'] += (int)$row['net_qty'];
                    $totals['net_revenue'] += (float)$row['net_revenue'];
                    $totals['gross_profit'] += (float)$row['gross_profit'];
                }
                unset($row);
                return self::pack('sales_by_category', 'المبيعات حسب التصنيف', [
                    ['key' => 'category_name', 'label' => 'التصنيف', 'type' => 'text'],
                    ['key' => 'net_qty', 'label' => 'الكمية الصافية', 'type' => 'int'],
                    ['key' => 'net_revenue', 'label' => 'صافي الإيراد', 'type' => 'money'],
                    ['key' => 'gross_profit', 'label' => 'الربح', 'type' => 'money'],
                ], $rows, $totals, $period);

            case 'sales_by_customer':
                $stmt = $pdo->prepare('
                    SELECT COALESCE(c.name, \'عميل نقدي\') AS customer_name, COALESCE(c.phone, \'-\') AS phone,
                           COUNT(DISTINCT s.id) AS invoice_count,
                           COALESCE(SUM(s.total_amount), 0) AS gross_sales,
                           COALESCE(SUM(s.refunded_amount), 0) AS refunds,
                           COALESCE(SUM(s.total_amount - s.refunded_amount), 0) AS net_sales,
                           COALESCE(SUM(s.remaining_debt), 0) AS debt
                    FROM sales s
                    LEFT JOIN customers c ON c.id = s.customer_id
                    WHERE s.branch_id = :b AND s.status IN (\'completed\', \'returned\')
                      AND s.sale_date BETWEEN :start AND :end AND s.deleted_at IS NULL
                    GROUP BY s.customer_id, c.name, c.phone
                    ORDER BY net_sales DESC
                ');
                $stmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
                $rows = $stmt->fetchAll();
                $totals = ['invoice_count' => 0, 'net_sales' => 0.0, 'debt' => 0.0];
                foreach ($rows as $row) {
                    $totals['invoice_count'] += (int)$row['invoice_count'];
                    $totals['net_sales'] += (float)$row['net_sales'];
                    $totals['debt'] += (float)$row['debt'];
                }
                return self::pack('sales_by_customer', 'المبيعات حسب العميل', [
                    ['key' => 'customer_name', 'label' => 'العميل', 'type' => 'text'],
                    ['key' => 'phone', 'label' => 'الهاتف', 'type' => 'text'],
                    ['key' => 'invoice_count', 'label' => 'الفواتير', 'type' => 'int'],
                    ['key' => 'net_sales', 'label' => 'صافي المبيعات', 'type' => 'money'],
                    ['key' => 'debt', 'label' => 'الدين المتبقي', 'type' => 'money'],
                ], $rows, $totals, $period);

            case 'sales_by_cashier':
                $stmt = $pdo->prepare('
                    SELECT u.full_name AS cashier_name, COUNT(DISTINCT s.id) AS invoice_count,
                           COALESCE(SUM(s.total_amount), 0) AS gross_sales,
                           COALESCE(SUM(s.total_discount), 0) AS discounts,
                           COALESCE(SUM(s.total_amount - s.refunded_amount), 0) AS net_sales
                    FROM sales s
                    JOIN users u ON u.id = s.cashier_id
                    WHERE s.branch_id = :b AND s.status IN (\'completed\', \'returned\')
                      AND s.sale_date BETWEEN :start AND :end AND s.deleted_at IS NULL
                    GROUP BY s.cashier_id, u.full_name
                    ORDER BY net_sales DESC
                ');
                $stmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
                $rows = $stmt->fetchAll();
                $totals = ['invoice_count' => 0, 'gross_sales' => 0.0, 'discounts' => 0.0, 'net_sales' => 0.0];
                foreach ($rows as $row) {
                    $totals['invoice_count'] += (int)$row['invoice_count'];
                    $totals['gross_sales'] += (float)$row['gross_sales'];
                    $totals['discounts'] += (float)$row['discounts'];
                    $totals['net_sales'] += (float)$row['net_sales'];
                }
                return self::pack('sales_by_cashier', 'المبيعات حسب الكاشير', [
                    ['key' => 'cashier_name', 'label' => 'الكاشير', 'type' => 'text'],
                    ['key' => 'invoice_count', 'label' => 'الفواتير', 'type' => 'int'],
                    ['key' => 'gross_sales', 'label' => 'إجمالي المبيعات', 'type' => 'money'],
                    ['key' => 'discounts', 'label' => 'الخصومات', 'type' => 'money'],
                    ['key' => 'net_sales', 'label' => 'صافي المبيعات', 'type' => 'money'],
                ], $rows, $totals, $period);

            case 'sales_by_payment_method':
                $stmt = $pdo->prepare('
                    SELECT p.payment_method, COUNT(DISTINCT p.sale_id) AS invoice_count,
                           COALESCE(SUM(p.amount_base), 0) AS total_amount
                    FROM payments p
                    JOIN sales s ON s.id = p.sale_id
                    WHERE s.branch_id = :b AND s.status IN (\'completed\', \'returned\')
                      AND s.sale_date BETWEEN :start AND :end AND p.reversed_at IS NULL
                    GROUP BY p.payment_method
                    ORDER BY total_amount DESC
                ');
                $stmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
                $rows = $stmt->fetchAll();
                $labels = ['cash' => 'نقدي', 'card' => 'بطاقة', 'transfer' => 'تحويل', 'cheque' => 'شيك', 'credit' => 'آجل'];
                $totals = ['invoice_count' => 0, 'total_amount' => 0.0];
                foreach ($rows as &$row) {
                    $row['payment_method_label'] = $labels[$row['payment_method']] ?? $row['payment_method'];
                    $totals['invoice_count'] += (int)$row['invoice_count'];
                    $totals['total_amount'] += (float)$row['total_amount'];
                }
                unset($row);
                return self::pack('sales_by_payment_method', 'المبيعات حسب طريقة الدفع', [
                    ['key' => 'payment_method_label', 'label' => 'طريقة الدفع', 'type' => 'text'],
                    ['key' => 'invoice_count', 'label' => 'الفواتير', 'type' => 'int'],
                    ['key' => 'total_amount', 'label' => 'المبلغ', 'type' => 'money'],
                ], $rows, $totals, $period);

            case 'profit_report':
                $sales = self::dailySales($pdo, $branchId, $start, $end, $startDate, $endDate);
                $expStmt = $pdo->prepare('
                    SELECT expense_date AS report_date, COALESCE(SUM(amount_in_base), 0) AS expenses
                    FROM expenses
                    WHERE branch_id = :b AND deleted_at IS NULL AND is_archived = 0
                      AND expense_date BETWEEN :start_date AND :end_date
                    GROUP BY expense_date
                ');
                $expStmt->execute([':b' => $branchId, ':start_date' => $startDate, ':end_date' => $endDate]);
                $expensesByDate = [];
                foreach ($expStmt->fetchAll() as $row) {
                    $expensesByDate[$row['report_date']] = (float)$row['expenses'];
                }
                $rows = [];
                $totals = ['net_sales' => 0.0, 'net_cogs' => 0.0, 'gross_profit' => 0.0, 'expenses' => 0.0, 'net_profit' => 0.0];
                foreach ($sales['rows'] as $row) {
                    $expenses = $expensesByDate[$row['report_date']] ?? 0.0;
                    $netProfit = round((float)$row['gross_profit'] - $expenses, 3);
                    $rows[] = [
                        'report_date' => $row['report_date'],
                        'net_sales' => $row['net_sales'],
                        'net_cogs' => $row['net_cogs'],
                        'gross_profit' => $row['gross_profit'],
                        'expenses' => round($expenses, 3),
                        'net_profit' => $netProfit,
                    ];
                    $totals['net_sales'] += (float)$row['net_sales'];
                    $totals['net_cogs'] += (float)$row['net_cogs'];
                    $totals['gross_profit'] += (float)$row['gross_profit'];
                    $totals['expenses'] += $expenses;
                    $totals['net_profit'] += $netProfit;
                }
                return self::pack('profit_report', 'تقرير الأرباح والخسائر', [
                    ['key' => 'report_date', 'label' => 'التاريخ', 'type' => 'date'],
                    ['key' => 'net_sales', 'label' => 'صافي المبيعات', 'type' => 'money'],
                    ['key' => 'net_cogs', 'label' => 'تكلفة البضاعة', 'type' => 'money'],
                    ['key' => 'gross_profit', 'label' => 'الربح الإجمالي', 'type' => 'money'],
                    ['key' => 'expenses', 'label' => 'المصروفات', 'type' => 'money'],
                    ['key' => 'net_profit', 'label' => 'صافي الربح', 'type' => 'money'],
                ], $rows, $totals, $period);

            case 'returns_report':
                $stmt = $pdo->prepare('
                    SELECT sr.return_number, sr.created_at, s.invoice_number,
                           COALESCE(c.name, \'عميل نقدي\') AS customer_name,
                           sr.total_refund_amount, sr.cash_refund_amount, sr.card_refund_amount,
                           sr.debt_reduction_amount, sr.refund_method, sr.reason, u.full_name AS user_name
                    FROM sale_returns sr
                    JOIN sales s ON s.id = sr.sale_id
                    LEFT JOIN customers c ON c.id = sr.customer_id
                    JOIN users u ON u.id = sr.created_by
                    WHERE sr.branch_id = :b AND sr.created_at BETWEEN :start AND :end
                    ORDER BY sr.id DESC
                ');
                $stmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
                $rows = $stmt->fetchAll();
                $totals = ['total_refund_amount' => 0.0, 'cash_refund_amount' => 0.0, 'card_refund_amount' => 0.0, 'debt_reduction_amount' => 0.0];
                foreach ($rows as $row) {
                    $totals['total_refund_amount'] += (float)$row['total_refund_amount'];
                    $totals['cash_refund_amount'] += (float)$row['cash_refund_amount'];
                    $totals['card_refund_amount'] += (float)$row['card_refund_amount'];
                    $totals['debt_reduction_amount'] += (float)$row['debt_reduction_amount'];
                }
                return self::pack('returns_report', 'تقرير المرتجعات', [
                    ['key' => 'return_number', 'label' => 'رقم المرتجع', 'type' => 'text'],
                    ['key' => 'created_at', 'label' => 'التاريخ', 'type' => 'datetime'],
                    ['key' => 'invoice_number', 'label' => 'الفاتورة', 'type' => 'text'],
                    ['key' => 'customer_name', 'label' => 'العميل', 'type' => 'text'],
                    ['key' => 'total_refund_amount', 'label' => 'إجمالي الاسترداد', 'type' => 'money'],
                    ['key' => 'cash_refund_amount', 'label' => 'نقدي', 'type' => 'money'],
                    ['key' => 'card_refund_amount', 'label' => 'بطاقة', 'type' => 'money'],
                    ['key' => 'debt_reduction_amount', 'label' => 'خصم دين', 'type' => 'money'],
                    ['key' => 'reason', 'label' => 'السبب', 'type' => 'text'],
                ], $rows, $totals, $period);

            case 'inventory_valuation':
                $stmt = $pdo->prepare('
                    SELECT p.name AS product_name, p.sku, v.variant_name,
                           COALESCE(inv.quantity, 0) AS quantity,
                           COALESCE(inv.average_cost, p.purchase_cost) AS average_cost,
                           COALESCE(inv.quantity, 0) * COALESCE(inv.average_cost, p.purchase_cost) AS stock_value,
                           COALESCE(v.selling_price, p.selling_price) AS selling_price,
                           COALESCE(inv.quantity, 0) * COALESCE(v.selling_price, p.selling_price) AS potential_revenue
                    FROM products p
                    LEFT JOIN product_variants v ON v.product_id = p.id AND v.deleted_at IS NULL AND v.is_active = 1
                    LEFT JOIN inventory inv ON inv.product_id = p.id
                        AND ((v.id IS NOT NULL AND inv.variant_id = v.id) OR (v.id IS NULL AND inv.variant_id IS NULL))
                        AND inv.branch_id = :b
                    WHERE p.deleted_at IS NULL AND p.is_archived = 0
                    ORDER BY stock_value DESC
                ');
                $stmt->execute([':b' => $branchId]);
                $rows = $stmt->fetchAll();
                $totals = ['quantity' => 0, 'stock_value' => 0.0, 'potential_revenue' => 0.0];
                foreach ($rows as $row) {
                    $totals['quantity'] += (int)$row['quantity'];
                    $totals['stock_value'] += (float)$row['stock_value'];
                    $totals['potential_revenue'] += (float)$row['potential_revenue'];
                }
                return self::pack('inventory_valuation', 'تقييم المخزون', [
                    ['key' => 'product_name', 'label' => 'المنتج', 'type' => 'text'],
                    ['key' => 'variant_name', 'label' => 'الخيار', 'type' => 'text'],
                    ['key' => 'sku', 'label' => 'الرمز', 'type' => 'text'],
                    ['key' => 'quantity', 'label' => 'الكمية', 'type' => 'int'],
                    ['key' => 'average_cost', 'label' => 'متوسط التكلفة', 'type' => 'money'],
                    ['key' => 'stock_value', 'label' => 'قيمة المخزون', 'type' => 'money'],
                    ['key' => 'selling_price', 'label' => 'سعر البيع', 'type' => 'money'],
                    ['key' => 'potential_revenue', 'label' => 'الإيراد المحتمل', 'type' => 'money'],
                ], $rows, $totals, $period);

            case 'low_stock':
                $rows = InventoryService::getLowStock($branchId, 500);
                foreach ($rows as &$row) {
                    $min = (int)($row['variant_min_stock'] ?? $row['min_stock_level']);
                    $row['deficit'] = max(0, $min - (int)$row['quantity']);
                }
                unset($row);
                $totals = ['quantity' => 0, 'deficit' => 0];
                foreach ($rows as $row) {
                    $totals['quantity'] += (int)$row['quantity'];
                    $totals['deficit'] += (int)$row['deficit'];
                }
                return self::pack('low_stock', 'تنبيهات المخزون المنخفض', [
                    ['key' => 'product_name', 'label' => 'المنتج', 'type' => 'text'],
                    ['key' => 'variant_name', 'label' => 'الخيار', 'type' => 'text'],
                    ['key' => 'sku', 'label' => 'الرمز', 'type' => 'text'],
                    ['key' => 'quantity', 'label' => 'المتوفر', 'type' => 'int'],
                    ['key' => 'min_stock_level', 'label' => 'الحد الأدنى', 'type' => 'int'],
                    ['key' => 'deficit', 'label' => 'النقص', 'type' => 'int'],
                ], $rows, $totals, $period);

            case 'inventory_movements':
                $rows = InventoryService::getMovements($branchId, [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'limit' => 1000,
                ]);
                $movementLabels = [
                    'sale' => 'بيع',
                    'purchase' => 'شراء',
                    'sale_return' => 'مرتجع بيع',
                    'purchase_return' => 'مرتجع شراء',
                    'adjustment' => 'تسوية مخزون',
                    'stocktake' => 'جرد',
                    'damaged' => 'تالف',
                    'lost' => 'مفقود',
                    'initial' => 'رصيد افتتاحي',
                    'sale_cancel' => 'إلغاء بيع',
                    'purchase_cancel' => 'إلغاء شراء',
                    'transfer_out' => 'تحويل صادر',
                    'transfer_in' => 'تحويل وارد',
                ];
                foreach ($rows as &$row) {
                    $row['movement_type_label'] = $movementLabels[(string)$row['movement_type']] ?? (string)$row['movement_type'];
                }
                unset($row);
                return self::pack('inventory_movements', 'حركات المخزون', [
                    ['key' => 'created_at', 'label' => 'التاريخ', 'type' => 'datetime'],
                    ['key' => 'product_name', 'label' => 'المنتج', 'type' => 'text'],
                    ['key' => 'variant_name', 'label' => 'الخيار', 'type' => 'text'],
                    ['key' => 'movement_type_label', 'label' => 'نوع الحركة', 'type' => 'text'],
                    ['key' => 'quantity_before', 'label' => 'قبل', 'type' => 'int'],
                    ['key' => 'quantity_change', 'label' => 'التغيير', 'type' => 'int'],
                    ['key' => 'quantity_after', 'label' => 'بعد', 'type' => 'int'],
                    ['key' => 'unit_cost', 'label' => 'التكلفة', 'type' => 'money'],
                    ['key' => 'invoice_number', 'label' => 'المرجع', 'type' => 'text'],
                    ['key' => 'user_name', 'label' => 'المستخدم', 'type' => 'text'],
                ], $rows, ['count' => count($rows)], $period);

            case 'purchases_report':
                $stmt = $pdo->prepare('
                    SELECT pu.invoice_number, pu.purchase_date, s.name AS supplier_name,
                           pu.total_amount, pu.paid_amount, pu.remaining_amount,
                           pu.payment_status, pu.status
                    FROM purchases pu
                    JOIN suppliers s ON s.id = pu.supplier_id
                    WHERE pu.branch_id = :b AND pu.purchase_date BETWEEN :start AND :end
                    ORDER BY pu.id DESC
                ');
                $stmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
                $rows = $stmt->fetchAll();
                $totals = ['total_amount' => 0.0, 'paid_amount' => 0.0, 'remaining_amount' => 0.0];
                foreach ($rows as $row) {
                    if ($row['status'] === 'cancelled') {
                        continue;
                    }
                    $totals['total_amount'] += (float)$row['total_amount'];
                    $totals['paid_amount'] += (float)$row['paid_amount'];
                    $totals['remaining_amount'] += (float)$row['remaining_amount'];
                }
                return self::pack('purchases_report', 'تقرير المشتريات', [
                    ['key' => 'invoice_number', 'label' => 'الفاتورة', 'type' => 'text'],
                    ['key' => 'purchase_date', 'label' => 'التاريخ', 'type' => 'datetime'],
                    ['key' => 'supplier_name', 'label' => 'المورد', 'type' => 'text'],
                    ['key' => 'total_amount', 'label' => 'الإجمالي', 'type' => 'money'],
                    ['key' => 'paid_amount', 'label' => 'المدفوع', 'type' => 'money'],
                    ['key' => 'remaining_amount', 'label' => 'المتبقي', 'type' => 'money'],
                    ['key' => 'payment_status', 'label' => 'حالة الدفع', 'type' => 'text'],
                    ['key' => 'status', 'label' => 'الحالة', 'type' => 'text'],
                ], $rows, $totals, $period);

            case 'supplier_balances':
                $stmt = $pdo->prepare('
                    SELECT s.name AS supplier_name, s.phone, s.current_balance,
                           (SELECT MAX(p.purchase_date) FROM purchases p WHERE p.supplier_id = s.id AND p.status = \'completed\') AS last_purchase
                    FROM suppliers s
                    WHERE s.deleted_at IS NULL AND s.is_active = 1
                    ORDER BY s.current_balance DESC
                ');
                $stmt->execute();
                $rows = $stmt->fetchAll();
                $totals = ['current_balance' => 0.0];
                foreach ($rows as $row) {
                    $totals['current_balance'] += (float)$row['current_balance'];
                }
                return self::pack('supplier_balances', 'أرصدة الموردين', [
                    ['key' => 'supplier_name', 'label' => 'المورد', 'type' => 'text'],
                    ['key' => 'phone', 'label' => 'الهاتف', 'type' => 'text'],
                    ['key' => 'current_balance', 'label' => 'الرصيد المستحق', 'type' => 'money'],
                    ['key' => 'last_purchase', 'label' => 'آخر عملية شراء', 'type' => 'datetime'],
                ], $rows, $totals, $period);

            case 'customer_debts':
                $stmt = $pdo->prepare('
                    SELECT c.name AS customer_name, c.phone, c.current_debt, c.credit_limit,
                           (SELECT MAX(s.sale_date) FROM sales s WHERE s.customer_id = c.id AND s.status IN (\'completed\', \'returned\')) AS last_sale
                    FROM customers c
                    WHERE c.deleted_at IS NULL AND c.is_active = 1 AND c.current_debt > 0
                    ORDER BY c.current_debt DESC
                ');
                $stmt->execute();
                $rows = $stmt->fetchAll();
                $totals = ['current_debt' => 0.0];
                foreach ($rows as $row) {
                    $totals['current_debt'] += (float)$row['current_debt'];
                }
                return self::pack('customer_debts', 'ديون العملاء', [
                    ['key' => 'customer_name', 'label' => 'العميل', 'type' => 'text'],
                    ['key' => 'phone', 'label' => 'الهاتف', 'type' => 'text'],
                    ['key' => 'current_debt', 'label' => 'الدين الحالي', 'type' => 'money'],
                    ['key' => 'credit_limit', 'label' => 'حد الائتمان', 'type' => 'money'],
                    ['key' => 'last_sale', 'label' => 'آخر عملية بيع', 'type' => 'datetime'],
                ], $rows, $totals, $period);

            case 'expenses_report':
                $stmt = $pdo->prepare('
                    SELECT e.expense_number, e.expense_date, ec.name AS category_name,
                           e.description, e.amount_in_base, e.payment_method, u.full_name AS user_name
                    FROM expenses e
                    JOIN expense_categories ec ON ec.id = e.category_id
                    JOIN users u ON u.id = e.created_by
                    WHERE e.branch_id = :b AND e.deleted_at IS NULL AND e.is_archived = 0
                      AND e.expense_date BETWEEN :start_date AND :end_date
                    ORDER BY e.expense_date DESC, e.id DESC
                ');
                $stmt->execute([':b' => $branchId, ':start_date' => $startDate, ':end_date' => $endDate]);
                $rows = $stmt->fetchAll();
                $totals = ['amount_in_base' => 0.0];
                foreach ($rows as $row) {
                    $totals['amount_in_base'] += (float)$row['amount_in_base'];
                }
                return self::pack('expenses_report', 'تقرير المصروفات', [
                    ['key' => 'expense_number', 'label' => 'رقم السند', 'type' => 'text'],
                    ['key' => 'expense_date', 'label' => 'التاريخ', 'type' => 'date'],
                    ['key' => 'category_name', 'label' => 'التصنيف', 'type' => 'text'],
                    ['key' => 'description', 'label' => 'البيان', 'type' => 'text'],
                    ['key' => 'amount_in_base', 'label' => 'المبلغ', 'type' => 'money'],
                    ['key' => 'payment_method', 'label' => 'طريقة الدفع', 'type' => 'text'],
                    ['key' => 'user_name', 'label' => 'المستخدم', 'type' => 'text'],
                ], $rows, $totals, $period);

            case 'cash_sessions':
                $rows = CashRegisterService::getSessions($branchId, [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'limit' => 300,
                ]);
                $totals = ['opening_cash' => 0.0, 'expected_cash' => 0.0, 'actual_cash' => 0.0, 'discrepancy' => 0.0];
                foreach ($rows as $row) {
                    $totals['opening_cash'] += (float)$row['opening_cash'];
                    $totals['expected_cash'] += (float)$row['expected_cash'];
                    $totals['actual_cash'] += (float)($row['actual_cash'] ?? 0);
                    $totals['discrepancy'] += (float)($row['discrepancy'] ?? 0);
                }
                return self::pack('cash_sessions', 'ورديات الصندوق', [
                    ['key' => 'session_number', 'label' => 'الوردية', 'type' => 'text'],
                    ['key' => 'register_name', 'label' => 'الصندوق', 'type' => 'text'],
                    ['key' => 'user_name', 'label' => 'المستخدم', 'type' => 'text'],
                    ['key' => 'opened_at', 'label' => 'الفتح', 'type' => 'datetime'],
                    ['key' => 'closed_at', 'label' => 'الإغلاق', 'type' => 'datetime'],
                    ['key' => 'opening_cash', 'label' => 'الافتتاحي', 'type' => 'money'],
                    ['key' => 'expected_cash', 'label' => 'المتوقع', 'type' => 'money'],
                    ['key' => 'actual_cash', 'label' => 'الفعلي', 'type' => 'money'],
                    ['key' => 'discrepancy', 'label' => 'الفرق', 'type' => 'money'],
                    ['key' => 'status', 'label' => 'الحالة', 'type' => 'text'],
                ], $rows, $totals, $period);

            case 'discounts_report':
                $stmt = $pdo->prepare('
                    SELECT DATE(sale_date) AS report_date, COUNT(*) AS invoice_count,
                           COALESCE(SUM(item_discount_total), 0) AS item_discount,
                           COALESCE(SUM(invoice_discount), 0) AS invoice_discount,
                           COALESCE(SUM(promotion_discount), 0) AS promotion_discount,
                           COALESCE(SUM(total_discount), 0) AS total_discount
                    FROM sales
                    WHERE branch_id = :b AND status IN (\'completed\', \'returned\')
                      AND sale_date BETWEEN :start AND :end AND deleted_at IS NULL
                    GROUP BY DATE(sale_date)
                    ORDER BY report_date DESC
                ');
                $stmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
                $rows = $stmt->fetchAll();
                $totals = ['invoice_count' => 0, 'item_discount' => 0.0, 'invoice_discount' => 0.0, 'promotion_discount' => 0.0, 'total_discount' => 0.0];
                foreach ($rows as $row) {
                    $totals['invoice_count'] += (int)$row['invoice_count'];
                    $totals['item_discount'] += (float)$row['item_discount'];
                    $totals['invoice_discount'] += (float)$row['invoice_discount'];
                    $totals['promotion_discount'] += (float)$row['promotion_discount'];
                    $totals['total_discount'] += (float)$row['total_discount'];
                }
                return self::pack('discounts_report', 'تقرير الخصومات', [
                    ['key' => 'report_date', 'label' => 'التاريخ', 'type' => 'date'],
                    ['key' => 'invoice_count', 'label' => 'الفواتير', 'type' => 'int'],
                    ['key' => 'item_discount', 'label' => 'خصم الأصناف', 'type' => 'money'],
                    ['key' => 'invoice_discount', 'label' => 'خصم الفاتورة', 'type' => 'money'],
                    ['key' => 'promotion_discount', 'label' => 'خصم ترويجي (سجل سابق)', 'type' => 'money'],
                    ['key' => 'total_discount', 'label' => 'إجمالي الخصم', 'type' => 'money'],
                ], $rows, $totals, $period);

            case 'expired_products':
                $stmt = $pdo->prepare('
                    SELECT pb.batch_number, p.name AS product_name, p.sku,
                           p.active_ingredient, p.strength, pb.expiry_date,
                           pb.quantity, pb.purchase_cost
                    FROM product_batches pb
                    JOIN products p ON p.id = pb.product_id
                    WHERE pb.branch_id = :b AND pb.quantity > 0 AND pb.expiry_date < :today
                          AND p.deleted_at IS NULL
                    ORDER BY pb.expiry_date ASC
                ');
                $stmt->execute([':b' => $branchId, ':today' => date('Y-m-d')]);
                $rows = $stmt->fetchAll();
                $totalQty = 0.0;
                $totalValue = 0.0;
                foreach ($rows as &$row) {
                    $row['days_left'] = (int)floor((strtotime($row['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
                    $row['total_cost'] = round((float)$row['quantity'] * (float)$row['purchase_cost'], 3);
                    $totalQty += (float)$row['quantity'];
                    $totalValue += (float)$row['total_cost'];
                }
                unset($row);
                return self::pack('expired_products', 'تقرير الأدوية المنتهية الصلاحية', [
                    ['key' => 'product_name', 'label' => 'الدواء', 'type' => 'text'],
                    ['key' => 'sku', 'label' => 'الكود', 'type' => 'text'],
                    ['key' => 'active_ingredient', 'label' => 'المادة الفعالة', 'type' => 'text'],
                    ['key' => 'batch_number', 'label' => 'الدفعة', 'type' => 'text'],
                    ['key' => 'expiry_date', 'label' => 'تاريخ الانتهاء', 'type' => 'date'],
                    ['key' => 'days_left', 'label' => 'الأيام', 'type' => 'int'],
                    ['key' => 'quantity', 'label' => 'الكمية', 'type' => 'int'],
                    ['key' => 'total_cost', 'label' => 'القيمة', 'type' => 'money'],
                ], $rows, ['count' => count($rows), 'total_quantity' => round($totalQty, 3), 'total_value' => round($totalValue, 3)], $period);

            case 'near_expiry':
                $alertDays = BatchService::alertDays();
                $stmt = $pdo->prepare('
                    SELECT pb.batch_number, p.name AS product_name, p.sku,
                           p.active_ingredient, pb.expiry_date, pb.quantity
                    FROM product_batches pb
                    JOIN products p ON p.id = pb.product_id
                    WHERE pb.branch_id = :b AND pb.quantity > 0
                          AND pb.expiry_date BETWEEN :today AND :limit
                          AND p.deleted_at IS NULL
                    ORDER BY pb.expiry_date ASC
                ');
                $stmt->execute([':b' => $branchId, ':today' => date('Y-m-d'), ':limit' => date('Y-m-d', strtotime('+' . $alertDays . ' days'))]);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$row) {
                    $row['days_left'] = (int)floor((strtotime($row['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
                }
                unset($row);
                return self::pack('near_expiry', 'تقرير الأدوية قريبة الانتهاء', [
                    ['key' => 'product_name', 'label' => 'الدواء', 'type' => 'text'],
                    ['key' => 'active_ingredient', 'label' => 'المادة الفعالة', 'type' => 'text'],
                    ['key' => 'batch_number', 'label' => 'الدفعة', 'type' => 'text'],
                    ['key' => 'expiry_date', 'label' => 'تاريخ الانتهاء', 'type' => 'date'],
                    ['key' => 'days_left', 'label' => 'الأيام المتبقية', 'type' => 'int'],
                    ['key' => 'quantity', 'label' => 'الكمية', 'type' => 'int'],
                ], $rows, ['count' => count($rows), 'alert_days' => $alertDays], $period);

            case 'batches_by_product':
                $stmt = $pdo->prepare('
                    SELECT p.name AS product_name, p.sku, p.active_ingredient,
                           pb.batch_number, pb.manufacturing_date, pb.expiry_date,
                           pb.quantity, pb.purchase_cost
                    FROM product_batches pb
                    JOIN products p ON p.id = pb.product_id
                    WHERE pb.branch_id = :b AND p.deleted_at IS NULL
                    ORDER BY p.name ASC, pb.expiry_date ASC
                ');
                $stmt->execute([':b' => $branchId]);
                $rows = $stmt->fetchAll();
                $totalQty = 0.0;
                foreach ($rows as $row) {
                    $totalQty += (float)$row['quantity'];
                }
                return self::pack('batches_by_product', 'تقرير الدفعات حسب الدواء', [
                    ['key' => 'product_name', 'label' => 'الدواء', 'type' => 'text'],
                    ['key' => 'sku', 'label' => 'الكود', 'type' => 'text'],
                    ['key' => 'active_ingredient', 'label' => 'المادة الفعالة', 'type' => 'text'],
                    ['key' => 'batch_number', 'label' => 'الدفعة', 'type' => 'text'],
                    ['key' => 'manufacturing_date', 'label' => 'تاريخ التصنيع', 'type' => 'date'],
                    ['key' => 'expiry_date', 'label' => 'تاريخ الانتهاء', 'type' => 'date'],
                    ['key' => 'quantity', 'label' => 'الكمية', 'type' => 'int'],
                    ['key' => 'purchase_cost', 'label' => 'التكلفة', 'type' => 'money'],
                ], $rows, ['count' => count($rows), 'total_quantity' => round($totalQty, 3)], $period);

            case 'sales_by_ingredient':
                $stmt = $pdo->prepare('
                    SELECT COALESCE(NULLIF(p.active_ingredient, \'\'), \'غير محدد\') AS ingredient,
                           SUM(si.quantity) AS quantity_sold,
                           SUM(si.net_total) AS net_sales,
                           SUM(si.line_cogs) AS total_cogs,
                           COUNT(DISTINCT si.sale_id) AS invoice_count
                    FROM sale_items si
                    JOIN sales s ON s.id = si.sale_id
                    JOIN products p ON p.id = si.product_id
                    WHERE s.branch_id = :b AND s.status IN (\'completed\', \'returned\')
                          AND s.sale_date BETWEEN :start AND :end AND s.deleted_at IS NULL
                    GROUP BY ingredient
                    ORDER BY net_sales DESC
                ');
                $stmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
                $rows = $stmt->fetchAll();
                $totals = ['invoice_count' => 0, 'quantity_sold' => 0, 'net_sales' => 0.0, 'total_cogs' => 0.0];
                foreach ($rows as $row) {
                    $totals['invoice_count'] += (int)$row['invoice_count'];
                    $totals['quantity_sold'] += (int)$row['quantity_sold'];
                    $totals['net_sales'] += (float)$row['net_sales'];
                    $totals['total_cogs'] += (float)$row['total_cogs'];
                }
                return self::pack('sales_by_ingredient', 'المبيعات حسب المادة الفعالة', [
                    ['key' => 'ingredient', 'label' => 'المادة الفعالة', 'type' => 'text'],
                    ['key' => 'invoice_count', 'label' => 'الفواتير', 'type' => 'int'],
                    ['key' => 'quantity_sold', 'label' => 'الكمية المباعة', 'type' => 'int'],
                    ['key' => 'net_sales', 'label' => 'صافي المبيعات', 'type' => 'money'],
                    ['key' => 'total_cogs', 'label' => 'التكلفة', 'type' => 'money'],
                ], $rows, $totals, $period);

            case 'audit_log':
                $stmt = $pdo->prepare('
                    SELECT al.created_at, COALESCE(u.full_name, \'النظام\') AS user_name,
                           al.action_type AS action, al.entity_type AS module, al.description
                    FROM audit_logs al
                    LEFT JOIN users u ON u.id = al.user_id
                    WHERE al.created_at BETWEEN :start AND :end
                    ORDER BY al.id DESC
                    LIMIT 1000
                ');
                $stmt->execute([':start' => $start, ':end' => $end]);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$row) {
                    $row['action'] = AuditService::actionLabel((string)($row['action'] ?? ''));
                }
                unset($row);
                return self::pack('audit_log', 'سجل التدقيق', [
                    ['key' => 'created_at', 'label' => 'التاريخ', 'type' => 'datetime'],
                    ['key' => 'user_name', 'label' => 'المستخدم', 'type' => 'text'],
                    ['key' => 'action', 'label' => 'العملية', 'type' => 'text'],
                    ['key' => 'module', 'label' => 'الوحدة', 'type' => 'text'],
                    ['key' => 'description', 'label' => 'التفاصيل', 'type' => 'text'],
                ], $rows, ['count' => count($rows)], $period);

            case 'dispensed_by_prescription':
                $rows = PharmacyReportService::dispensedByPrescription($branchId, $startDate, $endDate);
                $quantity = 0.0;
                foreach ($rows as $row) {
                    $quantity += (float)($row['quantity'] ?? 0);
                }
                return self::pack('dispensed_by_prescription', 'الأدوية المصروفة حسب الروشتات', [
                    ['key' => 'prescription_number', 'label' => 'رقم الروشتة', 'type' => 'text'],
                    ['key' => 'prescription_date', 'label' => 'تاريخ الروشتة', 'type' => 'date'],
                    ['key' => 'patient_name', 'label' => 'المريض', 'type' => 'text'],
                    ['key' => 'doctor_name', 'label' => 'الطبيب', 'type' => 'text'],
                    ['key' => 'product_name', 'label' => 'الدواء', 'type' => 'text'],
                    ['key' => 'quantity', 'label' => 'الكمية', 'type' => 'number'],
                    ['key' => 'dose', 'label' => 'الجرعة', 'type' => 'text'],
                    ['key' => 'dosage_instructions', 'label' => 'تعليمات الاستخدام', 'type' => 'text'],
                    ['key' => 'duration_days', 'label' => 'مدة العلاج (أيام)', 'type' => 'number'],
                    ['key' => 'invoice', 'label' => 'رقم الفاتورة', 'type' => 'text'],
                ], $rows, ['count' => count($rows), 'total_quantity' => round($quantity, 3)], $period);

            case 'stocktake_damage':
                $data = PharmacyReportService::stocktakeAndDamage($branchId, $startDate, $endDate);
                return self::pack('stocktake_damage', 'الجرد الفعلي والأدوية التالفة', [
                    ['key' => 'type', 'label' => 'النوع', 'type' => 'text'],
                    ['key' => 'reference', 'label' => 'الرقم', 'type' => 'text'],
                    ['key' => 'date', 'label' => 'التاريخ', 'type' => 'date'],
                    ['key' => 'product_name', 'label' => 'الدواء', 'type' => 'text'],
                    ['key' => 'quantity', 'label' => 'الكمية', 'type' => 'number'],
                    ['key' => 'difference_qty', 'label' => 'فرق الجرد', 'type' => 'number'],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'text'],
                ], $data['rows'], $data['summary'], $period);

            case 'storage_conditions':
                $rows = PharmacyReportService::storageConditions($branchId);
                return self::pack('storage_conditions', 'الأدوية ذات شروط تخزين خاصة', [
                    ['key' => 'name', 'label' => 'الدواء', 'type' => 'text'],
                    ['key' => 'storage_code', 'label' => 'رمز التخزين', 'type' => 'text'],
                    ['key' => 'alert_label', 'label' => 'شرط التخزين', 'type' => 'text'],
                    ['key' => 'storage_conditions', 'label' => 'التعليمات', 'type' => 'text'],
                    ['key' => 'stock_qty', 'label' => 'الرصيد', 'type' => 'number'],
                ], $rows, ['count' => count($rows)], $period);

            case 'supplier_price_comparison':
                $data = PharmacyReportService::supplierPriceComparison($branchId, $startDate, $endDate);
                $rows = $data['records'] ?? $data['rows'] ?? [];
                return self::pack('supplier_price_comparison', 'مقارنة أسعار الموردين', [
                    ['key' => 'purchase_date', 'label' => 'التاريخ', 'type' => 'date'],
                    ['key' => 'product_name', 'label' => 'الدواء', 'type' => 'text'],
                    ['key' => 'supplier_name', 'label' => 'المورد', 'type' => 'text'],
                    ['key' => 'unit_cost', 'label' => 'سعر الشراء', 'type' => 'number'],
                    ['key' => 'quantity', 'label' => 'الكمية', 'type' => 'number'],
                    ['key' => 'invoice_number', 'label' => 'رقم الفاتورة', 'type' => 'text'],
                ], $rows, [
                    'records' => count($rows),
                    'suppliers' => count($data['suppliers'] ?? []),
                    'total_quantity' => $data['total_quantity'] ?? 0,
                ], $period);

            case 'demand_forecast':
                $days = min(max((int)($filters['days'] ?? 30), 1), 365);
                $rows = DemandForecastService::forecast($branchId, $days);
                return self::pack('demand_forecast', 'توقع نقص الأدوية', [
                    ['key' => 'name', 'label' => 'الدواء', 'type' => 'text'],
                    ['key' => 'stock_qty', 'label' => 'الرصيد الحالي', 'type' => 'number'],
                    ['key' => 'avg_daily_sales', 'label' => 'متوسط المبيعات اليومية', 'type' => 'number'],
                    ['key' => 'days_of_cover', 'label' => 'أيام التغطية', 'type' => 'number'],
                    ['key' => 'suggested_order_qty', 'label' => 'الكمية المقترحة للطلب', 'type' => 'number'],
                    ['key' => 'status', 'label' => 'الحالة', 'type' => 'text'],
                ], $rows, [
                    'count' => count($rows),
                    'critical' => count(array_filter($rows, static function (array $row): bool {
                        return ($row['status'] ?? '') === 'critical';
                    })),
                    'horizon_days' => $days,
                ], $period);

            case 'slow_moving':
                $days = min(max((int)($filters['days'] ?? 90), 1), 365);
                $rows = DemandForecastService::slowMoving($branchId, $days);
                return self::pack('slow_moving', 'الأدوية بطيئة الحركة', [
                    ['key' => 'name', 'label' => 'الدواء', 'type' => 'text'],
                    ['key' => 'stock_qty', 'label' => 'الرصيد', 'type' => 'number'],
                    ['key' => 'last_sale_date', 'label' => 'آخر بيع', 'type' => 'date'],
                    ['key' => 'days_since_last_sale', 'label' => 'الأيام منذ آخر بيع', 'type' => 'number'],
                ], $rows, ['count' => count($rows)], $period);

            case 'best_selling':
                $days = min(max((int)($filters['days'] ?? 30), 1), 365);
                $rows = DemandForecastService::bestSelling($branchId, $days, 20);
                return self::pack('best_selling', 'الأدوية الأكثر مبيعًا', [
                    ['key' => 'name', 'label' => 'الدواء', 'type' => 'text'],
                    ['key' => 'total_qty', 'label' => 'الكمية المباعة', 'type' => 'number'],
                    ['key' => 'total_revenue', 'label' => 'الإيراد', 'type' => 'number'],
                ], $rows, ['count' => count($rows)], $period);
        }

        throw new \RuntimeException('نوع التقرير غير معروف.');
    }

    public static function listReportTypes(): array
    {
        return [
            'sales_summary' => 'ملخص المبيعات اليومي',
            'sales_by_product' => 'المبيعات حسب الدواء',
            'sales_by_category' => 'المبيعات حسب التصنيف العلاجي',
            'sales_by_customer' => 'المبيعات حسب المريض',
            'sales_by_cashier' => 'المبيعات حسب الكاشير',
            'sales_by_payment_method' => 'المبيعات حسب طريقة الدفع',
            'profit_report' => 'تقرير الأرباح والخسائر',
            'returns_report' => 'تقرير المرتجعات',
            'inventory_valuation' => 'تقييم المخزون',
            'low_stock' => 'المخزون المنخفض',
            'inventory_movements' => 'حركات المخزون',
            'expired_products' => 'الأدوية المنتهية الصلاحية',
            'near_expiry' => 'الأدوية قريبة الانتهاء',
            'batches_by_product' => 'دفعات الأدوية',
            'sales_by_ingredient' => 'المبيعات حسب المادة الفعالة',
            'purchases_report' => 'تقرير المشتريات',
            'supplier_balances' => 'أرصدة الموردين',
            'customer_debts' => 'ديون المرضى',
            'expenses_report' => 'تقرير المصروفات',
            'cash_sessions' => 'ورديات الصندوق',
            'discounts_report' => 'تقرير الخصومات',
            'dispensed_by_prescription' => 'الأدوية المصروفة حسب الروشتات',
            'stocktake_damage' => 'الجرد الفعلي والأدوية التالفة',
            'storage_conditions' => 'الأدوية ذات شروط تخزين خاصة',
            'supplier_price_comparison' => 'مقارنة أسعار الموردين',
            'demand_forecast' => 'توقع نقص الأدوية',
            'slow_moving' => 'الأدوية بطيئة الحركة',
            'best_selling' => 'الأدوية الأكثر مبيعًا',
            'audit_log' => 'سجل التدقيق',
        ];
    }

    private static function dailySales(PDO $pdo, int $branchId, string $start, string $end, string $startDate, string $endDate): array
    {
        $stmt = $pdo->prepare('
            SELECT DATE(sale_date) AS report_date, COUNT(*) AS invoice_count,
                   COALESCE(SUM(subtotal), 0) AS gross_sales,
                   COALESCE(SUM(total_discount), 0) AS discounts,
                   COALESCE(SUM(tax_amount), 0) AS tax_amount,
                   COALESCE(SUM(total_amount), 0) AS billed_sales,
                   COALESCE(SUM(total_cogs), 0) AS gross_cogs
            FROM sales
            WHERE branch_id = :b AND status IN (\'completed\', \'returned\')
              AND sale_date BETWEEN :start AND :end AND deleted_at IS NULL
            GROUP BY DATE(sale_date)
        ');
        $stmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
        $salesByDate = [];
        foreach ($stmt->fetchAll() as $row) {
            $salesByDate[$row['report_date']] = $row;
        }

        // Collected amounts by payment method, net of the matching refunds.
        $payStmt = $pdo->prepare('
            SELECT DATE(s.sale_date) AS report_date,
                   COALESCE(SUM(CASE WHEN p.payment_method = \'cash\' THEN p.amount_base ELSE 0 END), 0) AS cash_collected,
                   COALESCE(SUM(CASE WHEN p.payment_method = \'card\' THEN p.amount_base ELSE 0 END), 0) AS card_collected,
                   COALESCE(SUM(CASE WHEN p.payment_method IN (\'transfer\', \'bank_transfer\', \'cheque\') THEN p.amount_base ELSE 0 END), 0) AS transfer_collected
            FROM payments p
            JOIN sales s ON s.id = p.sale_id
            WHERE s.branch_id = :b AND s.status IN (\'completed\', \'returned\')
              AND s.sale_date BETWEEN :start AND :end AND s.deleted_at IS NULL
              AND p.reversed_at IS NULL
            GROUP BY DATE(s.sale_date)
        ');
        $payStmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
        $paymentsByDate = [];
        foreach ($payStmt->fetchAll() as $row) {
            $paymentsByDate[$row['report_date']] = $row;
        }

        $retStmt = $pdo->prepare('
            SELECT DATE(created_at) AS report_date,
                   COALESCE(SUM(total_refund_amount), 0) AS returns,
                   COALESCE(SUM(total_returned_cogs), 0) AS returned_cogs,
                   COALESCE(SUM(cash_refund_amount), 0) AS cash_refund,
                   COALESCE(SUM(card_refund_amount), 0) AS card_refund,
                   COALESCE(SUM(CASE
                       WHEN refund_method IN (\'transfer\', \'bank_transfer\', \'cheque\')
                        AND (total_refund_amount - cash_refund_amount - card_refund_amount - debt_reduction_amount) > 0
                       THEN (total_refund_amount - cash_refund_amount - card_refund_amount - debt_reduction_amount)
                       ELSE 0 END), 0) AS transfer_refund
            FROM sale_returns
            WHERE branch_id = :b AND created_at BETWEEN :start AND :end
            GROUP BY DATE(created_at)
        ');
        $retStmt->execute([':b' => $branchId, ':start' => $start, ':end' => $end]);
        $returnsByDate = [];
        foreach ($retStmt->fetchAll() as $row) {
            $returnsByDate[$row['report_date']] = $row;
        }

        $dates = array_unique(array_merge(array_keys($salesByDate), array_keys($returnsByDate)));
        sort($dates);
        $rows = [];
        $totals = [
            'invoice_count' => 0, 'gross_sales' => 0.0, 'discounts' => 0.0, 'tax_amount' => 0.0, 'returns' => 0.0,
            'cash_amount' => 0.0, 'card_amount' => 0.0, 'transfer_amount' => 0.0,
            'net_sales' => 0.0, 'net_cogs' => 0.0, 'gross_profit' => 0.0,
        ];
        foreach ($dates as $date) {
            $sale = $salesByDate[$date] ?? ['invoice_count' => 0, 'gross_sales' => 0, 'discounts' => 0, 'tax_amount' => 0, 'billed_sales' => 0, 'gross_cogs' => 0];
            $ret = $returnsByDate[$date] ?? ['returns' => 0, 'returned_cogs' => 0, 'cash_refund' => 0, 'card_refund' => 0, 'transfer_refund' => 0];
            $pay = $paymentsByDate[$date] ?? ['cash_collected' => 0, 'card_collected' => 0, 'transfer_collected' => 0];
            $netSales = round((float)$sale['billed_sales'] - (float)$ret['returns'], 3);
            $netCogs = round((float)$sale['gross_cogs'] - (float)$ret['returned_cogs'], 3);
            $grossProfit = round($netSales - $netCogs, 3);
            $cashAmount = round((float)$pay['cash_collected'] - (float)$ret['cash_refund'], 3);
            $cardAmount = round((float)$pay['card_collected'] - (float)$ret['card_refund'], 3);
            $transferAmount = round((float)$pay['transfer_collected'] - (float)$ret['transfer_refund'], 3);
            $rows[] = [
                'report_date' => $date,
                'invoice_count' => (int)$sale['invoice_count'],
                'gross_sales' => round((float)$sale['gross_sales'], 3),
                'discounts' => round((float)$sale['discounts'], 3),
                'tax_amount' => round((float)$sale['tax_amount'], 3),
                'cash_amount' => $cashAmount,
                'card_amount' => $cardAmount,
                'transfer_amount' => $transferAmount,
                'returns' => round((float)$ret['returns'], 3),
                'net_sales' => $netSales,
                'net_cogs' => $netCogs,
                'gross_profit' => $grossProfit,
            ];
            $totals['invoice_count'] += (int)$sale['invoice_count'];
            $totals['gross_sales'] += (float)$sale['gross_sales'];
            $totals['discounts'] += (float)$sale['discounts'];
            $totals['tax_amount'] += (float)$sale['tax_amount'];
            $totals['cash_amount'] += $cashAmount;
            $totals['card_amount'] += $cardAmount;
            $totals['transfer_amount'] += $transferAmount;
            $totals['returns'] += (float)$ret['returns'];
            $totals['net_sales'] += $netSales;
            $totals['net_cogs'] += $netCogs;
            $totals['gross_profit'] += $grossProfit;
        }
        $totals = array_map(static function ($value) {
            return is_float($value) ? round($value, 3) : $value;
        }, $totals);
        return ['rows' => $rows, 'totals' => $totals];
    }

    private static function pack(string $type, string $title, array $columns, array $rows, array $summary, array $period): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'summary' => $summary,
            'period' => $period,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private static function validDate(string $value, string $fallback): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $fallback;
        }
        $parts = explode('-', $value);
        return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]) ? $value : $fallback;
    }
}
