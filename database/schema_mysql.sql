-- LEVI POS - MySQL / MariaDB Schema (production ready)
-- All indexed string columns are VARCHAR; TEXT is used only for free text.
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- 1. branches
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    address VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    is_main TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. roles
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    display_name_ar VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 1,
    is_hidden TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. permissions
CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    module VARCHAR(50) NOT NULL,
    display_name_ar VARCHAR(150) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. role_permissions
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. users
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    role_id INT NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    KEY idx_users_branch (branch_id),
    CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. categories
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    description VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. brands
CREATE TABLE IF NOT EXISTS brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    country VARCHAR(100) NULL,
    description VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. products
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    brand_id INT NULL,
    name VARCHAR(200) NOT NULL,
    sku VARCHAR(100) NOT NULL UNIQUE,
    barcode VARCHAR(100) NULL UNIQUE,
    product_type VARCHAR(30) NOT NULL DEFAULT 'standard',
    phone_model VARCHAR(150) NULL,
    color VARCHAR(100) NULL,
    size VARCHAR(100) NULL,
    description TEXT NULL,
    image_path VARCHAR(500) NULL,
    purchase_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    selling_price DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    wholesale_price DECIMAL(15,3) NULL,
    min_stock_level INT NOT NULL DEFAULT 5,
    warranty_days INT NOT NULL DEFAULT 0,
    has_variants TINYINT(1) NOT NULL DEFAULT 0,
    active_ingredient VARCHAR(255) NULL,
    strength VARCHAR(120) NULL,
    dosage_form VARCHAR(120) NULL,
    manufacturer VARCHAR(255) NULL,
    registration_number VARCHAR(120) NULL,
    country_of_origin VARCHAR(120) NULL,
    storage_conditions TEXT NULL,
    storage_condition_code VARCHAR(40) NULL,
    base_unit VARCHAR(40) NOT NULL DEFAULT 'piece',
    default_unit_id INT NULL,
    last_sale_date DATE NULL,
    requires_prescription TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    KEY idx_products_name (name),
    KEY idx_products_ingredient (active_ingredient),
    KEY idx_products_model_color (phone_model, color),
    KEY idx_products_category (category_id),
    KEY idx_products_brand (brand_id),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id),
    CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. product_variants
CREATE TABLE IF NOT EXISTS product_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    variant_name VARCHAR(150) NOT NULL,
    phone_model VARCHAR(150) NULL,
    color VARCHAR(100) NULL,
    size VARCHAR(100) NULL,
    sku VARCHAR(100) NOT NULL UNIQUE,
    barcode VARCHAR(100) NULL UNIQUE,
    purchase_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    selling_price DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    wholesale_price DECIMAL(15,3) NULL,
    min_stock_level INT NOT NULL DEFAULT 3,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    KEY idx_variants_product (product_id),
    CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. product_images
CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    variant_id INT NULL,
    image_path VARCHAR(500) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_images_product (product_id),
    CONSTRAINT fk_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_images_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. inventory
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    product_id INT NOT NULL,
    variant_id INT NULL,
    quantity INT NOT NULL DEFAULT 0,
    reserved_quantity INT NOT NULL DEFAULT 0,
    average_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    last_counted_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inventory (branch_id, product_id, variant_id),
    CONSTRAINT fk_inventory_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_inventory_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_inventory_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. inventory_movements
CREATE TABLE IF NOT EXISTS inventory_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    product_id INT NOT NULL,
    variant_id INT NULL,
    movement_type VARCHAR(50) NOT NULL,
    quantity_before INT NOT NULL,
    quantity_change INT NOT NULL,
    quantity_after INT NOT NULL,
    unit_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    reference_type VARCHAR(50) NULL,
    reference_id INT NULL,
    invoice_number VARCHAR(64) NULL,
    reason VARCHAR(500) NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_movements_product (product_id, created_at),
    KEY idx_movements_branch (branch_id, created_at),
    CONSTRAINT fk_movements_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_movements_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_movements_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id),
    CONSTRAINT fk_movements_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12b. stock_transfers
