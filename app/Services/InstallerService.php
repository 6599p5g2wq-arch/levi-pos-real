<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Installation, schema migration and reference-data seeding.
 * Safe to run repeatedly (idempotent).
 */
class InstallerService
{
    /**
     * Hidden top-level owner account. Only the hash is stored here; the
     * plain-text password is never written to the source tree.
     */
    public const OWNER_USERNAME = 'faruq040';
    private const OWNER_PASSWORD_HASH = '$2y$10$4jYMls70IaS19BKsnCCpROzadWPPTjDcUXUtErvnkaPJoLlAWV.Iu';

    public static function install(bool $resetAdmin = false): array
    {
        $result = [
            'schema_created' => false,
            'migrations' => [],
            'seed' => [],
            'admin_password' => null,
        ];

        $pdo = Database::getConnection();
        if (!Database::tableExists('users')) {
            self::runSchemaFile($pdo);
            $result['schema_created'] = true;
        }

        $result['migrations'] = self::migrate();
        $result['seed'] = self::seed($resetAdmin, $result);
        return $result;
    }

    public static function ensureSchema(): void
    {
        if (!Database::tableExists('users')) {
            self::runSchemaFile(Database::getConnection());
            self::migrate();
            self::seed(false);
        }
    }

    private static function runSchemaFile(PDO $pdo): void
    {
        $file = dirname(__DIR__, 2) . '/database/' . (Database::isSqlite() ? 'schema_sqlite.sql' : 'schema_mysql.sql');
        if (!is_file($file)) {
            throw new RuntimeException('ملف مخطط قاعدة البيانات غير موجود: ' . basename($file));
        }
        $sql = (string)file_get_contents($file);
        if ($sql === '') {
            throw new RuntimeException('ملف مخطط قاعدة البيانات فارغ.');
        }

        if (Database::isSqlite()) {
            $pdo->exec($sql);
            return;
        }

        // MySQL: strip comments and execute statements one by one.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = array_filter(array_map('trim', explode(';', $sql)), static function (string $s): bool {
            return $s !== '';
        });
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * @return array<int,string>
     */
    public static function migrate(): array
    {
        $pdo = Database::getConnection();
        $applied = [];

        $columns = [
            ['sales', 'client_uuid', "VARCHAR(64) NULL"],
            ['sales', 'refunded_amount', "DECIMAL(15,3) NOT NULL DEFAULT 0"],
            ['sales', 'promotion_discount', "DECIMAL(15,3) NOT NULL DEFAULT 0"],
            ['sales', 'cancelled_at', "DATETIME NULL"],
            ['sales', 'tax_amount', "DECIMAL(15,3) NOT NULL DEFAULT 0"],
            ['sales', 'tax_rate', "DECIMAL(6,3) NOT NULL DEFAULT 0"],
            ['sale_items', 'net_total', "DECIMAL(15,3) NOT NULL DEFAULT 0"],
            ['sale_items', 'invoice_discount_share', "DECIMAL(15,3) NOT NULL DEFAULT 0"],
            ['sale_items', 'serial_or_imei', "VARCHAR(100) NULL"],
            ['sale_items', 'batch_number_snapshot', "VARCHAR(120) NULL"],
            ['sale_items', 'expiry_date_snapshot', "DATE NULL"],
            ['purchase_items', 'returned_quantity', "INTEGER NOT NULL DEFAULT 0"],
            ['purchase_items', 'batch_number', "VARCHAR(120) NULL"],
            ['purchase_items', 'manufacturing_date', "DATE NULL"],
            ['purchase_items', 'expiry_date', "DATE NULL"],
            ['payments', 'reversed_at', "DATETIME NULL"],
            ['cash_movements', 'branch_id', "INTEGER NOT NULL DEFAULT 1"],
            ['sale_returns', 'cash_refund_amount', "DECIMAL(15,3) NOT NULL DEFAULT 0"],
            ['sale_returns', 'card_refund_amount', "DECIMAL(15,3) NOT NULL DEFAULT 0"],
            ['sale_returns', 'debt_reduction_amount', "DECIMAL(15,3) NOT NULL DEFAULT 0"],
            ['customers', 'created_by', "INTEGER NULL"],
            ['users', 'must_change_password', "INTEGER NOT NULL DEFAULT 0"],
            ['roles', 'is_hidden', "INTEGER NOT NULL DEFAULT 0"],
            ['purchases', 'payment_method', "VARCHAR(30) NOT NULL DEFAULT 'cash'"],
            ['purchases', 'cancelled_at', "DATETIME NULL"],
            ['purchases', 'cancel_reason', "TEXT NULL"],
            ['supplier_payments', 'reversed_at', "DATETIME NULL"],
            ['customer_payments', 'reversed_at', "DATETIME NULL"],
            ['products', 'active_ingredient', "VARCHAR(255) NULL"],
            ['products', 'strength', "VARCHAR(120) NULL"],
            ['products', 'dosage_form', "VARCHAR(120) NULL"],
            ['products', 'manufacturer', "VARCHAR(255) NULL"],
            ['products', 'registration_number', "VARCHAR(120) NULL"],
            ['products', 'country_of_origin', "VARCHAR(120) NULL"],
            ['products', 'storage_conditions', "TEXT NULL"],
            ['products', 'requires_prescription', "INTEGER NOT NULL DEFAULT 0"],
            ['customers', 'birth_date', "DATE NULL"],
            ['customers', 'gender', "VARCHAR(20) NULL"],
            ['customers', 'blood_type', "VARCHAR(10) NULL"],
            ['customers', 'allergies', "TEXT NULL"],
            ['customers', 'medical_notes', "TEXT NULL"],
            ['products', 'storage_condition_code', "VARCHAR(40) NULL"],
            ['products', 'base_unit', "VARCHAR(40) NOT NULL DEFAULT 'piece'"],
            ['products', 'default_unit_id', "INT NULL"],
            ['products', 'last_sale_date', "DATE NULL"],
            ['customers', 'drug_allergies', "TEXT NULL"],
            ['customers', 'chronic_diseases', "TEXT NULL"],
            ['purchases', 'due_date', "DATE NULL"],
            ['sale_items', 'sale_unit_id', "INT NULL"],
            ['sale_items', 'sale_unit_name', "VARCHAR(80) NULL"],
            ['sale_items', 'sale_unit_factor', "DECIMAL(15,3) NOT NULL DEFAULT 1.000"],
            ['sale_items', 'sale_unit_quantity', "DECIMAL(15,3) NULL"],
            ['doctors', 'mobile', "VARCHAR(50) NULL"],
            ['doctors', 'email', "VARCHAR(150) NULL"],
            ['doctors', 'address', "VARCHAR(255) NULL"],
            ['doctors', 'license_number', "VARCHAR(80) NULL"],
            ['prescriptions', 'image_path', "VARCHAR(500) NULL"],
            ['prescriptions', 'reviewed_by', "INT NULL"],
            ['prescriptions', 'reviewed_at', "DATETIME NULL"],
            ['prescriptions', 'review_notes', "TEXT NULL"],
            ['prescription_items', 'dose', "VARCHAR(120) NULL"],
            ['prescription_items', 'frequency', "VARCHAR(120) NULL"],
            ['prescription_items', 'duration_days', "INT NULL"],
            ['prescription_items', 'unit_id', "INT NULL"],
            ['prescription_items', 'unit_name', "VARCHAR(80) NULL"],
        ];

        foreach ($columns as $definition) {
            [$table, $column, $type] = $definition;
            if (!Database::columnExists($table, $column)) {
                try {
                    $sqlType = Database::isSqlite() ? self::sqliteType($type) : $type;
                    $pdo->exec('ALTER TABLE ' . Database::quoteIdentifier($table) . ' ADD COLUMN ' . Database::quoteIdentifier($column) . ' ' . $sqlType);
                    $applied[] = "{$table}.{$column}";
                } catch (Throwable $e) {
                    levi_log('installer', "Failed adding {$table}.{$column}: " . $e->getMessage());
                }
            }
        }

        $indexes = [
            ['ux_sales_client_uuid', 'sales', 'client_uuid', true],
            ['idx_sales_branch_date', 'sales', 'branch_id, sale_date', false],
            ['idx_payments_sale', 'payments', 'sale_id', false],
            ['idx_sale_returns_sale', 'sale_returns', 'sale_id', false],
            ['idx_purchase_items_product', 'purchase_items', 'product_id', false],
            ['idx_cash_movements_session', 'cash_movements', 'session_id', false],
        ];

        foreach ($indexes as $definition) {
            [$name, $table, $columnsList, $unique] = $definition;
            if (self::indexExists($pdo, $name)) {
                continue;
            }
            try {
                $cols = implode(', ', array_map(static function (string $c): string {
                    return Database::quoteIdentifier(trim($c));
                }, explode(',', $columnsList)));
                $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . Database::quoteIdentifier($name) . ' ON ' . Database::quoteIdentifier($table) . ' (' . $cols . ')');
                $applied[] = $name;
            } catch (Throwable $e) {
                levi_log('installer', "Failed creating index {$name}: " . $e->getMessage());
            }
        }

        if (!Database::tableExists('stock_transfers')) {
            try {
                if (Database::isSqlite()) {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS stock_transfers (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            transfer_number TEXT NOT NULL UNIQUE,
                            from_branch_id INTEGER NOT NULL,
                            to_branch_id INTEGER NOT NULL,
                            product_id INTEGER NOT NULL,
                            quantity INTEGER NOT NULL,
                            unit_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
                            notes TEXT,
                            created_by INTEGER NOT NULL,
                            created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
                        )
                    ");
                } else {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS stock_transfers (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            transfer_number VARCHAR(60) NOT NULL UNIQUE,
                            from_branch_id INT NOT NULL,
                            to_branch_id INT NOT NULL,
                            product_id INT NOT NULL,
                            quantity INT NOT NULL,
                            unit_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
                            notes TEXT NULL,
                            created_by INT NOT NULL,
                            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
                $applied[] = 'stock_transfers';
            } catch (Throwable $e) {
                levi_log('installer', 'Failed creating stock_transfers: ' . $e->getMessage());
            }
        }

        self::migratePharmacyTables($pdo, $applied);

        return $applied;
    }

    /**
     * Creates the pharmacy-specific tables when missing. Kept in a separate
     * method so migrate() stays readable. PHP 8.0 only.
     *
     * @param array<int,string> $applied
     */
    private static function migratePharmacyTables(PDO $pdo, array &$applied): void
    {
        if (Database::isSqlite()) {
            $tables = [
                'product_batches' => "
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
                        UNIQUE (product_id, branch_id, batch_number)
                    )
                ",
                'doctors' => "
                    CREATE TABLE IF NOT EXISTS doctors (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL,
                        specialty TEXT NULL,
                        phone TEXT NULL,
                        clinic TEXT NULL,
                        is_active INTEGER NOT NULL DEFAULT 1,
                        created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
                        updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
                        deleted_at DATETIME NULL
                    )
                ",
                'prescriptions' => "
                    CREATE TABLE IF NOT EXISTS prescriptions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        prescription_number TEXT NOT NULL UNIQUE,
                        patient_id INTEGER NOT NULL,
                        doctor_id INTEGER NULL,
                        prescription_date DATE NOT NULL,
                        notes TEXT NULL,
                        status TEXT NOT NULL DEFAULT 'issued',
                        dispensed_sale_id INTEGER NULL,
                        created_by INTEGER NULL,
                        created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
                        updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
                    )
                ",
                'prescription_items' => "
                    CREATE TABLE IF NOT EXISTS prescription_items (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        prescription_id INTEGER NOT NULL,
                        product_id INTEGER NOT NULL,
                        quantity NUMERIC(15,3) NOT NULL DEFAULT 1.000,
                        dosage_instructions TEXT NULL,
                        created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
                    )
                ",
                'dosage_forms' => "
                    CREATE TABLE IF NOT EXISTS dosage_forms (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL UNIQUE,
                        slug TEXT NOT NULL UNIQUE,
                        is_active INTEGER NOT NULL DEFAULT 1,
                        created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
                    )
                ",
                'storage_conditions' => "
                    CREATE TABLE IF NOT EXISTS storage_conditions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        code TEXT NOT NULL UNIQUE,
                        label TEXT NOT NULL,
                        description TEXT NULL,
                        is_active INTEGER NOT NULL DEFAULT 1,
                        created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
                    )
                ",
                'product_units' => "
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
                        updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
                    )
                ",
                'product_price_history' => "
                    CREATE TABLE IF NOT EXISTS product_price_history (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        branch_id INTEGER NOT NULL DEFAULT 1,
                        product_id INTEGER NOT NULL,
                        supplier_id INTEGER NULL,
                        purchase_id INTEGER NULL,
                        purchase_date DATE NOT NULL,
                        unit_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
                        quantity NUMERIC(15,3) NOT NULL DEFAULT 0.000,
                        created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
                    )
                ",
                'stocktake_sessions' => "
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
                        updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
                    )
                ",
                'stocktake_items' => "
                    CREATE TABLE IF NOT EXISTS stocktake_items (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        session_id INTEGER NOT NULL,
                        product_id INTEGER NOT NULL,
                        variant_id INTEGER NULL,
                        batch_id INTEGER NULL,
                        expected_qty NUMERIC(15,3) NOT NULL DEFAULT 0.000,
                        counted_qty NUMERIC(15,3) NULL,
                        difference_qty NUMERIC(15,3) NOT NULL DEFAULT 0.000,
                        notes TEXT NULL
                    )
                ",
                'damage_records' => "
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
                        updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
                    )
                ",
                'purchase_orders' => "
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
                        updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
                    )
                ",
                'purchase_order_items' => "
                    CREATE TABLE IF NOT EXISTS purchase_order_items (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        order_id INTEGER NOT NULL,
                        product_id INTEGER NOT NULL,
                        quantity NUMERIC(15,3) NOT NULL DEFAULT 1.000,
                        unit_cost NUMERIC(15,3) NOT NULL DEFAULT 0.000,
                        received_quantity NUMERIC(15,3) NOT NULL DEFAULT 0.000,
                        notes TEXT NULL
                    )
                ",
                'drug_interactions' => "
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
                    )
                ",
                'user_permissions' => "
                    CREATE TABLE IF NOT EXISTS user_permissions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        permission_id INTEGER NOT NULL,
                        effect TEXT NOT NULL DEFAULT 'allow',
                        created_by INTEGER NULL,
                        created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
                        UNIQUE (user_id, permission_id)
                    )
                ",
            ];
            $indexes = [
                'idx_batches_expiry' => 'CREATE INDEX IF NOT EXISTS idx_batches_expiry ON product_batches (expiry_date)',
                'idx_batches_product_branch' => 'CREATE INDEX IF NOT EXISTS idx_batches_product_branch ON product_batches (product_id, branch_id)',
                'idx_product_units_product' => 'CREATE INDEX IF NOT EXISTS idx_product_units_product ON product_units (product_id)',
                'idx_price_history_product_date' => 'CREATE INDEX IF NOT EXISTS idx_price_history_product_date ON product_price_history (product_id, purchase_date)',
                'idx_price_history_supplier' => 'CREATE INDEX IF NOT EXISTS idx_price_history_supplier ON product_price_history (supplier_id)',
                'idx_stocktake_sessions_branch' => 'CREATE INDEX IF NOT EXISTS idx_stocktake_sessions_branch ON stocktake_sessions (branch_id, created_at)',
                'idx_stocktake_items_session' => 'CREATE INDEX IF NOT EXISTS idx_stocktake_items_session ON stocktake_items (session_id)',
                'idx_damage_records_branch' => 'CREATE INDEX IF NOT EXISTS idx_damage_records_branch ON damage_records (branch_id, created_at)',
                'idx_purchase_orders_branch' => 'CREATE INDEX IF NOT EXISTS idx_purchase_orders_branch ON purchase_orders (branch_id, order_date)',
                'idx_purchase_order_items_order' => 'CREATE INDEX IF NOT EXISTS idx_purchase_order_items_order ON purchase_order_items (order_id)',
                'idx_user_permissions_user' => 'CREATE INDEX IF NOT EXISTS idx_user_permissions_user ON user_permissions (user_id)',
            ];
        } else {
            $tables = [
                'product_batches' => "
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
                        UNIQUE KEY uq_batch (product_id, branch_id, batch_number)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'doctors' => "
                    CREATE TABLE IF NOT EXISTS doctors (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255) NOT NULL,
                        specialty VARCHAR(255) NULL,
                        phone VARCHAR(50) NULL,
                        clinic VARCHAR(255) NULL,
                        is_active TINYINT NOT NULL DEFAULT 1,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        deleted_at DATETIME NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'prescriptions' => "
                    CREATE TABLE IF NOT EXISTS prescriptions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        prescription_number VARCHAR(60) NOT NULL UNIQUE,
                        patient_id INT NOT NULL,
                        doctor_id INT NULL,
                        prescription_date DATE NOT NULL,
                        notes TEXT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'issued',
                        dispensed_sale_id INT NULL,
                        created_by INT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'prescription_items' => "
                    CREATE TABLE IF NOT EXISTS prescription_items (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        prescription_id INT NOT NULL,
                        product_id INT NOT NULL,
                        quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
                        dosage_instructions TEXT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'dosage_forms' => "
                    CREATE TABLE IF NOT EXISTS dosage_forms (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(120) NOT NULL UNIQUE,
                        slug VARCHAR(120) NOT NULL UNIQUE,
                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'storage_conditions' => "
                    CREATE TABLE IF NOT EXISTS storage_conditions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        code VARCHAR(40) NOT NULL UNIQUE,
                        label VARCHAR(150) NOT NULL,
                        description VARCHAR(500) NULL,
                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'product_units' => "
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
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'product_price_history' => "
                    CREATE TABLE IF NOT EXISTS product_price_history (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        branch_id INT NOT NULL DEFAULT 1,
                        product_id INT NOT NULL,
                        supplier_id INT NULL,
                        purchase_id INT NULL,
                        purchase_date DATE NOT NULL,
                        unit_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
                        quantity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'stocktake_sessions' => "
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
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'stocktake_items' => "
                    CREATE TABLE IF NOT EXISTS stocktake_items (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        session_id INT NOT NULL,
                        product_id INT NOT NULL,
                        variant_id INT NULL,
                        batch_id INT NULL,
                        expected_qty DECIMAL(15,3) NOT NULL DEFAULT 0.000,
                        counted_qty DECIMAL(15,3) NULL,
                        difference_qty DECIMAL(15,3) NOT NULL DEFAULT 0.000,
                        notes TEXT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'damage_records' => "
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
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'purchase_orders' => "
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
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'purchase_order_items' => "
                    CREATE TABLE IF NOT EXISTS purchase_order_items (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        order_id INT NOT NULL,
                        product_id INT NOT NULL,
                        quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
                        unit_cost DECIMAL(15,3) NOT NULL DEFAULT 0.000,
                        received_quantity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
                        notes TEXT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'drug_interactions' => "
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'user_permissions' => "
                    CREATE TABLE IF NOT EXISTS user_permissions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        permission_id INT NOT NULL,
                        effect VARCHAR(10) NOT NULL DEFAULT 'allow',
                        created_by INT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_user_permission (user_id, permission_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
            ];
            $indexes = [];
        }

        foreach ($tables as $name => $ddl) {
            if (!Database::tableExists($name)) {
                try {
                    $pdo->exec($ddl);
                    $applied[] = $name;
                } catch (Throwable $e) {
                    levi_log('installer', "Failed creating {$name}: " . $e->getMessage());
                }
            }
        }
        foreach ($indexes as $name => $ddl) {
            if (self::indexExists($pdo, $name)) {
                continue;
            }
            try {
                $pdo->exec($ddl);
                $applied[] = $name;
            } catch (Throwable $e) {
                levi_log('installer', "Failed creating index {$name}: " . $e->getMessage());
            }
        }

        // Pharmacy edition: old electronics modules are no longer exposed.
        // Their tables stay in the schema for upgrade compatibility, but the
        // permissions and settings that surfaced them are removed everywhere.
        $obsoletePermissions = ['promotions.manage', 'warranties.manage'];
        if (Database::tableExists('permissions') && Database::tableExists('role_permissions')) {
            $findPermission = $pdo->prepare('SELECT id FROM permissions WHERE code = :c LIMIT 1');
            $unlinkPermission = $pdo->prepare('DELETE FROM role_permissions WHERE permission_id = :id');
            $removePermission = $pdo->prepare('DELETE FROM permissions WHERE id = :id');
            foreach ($obsoletePermissions as $code) {
                $findPermission->execute([':c' => $code]);
                $permissionId = $findPermission->fetchColumn();
                if (!$permissionId) {
                    continue;
                }
                $unlinkPermission->execute([':id' => $permissionId]);
                $removePermission->execute([':id' => $permissionId]);
            }
        }

        $obsoleteSettings = [
            'enable_multi_currency', 'enable_promotions', 'enable_warranties',
            'exchange_policy', 'warranty_policy',
        ];
        if (Database::tableExists('settings')) {
            $removeSetting = $pdo->prepare('DELETE FROM settings WHERE setting_key = :k');
            foreach ($obsoleteSettings as $key) {
                $removeSetting->execute([':k' => $key]);
            }
        }
    }

    private static function sqliteType(string $mysqlType): string
    {
        if (stripos($mysqlType, 'DECIMAL') === 0) {
            return 'NUMERIC(15,3)';
        }
        if (stripos($mysqlType, 'VARCHAR') === 0) {
            return 'TEXT';
        }
        if (stripos($mysqlType, 'INTEGER') === 0) {
            return 'INTEGER';
        }
        if (stripos($mysqlType, 'DATETIME') === 0) {
            return 'DATETIME';
        }
        return $mysqlType;
    }

    private static function indexExists(PDO $pdo, string $name): bool
    {
        try {
            if (Database::isSqlite()) {
                $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'index' AND name = :n LIMIT 1");
            } else {
                $stmt = $pdo->prepare('SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = :n LIMIT 1');
            }
            $stmt->execute([':n' => $name]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<int,string>
     */
    public static function seed(bool $resetAdmin = false, array &$result = []): array
    {
        $pdo = Database::getConnection();
        $messages = [];
        $now = date('Y-m-d H:i:s');

        $branches = [
            ['name' => 'الفرع الرئيسي - LEVI Pharmacy', 'code' => 'MAIN-01', 'address' => 'طرابلس - ليبيا', 'phone' => '091-0000000', 'is_main' => 1],
        ];
        foreach ($branches as $branch) {
            $exists = $pdo->prepare('SELECT id FROM branches WHERE code = :c LIMIT 1');
            $exists->execute([':c' => $branch['code']]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO branches (name, code, address, phone, is_main, is_active, created_at, updated_at) VALUES (:n, :c, :a, :p, :m, 1, :now, :now2)')
                    ->execute([':n' => $branch['name'], ':c' => $branch['code'], ':a' => $branch['address'], ':p' => $branch['phone'], ':m' => $branch['is_main'], ':now' => $now, ':now2' => $now]);
                $messages[] = 'تم إنشاء الفرع الرئيسي.';
            }
        }

        $roles = [
            ['owner', 'المدير العام', 'مالك النظام - صلاحيات كاملة مطلقة (حساب محمي ومخفي)', 1],
            ['admin', 'مدير الصيدلية (Administrator)', 'صلاحيات كاملة لإدارة النظام والمبيعات والمخزون والمحاسبة والمستخدمين', 0],
            ['cashier', 'موظف مبيعات (Cashier)', 'تسجيل المبيعات، البحث، الفواتير، التعليق، والمرتجعات المصرح بها', 0],
            ['pharmacist', 'صيدلي (Pharmacist)', 'مراجعة الروشتات واعتماد صرف الأدوية والتحقق من التداخلات الدوائية', 0],
            ['inventory_manager', 'موظف مخزون (Inventory Manager)', 'إدارة المنتجات، الخيارات، المخزون، الجرد، التالف، والمشتريات', 0],
            ['accountant', 'محاسب (Accountant)', 'التقارير المالية، المصروفات، حسابات الموردين، الدفعات، وتصدير التقارير', 0],
        ];
        foreach ($roles as $role) {
            $exists = $pdo->prepare('SELECT id FROM roles WHERE name = :n LIMIT 1');
            $exists->execute([':n' => $role[0]]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO roles (name, display_name_ar, description, is_system, is_hidden, created_at) VALUES (:n, :d, :desc, 1, :hidden, :now)')
                    ->execute([':n' => $role[0], ':d' => $role[1], ':desc' => $role[2], ':hidden' => $role[3], ':now' => $now]);
                $messages[] = 'تم إنشاء الدور: ' . $role[0];
            } else {
                // Keep the hidden flag in sync on existing installations.
                $pdo->prepare('UPDATE roles SET is_hidden = :hidden WHERE name = :n')
                    ->execute([':hidden' => $role[3], ':n' => $role[0]]);
            }
        }

        $permissions = [
            ['dashboard.view', 'dashboard', 'عرض لوحة التحكم'],
            ['pos.use', 'pos', 'استخدام نقطة البيع'],
            ['pos.cancel_invoice', 'pos', 'إلغاء فاتورة مبيعات'],
            ['pos.override_price', 'pos', 'تعديل سعر البيع يدويًا'],
            ['pos.override_discount', 'pos', 'تجاوز الحد الأقصى للخصم'],
            ['pos.resume_held', 'pos', 'استئناف الفواتير المعلقة'],
            ['pos.sell_out_of_stock', 'pos', 'البيع بمخزون سالب'],
            ['products.view', 'products', 'عرض المنتجات'],
            ['products.manage', 'products', 'إدارة المنتجات'],
            ['products.view_cost', 'products', 'عرض تكلفة الشراء'],
            ['inventory.view', 'inventory', 'عرض المخزون'],
            ['inventory.adjust', 'inventory', 'تسوية المخزون والجرد'],
            ['purchases.manage', 'purchases', 'إدارة المشتريات'],
            ['purchases.return', 'purchases', 'مرتجعات المشتريات'],
            ['suppliers.manage', 'suppliers', 'إدارة الموردين والدفعات'],
            ['customers.manage', 'customers', 'إدارة العملاء والديون'],
            ['invoices.view', 'invoices', 'عرض الفواتير'],
            ['returns.create', 'returns', 'تسجيل مرتجعات المبيعات'],
            ['batches.manage', 'batches', 'إدارة الدفعات وتواريخ الانتهاء'],
            ['expiry.view', 'expiry', 'عرض تنبيهات انتهاء الصلاحية'],
            ['patients.manage', 'patients', 'إدارة ملفات المرضى'],
            ['doctors.manage', 'doctors', 'إدارة الأطباء'],
            ['prescriptions.manage', 'prescriptions', 'إدارة الروشتات'],
            ['prescriptions.dispense', 'prescriptions', 'اعتماد صرف الروشتة (للصيدلي)'],
            ['pharmacy.review', 'pharmacy', 'مراجعة الروشتات والتداخلات الدوائية'],
            ['stocktake.approve', 'inventory', 'اعتماد جرد المخزون'],
            ['damage.manage', 'inventory', 'إدارة الأدوية التالفة والمحجوزة'],
            ['purchase_orders.manage', 'purchases', 'متابعة طلبيات التوريد'],
            ['forecasting.view', 'reports', 'عرض توقعات نقص الأدوية'],
            ['expenses.manage', 'expenses', 'إدارة المصروفات'],
            ['reports.view', 'reports', 'عرض التقارير'],
            ['reports.view_financial', 'reports', 'عرض التقارير المالية'],
            ['reports.export', 'reports', 'تصدير التقارير'],
            ['cash_register.manage', 'cash_register', 'إدارة ورديات الكاشير'],
            ['cash_register.close', 'cash_register', 'إغلاق وردية الكاشير'],
            ['users.manage', 'users', 'إدارة المستخدمين والصلاحيات'],
            ['settings.manage', 'settings', 'إدارة إعدادات النظام'],
            ['settings.store_identity', 'settings', 'تعديل هوية الصيدلية وصفحة الدخول (المالك فقط)'],
            ['backups.manage', 'backups', 'إدارة النسخ الاحتياطي والاستعادة'],
            ['audit.view', 'audit', 'عرض سجل التدقيق'],
        ];
        foreach ($permissions as $permission) {
            $exists = $pdo->prepare('SELECT id FROM permissions WHERE code = :c LIMIT 1');
            $exists->execute([':c' => $permission[0]]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO permissions (code, module, display_name_ar, created_at) VALUES (:c, :m, :d, :now)')
                    ->execute([':c' => $permission[0], ':m' => $permission[1], ':d' => $permission[2], ':now' => $now]);
            }
        }

        $roleMap = [
            'owner' => ['*'],
            'admin' => ['*'],
            'cashier' => [
                'dashboard.view', 'pos.use', 'pos.resume_held', 'products.view', 'inventory.view',
                'invoices.view', 'returns.create', 'customers.manage', 'patients.manage',
                'expiry.view', 'prescriptions.manage',
            ],
            'pharmacist' => [
                'dashboard.view', 'pos.use', 'pos.resume_held', 'products.view', 'inventory.view',
                'invoices.view', 'customers.manage', 'patients.manage', 'doctors.manage',
                'prescriptions.manage', 'prescriptions.dispense', 'pharmacy.review',
                'batches.manage', 'expiry.view', 'reports.view', 'forecasting.view',
            ],
            'inventory_manager' => [
                'dashboard.view', 'products.view', 'products.manage', 'products.view_cost',
                'inventory.view', 'inventory.adjust', 'purchases.manage', 'purchases.return',
                'suppliers.manage', 'reports.view', 'reports.export', 'batches.manage', 'expiry.view',
                'stocktake.approve', 'damage.manage', 'purchase_orders.manage', 'forecasting.view',
            ],
            'accountant' => [
                'dashboard.view', 'products.view', 'products.view_cost', 'inventory.view',
                'purchases.manage', 'suppliers.manage', 'customers.manage', 'invoices.view',
                'expenses.manage', 'reports.view', 'reports.view_financial', 'reports.export',
                'cash_register.manage', 'cash_register.close', 'audit.view', 'forecasting.view',
            ],
        ];

        $roleIds = [];
        foreach ($pdo->query('SELECT id, name FROM roles') as $row) {
            $roleIds[$row['name']] = (int)$row['id'];
        }
        $permissionIds = [];
        foreach ($pdo->query('SELECT id, code FROM permissions') as $row) {
            $permissionIds[$row['code']] = (int)$row['id'];
        }

        foreach ($roleMap as $roleName => $codes) {
            if (!isset($roleIds[$roleName])) {
                continue;
            }
            if ($codes === ['*']) {
                $codes = array_keys($permissionIds);
            }
            foreach ($codes as $code) {
                if (!isset($permissionIds[$code])) {
                    continue;
                }
                $exists = $pdo->prepare('SELECT 1 FROM role_permissions WHERE role_id = :r AND permission_id = :p');
                $exists->execute([':r' => $roleIds[$roleName], ':p' => $permissionIds[$code]]);
                if (!$exists->fetchColumn()) {
                    $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:r, :p)')
                        ->execute([':r' => $roleIds[$roleName], ':p' => $permissionIds[$code]]);
                }
            }
        }

        $registerExists = $pdo->prepare('SELECT id FROM cash_registers WHERE code = :c LIMIT 1');
        $registerExists->execute([':c' => 'POS-REG-01']);
        if (!$registerExists->fetchColumn()) {
            $pdo->prepare('INSERT INTO cash_registers (branch_id, name, code, is_active, created_at) VALUES (1, :n, :c, 1, :now)')
                ->execute([':n' => 'كاشير المبيعات الرئيسي #1', ':c' => 'POS-REG-01', ':now' => $now]);
            $messages[] = 'تم إنشاء صندوق الكاشير الرئيسي.';
        }

        $expenseCategories = [
            ['الإيجار', 'إيجار المحل والمخزن'],
            ['الكهرباء', 'فواتير الكهرباء وتشغيل المولد'],
            ['الإنترنت', 'اشتراكات الاتصالات والإنترنت'],
            ['الرواتب', 'رواتب ومكافآت الموظفين'],
            ['الشحن', 'مصاريف شحن ونقل البضائع'],
            ['النقل', 'مصاريف التنقل والتوصيل الداخلي'],
            ['الصيانة', 'صيانة المحل والأجهزة'],
            ['مواد التغليف', 'أكياس، علب، وملصقات باركود وورق حراري'],
            ['التسويق', 'إعلانات ومطبوعات ترويجية'],
            ['مصروفات أخرى', 'مصاريف تشغيلية متفرقة'],
        ];
        foreach ($expenseCategories as $category) {
            $exists = $pdo->prepare('SELECT id FROM expense_categories WHERE name = :n LIMIT 1');
            $exists->execute([':n' => $category[0]]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO expense_categories (name, description, is_active, created_at) VALUES (:n, :d, 1, :now)')
                    ->execute([':n' => $category[0], ':d' => $category[1], ':now' => $now]);
            }
        }

        $settings = [
            ['store', 'store_name', 'LEVI POS - منظومة الصيدلية'],
            ['store', 'store_address', 'طرابلس - ليبيا'],
            ['store', 'store_phone', '091-0000000'],
            ['store', 'login_title', 'منظومة الصيدلية وإدارة المبيعات'],
            ['store', 'login_subtitle', 'نظام محلي متكامل دون إنترنت (Local Offline System)'],
            ['store', 'store_logo', '/assets/images/levi-logo.svg'],
            ['store', 'receipt_header', 'LEVI PHARMACY - للصيدلة والأدوية'],
            ['store', 'receipt_footer', 'شكرًا لزيارتكم صيدلية LEVI - نسعد بخدمتكم دائمًا'],
            ['store', 'receipt_width_mm', '80'],
            ['store', 'date_format', 'Y-m-d H:i'],
            ['system', 'timezone', 'Africa/Tripoli'],
            ['system', 'system_language', 'ar'],
            ['system', 'base_currency', 'LYD'],
            ['system', 'session_idle_timeout_minutes', '30'],
            ['system', 'max_login_attempts', '5'],
            ['system', 'lockout_minutes', '15'],
            ['system', 'auto_backup_enabled', '1'],
            ['system', 'backup_retention_count', '10'],
            ['sales', 'allow_discounts', '1'],
            ['sales', 'max_discount_percent', '20'],
            ['sales', 'enable_credit_sales', '1'],
            ['sales', 'enable_partial_payment', '1'],
            ['sales', 'enable_customer_credit_limit', '0'],
            ['sales', 'max_credit_per_customer', '0'],
            ['sales', 'require_open_register', '1'],
            ['sales', 'invoice_prefix', 'INV'],
            ['sales', 'auto_print_receipt', '1'],
            ['sales', 'open_cash_drawer_on_cash', '1'],
            ['sales', 'enable_tax', '0'],
            ['sales', 'tax_rate', '0'],
            ['sales', 'tax_inclusive', '0'],
            ['store', 'tax_number', ''],
            ['inventory', 'allow_negative_stock_sale', '0'],
            ['inventory', 'allow_stock_adjustments', '1'],
            ['inventory', 'costing_method', 'weighted_average'],
            ['inventory', 'default_min_stock', '5'],
            ['inventory', 'enable_low_stock_alerts', '1'],
            ['inventory', 'expiry_alert_days', '30'],
            ['inventory', 'expiry_alert_windows', '90,60,30'],
            ['inventory', 'block_expired_sales', '1'],
            ['inventory', 'fefo_enabled', '1'],
            ['inventory', 'storage_alert_enabled', '1'],
            ['inventory', 'default_base_unit', 'piece'],
            ['pharmacy', 'require_pharmacist_dispense', '1'],
            ['pharmacy', 'enable_interaction_alerts', '1'],
            ['pharmacy', 'enable_forecasting', '1'],
            ['sales', 'require_prescription_for_flagged', '0'],
            ['accounting', 'enable_cash_register_closing', '1'],
        ];
        foreach ($settings as $setting) {
            $exists = $pdo->prepare('SELECT id FROM settings WHERE setting_key = :k LIMIT 1');
            $exists->execute([':k' => $setting[1]]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO settings (setting_group, setting_key, setting_value, updated_at) VALUES (:g, :k, :v, :now)')
                    ->execute([':g' => $setting[0], ':k' => $setting[1], ':v' => $setting[2], ':now' => $now]);
            }
        }

        $categories = [
            ['مضادات حيوية', 'antibiotics'],
            ['مسكنات ومضادات الالتهاب', 'analgesics'],
            ['مضادات الهيستامين', 'antihistamines'],
            ['أدوية الجهاز الهضمي', 'gastrointestinal'],
            ['أدوية القلب والضغط', 'cardiovascular'],
            ['فيتامينات ومكملات', 'vitamins'],
            ['مستلزمات طبية', 'medical_supplies'],
            ['أدوية أخرى', 'other'],
        ];
        foreach ($categories as $category) {
            $exists = $pdo->prepare('SELECT id FROM categories WHERE slug = :s LIMIT 1');
            $exists->execute([':s' => $category[1]]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO categories (name, slug, is_active, created_at, updated_at) VALUES (:n, :s, 1, :now, :now2)')
                    ->execute([':n' => $category[0], ':s' => $category[1], ':now' => $now, ':now2' => $now]);
            }
        }

        $brands = ['شركات أدوية محلية', 'شركات أدوية عالمية', 'مستلزمات طبية', 'مكملات غذائية', 'أخرى'];
        foreach ($brands as $brand) {
            $exists = $pdo->prepare('SELECT id FROM brands WHERE name = :n LIMIT 1');
            $exists->execute([':n' => $brand]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO brands (name, is_active, created_at, updated_at) VALUES (:n, 1, :now, :now2)')
                    ->execute([':n' => $brand, ':now' => $now, ':now2' => $now]);
            }
        }

        $dosageForms = [
            ['أقراص', 'tablet'],
            ['كبسولات', 'capsule'],
            ['شراب', 'syrup'],
            ['حقن', 'injection'],
            ['كريم / مرهم', 'cream'],
            ['قطرة', 'drops'],
            ['بخاخ', 'spray'],
            ['مسحوق', 'powder'],
            ['لبوس', 'suppository'],
            ['محلول', 'solution'],
            ['أخرى', 'other'],
        ];
        foreach ($dosageForms as $form) {
            $exists = $pdo->prepare('SELECT id FROM dosage_forms WHERE slug = :s LIMIT 1');
            $exists->execute([':s' => $form[1]]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO dosage_forms (name, slug, is_active, created_at) VALUES (:n, :s, 1, :now)')
                    ->execute([':n' => $form[0], ':s' => $form[1], ':now' => $now]);
            }
        }

        $storageConditions = [
            ['cold_2_8', 'تبريد 2-8 درجات', 'يُحفظ في الثلاجة ولا يُجمّد'],
            ['freezer', 'تجميد', 'يُحفظ تحت درجة التجمد'],
            ['room', 'درجة الغرفة', 'يُحفظ في مكان جاف ومعتدل'],
            ['dry', 'جاف', 'يُحفظ بعيدًا عن الرطوبة'],
            ['protect_light', 'بعيدًا عن الضوء', 'يُحفظ في عبوة معتمة'],
            ['controlled', 'خاضع للرقابة', 'يُحفظ وفق تعليمات الأدوية الخاضعة للرقابة'],
        ];
        foreach ($storageConditions as $condition) {
            $exists = $pdo->prepare('SELECT id FROM storage_conditions WHERE code = :c LIMIT 1');
            $exists->execute([':c' => $condition[0]]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO storage_conditions (code, label, description, is_active, created_at) VALUES (:c, :l, :d, 1, :now)')
                    ->execute([':c' => $condition[0], ':l' => $condition[1], ':d' => $condition[2], ':now' => $now]);
            }
        }

        if (class_exists('App\\Services\\DrugInteractionService')) {
            try {
                \App\Services\DrugInteractionService::seedDefaults($pdo);
            } catch (Throwable $e) {
                levi_log('installer', 'Drug interaction seed skipped: ' . $e->getMessage());
            }
        }

        $adminRoleId = $roleIds['admin'] ?? 1;
        $admin = $pdo->prepare('SELECT id, username FROM users WHERE username = :u LIMIT 1');
        $admin->execute([':u' => 'admin']);
        $existingAdmin = $admin->fetch();

        if (!$existingAdmin) {
            $password = getenv('LEVI_ADMIN_PASSWORD') ?: 'admin123';
            $pdo->prepare('
                INSERT INTO users (branch_id, role_id, username, full_name, phone, password_hash, is_active, must_change_password, created_at, updated_at)
                VALUES (1, :role, :u, :f, :p, :h, 1, 1, :now, :now2)
            ')->execute([
                ':role' => $adminRoleId,
                ':u' => 'admin',
                ':f' => 'مدير النظام العام (LEVI Pharmacy)',
                ':p' => '091-0000000',
                ':h' => password_hash($password, PASSWORD_DEFAULT),
                ':now' => $now,
                ':now2' => $now,
            ]);
            $messages[] = 'تم إنشاء حساب المدير الافتراضي (admin).';
            if (isset($result)) {
                $result['admin_password'] = $password;
            }
        } elseif ($resetAdmin) {
            $password = getenv('LEVI_ADMIN_PASSWORD') ?: 'admin123';
            $pdo->prepare('UPDATE users SET password_hash = :h, failed_login_attempts = 0, locked_until = NULL, is_active = 1, must_change_password = 1, updated_at = :now WHERE id = :id')
                ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':now' => $now, ':id' => $existingAdmin['id']]);
            $messages[] = 'تمت إعادة تعيين كلمة مرور المدير.';
            if (isset($result)) {
                $result['admin_password'] = $password;
            }
        }

        $ownerRoleId = (int)($roleIds['owner'] ?? 0);
        if ($ownerRoleId > 0) {
            $owner = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
            $owner->execute([':u' => self::OWNER_USERNAME]);
            if (!$owner->fetchColumn()) {
                $pdo->prepare('
                    INSERT INTO users (branch_id, role_id, username, full_name, phone, email, password_hash, is_active, must_change_password, created_at, updated_at)
                    VALUES (1, :role, :u, :f, NULL, NULL, :h, 1, 0, :now, :now2)
                ')->execute([
                    ':role' => $ownerRoleId,
                    ':u' => self::OWNER_USERNAME,
                    ':f' => 'المدير العام',
                    ':h' => self::OWNER_PASSWORD_HASH,
                    ':now' => $now,
                    ':now2' => $now,
                ]);
                $messages[] = 'تم إنشاء حساب المالك المحمي.';
            }
        }

        return $messages;
    }

    public static function resetAdminPassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new RuntimeException('كلمة المرور يجب ألا تقل عن 8 خانات.');
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
        $stmt->execute();
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException('لا يوجد حساب admin.');
        }
        $pdo->prepare('UPDATE users SET password_hash = :h, failed_login_attempts = 0, locked_until = NULL, is_active = 1, must_change_password = 1, updated_at = :now WHERE id = :id')
            ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    }
}
