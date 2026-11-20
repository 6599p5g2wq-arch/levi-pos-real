PRAGMA foreign_keys = ON;
PRAGMA foreign_keys = ON;
PRAGMA synchronous = FULL;

-- 1. branches (إدارة الفروع والمستودعات)
CREATE TABLE IF NOT EXISTS branches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    code TEXT NOT NULL UNIQUE,
    address TEXT,
    phone TEXT,
    is_main INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 2. roles (الأدوار الوظيفية)
CREATE TABLE IF NOT EXISTS roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    display_name_ar TEXT NOT NULL,
    description TEXT,
    is_system INTEGER NOT NULL DEFAULT 1,
    is_hidden INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 3. permissions (الصلاحيات التفصيلية)
CREATE TABLE IF NOT EXISTS permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    module TEXT NOT NULL,
    display_name_ar TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 4. role_permissions (ربط الأدوار بالصلاحيات)
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INTEGER NOT NULL,
    permission_id INTEGER NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

-- 5. users (المستخدمون والموظفون)
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    role_id INTEGER NOT NULL,
    username TEXT NOT NULL UNIQUE,
    full_name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    password_hash TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    failed_login_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    deleted_at DATETIME NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- 6. categories (تصنيفات المنتجات)
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    deleted_at DATETIME NULL,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- 7. brands (العلامات التجارية)
CREATE TABLE IF NOT EXISTS brands (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    country TEXT,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 8. products (المنتجات الأساسية)
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    brand_id INTEGER NULL,
    name TEXT NOT NULL,
    sku TEXT NOT NULL UNIQUE,
    barcode TEXT UNIQUE,
    product_type TEXT NOT NULL DEFAULT 'standard', -- standard, variant_parent
    phone_model TEXT,
    color TEXT,
    size TEXT,
    description TEXT,
    image_path TEXT,
    purchase_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    selling_price NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    wholesale_price NUMERIC(15,3) NULL,
    min_stock_level INTEGER NOT NULL DEFAULT 5,
    warranty_days INTEGER NOT NULL DEFAULT 0,
    has_variants INTEGER NOT NULL DEFAULT 0,
    active_ingredient TEXT NULL,
    strength TEXT NULL,
    dosage_form TEXT NULL,
    manufacturer TEXT NULL,
    registration_number TEXT NULL,
    country_of_origin TEXT NULL,
    storage_conditions TEXT NULL,
    storage_condition_code TEXT NULL,
    base_unit TEXT NOT NULL DEFAULT 'piece',
    default_unit_id INTEGER NULL,
    last_sale_date DATE NULL,
    requires_prescription INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    is_archived INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    deleted_at DATETIME NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 9. product_variants (خيارات المنتجات مثل الألوان والموديلات والأحجام)
CREATE TABLE IF NOT EXISTS product_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    variant_name TEXT NOT NULL,
    phone_model TEXT,
    color TEXT,
    size TEXT,
    sku TEXT NOT NULL UNIQUE,
    barcode TEXT UNIQUE,
    purchase_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    selling_price NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    wholesale_price NUMERIC(15,3) NULL,
    min_stock_level INTEGER NOT NULL DEFAULT 3,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    deleted_at DATETIME NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- 10. product_images (صور المنتجات)
CREATE TABLE IF NOT EXISTS product_images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    image_path TEXT NOT NULL,
    is_primary INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
);

-- 11. inventory (أرصدة المخزون الحالية لكل منتج/خيار في كل فرع)
CREATE TABLE IF NOT EXISTS inventory (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    quantity INTEGER NOT NULL DEFAULT 0,
    reserved_quantity INTEGER NOT NULL DEFAULT 0,
    average_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    last_counted_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE(branch_id, product_id, variant_id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id)
);

-- 12. inventory_movements (سجل حركة المخزون التفصيلي غير القابل للحذف)
CREATE TABLE IF NOT EXISTS inventory_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    movement_type TEXT NOT NULL, -- sale, purchase, sale_return, purchase_return, adjustment, damaged, lost, stocktake, initial
    quantity_before INTEGER NOT NULL,
    quantity_change INTEGER NOT NULL,
    quantity_after INTEGER NOT NULL,
    unit_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    reference_type TEXT NULL, -- sale, purchase, sale_return, purchase_return, stock_adjustment
    reference_id INTEGER NULL,
    invoice_number TEXT NULL,
    reason TEXT,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 12b. stock_transfers (تحويل المخزون بين الفروع)