CREATE TABLE IF NOT EXISTS stock_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transfer_number VARCHAR(60) NOT NULL UNIQUE,
    from_branch_id INT NOT NULL,
    to_branch_id INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT NULL,
    quantity INT NOT NULL,
    unit_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    notes TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_stock_transfers_from (from_branch_id),
    KEY idx_stock_transfers_to (to_branch_id),
    CONSTRAINT fk_st_from FOREIGN KEY (from_branch_id) REFERENCES branches(id),
    CONSTRAINT fk_st_to FOREIGN KEY (to_branch_id) REFERENCES branches(id),
    CONSTRAINT fk_st_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_st_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id),
    CONSTRAINT fk_st_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. stock_adjustments
CREATE TABLE IF NOT EXISTS stock_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    adjustment_number VARCHAR(64) NOT NULL UNIQUE,
    product_id INT NOT NULL,
    variant_id INT NULL,
    adjustment_type VARCHAR(30) NOT NULL,
    quantity_before INT NOT NULL,
    quantity_adjusted INT NOT NULL,
    quantity_after INT NOT NULL,
    unit_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    reason VARCHAR(500) NOT NULL,
    notes TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_adjustments_branch (branch_id, created_at),
    CONSTRAINT fk_adjustments_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_adjustments_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_adjustments_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id),
    CONSTRAINT fk_adjustments_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. suppliers
CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    company_name VARCHAR(150) NULL,
    phone VARCHAR(50) NOT NULL,
    email VARCHAR(150) NULL,
    address VARCHAR(255) NULL,
    opening_balance DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    current_balance DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    first_deal_date DATE NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    KEY idx_suppliers_name (name),
    KEY idx_suppliers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. purchases
CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    invoice_number VARCHAR(64) NOT NULL UNIQUE,
    supplier_invoice_no VARCHAR(100) NULL,
    supplier_id INT NOT NULL,
    purchase_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    subtotal DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    discount_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    shipping_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    extra_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    cost_allocation_method VARCHAR(20) NOT NULL DEFAULT 'value',
    total_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    paid_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    remaining_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    payment_status VARCHAR(20) NOT NULL DEFAULT 'paid',
    payment_method VARCHAR(30) NOT NULL DEFAULT 'cash', -- cash, card, transfer, cheque
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    due_date DATE NULL,
    notes TEXT NULL,
    cancelled_at DATETIME NULL,
    cancel_reason TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    KEY idx_purchases_date (purchase_date),
    KEY idx_purchases_supplier (supplier_id),
    CONSTRAINT fk_purchases_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_purchases_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. purchase_items
CREATE TABLE IF NOT EXISTS purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT NULL,
    batch_number VARCHAR(120) NULL,
    manufacturing_date DATE NULL,
    expiry_date DATE NULL,
    quantity INT NOT NULL,
    remaining_fifo_qty INT NOT NULL DEFAULT 0,
    returned_quantity INT NOT NULL DEFAULT 0,
    unit_cost DECIMAL(15,3) NOT NULL,
    allocated_extra_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    landed_unit_cost DECIMAL(15,3) NOT NULL,
    discount_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    line_total DECIMAL(15,3) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_purchase_items_product (product_id),
    KEY idx_purchase_items_purchase (purchase_id),
    CONSTRAINT fk_pi_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_pi_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. supplier_payments
CREATE TABLE IF NOT EXISTS supplier_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    receipt_number VARCHAR(64) NOT NULL UNIQUE,
    supplier_id INT NOT NULL,
    purchase_id INT NULL,
    amount DECIMAL(15,3) NOT NULL,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'LYD',
    exchange_rate DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
    amount_in_base DECIMAL(15,3) NOT NULL,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'cash', -- cash, card, transfer, cheque
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    reversed_at DATETIME NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sp_supplier (supplier_id),
    CONSTRAINT fk_sp_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_sp_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_sp_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL,
    CONSTRAINT fk_sp_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. purchase_returns
