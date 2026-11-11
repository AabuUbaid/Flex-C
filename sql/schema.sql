-- RetailPOS schema: MySQL 5.7+/8.x or MariaDB 10.3+, InnoDB, utf8mb4
-- Money: DECIMAL(14,2). Quantities: DECIMAL(14,3). Item cost: DECIMAL(14,4).
-- Every business table carries tenant_id; the API always filters by it.

CREATE TABLE tenants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE companies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  name_ar VARCHAR(150) NULL,
  trn VARCHAR(20) NULL,
  address VARCHAR(255) NULL,
  phone VARCHAR(40) NULL,
  footer VARCHAR(255) NULL,
  logo MEDIUMTEXT NULL,                    -- data:image/...;base64 (shop/B2B invoice header)
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shops (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  code VARCHAR(10) NOT NULL,               -- generated from shop name + 001, 002 ... for branches
  group_code VARCHAR(10) NULL,             -- same value for all branches of one shop
  name VARCHAR(120) NOT NULL,
  trade ENUM('clothes','mobile','grocery','stationery','other') NOT NULL DEFAULT 'other',
  address VARCHAR(255) NULL,
  logo MEDIUMTEXT NULL,                    -- data:image/...;base64
  UNIQUE KEY uq_shop_code (tenant_id, code),
  FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE registers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  shop_id INT UNSIGNED NOT NULL,
  code VARCHAR(10) NOT NULL,
  UNIQUE KEY uq_register (shop_id, code),
  FOREIGN KEY (shop_id) REFERENCES shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(40) NULL,              -- shop code + first 3 letters of name + 6 digits
  email VARCHAR(190) NULL,                -- optional; sign-in also works with username
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','manager','accountant','cashier','purchase') NOT NULL DEFAULT 'cashier',
  shop_id INT UNSIGNED NULL,              -- NULL = all shops, set = restricted to that shop
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_user_email (email),
  UNIQUE KEY uq_user_username (tenant_id, username),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_tokens (
  token_hash CHAR(64) PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  KEY idx_token_exp (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_attempt (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  sku VARCHAR(40) NOT NULL,
  name VARCHAR(200) NOT NULL,
  name_ar VARCHAR(200) NULL,
  category VARCHAR(80) NULL,
  uom VARCHAR(10) NOT NULL DEFAULT 'pc',
  price DECIMAL(14,2) NOT NULL DEFAULT 0,          -- selling price, VAT inclusive
  cost DECIMAL(14,4) NOT NULL DEFAULT 0,           -- weighted average cost, ex VAT
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  reorder_level DECIMAL(14,3) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_item_sku (company_id, sku),
  KEY idx_item_tenant (tenant_id, company_id, active),
  FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE item_barcodes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  barcode VARCHAR(64) NOT NULL,
  UNIQUE KEY uq_barcode (company_id, barcode),
  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(40) NULL,
  trn VARCHAR(20) NULL,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,   -- what we owed on day one
  FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only: stock = SUM(qty). Never UPDATE or DELETE rows here.
CREATE TABLE stock_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  shop_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty DECIMAL(14,3) NOT NULL,
  unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  ref_type ENUM('sale','purchase','adjust','opening','transfer_in','transfer_out') NOT NULL,
  ref_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ledger_stock (tenant_id, shop_id, item_id),
  KEY idx_ledger_item (item_id),
  KEY idx_ledger_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  shop_id INT UNSIGNED NOT NULL,
  register_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  authorised_by INT UNSIGNED NULL,        -- manager/accountant who approved a return
  auth_ref VARCHAR(40) NULL,              -- token | offline
  invoice_no VARCHAR(40) NOT NULL,
  seq INT UNSIGNED NOT NULL DEFAULT 0,
  sold_at DATETIME NOT NULL,
  type ENUM('sale','return') NOT NULL DEFAULT 'sale',
  gross DECIMAL(14,2) NOT NULL,
  discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL,          -- VAT inclusive; negative for returns
  vat_total DECIMAL(14,2) NOT NULL,
  net_total DECIMAL(14,2) NOT NULL,      -- total - vat_total
  cogs DECIMAL(14,2) NOT NULL DEFAULT 0,
  customer_name VARCHAR(120) NULL,
  customer_trn VARCHAR(20) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- when the server received it
  UNIQUE KEY uq_sales_uuid (uuid),
  UNIQUE KEY uq_sales_invoice (tenant_id, invoice_no),
  KEY idx_sales_shop_date (shop_id, sold_at),
  KEY idx_sales_company_date (company_id, sold_at),
  KEY idx_sales_register (register_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sale_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id BIGINT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty DECIMAL(14,3) NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_rate DECIMAL(5,2) NOT NULL,
  vat_amount DECIMAL(14,2) NOT NULL,
  line_total DECIMAL(14,2) NOT NULL,
  unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  KEY idx_lines_sale (sale_id),
  KEY idx_lines_item (item_id),
  FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id BIGINT UNSIGNED NOT NULL,
  method ENUM('cash','card','bank','other') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  KEY idx_pay_sale (sale_id),
  FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  shop_id INT UNSIGNED NOT NULL,           -- shop that received the goods
  supplier_id INT UNSIGNED NOT NULL,
  bill_no VARCHAR(40) NOT NULL,
  bill_date DATE NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,         -- ex VAT
  vat_total DECIMAL(14,2) NOT NULL,
  total DECIMAL(14,2) NOT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_purchase_bill (supplier_id, bill_no),
  KEY idx_purchase_company (company_id, bill_date),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id BIGINT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty DECIMAL(14,3) NOT NULL,
  unit_cost DECIMAL(14,4) NOT NULL,        -- ex VAT
  vat_rate DECIMAL(5,2) NOT NULL,
  vat_amount DECIMAL(14,2) NOT NULL,
  line_total DECIMAL(14,2) NOT NULL,       -- incl VAT
  KEY idx_pl_purchase (purchase_id),
  FOREIGN KEY (purchase_id) REFERENCES purchases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  purchase_id BIGINT UNSIGNED NULL,
  amount DECIMAL(14,2) NOT NULL,
  method ENUM('cash','card','bank','cheque') NOT NULL DEFAULT 'cash',
  paid_at DATE NOT NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sp_supplier (supplier_id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  shop_id INT UNSIGNED NULL,
  category VARCHAR(60) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,           -- incl VAT
  vat_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  expense_date DATE NOT NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_exp_company (company_id, expense_date),
  FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pre-aggregated numbers so the owner dashboard stays fast on shared hosting.
CREATE TABLE daily_summary (
  tenant_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  shop_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  txn_count INT NOT NULL DEFAULT 0,
  sales_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  cogs DECIMAL(14,2) NOT NULL DEFAULT 0,
  cash_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  card_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  other_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (shop_id, day),
  KEY idx_ds_tenant_day (tenant_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  detail TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-customer preferences (low stock alert level, share contacts, currency).
CREATE TABLE tenant_settings (
  tenant_id INT UNSIGNED NOT NULL PRIMARY KEY,
  low_stock_threshold DECIMAL(14,3) NOT NULL DEFAULT 10,
  share_email VARCHAR(190) NULL,
  share_whatsapp VARCHAR(40) NULL,
  currency VARCHAR(8) NOT NULL DEFAULT 'AED',
  receipt_note VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived, single-use approvals for returns / discounts (hashed token).
CREATE TABLE authorisations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  purpose VARCHAR(30) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_auth_token (token_hash),
  KEY idx_auth_exp (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Low stock and purchasing alerts shown to owner / admin / purchase team.
CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NULL,
  shop_id INT UNSIGNED NULL,
  item_id INT UNSIGNED NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'low_stock',
  level ENUM('info','warn','bad') NOT NULL DEFAULT 'warn',
  title VARCHAR(190) NOT NULL,
  detail VARCHAR(500) NULL,
  qty DECIMAL(14,3) NULL,
  status ENUM('new','read','resolved') NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif (tenant_id, status, created_at),
  KEY idx_notif_item (shop_id, item_id, type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cash-up (Z report) per register per day.
CREATE TABLE daily_closings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  shop_id INT UNSIGNED NOT NULL,
  register_id INT UNSIGNED NOT NULL,
  close_date DATE NOT NULL,
  opening_float DECIMAL(14,2) NOT NULL DEFAULT 0,
  cash_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  card_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  bank_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  other_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  returns_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  expected_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
  counted_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
  difference DECIMAL(14,2) NOT NULL DEFAULT 0,
  txn_count INT NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  closed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_close (register_id, close_date),
  KEY idx_close_shop (shop_id, close_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