CREATE TABLE IF NOT EXISTS stock_transfers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transfer_number TEXT NOT NULL UNIQUE,
    from_branch_id INTEGER NOT NULL,
    to_branch_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    quantity INTEGER NOT NULL,
    unit_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    notes TEXT,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (from_branch_id) REFERENCES branches(id),
    FOREIGN KEY (to_branch_id) REFERENCES branches(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 13. stock_adjustments (عمليات الجرد والتسويات والتالف والمفقود)
CREATE TABLE IF NOT EXISTS stock_adjustments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    adjustment_number TEXT NOT NULL UNIQUE,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    adjustment_type TEXT NOT NULL, -- stocktake, adjustment, damaged, lost
    quantity_before INTEGER NOT NULL,
    quantity_adjusted INTEGER NOT NULL,
    quantity_after INTEGER NOT NULL,
    unit_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    reason TEXT NOT NULL,
    notes TEXT,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 14. suppliers (الموردون)
CREATE TABLE IF NOT EXISTS suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    company_name TEXT,
    phone TEXT NOT NULL,
    email TEXT,
    address TEXT,
    opening_balance NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    current_balance NUMERIC(15,3) NOT NULL DEFAULT 0.000, -- المبلغ المستحق للمورد
    first_deal_date DATE NULL,
    notes TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    deleted_at DATETIME NULL
);

-- 15. purchases (فواتير المشتريات)
CREATE TABLE IF NOT EXISTS purchases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    invoice_number TEXT NOT NULL UNIQUE,
    supplier_invoice_no TEXT,
    supplier_id INTEGER NOT NULL,
    purchase_date DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    subtotal NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    discount_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    shipping_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    extra_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    cost_allocation_method TEXT NOT NULL DEFAULT 'value', -- quantity, value
    total_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    paid_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    remaining_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    payment_status TEXT NOT NULL DEFAULT 'paid', -- paid, partial, unpaid
    payment_method TEXT NOT NULL DEFAULT 'cash', -- cash, card, transfer, cheque (credit for fully unpaid purchases)
    status TEXT NOT NULL DEFAULT 'completed', -- completed, cancelled
    due_date DATE NULL,
    notes TEXT,
    cancelled_at DATETIME NULL,
    cancel_reason TEXT NULL,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    deleted_at DATETIME NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 16. purchase_items (تفاصيل فواتير المشتريات وتكلفة الوحدة بعد تحميل الشحن)
CREATE TABLE IF NOT EXISTS purchase_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    purchase_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    batch_number TEXT NULL,
    manufacturing_date DATE NULL,
    expiry_date DATE NULL,
    quantity INTEGER NOT NULL,
    remaining_fifo_qty INTEGER NOT NULL DEFAULT 0,
    returned_quantity INTEGER NOT NULL DEFAULT 0,
    unit_cost NUMERIC(15,3) NOT NULL,
    allocated_extra_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    landed_unit_cost NUMERIC(15,3) NOT NULL,
    discount_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    line_total NUMERIC(15,3) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id)
);

-- 17. supplier_payments (سندات صرف ودفعات الموردين)
CREATE TABLE IF NOT EXISTS supplier_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    receipt_number TEXT NOT NULL UNIQUE,
    supplier_id INTEGER NOT NULL,
    purchase_id INTEGER NULL,
    amount NUMERIC(15,3) NOT NULL,
    currency_code TEXT NOT NULL DEFAULT 'LYD',
    exchange_rate NUMERIC(15,4) NOT NULL DEFAULT 1.0000,
    amount_in_base NUMERIC(15,3) NOT NULL,
    payment_method TEXT NOT NULL DEFAULT 'cash', -- cash, card, transfer, cheque (credit for fully unpaid purchases)
    payment_date DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    notes TEXT,
    reversed_at DATETIME NULL,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 18. purchase_returns (مرتجعات المشتريات للموردين)