CREATE TABLE IF NOT EXISTS purchase_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    return_number VARCHAR(64) NOT NULL UNIQUE,
    purchase_id INT NOT NULL,
    supplier_id INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT NULL,
    quantity INT NOT NULL,
    unit_cost DECIMAL(15,3) NOT NULL,
    total_amount DECIMAL(15,3) NOT NULL,
    refund_method VARCHAR(30) NOT NULL DEFAULT 'balance_deduction',
    reason VARCHAR(500) NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pr_purchase (purchase_id),
    KEY idx_pr_supplier (supplier_id),
    CONSTRAINT fk_pr_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_pr_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id),
    CONSTRAINT fk_pr_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_pr_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_pr_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id),
    CONSTRAINT fk_pr_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. customers
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NULL,
    address VARCHAR(255) NULL,
    credit_limit DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    current_debt DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    notes TEXT NULL,
    birth_date DATE NULL,
    gender VARCHAR(20) NULL,
    blood_type VARCHAR(10) NULL,
    allergies TEXT NULL,
    drug_allergies TEXT NULL,
    chronic_diseases TEXT NULL,
    medical_notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    KEY idx_customers_name (name),
    CONSTRAINT fk_customers_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. cash_registers
CREATE TABLE IF NOT EXISTS cash_registers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_registers_branch (branch_id),
    CONSTRAINT fk_registers_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. cash_register_sessions
CREATE TABLE IF NOT EXISTS cash_register_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cash_register_id INT NOT NULL DEFAULT 1,
    user_id INT NOT NULL,
    session_number VARCHAR(64) NOT NULL UNIQUE,
    opening_cash DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    total_sales DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    cash_sales DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    card_sales DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    cash_expenses DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    cash_refunds DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    expected_cash DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    actual_cash DECIMAL(15,3) NULL,
    discrepancy DECIMAL(15,3) NULL,
    discrepancy_reason TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    closed_by INT NULL,
    KEY idx_sessions_status (status),
    KEY idx_sessions_register (cash_register_id),
    CONSTRAINT fk_sessions_register FOREIGN KEY (cash_register_id) REFERENCES cash_registers(id),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_sessions_closed_by FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. cash_movements
CREATE TABLE IF NOT EXISTS cash_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    branch_id INT NOT NULL DEFAULT 1,
    movement_type VARCHAR(40) NOT NULL,
    amount DECIMAL(15,3) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id INT NULL,
    description VARCHAR(500) NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cash_movements_session (session_id),
    KEY idx_cash_movements_branch (branch_id, created_at),
    CONSTRAINT fk_cm_session FOREIGN KEY (session_id) REFERENCES cash_register_sessions(id),
    CONSTRAINT fk_cm_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_cm_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. promotions
