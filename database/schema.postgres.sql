-- =====================================================================
-- Unique Multi Purposes Cooperative Society Limited (UMPCSL)
-- Database schema - PostgreSQL / Supabase
-- =====================================================================

CREATE TABLE IF NOT EXISTS users (
    id            SERIAL PRIMARY KEY,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(190) NOT NULL,
    phone         VARCHAR(40),
    role          VARCHAR(20) NOT NULL DEFAULT 'member' CHECK (role IN ('admin','staff','member')),
    status        VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended','pending')),
    last_login_at TIMESTAMP,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS members (
    id                SERIAL PRIMARY KEY,
    user_id           INTEGER NOT NULL UNIQUE REFERENCES users (id) ON DELETE CASCADE,
    membership_no     VARCHAR(30) NOT NULL UNIQUE,
    first_name        VARCHAR(90) NOT NULL,
    last_name         VARCHAR(90) NOT NULL,
    other_names       VARCHAR(90),
    gender            VARCHAR(20) CHECK (gender IN ('male','female','other')),
    date_of_birth     DATE,
    marital_status    VARCHAR(20) CHECK (marital_status IN ('single','married','divorced','widowed')),
    address           VARCHAR(255),
    occupation        VARCHAR(120),
    pn_number         VARCHAR(50),
    npa_branch        VARCHAR(40) CHECK (npa_branch IN ('LPC','Tincan','Leki Port','Onne','Rivers Port','Abuja Office')),
    department        VARCHAR(120),
    designation       VARCHAR(120),
    monthly_income    NUMERIC(15,2) NOT NULL DEFAULT 0,
    passport_photo    VARCHAR(255),
    bank_name         VARCHAR(120),
    bank_account_name VARCHAR(150),
    bank_account_no   VARCHAR(40),
    joined_at         DATE,
    status            VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    assigned_user_id  INTEGER REFERENCES users (id) ON DELETE SET NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS next_of_kin (
    id           SERIAL PRIMARY KEY,
    member_id    INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    name         VARCHAR(150) NOT NULL,
    relationship VARCHAR(80),
    phone        VARCHAR(40),
    address      VARCHAR(255)
);
CREATE INDEX IF NOT EXISTS idx_nok_member ON next_of_kin (member_id);

CREATE TABLE IF NOT EXISTS nominees (
    id           SERIAL PRIMARY KEY,
    member_id    INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    name         VARCHAR(150) NOT NULL,
    relationship VARCHAR(80),
    phone        VARCHAR(40),
    percentage   NUMERIC(5,2) NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_nominee_member ON nominees (member_id);

CREATE TABLE IF NOT EXISTS referrals (
    id             SERIAL PRIMARY KEY,
    member_id      INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    referred_name  VARCHAR(150) NOT NULL,
    referred_phone VARCHAR(40),
    referred_email VARCHAR(190),
    status         VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','registered','declined')),
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_referral_member ON referrals (member_id);

CREATE TABLE IF NOT EXISTS savings_accounts (
    id            SERIAL PRIMARY KEY,
    member_id     INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    account_no    VARCHAR(30) NOT NULL,
    type          VARCHAR(30) NOT NULL CHECK (type IN ('monthly','share_capital','special_land','special_housing')),
    target_amount NUMERIC(15,2) NOT NULL DEFAULT 0,
    balance       NUMERIC(15,2) NOT NULL DEFAULT 0,
    status        VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','closed')),
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (member_id, type)
);
CREATE INDEX IF NOT EXISTS idx_savings_member ON savings_accounts (member_id);

CREATE TABLE IF NOT EXISTS savings_requests (
    id                 SERIAL PRIMARY KEY,
    member_id          INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    savings_account_id INTEGER NOT NULL REFERENCES savings_accounts (id) ON DELETE CASCADE,
    type               VARCHAR(20) NOT NULL CHECK (type IN ('contribution','target')),
    amount             NUMERIC(15,2),
    method             VARCHAR(40),
    reference          VARCHAR(120),
    narration          VARCHAR(255),
    requested_target   NUMERIC(15,2),
    reason             VARCHAR(255),
    status             VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    requested_by       INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    requested_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by        INTEGER REFERENCES users (id) ON DELETE SET NULL,
    reviewed_at        TIMESTAMP,
    review_remarks     VARCHAR(255),
    receipt_no         VARCHAR(60),
    payment_id         INTEGER
);
CREATE INDEX IF NOT EXISTS idx_savings_requests_member ON savings_requests (member_id);
CREATE INDEX IF NOT EXISTS idx_savings_requests_status ON savings_requests (status);

CREATE TABLE IF NOT EXISTS payment_requests (
    id                    SERIAL PRIMARY KEY,
    member_id             INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    type                  VARCHAR(30) NOT NULL CHECK (type IN ('loan_repayment','order','savings','other')),
    related_id            INTEGER,
    amount                NUMERIC(15,2) NOT NULL,
    method                VARCHAR(40) NOT NULL DEFAULT 'cash',
    reference             VARCHAR(120),
    narration             VARCHAR(255),
    payload               TEXT,
    status                VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    transaction_reference VARCHAR(120),
    requested_by          INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    requested_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by           INTEGER REFERENCES users (id) ON DELETE SET NULL,
    reviewed_at           TIMESTAMP,
    review_remarks        VARCHAR(255),
    receipt_no            VARCHAR(60),
    payment_id            INTEGER
);
CREATE INDEX IF NOT EXISTS idx_payment_requests_member ON payment_requests (member_id);
CREATE INDEX IF NOT EXISTS idx_payment_requests_status ON payment_requests (status);
CREATE INDEX IF NOT EXISTS idx_payment_requests_type ON payment_requests (type);
CREATE UNIQUE INDEX IF NOT EXISTS uq_payment_requests_txn ON payment_requests (transaction_reference);

CREATE TABLE IF NOT EXISTS loans (
    id              SERIAL PRIMARY KEY,
    loan_no         VARCHAR(30) NOT NULL UNIQUE,
    member_id       INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    amount          NUMERIC(15,2) NOT NULL,
    interest_rate   NUMERIC(5,2) NOT NULL DEFAULT 0,
    term_months     INTEGER NOT NULL DEFAULT 1 CHECK (term_months >= 0),
    purpose         VARCHAR(255),
    status          VARCHAR(30) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','under_review','approved','rejected','disbursed','completed','defaulted')),
    total_repayable NUMERIC(15,2) NOT NULL DEFAULT 0,
    outstanding     NUMERIC(15,2) NOT NULL DEFAULT 0,
    applied_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by     INTEGER REFERENCES users (id) ON DELETE SET NULL,
    reviewed_at     TIMESTAMP,
    disbursed_at    TIMESTAMP,
    remarks         VARCHAR(255)
);
CREATE INDEX IF NOT EXISTS idx_loans_member ON loans (member_id);
CREATE INDEX IF NOT EXISTS idx_loans_status ON loans (status);

CREATE TABLE IF NOT EXISTS loan_guarantors (
    id                SERIAL PRIMARY KEY,
    loan_id           INTEGER NOT NULL REFERENCES loans (id) ON DELETE CASCADE,
    member_id         INTEGER,
    name              VARCHAR(150) NOT NULL,
    phone             VARCHAR(40),
    relationship      VARCHAR(80),
    amount_guaranteed NUMERIC(15,2) NOT NULL DEFAULT 0,
    status            VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','verified','rejected')),
    verified_at       TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_guarantor_loan ON loan_guarantors (loan_id);

CREATE TABLE IF NOT EXISTS loan_collateral (
    id              SERIAL PRIMARY KEY,
    loan_id         INTEGER NOT NULL REFERENCES loans (id) ON DELETE CASCADE,
    description     VARCHAR(255) NOT NULL,
    estimated_value NUMERIC(15,2) NOT NULL DEFAULT 0,
    document_path   VARCHAR(255)
);
CREATE INDEX IF NOT EXISTS idx_collateral_loan ON loan_collateral (loan_id);

CREATE TABLE IF NOT EXISTS loan_schedule (
    id          SERIAL PRIMARY KEY,
    loan_id     INTEGER NOT NULL REFERENCES loans (id) ON DELETE CASCADE,
    installment INTEGER NOT NULL CHECK (installment >= 0),
    due_date    DATE NOT NULL,
    amount_due  NUMERIC(15,2) NOT NULL,
    amount_paid NUMERIC(15,2) NOT NULL DEFAULT 0,
    status      VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','partial','paid','missed'))
);
CREATE INDEX IF NOT EXISTS idx_schedule_loan ON loan_schedule (loan_id);

CREATE TABLE IF NOT EXISTS payments (
    id                 SERIAL PRIMARY KEY,
    receipt_no         VARCHAR(30) NOT NULL UNIQUE,
    member_id          INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    loan_id            INTEGER REFERENCES loans (id) ON DELETE SET NULL,
    savings_account_id INTEGER REFERENCES savings_accounts (id) ON DELETE SET NULL,
    type               VARCHAR(30) NOT NULL DEFAULT 'other'
                       CHECK (type IN ('loan_repayment','savings_contribution','share_capital','special_savings','fee','other')),
    amount             NUMERIC(15,2) NOT NULL,
    method             VARCHAR(30) NOT NULL DEFAULT 'cash'
                       CHECK (method IN ('cash','bank_transfer','paystack','remita','other')),
    reference          VARCHAR(120),
    status             VARCHAR(20) NOT NULL DEFAULT 'confirmed' CHECK (status IN ('pending','confirmed','failed')),
    narration          VARCHAR(255),
    paid_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by        INTEGER REFERENCES users (id) ON DELETE SET NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_payments_member ON payments (member_id);
CREATE INDEX IF NOT EXISTS idx_payments_loan ON payments (loan_id);

CREATE TABLE IF NOT EXISTS audit_logs (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER,
    action     VARCHAR(80) NOT NULL,
    entity     VARCHAR(60),
    entity_id  INTEGER,
    details    VARCHAR(255),
    ip_address VARCHAR(60),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs (user_id);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_logs (action);

CREATE TABLE IF NOT EXISTS settings (
    "key"   VARCHAR(60) PRIMARY KEY,
    "value" VARCHAR(190)
);

CREATE TABLE IF NOT EXISTS notification_templates (
    event_key     VARCHAR(60) PRIMARY KEY,
    email_subject VARCHAR(190) NOT NULL DEFAULT '',
    email_body    TEXT NOT NULL,
    sms           TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS notifications (
    id         SERIAL PRIMARY KEY,
    member_id  INTEGER REFERENCES members (id) ON DELETE CASCADE,
    channel    VARCHAR(10) NOT NULL CHECK (channel IN ('email','sms')),
    event      VARCHAR(60) NOT NULL,
    recipient  VARCHAR(190) NOT NULL,
    subject    VARCHAR(190),
    body       TEXT NOT NULL,
    status     VARCHAR(20) NOT NULL DEFAULT 'logged' CHECK (status IN ('sent','logged','failed')),
    provider   VARCHAR(60),
    error      VARCHAR(255),
    attempts   INTEGER NOT NULL DEFAULT 1 CHECK (attempts >= 0),
    sent_at    TIMESTAMP,
    created_by INTEGER REFERENCES users (id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_notif_member ON notifications (member_id);
CREATE INDEX IF NOT EXISTS idx_notif_channel ON notifications (channel);
CREATE INDEX IF NOT EXISTS idx_notif_status ON notifications (status);

CREATE TABLE IF NOT EXISTS accounts (
    id         SERIAL PRIMARY KEY,
    code       VARCHAR(20) NOT NULL UNIQUE,
    name       VARCHAR(120) NOT NULL,
    type       VARCHAR(20) NOT NULL CHECK (type IN ('asset','liability','equity','revenue','expense')),
    normal     VARCHAR(10) NOT NULL CHECK (normal IN ('debit','credit')),
    is_system  INTEGER NOT NULL DEFAULT 0 CHECK (is_system IN (0,1)),
    is_active  INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS journals (
    id         SERIAL PRIMARY KEY,
    entry_no   VARCHAR(30) NOT NULL UNIQUE,
    entry_date DATE NOT NULL,
    narration  VARCHAR(255),
    reference  VARCHAR(120),
    source     VARCHAR(40),
    source_id  INTEGER,
    created_by INTEGER,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_journals_date ON journals (entry_date);
CREATE INDEX IF NOT EXISTS idx_journals_source ON journals (source);

CREATE TABLE IF NOT EXISTS journal_lines (
    id         SERIAL PRIMARY KEY,
    journal_id INTEGER NOT NULL REFERENCES journals (id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES accounts (id),
    debit      NUMERIC(15,2) NOT NULL DEFAULT 0,
    credit     NUMERIC(15,2) NOT NULL DEFAULT 0,
    memo       VARCHAR(190)
);
CREATE INDEX IF NOT EXISTS idx_lines_journal ON journal_lines (journal_id);
CREATE INDEX IF NOT EXISTS idx_lines_account ON journal_lines (account_id);

CREATE TABLE IF NOT EXISTS product_categories (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(120) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id            SERIAL PRIMARY KEY,
    sku           VARCHAR(40) NOT NULL UNIQUE,
    name          VARCHAR(150) NOT NULL,
    category_id   INTEGER REFERENCES product_categories (id) ON DELETE SET NULL,
    unit          VARCHAR(30) NOT NULL DEFAULT 'unit',
    description   TEXT,
    cost_price    NUMERIC(15,2) NOT NULL DEFAULT 0,
    price         NUMERIC(15,2) NOT NULL DEFAULT 0,
    stock_qty     INTEGER NOT NULL DEFAULT 0,
    reorder_level INTEGER NOT NULL DEFAULT 0,
    image         VARCHAR(255),
    is_active     INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    is_online     INTEGER NOT NULL DEFAULT 1 CHECK (is_online IN (0,1)),
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_products_category ON products (category_id);
CREATE INDEX IF NOT EXISTS idx_products_active ON products (is_active);

CREATE TABLE IF NOT EXISTS stock_movements (
    id            SERIAL PRIMARY KEY,
    product_id    INTEGER NOT NULL REFERENCES products (id) ON DELETE CASCADE,
    type          VARCHAR(20) NOT NULL CHECK (type IN ('opening','purchase','sale','adjustment','return')),
    change_qty    INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    unit_cost     NUMERIC(15,2) NOT NULL DEFAULT 0,
    reference     VARCHAR(60),
    notes         VARCHAR(255),
    created_by    INTEGER REFERENCES users (id) ON DELETE SET NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_moves_product ON stock_movements (product_id);
CREATE INDEX IF NOT EXISTS idx_moves_type ON stock_movements (type);

CREATE TABLE IF NOT EXISTS sales (
    id                SERIAL PRIMARY KEY,
    sale_no           VARCHAR(30) NOT NULL UNIQUE,
    member_id         INTEGER REFERENCES members (id) ON DELETE SET NULL,
    customer_name     VARCHAR(150) NOT NULL,
    customer_phone    VARCHAR(40),
    customer_email    VARCHAR(190),
    channel           VARCHAR(20) NOT NULL DEFAULT 'store' CHECK (channel IN ('store','online')),
    status            VARCHAR(20) NOT NULL DEFAULT 'completed'
                      CHECK (status IN ('pending','paid','fulfilled','completed','cancelled')),
    subtotal          NUMERIC(15,2) NOT NULL DEFAULT 0,
    discount          NUMERIC(15,2) NOT NULL DEFAULT 0,
    total             NUMERIC(15,2) NOT NULL DEFAULT 0,
    cost_total        NUMERIC(15,2) NOT NULL DEFAULT 0,
    payment_method    VARCHAR(30),
    payment_reference VARCHAR(120),
    notes             VARCHAR(255),
    accounts_posted   INTEGER NOT NULL DEFAULT 0 CHECK (accounts_posted IN (0,1)),
    stock_returned    INTEGER NOT NULL DEFAULT 0 CHECK (stock_returned IN (0,1)),
    sold_by           INTEGER REFERENCES users (id) ON DELETE SET NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at           TIMESTAMP,
    fulfilled_at      TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sales_member ON sales (member_id);
CREATE INDEX IF NOT EXISTS idx_sales_status ON sales (status);
CREATE INDEX IF NOT EXISTS idx_sales_channel ON sales (channel);

CREATE TABLE IF NOT EXISTS sale_items (
    id           SERIAL PRIMARY KEY,
    sale_id      INTEGER NOT NULL REFERENCES sales (id) ON DELETE CASCADE,
    product_id   INTEGER REFERENCES products (id) ON DELETE SET NULL,
    product_name VARCHAR(150) NOT NULL,
    unit_price   NUMERIC(15,2) NOT NULL DEFAULT 0,
    cost_price   NUMERIC(15,2) NOT NULL DEFAULT 0,
    quantity     INTEGER NOT NULL DEFAULT 1,
    line_total   NUMERIC(15,2) NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_sitems_sale ON sale_items (sale_id);
CREATE INDEX IF NOT EXISTS idx_sitems_product ON sale_items (product_id);

CREATE TABLE IF NOT EXISTS transactions (
    id                 SERIAL PRIMARY KEY,
    reference          VARCHAR(60) NOT NULL UNIQUE,
    provider           VARCHAR(30) NOT NULL,
    purpose            VARCHAR(30) NOT NULL CHECK (purpose IN ('savings','loan_repayment','order','other')),
    member_id          INTEGER REFERENCES members (id) ON DELETE SET NULL,
    amount             NUMERIC(15,2) NOT NULL,
    currency           VARCHAR(10) NOT NULL DEFAULT 'NGN',
    status             VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','success','failed','cancelled')),
    provider_reference VARCHAR(120),
    authorization_url  VARCHAR(500),
    related_id         INTEGER,
    payment_id         INTEGER,
    gateway_response   TEXT,
    meta               TEXT,
    initiated_by       INTEGER REFERENCES users (id) ON DELETE SET NULL,
    paid_at            TIMESTAMP,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_txn_member ON transactions (member_id);
CREATE INDEX IF NOT EXISTS idx_txn_status ON transactions (status);
CREATE INDEX IF NOT EXISTS idx_txn_purpose ON transactions (purpose);
CREATE INDEX IF NOT EXISTS idx_txn_provider ON transactions (provider);

CREATE TABLE IF NOT EXISTS import_logs (
    id          SERIAL PRIMARY KEY,
    batch_no    VARCHAR(30) NOT NULL UNIQUE,
    import_type VARCHAR(30) NOT NULL CHECK (import_type IN ('members','savings','loans','repayments')),
    filename    VARCHAR(190),
    total_rows  INTEGER NOT NULL DEFAULT 0 CHECK (total_rows >= 0),
    imported    INTEGER NOT NULL DEFAULT 0 CHECK (imported >= 0),
    skipped     INTEGER NOT NULL DEFAULT 0 CHECK (skipped >= 0),
    failed      INTEGER NOT NULL DEFAULT 0 CHECK (failed >= 0),
    status      VARCHAR(20) NOT NULL DEFAULT 'completed' CHECK (status IN ('completed','partial','failed')),
    details     TEXT,
    imported_by INTEGER REFERENCES users (id) ON DELETE SET NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_import_type ON import_logs (import_type);

CREATE TABLE IF NOT EXISTS app_sessions (
    id         VARCHAR(128) PRIMARY KEY,
    payload    TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_expiry ON app_sessions (expires_at);

CREATE TABLE IF NOT EXISTS import_uploads (
    token      VARCHAR(32) PRIMARY KEY,
    filename   VARCHAR(190),
    content    TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- MySQL's ON UPDATE CURRENT_TIMESTAMP equivalent for the tables that use it.
CREATE OR REPLACE FUNCTION umpcsl_set_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_users_updated_at ON users;
CREATE TRIGGER trg_users_updated_at BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION umpcsl_set_updated_at();

DROP TRIGGER IF EXISTS trg_members_updated_at ON members;
CREATE TRIGGER trg_members_updated_at BEFORE UPDATE ON members
    FOR EACH ROW EXECUTE FUNCTION umpcsl_set_updated_at();

DROP TRIGGER IF EXISTS trg_products_updated_at ON products;
CREATE TRIGGER trg_products_updated_at BEFORE UPDATE ON products
    FOR EACH ROW EXECUTE FUNCTION umpcsl_set_updated_at();

DROP TRIGGER IF EXISTS trg_sales_updated_at ON sales;
CREATE TRIGGER trg_sales_updated_at BEFORE UPDATE ON sales
    FOR EACH ROW EXECUTE FUNCTION umpcsl_set_updated_at();

DROP TRIGGER IF EXISTS trg_transactions_updated_at ON transactions;
CREATE TRIGGER trg_transactions_updated_at BEFORE UPDATE ON transactions
    FOR EACH ROW EXECUTE FUNCTION umpcsl_set_updated_at();