CREATE TABLE IF NOT EXISTS purchase_returns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    return_number TEXT NOT NULL UNIQUE,
    purchase_id INTEGER NOT NULL,
    supplier_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    quantity INTEGER NOT NULL,
    unit_cost NUMERIC(15,3) NOT NULL,
    total_amount NUMERIC(15,3) NOT NULL,
    refund_method TEXT NOT NULL DEFAULT 'balance_deduction', -- cash_refund, balance_deduction
    reason TEXT NOT NULL,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (purchase_id) REFERENCES purchases(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 19. customers (العملاء والزبائن وحسابات الديون)
CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT NOT NULL UNIQUE,
    email TEXT,
    address TEXT,
    credit_limit NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    current_debt NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    notes TEXT,
    birth_date DATE NULL,
    gender TEXT NULL,
    blood_type TEXT NULL,
    allergies TEXT NULL,
    drug_allergies TEXT NULL,
    chronic_diseases TEXT NULL,
    medical_notes TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    deleted_at DATETIME NULL
);

-- 20. cash_registers (صناديق الكاشير ونقاط البيع)
CREATE TABLE IF NOT EXISTS cash_registers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    name TEXT NOT NULL,
    code TEXT NOT NULL UNIQUE,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (branch_id) REFERENCES branches(id)
);

-- 21. cash_register_sessions (جلسات وإغلاق يومية الكاشير)
CREATE TABLE IF NOT EXISTS cash_register_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cash_register_id INTEGER NOT NULL DEFAULT 1,
    user_id INTEGER NOT NULL,
    session_number TEXT NOT NULL UNIQUE,
    opening_cash NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    total_sales NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    cash_sales NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    card_sales NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    cash_expenses NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    cash_refunds NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    expected_cash NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    actual_cash NUMERIC(15,3) NULL,
    discrepancy NUMERIC(15,3) NULL,
    discrepancy_reason TEXT NULL,
    status TEXT NOT NULL DEFAULT 'open', -- open, closed
    opened_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    closed_at DATETIME NULL,
    closed_by INTEGER NULL,
    FOREIGN KEY (cash_register_id) REFERENCES cash_registers(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (closed_by) REFERENCES users(id)
);