CREATE TABLE IF NOT EXISTS promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    name VARCHAR(150) NOT NULL,
    promo_type VARCHAR(40) NOT NULL,
    target_product_id INT NULL,
    target_category_id INT NULL,
    discount_type VARCHAR(20) NOT NULL DEFAULT 'percentage',
    discount_value DECIMAL(15,3) NOT NULL,
    min_invoice_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    min_qty INT NOT NULL DEFAULT 1,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_promotions_active (is_active, start_date, end_date),
    CONSTRAINT fk_promotions_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_promotions_product FOREIGN KEY (target_product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_promotions_category FOREIGN KEY (target_category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. sales
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    session_id INT NULL,
    invoice_number VARCHAR(64) NOT NULL UNIQUE,
    customer_id INT NULL,
    cashier_id INT NOT NULL,
    sale_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    subtotal DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    item_discount_total DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    invoice_discount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    total_discount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    total_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    tax_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    total_cogs DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    paid_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    remaining_debt DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    change_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
    payment_status VARCHAR(20) NOT NULL DEFAULT 'paid',
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    client_uuid VARCHAR(64) NULL,
    promotion_discount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    refunded_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    notes TEXT NULL,
    cancelled_by INT NULL,
    cancel_reason TEXT NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    UNIQUE KEY ux_sales_client_uuid (client_uuid),
    KEY idx_sales_branch_date (branch_id, sale_date),
    KEY idx_sales_status (status),
    KEY idx_sales_customer (customer_id),
    KEY idx_sales_cashier (cashier_id),
    CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_sales_session FOREIGN KEY (session_id) REFERENCES cash_register_sessions(id),
    CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_cashier FOREIGN KEY (cashier_id) REFERENCES users(id),
    CONSTRAINT fk_sales_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 25. sale_items
CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT NULL,
    product_name_snapshot VARCHAR(200) NOT NULL,
    sku_snapshot VARCHAR(100) NOT NULL,
    variant_snapshot VARCHAR(150) NULL,
    batch_number_snapshot VARCHAR(120) NULL,
    expiry_date_snapshot DATE NULL,
    serial_or_imei VARCHAR(100) NULL,
    quantity INT NOT NULL,
    sale_unit_id INT NULL,
    sale_unit_name VARCHAR(80) NULL,
    sale_unit_factor DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    sale_unit_quantity DECIMAL(15,3) NULL,
    returned_quantity INT NOT NULL DEFAULT 0,
    unit_cost DECIMAL(15,3) NOT NULL,
    unit_price DECIMAL(15,3) NOT NULL,
    discount_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    line_total DECIMAL(15,3) NOT NULL,
    invoice_discount_share DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    net_total DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    line_cogs DECIMAL(15,3) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sale_items_sale (sale_id),
    KEY idx_sale_items_product (product_id),
    CONSTRAINT fk_si_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_si_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_si_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26. payments
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'LYD',
    exchange_rate DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
    amount_foreign DECIMAL(15,3) NOT NULL,
    amount_base DECIMAL(15,3) NOT NULL,
    reference_no VARCHAR(100) NULL,
    paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reversed_at DATETIME NULL,
    created_by INT NOT NULL,
    KEY idx_payments_sale (sale_id),
    CONSTRAINT fk_payments_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 27. customer_payments
CREATE TABLE IF NOT EXISTS customer_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    receipt_number VARCHAR(64) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    sale_id INT NULL,
    amount DECIMAL(15,3) NOT NULL,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'LYD',
    exchange_rate DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
    amount_in_base DECIMAL(15,3) NOT NULL,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'cash', -- cash, card, transfer, cheque
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    reversed_at DATETIME NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cp_customer (customer_id),
    CONSTRAINT fk_cp_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_cp_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_cp_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    CONSTRAINT fk_cp_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 28. sale_returns
CREATE TABLE IF NOT EXISTS sale_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    return_number VARCHAR(64) NOT NULL UNIQUE,
    sale_id INT NOT NULL,
    customer_id INT NULL,
    return_type VARCHAR(20) NOT NULL DEFAULT 'refund',
    exchange_sale_id INT NULL,
    total_refund_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    total_returned_cogs DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    cash_refund_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    card_refund_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    debt_reduction_amount DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    price_difference DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    refund_method VARCHAR(30) NOT NULL DEFAULT 'cash',
    reason VARCHAR(500) NOT NULL,
    notes TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sale_returns_sale (sale_id),
    KEY idx_sale_returns_branch (branch_id, created_at),
    CONSTRAINT fk_sr_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_sr_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
    CONSTRAINT fk_sr_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_sr_exchange FOREIGN KEY (exchange_sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    CONSTRAINT fk_sr_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 29. sale_return_items
CREATE TABLE IF NOT EXISTS sale_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_return_id INT NOT NULL,
    sale_item_id INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT NULL,
    quantity INT NOT NULL,
    unit_refund_price DECIMAL(15,3) NOT NULL,
    unit_cost DECIMAL(15,3) NOT NULL,
    line_refund_total DECIMAL(15,3) NOT NULL,
    item_condition VARCHAR(30) NOT NULL DEFAULT 'resellable',
    restocked TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_sri_return (sale_return_id),
    CONSTRAINT fk_sri_return FOREIGN KEY (sale_return_id) REFERENCES sale_returns(id) ON DELETE CASCADE,
    CONSTRAINT fk_sri_sale_item FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
    CONSTRAINT fk_sri_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_sri_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 30. warranties
CREATE TABLE IF NOT EXISTS warranties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    warranty_number VARCHAR(64) NOT NULL UNIQUE,
    sale_id INT NOT NULL,
    sale_item_id INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT NULL,
    customer_id INT NULL,
    customer_name VARCHAR(150) NULL,
    customer_phone VARCHAR(50) NULL,
    serial_or_imei VARCHAR(100) NULL,
    sale_date DATE NOT NULL,
    warranty_days INT NOT NULL,
    expiry_date DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    claim_notes TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_warranties_expiry (expiry_date),
    KEY idx_warranties_sale (sale_id),
    CONSTRAINT fk_warranties_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
    CONSTRAINT fk_warranties_sale_item FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
    CONSTRAINT fk_warranties_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_warranties_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id),
    CONSTRAINT fk_warranties_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_warranties_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacy: product_batches (دفعات الأدوية وتواريخ الانتهاء)
CREATE TABLE IF NOT EXISTS product_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    branch_id INT NOT NULL DEFAULT 1,
    batch_number VARCHAR(120) NOT NULL,
    manufacturing_date DATE NULL,
    expiry_date DATE NOT NULL,
    quantity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    purchase_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_batch (product_id, branch_id, batch_number),
    KEY idx_batches_expiry (expiry_date),
    KEY idx_batches_product_branch (product_id, branch_id),
    CONSTRAINT fk_batches_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_batches_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacy: doctors (الأطباء)
CREATE TABLE IF NOT EXISTS doctors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    specialty VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    mobile VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    clinic VARCHAR(150) NULL,
    address VARCHAR(255) NULL,
    license_number VARCHAR(80) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacy: prescriptions (الروشتات)
CREATE TABLE IF NOT EXISTS prescriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prescription_number VARCHAR(60) NOT NULL UNIQUE,
    patient_id INT NOT NULL,
    doctor_id INT NULL,
    prescription_date DATE NOT NULL,
    notes TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'issued',
    dispensed_sale_id INT NULL,
    image_path VARCHAR(500) NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_prescriptions_patient (patient_id),
    KEY idx_prescriptions_status (status),
    CONSTRAINT fk_presc_patient FOREIGN KEY (patient_id) REFERENCES customers(id),
    CONSTRAINT fk_presc_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL,
    CONSTRAINT fk_presc_sale FOREIGN KEY (dispensed_sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    CONSTRAINT fk_presc_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacy: prescription_items (تفاصيل الروشتات)
CREATE TABLE IF NOT EXISTS prescription_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    dosage_instructions TEXT NULL,
    dose VARCHAR(120) NULL,
    frequency VARCHAR(120) NULL,
    duration_days INT NULL,
    unit_id INT NULL,
    unit_name VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_presc_items_presc (prescription_id),
    CONSTRAINT fk_pi_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE,
    CONSTRAINT fk_pi_presc_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacy extensions: organised dosage forms and storage condition catalogue
CREATE TABLE IF NOT EXISTS dosage_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    slug VARCHAR(120) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_conditions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacy: sellable units with conversion to the base stock unit
CREATE TABLE IF NOT EXISTS product_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    unit_name VARCHAR(80) NOT NULL,
    base_unit VARCHAR(40) NOT NULL DEFAULT 'piece',
    conversion_factor DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    selling_price DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    barcode VARCHAR(100) NULL UNIQUE,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_product_units_product (product_id),
    CONSTRAINT fk_product_units_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacy: historical purchase prices for supplier comparison
CREATE TABLE IF NOT EXISTS product_price_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    product_id INT NOT NULL,
    supplier_id INT NULL,
    purchase_id INT NULL,
    purchase_date DATE NOT NULL,
    unit_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    quantity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_price_history_product_date (product_id, purchase_date),
    KEY idx_price_history_supplier (supplier_id),
    CONSTRAINT fk_price_history_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_price_history_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_price_history_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT fk_price_history_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacy: stocktake sessions with counted quantities and approval
CREATE TABLE IF NOT EXISTS stocktake_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_number VARCHAR(60) NOT NULL UNIQUE,
    branch_id INT NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    include_batches TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_stocktake_sessions_branch (branch_id, created_at),
    CONSTRAINT fk_stocktake_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_stocktake_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_stocktake_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stocktake_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT NULL,
    batch_id INT NULL,
    expected_qty DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    counted_qty DECIMAL(15,3) NULL,
    difference_qty DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    notes TEXT NULL,
    KEY idx_stocktake_items_session (session_id),
    CONSTRAINT fk_stocktake_items_session FOREIGN KEY (session_id) REFERENCES stocktake_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_stocktake_items_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_stocktake_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    CONSTRAINT fk_stocktake_items_batch FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacy: damaged / quarantined / destroyed medicines
CREATE TABLE IF NOT EXISTS damage_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_number VARCHAR(60) NOT NULL UNIQUE,
    branch_id INT NOT NULL DEFAULT 1,
    product_id INT NOT NULL,
    variant_id INT NULL,
    batch_id INT NULL,
    quantity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    condition VARCHAR(30) NOT NULL DEFAULT 'damaged',
    reason VARCHAR(500) NOT NULL,
    action_taken VARCHAR(30) NOT NULL DEFAULT 'quarantine',
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_damage_records_branch (branch_id, created_at),
    CONSTRAINT fk_damage_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_damage_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_damage_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    CONSTRAINT fk_damage_batch FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE SET NULL,
    CONSTRAINT fk_damage_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacy: purchase orders and supply follow-up
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(60) NOT NULL UNIQUE,
    branch_id INT NOT NULL DEFAULT 1,
    supplier_id INT NOT NULL,
    order_date DATE NOT NULL,
    expected_date DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_purchase_orders_branch (branch_id, order_date),
    CONSTRAINT fk_po_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_po_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    unit_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    received_quantity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    notes TEXT NULL,
    KEY idx_purchase_order_items_order (order_id),
    CONSTRAINT fk_poi_order FOREIGN KEY (order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pharmacy: local drug-interaction reference. Decision remains with the pharmacist.
CREATE TABLE IF NOT EXISTS drug_interactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_a VARCHAR(255) NOT NULL,
    ingredient_b VARCHAR(255) NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'moderate',
    description VARCHAR(500) NOT NULL,
    source VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_interaction (ingredient_a, ingredient_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user permission overrides (allow/deny) on top of role permissions
CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    effect VARCHAR(10) NOT NULL DEFAULT 'allow',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_permission (user_id, permission_id),
    CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permissions_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 31. expense_categories
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    description VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 32. expenses
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    expense_number VARCHAR(64) NOT NULL UNIQUE,
    category_id INT NOT NULL,
    description VARCHAR(500) NOT NULL,
    amount DECIMAL(15,3) NOT NULL,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'LYD',
    exchange_rate DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
    amount_in_base DECIMAL(15,3) NOT NULL,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'cash', -- cash, card, transfer, cheque
    expense_date DATE NOT NULL,
    attachment_path VARCHAR(500) NULL,
    notes TEXT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    KEY idx_expenses_date_cat (expense_date, category_id),
    KEY idx_expenses_branch (branch_id, expense_date),
    CONSTRAINT fk_expenses_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES expense_categories(id),
    CONSTRAINT fk_expenses_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 33. exchange_rates
CREATE TABLE IF NOT EXISTS exchange_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    currency_code VARCHAR(10) NOT NULL UNIQUE,
    currency_name_ar VARCHAR(100) NOT NULL,
    symbol VARCHAR(20) NOT NULL,
    rate_to_base DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
    is_base TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rates_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 34. settings
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_group VARCHAR(50) NOT NULL,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 35. backups
CREATE TABLE IF NOT EXISTS backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(1000) NOT NULL,
    file_size_bytes BIGINT NOT NULL DEFAULT 0,
    checksum_sha256 CHAR(64) NOT NULL,
    backup_type VARCHAR(30) NOT NULL DEFAULT 'manual',
    storage_location VARCHAR(30) NOT NULL DEFAULT 'local',
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_backups_created (created_at),
    CONSTRAINT fk_backups_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 36. audit_logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username_snapshot VARCHAR(80) NULL,
    action_type VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id VARCHAR(64) NULL,
    old_values LONGTEXT NULL,
    new_values LONGTEXT NULL,
    description TEXT NOT NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_logs_date_action (created_at, action_type),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutability triggers (single-statement bodies keep the installer splitter safe).
CREATE TRIGGER trg_prevent_audit_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are immutable and cannot be modified.';

CREATE TRIGGER trg_prevent_audit_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are immutable and cannot be deleted.';

SET FOREIGN_KEY_CHECKS = 1;
