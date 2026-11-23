-- =====================================================================
-- Unique Multi Purposes Cooperative Society Limited (UMPCSL)
-- Database schema  -  MySQL / MariaDB
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Users (authentication + role based access)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(190) NOT NULL,
    phone         VARCHAR(40)  DEFAULT NULL,
    role          ENUM('admin','staff','member') NOT NULL DEFAULT 'member',
    status        ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
    last_login_at DATETIME     DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Members (profile / KYC / bank details)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS members (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NOT NULL,
    membership_no     VARCHAR(30)  NOT NULL,
    first_name        VARCHAR(90)  NOT NULL,
    last_name         VARCHAR(90)  NOT NULL,
    other_names       VARCHAR(90)  DEFAULT NULL,
    gender            ENUM('male','female','other') DEFAULT NULL,
    date_of_birth     DATE         DEFAULT NULL,
    marital_status    ENUM('single','married','divorced','widowed') DEFAULT NULL,
    address           VARCHAR(255) DEFAULT NULL,
    occupation        VARCHAR(120) DEFAULT NULL,
    pn_number         VARCHAR(50)  DEFAULT NULL,
    npa_branch        ENUM('LPC','Tincan','Leki Port','Onne','Rivers Port','Abuja Office') DEFAULT NULL,
    department        VARCHAR(120) DEFAULT NULL,
    designation       VARCHAR(120) DEFAULT NULL,
    monthly_income    DECIMAL(15,2) DEFAULT 0.00,
    passport_photo    VARCHAR(255) DEFAULT NULL,
    bank_name         VARCHAR(120) DEFAULT NULL,
    bank_account_name VARCHAR(150) DEFAULT NULL,
    bank_account_no   VARCHAR(40)  DEFAULT NULL,
    joined_at         DATE         DEFAULT NULL,
    status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
    assigned_user_id  INT UNSIGNED DEFAULT NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_members_user (user_id),
    UNIQUE KEY uq_members_no (membership_no),
    CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_members_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Next of kin
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS next_of_kin (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id    INT UNSIGNED NOT NULL,
    name         VARCHAR(150) NOT NULL,
    relationship VARCHAR(80)  DEFAULT NULL,
    phone        VARCHAR(40)  DEFAULT NULL,
    address      VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_nok_member (member_id),
    CONSTRAINT fk_nok_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Nominees (beneficiaries)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nominees (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id    INT UNSIGNED NOT NULL,
    name         VARCHAR(150) NOT NULL,
    relationship VARCHAR(80)  DEFAULT NULL,
    phone        VARCHAR(40)  DEFAULT NULL,
    percentage   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    KEY idx_nominee_member (member_id),
    CONSTRAINT fk_nominee_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Referrals
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS referrals (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id      INT UNSIGNED NOT NULL,
    referred_name  VARCHAR(150) NOT NULL,
    referred_phone VARCHAR(40)  DEFAULT NULL,
    referred_email VARCHAR(190) DEFAULT NULL,
    status         ENUM('pending','registered','declined') NOT NULL DEFAULT 'pending',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_referral_member (member_id),
    CONSTRAINT fk_referral_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Savings accounts (one per member per product)
--   monthly       - regular monthly savings
--   share_capital - equity contribution
--   special_land  - special savings for land
--   special_housing - special savings for housing
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS savings_accounts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id     INT UNSIGNED NOT NULL,
    account_no    VARCHAR(30)  NOT NULL,
    type          ENUM('monthly','share_capital','special_land','special_housing') NOT NULL,
    target_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    balance       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status        ENUM('active','closed') NOT NULL DEFAULT 'active',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_savings_member_type (member_id, type),
    KEY idx_savings_member (member_id),
    CONSTRAINT fk_savings_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_requests (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id         INT UNSIGNED NOT NULL,
    savings_account_id INT UNSIGNED NOT NULL,
    type              ENUM('contribution','target') NOT NULL,
    amount            DECIMAL(15,2) DEFAULT NULL,
    method            VARCHAR(40) DEFAULT NULL,
    reference         VARCHAR(120) DEFAULT NULL,
    narration         VARCHAR(255) DEFAULT NULL,
    requested_target  DECIMAL(15,2) DEFAULT NULL,
    reason            VARCHAR(255) DEFAULT NULL,
    status            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_by      INT UNSIGNED NOT NULL,
    requested_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by       INT UNSIGNED DEFAULT NULL,
    reviewed_at       DATETIME DEFAULT NULL,
    review_remarks    VARCHAR(255) DEFAULT NULL,
    receipt_no        VARCHAR(60) DEFAULT NULL,
    payment_id        INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_savings_requests_member (member_id),
    KEY idx_savings_requests_status (status),
    CONSTRAINT fk_savings_requests_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
    CONSTRAINT fk_savings_requests_account FOREIGN KEY (savings_account_id) REFERENCES savings_accounts (id) ON DELETE CASCADE,
    CONSTRAINT fk_savings_requests_requested FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_savings_requests_reviewed FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_requests (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id           INT UNSIGNED NOT NULL,
    type                ENUM('loan_repayment','order','savings','other') NOT NULL,
    related_id          INT UNSIGNED DEFAULT NULL,
    amount              DECIMAL(15,2) NOT NULL,
    method              VARCHAR(40) NOT NULL DEFAULT 'cash',
    reference           VARCHAR(120) DEFAULT NULL,
    narration           VARCHAR(255) DEFAULT NULL,
    payload             TEXT,
    status              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    transaction_reference VARCHAR(120) DEFAULT NULL,
    requested_by        INT UNSIGNED NOT NULL,
    requested_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by         INT UNSIGNED DEFAULT NULL,
    reviewed_at         DATETIME DEFAULT NULL,
    review_remarks      VARCHAR(255) DEFAULT NULL,
    receipt_no          VARCHAR(60) DEFAULT NULL,
    payment_id          INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_payment_requests_member (member_id),
    KEY idx_payment_requests_status (status),
    KEY idx_payment_requests_type (type),
    UNIQUE KEY uq_payment_requests_txn (transaction_reference),
    CONSTRAINT fk_payment_requests_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_requests_requested FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_requests_reviewed FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Loans
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS loans (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_no         VARCHAR(30)  NOT NULL,
    member_id       INT UNSIGNED NOT NULL,
    amount          DECIMAL(15,2) NOT NULL,
    interest_rate   DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    term_months     INT UNSIGNED  NOT NULL DEFAULT 1,
    purpose         VARCHAR(255)  DEFAULT NULL,
    status          ENUM('pending','under_review','approved','rejected','disbursed','completed','defaulted')
                    NOT NULL DEFAULT 'pending',
    total_repayable DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    outstanding     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    applied_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by     INT UNSIGNED DEFAULT NULL,
    reviewed_at     DATETIME DEFAULT NULL,
    disbursed_at    DATETIME DEFAULT NULL,
    remarks         VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loans_no (loan_no),
    KEY idx_loans_member (member_id),
    KEY idx_loans_status (status),
    CONSTRAINT fk_loans_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
    CONSTRAINT fk_loans_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Loan guarantors
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS loan_guarantors (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_id         INT UNSIGNED NOT NULL,
    member_id       INT UNSIGNED DEFAULT NULL,
    name            VARCHAR(150) NOT NULL,
    phone           VARCHAR(40)  DEFAULT NULL,
    relationship    VARCHAR(80)  DEFAULT NULL,
    amount_guaranteed DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status          ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    verified_at     DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_guarantor_loan (loan_id),
    CONSTRAINT fk_guarantor_loan FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Loan collateral
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS loan_collateral (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_id         INT UNSIGNED NOT NULL,
    description     VARCHAR(255) NOT NULL,
    estimated_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    document_path   VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_collateral_loan (loan_id),
    CONSTRAINT fk_collateral_loan FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Loan repayment schedule
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS loan_schedule (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_id      INT UNSIGNED NOT NULL,
    installment  INT UNSIGNED NOT NULL,
    due_date     DATE NOT NULL,
    amount_due   DECIMAL(15,2) NOT NULL,
    amount_paid  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status       ENUM('pending','partial','paid','missed') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (id),
    KEY idx_schedule_loan (loan_id),
    CONSTRAINT fk_schedule_loan FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Payments (single ledger of all money received)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    receipt_no         VARCHAR(30) NOT NULL,
    member_id          INT UNSIGNED NOT NULL,
    loan_id            INT UNSIGNED DEFAULT NULL,
    savings_account_id INT UNSIGNED DEFAULT NULL,
    type               ENUM('loan_repayment','savings_contribution','share_capital','special_savings','fee','other')
                       NOT NULL DEFAULT 'other',
    amount             DECIMAL(15,2) NOT NULL,
    method             ENUM('cash','bank_transfer','paystack','remita','other') NOT NULL DEFAULT 'cash',
    reference          VARCHAR(120) DEFAULT NULL,
    status             ENUM('pending','confirmed','failed') NOT NULL DEFAULT 'confirmed',
    narration          VARCHAR(255) DEFAULT NULL,
    paid_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by        INT UNSIGNED DEFAULT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_receipt (receipt_no),
    KEY idx_payments_member (member_id),
    KEY idx_payments_loan (loan_id),
    CONSTRAINT fk_payments_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_loan FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_savings FOREIGN KEY (savings_account_id) REFERENCES savings_accounts (id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Audit log
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED DEFAULT NULL,
    action     VARCHAR(80)  NOT NULL,
    entity     VARCHAR(60)  DEFAULT NULL,
    entity_id  INT UNSIGNED DEFAULT NULL,
    details    VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(60)  DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Settings (organisation policy values)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(60)  NOT NULL,
    `value` VARCHAR(190) DEFAULT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notification_templates (
    `event_key`     VARCHAR(60)  NOT NULL,
    `email_subject` VARCHAR(190) NOT NULL DEFAULT '',
    `email_body`    TEXT         NOT NULL,
    `sms`           TEXT         NOT NULL,
    PRIMARY KEY (`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Notifications (SMS / email log and outbox)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id  INT UNSIGNED DEFAULT NULL,
    channel    ENUM('email','sms') NOT NULL,
    event      VARCHAR(60)  NOT NULL,
    recipient  VARCHAR(190) NOT NULL,
    subject    VARCHAR(190) DEFAULT NULL,
    body       TEXT         NOT NULL,
    status     ENUM('sent','logged','failed') NOT NULL DEFAULT 'logged',
    provider   VARCHAR(60)  DEFAULT NULL,
    error      VARCHAR(255) DEFAULT NULL,
    attempts   INT UNSIGNED NOT NULL DEFAULT 1,
    sent_at    DATETIME     DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_member (member_id),
    KEY idx_notif_channel (channel),
    KEY idx_notif_status (status),
    CONSTRAINT fk_notif_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Accounting: chart of accounts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS accounts (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code      VARCHAR(20)  NOT NULL,
    name      VARCHAR(120) NOT NULL,
    type      ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    normal    ENUM('debit','credit') NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_accounts_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Accounting: journal entries (header)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS journals (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_no   VARCHAR(30)  NOT NULL,
    entry_date DATE         NOT NULL,
    narration  VARCHAR(255) DEFAULT NULL,
    reference  VARCHAR(120) DEFAULT NULL,
    source     VARCHAR(40)  DEFAULT NULL,
    source_id  INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_journals_no (entry_no),
    KEY idx_journals_date (entry_date),
    KEY idx_journals_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Accounting: journal entry lines
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS journal_lines (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    journal_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    debit      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    credit     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    memo       VARCHAR(190) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_lines_journal (journal_id),
    KEY idx_lines_account (account_id),
    CONSTRAINT fk_lines_journal FOREIGN KEY (journal_id) REFERENCES journals (id) ON DELETE CASCADE,
    CONSTRAINT fk_lines_account FOREIGN KEY (account_id) REFERENCES accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Inventory: product categories
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pcat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Inventory: products
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sku           VARCHAR(40)  NOT NULL,
    name          VARCHAR(150) NOT NULL,
    category_id   INT UNSIGNED DEFAULT NULL,
    unit          VARCHAR(30)  NOT NULL DEFAULT 'unit',
    description   TEXT         DEFAULT NULL,
    cost_price    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    price         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    stock_qty     INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 0,
    image         VARCHAR(255) DEFAULT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    is_online     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_sku (sku),
    KEY idx_products_category (category_id),
    KEY idx_products_active (is_active),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES product_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Inventory: stock movements (ledger of quantity changes)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_movements (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id    INT UNSIGNED NOT NULL,
    type          ENUM('opening','purchase','sale','adjustment','return') NOT NULL,
    change_qty    INT NOT NULL,
    balance_after INT NOT NULL,
    unit_cost     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    reference     VARCHAR(60)  DEFAULT NULL,
    notes         VARCHAR(255) DEFAULT NULL,
    created_by    INT UNSIGNED DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_moves_product (product_id),
    KEY idx_moves_type (type),
    CONSTRAINT fk_moves_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_moves_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Sales / store orders
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_no           VARCHAR(30) NOT NULL,
    member_id         INT UNSIGNED DEFAULT NULL,
    customer_name     VARCHAR(150) NOT NULL,
    customer_phone    VARCHAR(40)  DEFAULT NULL,
    customer_email    VARCHAR(190) DEFAULT NULL,
    channel           ENUM('store','online') NOT NULL DEFAULT 'store',
    status            ENUM('pending','paid','fulfilled','completed','cancelled') NOT NULL DEFAULT 'completed',
    subtotal          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discount          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    cost_total        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payment_method    VARCHAR(30) DEFAULT NULL,
    payment_reference VARCHAR(120) DEFAULT NULL,
    notes             VARCHAR(255) DEFAULT NULL,
    accounts_posted   TINYINT(1) NOT NULL DEFAULT 0,
    stock_returned    TINYINT(1) NOT NULL DEFAULT 0,
    sold_by           INT UNSIGNED DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at           DATETIME DEFAULT NULL,
    fulfilled_at      DATETIME DEFAULT NULL,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_no (sale_no),
    KEY idx_sales_member (member_id),
    KEY idx_sales_status (status),
    KEY idx_sales_channel (channel),
    CONSTRAINT fk_sales_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_user FOREIGN KEY (sold_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Sales: line items
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sale_items (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_id      INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED DEFAULT NULL,
    product_name VARCHAR(150) NOT NULL,
    unit_price   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    cost_price   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    quantity     INT NOT NULL DEFAULT 1,
    line_total   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    KEY idx_sitems_sale (sale_id),
    KEY idx_sitems_product (product_id),
    CONSTRAINT fk_sitems_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE,
    CONSTRAINT fk_sitems_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Online payment transactions (gateway checkout attempts)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference          VARCHAR(60)  NOT NULL,
    provider           VARCHAR(30)  NOT NULL,
    purpose            ENUM('savings','loan_repayment','order','other') NOT NULL,
    member_id          INT UNSIGNED DEFAULT NULL,
    amount             DECIMAL(15,2) NOT NULL,
    currency           VARCHAR(10)  NOT NULL DEFAULT 'NGN',
    status             ENUM('pending','success','failed','cancelled') NOT NULL DEFAULT 'pending',
    provider_reference VARCHAR(120) DEFAULT NULL,
    authorization_url  VARCHAR(500) DEFAULT NULL,
    related_id         INT UNSIGNED DEFAULT NULL,
    payment_id         INT UNSIGNED DEFAULT NULL,
    gateway_response   TEXT         DEFAULT NULL,
    meta               TEXT         DEFAULT NULL,
    initiated_by       INT UNSIGNED DEFAULT NULL,
    paid_at            DATETIME     DEFAULT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_txn_reference (reference),
    KEY idx_txn_member (member_id),
    KEY idx_txn_status (status),
    KEY idx_txn_purpose (purpose),
    KEY idx_txn_provider (provider),
    CONSTRAINT fk_txn_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE SET NULL,
    CONSTRAINT fk_txn_user FOREIGN KEY (initiated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Legacy data migration import log
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_logs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_no    VARCHAR(30)  NOT NULL,
    import_type ENUM('members','savings','loans','repayments') NOT NULL,
    filename    VARCHAR(190) DEFAULT NULL,
    total_rows  INT UNSIGNED NOT NULL DEFAULT 0,
    imported    INT UNSIGNED NOT NULL DEFAULT 0,
    skipped     INT UNSIGNED NOT NULL DEFAULT 0,
    failed      INT UNSIGNED NOT NULL DEFAULT 0,
    status      ENUM('completed','partial','failed') NOT NULL DEFAULT 'completed',
    details     TEXT         DEFAULT NULL,
    imported_by INT UNSIGNED DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_import_batch (batch_no),
    KEY idx_import_type (import_type),
    CONSTRAINT fk_import_user FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Shared PHP session storage (SESSION_DRIVER=database on Vercel)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_sessions (
    id         VARCHAR(128) NOT NULL,
    payload    LONGTEXT     NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Staged CSV uploads for the legacy importer (stateless-safe)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_uploads (
    token      VARCHAR(32)  NOT NULL,
    filename   VARCHAR(190) DEFAULT NULL,
    content    LONGTEXT     NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;