-- 22. cash_movements (حركات النقد داخل درج الكاشير)
CREATE TABLE IF NOT EXISTS cash_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL DEFAULT 1,
    movement_type TEXT NOT NULL, -- opening, sale_in, debt_collection, refund_out, expense_out, manual_in, manual_out, purchase_out, cancel_out
    amount NUMERIC(15,3) NOT NULL,
    reference_type TEXT NULL,
    reference_id INTEGER NULL,
    description TEXT,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (session_id) REFERENCES cash_register_sessions(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 23. promotions (العروض والخصومات الترويجية)
CREATE TABLE IF NOT EXISTS promotions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    name TEXT NOT NULL,
    promo_type TEXT NOT NULL, -- product_discount, category_discount, invoice_discount, bundle
    target_product_id INTEGER NULL,
    target_category_id INTEGER NULL,
    discount_type TEXT NOT NULL DEFAULT 'percentage', -- percentage, fixed
    discount_value NUMERIC(15,3) NOT NULL,
    min_invoice_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    min_qty INTEGER NOT NULL DEFAULT 1,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (target_product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (target_category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- 24. sales (فواتير المبيعات والفواتير المعلقة)
CREATE TABLE IF NOT EXISTS sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    session_id INTEGER NULL,
    invoice_number TEXT NOT NULL UNIQUE,
    customer_id INTEGER NULL,
    cashier_id INTEGER NOT NULL,
    sale_date DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    subtotal NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    item_discount_total NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    invoice_discount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    total_discount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    total_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    tax_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    tax_rate NUMERIC(6,3) NOT NULL DEFAULT 0.000,
    total_cogs NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    paid_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    remaining_debt NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    change_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    payment_method TEXT NOT NULL DEFAULT 'cash', -- cash, card, split, credit
    payment_status TEXT NOT NULL DEFAULT 'paid', -- paid, partial, unpaid, held
    status TEXT NOT NULL DEFAULT 'completed', -- completed, held, cancelled, returned
    client_uuid TEXT NULL,
    promotion_discount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    refunded_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    notes TEXT,
    cancelled_by INTEGER NULL,
    cancel_reason TEXT NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    deleted_at DATETIME NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (session_id) REFERENCES cash_register_sessions(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (cashier_id) REFERENCES users(id),
    FOREIGN KEY (cancelled_by) REFERENCES users(id)
);

-- 25. sale_items (تفاصيل المنتجات المباعة مع حفظ التكلفة والسعر التاريخي)
CREATE TABLE IF NOT EXISTS sale_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    product_name_snapshot TEXT NOT NULL,
    sku_snapshot TEXT NOT NULL,
    variant_snapshot TEXT NULL,
    batch_number_snapshot TEXT NULL,
    expiry_date_snapshot DATE NULL,
    serial_or_imei TEXT NULL,
    quantity INTEGER NOT NULL,
    sale_unit_id INTEGER NULL,
    sale_unit_name TEXT NULL,
    sale_unit_factor NUMERIC(15,3) NOT NULL DEFAULT 1.000,
    sale_unit_quantity NUMERIC(15,3) NULL,
    returned_quantity INTEGER NOT NULL DEFAULT 0,
    unit_cost NUMERIC(15,3) NOT NULL, -- التكلفة التاريخية وقت البيع لحساب COGS
    unit_price NUMERIC(15,3) NOT NULL, -- سعر البيع التاريخي وقت البيع
    discount_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    line_total NUMERIC(15,3) NOT NULL,
    invoice_discount_share NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    net_total NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    line_cogs NUMERIC(15,3) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id)
);

-- 26. payments (مدفوعات فواتير المبيعات وتعدد العملات والدفع المختلط)
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    payment_method TEXT NOT NULL, -- cash, card
    currency_code TEXT NOT NULL DEFAULT 'LYD',
    exchange_rate NUMERIC(15,4) NOT NULL DEFAULT 1.0000,
    amount_foreign NUMERIC(15,3) NOT NULL,
    amount_base NUMERIC(15,3) NOT NULL, -- القيمة بالدينار الليبي LYD
    reference_no TEXT NULL,
    paid_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    reversed_at DATETIME NULL,
    created_by INTEGER NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 27. customer_payments (سندات قبض تسديد ديون العملاء)
CREATE TABLE IF NOT EXISTS customer_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    receipt_number TEXT NOT NULL UNIQUE,
    customer_id INTEGER NOT NULL,
    sale_id INTEGER NULL,
    amount NUMERIC(15,3) NOT NULL,
    currency_code TEXT NOT NULL DEFAULT 'LYD',
    exchange_rate NUMERIC(15,4) NOT NULL DEFAULT 1.0000,
    amount_in_base NUMERIC(15,3) NOT NULL,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    payment_date DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    notes TEXT,
    reversed_at DATETIME NULL,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 28. sale_returns (فواتير مرتجعات المبيعات والاستبدال)
CREATE TABLE IF NOT EXISTS sale_returns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    return_number TEXT NOT NULL UNIQUE,
    sale_id INTEGER NOT NULL,
    customer_id INTEGER NULL,
    return_type TEXT NOT NULL DEFAULT 'refund', -- refund, exchange
    exchange_sale_id INTEGER NULL,
    total_refund_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    total_returned_cogs NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    cash_refund_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    card_refund_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    debt_reduction_amount NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    price_difference NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    refund_method TEXT NOT NULL DEFAULT 'cash', -- cash, card, debt_deduction
    reason TEXT NOT NULL,
    notes TEXT,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (sale_id) REFERENCES sales(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (exchange_sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 29. sale_return_items (تفاصيل المنتجات المرتجعة وحالتها المخزنية)
CREATE TABLE IF NOT EXISTS sale_return_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_return_id INTEGER NOT NULL,
    sale_item_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    quantity INTEGER NOT NULL,
    unit_refund_price NUMERIC(15,3) NOT NULL,
    unit_cost NUMERIC(15,3) NOT NULL,
    line_refund_total NUMERIC(15,3) NOT NULL,
    item_condition TEXT NOT NULL DEFAULT 'resellable', -- resellable, damaged, needs_inspection, non_resellable
    restocked INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (sale_return_id) REFERENCES sale_returns(id) ON DELETE CASCADE,
    FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id)
);

-- 30. warranties (إدارة الضمانات وطلبات الصيانة والاستبدال)
CREATE TABLE IF NOT EXISTS warranties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    warranty_number TEXT NOT NULL UNIQUE,
    sale_id INTEGER NOT NULL,
    sale_item_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    customer_id INTEGER NULL,
    customer_name TEXT,
    customer_phone TEXT,
    serial_or_imei TEXT,
    sale_date DATE NOT NULL,
    warranty_days INTEGER NOT NULL,
    expiry_date DATE NOT NULL,
    status TEXT NOT NULL DEFAULT 'active', -- active, expired, under_maintenance, replaced, void
    claim_notes TEXT,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (sale_id) REFERENCES sales(id),
    FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Pharmacy: product_batches (دفعات الأدوية وتواريخ الانتهاء)
CREATE TABLE IF NOT EXISTS product_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL DEFAULT 1,
    batch_number TEXT NOT NULL,
    manufacturing_date DATE NULL,
    expiry_date DATE NOT NULL,
    quantity NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    purchase_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (product_id, branch_id, batch_number),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id)
);

-- Pharmacy: doctors (الأطباء)
CREATE TABLE IF NOT EXISTS doctors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    specialty TEXT NULL,
    phone TEXT NULL,
    mobile TEXT NULL,
    email TEXT NULL,
    clinic TEXT NULL,
    address TEXT NULL,
    license_number TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    deleted_at DATETIME NULL
);

-- Pharmacy: prescriptions (الروشتات)
CREATE TABLE IF NOT EXISTS prescriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    prescription_number TEXT NOT NULL UNIQUE,
    patient_id INTEGER NOT NULL,
    doctor_id INTEGER NULL,
    prescription_date DATE NOT NULL,
    notes TEXT NULL,
    status TEXT NOT NULL DEFAULT 'issued',
    dispensed_sale_id INTEGER NULL,
    image_path TEXT NULL,
    reviewed_by INTEGER NULL,
    reviewed_at DATETIME NULL,
    review_notes TEXT NULL,
    created_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (patient_id) REFERENCES customers(id),
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL,
    FOREIGN KEY (dispensed_sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Pharmacy: prescription_items (تفاصيل الروشتات)
CREATE TABLE IF NOT EXISTS prescription_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    prescription_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity NUMERIC(15,3) NOT NULL DEFAULT 1.000,
    dosage_instructions TEXT NULL,
    dose TEXT NULL,
    frequency TEXT NULL,
    duration_days INTEGER NULL,
    unit_id INTEGER NULL,
    unit_name TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Pharmacy extensions: organised dosage forms and storage condition catalogue
CREATE TABLE IF NOT EXISTS dosage_forms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS storage_conditions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    description TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
);

-- Pharmacy: sellable units with conversion to the base stock unit
CREATE TABLE IF NOT EXISTS product_units (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    unit_name TEXT NOT NULL,
    base_unit TEXT NOT NULL DEFAULT 'piece',
    conversion_factor NUMERIC(15,3) NOT NULL DEFAULT 1.000,
    selling_price NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    barcode TEXT NULL UNIQUE,
    is_default INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Pharmacy: historical purchase prices for supplier comparison
CREATE TABLE IF NOT EXISTS product_price_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    product_id INTEGER NOT NULL,
    supplier_id INTEGER NULL,
    purchase_id INTEGER NULL,
    purchase_date DATE NOT NULL,
    unit_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    quantity NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL
);

-- Pharmacy: stocktake sessions with counted quantities and approval
CREATE TABLE IF NOT EXISTS stocktake_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_number TEXT NOT NULL UNIQUE,
    branch_id INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'draft',
    include_batches INTEGER NOT NULL DEFAULT 0,
    notes TEXT NULL,
    approved_by INTEGER NULL,
    approved_at DATETIME NULL,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS stocktake_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    batch_id INTEGER NULL,
    expected_qty NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    counted_qty NUMERIC(15,3) NULL,
    difference_qty NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    notes TEXT NULL,
    FOREIGN KEY (session_id) REFERENCES stocktake_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE SET NULL
);

-- Pharmacy: damaged / quarantined / destroyed medicines
CREATE TABLE IF NOT EXISTS damage_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_number TEXT NOT NULL UNIQUE,
    branch_id INTEGER NOT NULL DEFAULT 1,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NULL,
    batch_id INTEGER NULL,
    quantity NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    condition TEXT NOT NULL DEFAULT 'damaged',
    reason TEXT NOT NULL,
    action_taken TEXT NOT NULL DEFAULT 'quarantine',
    status TEXT NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Pharmacy: purchase orders and supply follow-up
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_number TEXT NOT NULL UNIQUE,
    branch_id INTEGER NOT NULL DEFAULT 1,
    supplier_id INTEGER NOT NULL,
    order_date DATE NOT NULL,
    expected_date DATE NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity NUMERIC(15,3) NOT NULL DEFAULT 1.000,
    unit_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    received_quantity NUMERIC(15,3) NOT NULL DEFAULT 0.000,
    notes TEXT NULL,
    FOREIGN KEY (order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Pharmacy: local drug-interaction reference. Decision remains with the pharmacist.
CREATE TABLE IF NOT EXISTS drug_interactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ingredient_a TEXT NOT NULL,
    ingredient_b TEXT NOT NULL,
    severity TEXT NOT NULL DEFAULT 'moderate',
    description TEXT NOT NULL,
    source TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (ingredient_a, ingredient_b)
);

-- Per-user permission overrides (allow/deny) on top of role permissions
CREATE TABLE IF NOT EXISTS user_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    permission_id INTEGER NOT NULL,
    effect TEXT NOT NULL DEFAULT 'allow',
    created_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (user_id, permission_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 31. expense_categories (تصنيفات المصروفات التشغيلية)
CREATE TABLE IF NOT EXISTS expense_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 32. expenses (المصروفات التشغيلية)
CREATE TABLE IF NOT EXISTS expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    expense_number TEXT NOT NULL UNIQUE,
    category_id INTEGER NOT NULL,
    description TEXT NOT NULL,
    amount NUMERIC(15,3) NOT NULL,
    currency_code TEXT NOT NULL DEFAULT 'LYD',
    exchange_rate NUMERIC(15,4) NOT NULL DEFAULT 1.0000,
    amount_in_base NUMERIC(15,3) NOT NULL,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    expense_date DATE NOT NULL,
    attachment_path TEXT NULL,
    notes TEXT,
    is_archived INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    deleted_at DATETIME NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (category_id) REFERENCES expense_categories(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 33. exchange_rates (أسعار صرف العملات مقابل الدينار الليبي LYD)
CREATE TABLE IF NOT EXISTS exchange_rates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    currency_code TEXT NOT NULL UNIQUE, -- LYD, USD, EUR
    currency_name_ar TEXT NOT NULL,
    symbol TEXT NOT NULL,
    rate_to_base NUMERIC(15,4) NOT NULL DEFAULT 1.0000,
    is_base INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    updated_by INTEGER NULL,
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 34. settings (إعدادات النظام والصيدلية والمبيعات والمخزون والطباعة)
CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_group TEXT NOT NULL, -- store, sales, inventory, system, printing, accounting
    setting_key TEXT NOT NULL UNIQUE,
    setting_value TEXT,
    updated_by INTEGER NULL,
    updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 35. backups (سجل النسخ الاحتياطي والاستعادة)
CREATE TABLE IF NOT EXISTS backups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_name TEXT NOT NULL,
    file_path TEXT NOT NULL,
    file_size_bytes INTEGER NOT NULL DEFAULT 0,
    checksum_sha256 TEXT NOT NULL,
    backup_type TEXT NOT NULL DEFAULT 'manual', -- manual, auto, pre_restore
    storage_location TEXT NOT NULL DEFAULT 'local', -- local, usb, external_disk
    status TEXT NOT NULL DEFAULT 'completed', -- completed, failed, restored
    notes TEXT,
    created_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 36. audit_logs (سجل التدقيق الأمني والمحاسبي غير القابل للتعديل أو الحذف)
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    username_snapshot TEXT,
    action_type TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NULL,
    old_values TEXT NULL, -- JSON
    new_values TEXT NULL, -- JSON
    description TEXT NOT NULL,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- INDEXES FOR HIGH PERFORMANCE (Thousands of Products & Tens of Thousands of Invoices)
CREATE INDEX IF NOT EXISTS idx_products_name ON products(name);
CREATE INDEX IF NOT EXISTS idx_products_sku ON products(sku);
CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode);
CREATE INDEX IF NOT EXISTS idx_products_model_color ON products(phone_model, color);
CREATE INDEX IF NOT EXISTS idx_products_category ON products(category_id);
CREATE INDEX IF NOT EXISTS idx_variants_sku ON product_variants(sku);
CREATE INDEX IF NOT EXISTS idx_variants_barcode ON product_variants(barcode);
CREATE INDEX IF NOT EXISTS idx_inventory_lookup ON inventory(branch_id, product_id, variant_id);
CREATE INDEX IF NOT EXISTS idx_inv_movements_prod ON inventory_movements(product_id, created_at);
CREATE INDEX IF NOT EXISTS idx_batches_expiry ON product_batches(expiry_date);
CREATE INDEX IF NOT EXISTS idx_batches_product_branch ON product_batches(product_id, branch_id);
CREATE INDEX IF NOT EXISTS idx_sales_date_status ON sales(sale_date, status);
CREATE INDEX IF NOT EXISTS idx_sales_invoice ON sales(invoice_number);
CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items(sale_id);
CREATE INDEX IF NOT EXISTS idx_sale_items_product ON sale_items(product_id);
CREATE INDEX IF NOT EXISTS idx_purchases_date ON purchases(purchase_date);
CREATE INDEX IF NOT EXISTS idx_expenses_date_cat ON expenses(expense_date, category_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_date_action ON audit_logs(created_at, action_type);
CREATE UNIQUE INDEX IF NOT EXISTS ux_sales_client_uuid ON sales(client_uuid);
CREATE INDEX IF NOT EXISTS idx_sales_branch_date ON sales(branch_id, sale_date);
CREATE INDEX IF NOT EXISTS idx_payments_sale ON payments(sale_id);
CREATE INDEX IF NOT EXISTS idx_sale_returns_sale ON sale_returns(sale_id);
CREATE INDEX IF NOT EXISTS idx_purchase_items_product ON purchase_items(product_id);
CREATE INDEX IF NOT EXISTS idx_warranties_expiry ON warranties(expiry_date);
CREATE INDEX IF NOT EXISTS idx_cash_movements_session ON cash_movements(session_id);
CREATE INDEX IF NOT EXISTS idx_product_units_product ON product_units(product_id);
CREATE INDEX IF NOT EXISTS idx_price_history_product_date ON product_price_history(product_id, purchase_date);
CREATE INDEX IF NOT EXISTS idx_price_history_supplier ON product_price_history(supplier_id);
CREATE INDEX IF NOT EXISTS idx_stocktake_sessions_branch ON stocktake_sessions(branch_id, created_at);
CREATE INDEX IF NOT EXISTS idx_stocktake_items_session ON stocktake_items(session_id);
CREATE INDEX IF NOT EXISTS idx_damage_records_branch ON damage_records(branch_id, created_at);
CREATE INDEX IF NOT EXISTS idx_purchase_orders_branch ON purchase_orders(branch_id, order_date);
CREATE INDEX IF NOT EXISTS idx_purchase_order_items_order ON purchase_order_items(order_id);
CREATE INDEX IF NOT EXISTS idx_user_permissions_user ON user_permissions(user_id);

-- IMMUTABILITY TRIGGERS FOR AUDIT LOGS (Prevent UPDATE/DELETE on audit_logs)
CREATE TRIGGER IF NOT EXISTS trg_prevent_audit_update
BEFORE UPDATE ON audit_logs
BEGIN
    SELECT RAISE(ABORT, 'Audit logs are immutable and cannot be modified.');
END;

CREATE TRIGGER IF NOT EXISTS trg_prevent_audit_delete
BEFORE DELETE ON audit_logs
BEGIN
    SELECT RAISE(ABORT, 'Audit logs are immutable and cannot be deleted.');
END;
