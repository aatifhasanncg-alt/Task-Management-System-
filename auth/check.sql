CREATE DATABASE IF NOT EXISTS mis_ask CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mis_ask;

-- ========================
-- LOOKUP TABLES
-- ========================

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) UNIQUE NOT NULL
);

INSERT INTO roles (role_name) VALUES 
('executive'),
('admin'),
('staff');


CREATE TABLE company_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) UNIQUE NOT NULL
);

INSERT INTO company_types (type_name) VALUES
('private'),
('public'),
('partnership'),
('Proprietorship'),
('ngo'),
('Cooperative'),
('Samuha'),
('JV');


CREATE TABLE file_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) UNIQUE
);

INSERT INTO file_types (type_name) VALUES
('New'),
('Old');


CREATE TABLE pan_vat_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(10) UNIQUE
);

INSERT INTO pan_vat_types (type_name) VALUES
('PAN'),
('VAT');


CREATE TABLE yes_no (
    id INT AUTO_INCREMENT PRIMARY KEY,
    value ENUM('Yes','No') UNIQUE
);

INSERT INTO yes_no (value) VALUES
('Yes'),
('No');


CREATE TABLE task_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    status_name VARCHAR(50) UNIQUE
);

INSERT INTO task_status (status_name) VALUES
('Not Started'),
('WIP'),
('Pending'),
('HBC'),
('Next Year'),
('Corporate Team'),
('NON Performance'),
('Done');


CREATE TABLE audit_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) UNIQUE
);

INSERT INTO audit_types (type_name) VALUES
('Retail'),
('Corporate');

-- ========================
-- BRANCHES
-- ========================

CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_name VARCHAR(100) NOT NULL,
    city VARCHAR(100),
    address TEXT,
    phone VARCHAR(60),
    email VARCHAR(150),
    is_head_office TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO branches (branch_name,city,address,phone,email,is_head_office) VALUES
('Hetauda Branch','Hetauda','Hetauda-10, Makawanpur','057-524773','hetauda@askglobal.com.np',1),
('Kawasoti Branch','Kawasoti','Kawasoti-03, Nawalpur','+977-9855030793','kawasoti@askglobal.com.np',0),
('Simara Branch','Simara','Jeetpursimara-02, Bara','053-520680','simara@askglobal.com.np',0);

-- ========================
-- DEPARTMENTS
-- ========================

CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dept_name VARCHAR(100) NOT NULL,
    dept_code VARCHAR(20) UNIQUE,
    color VARCHAR(20) DEFAULT '#c9a84c',
    icon VARCHAR(50) DEFAULT 'fa-briefcase',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO departments (dept_name,dept_code,color,icon) VALUES
('Tax','TAX','#f59e0b','fa-receipt'),
('Retail','RETAIL','#3b82f6','fa-store'),
('Corporate','CORP','#8b5cf6','fa-building'),
('Finance','FIN','#ef4444','fa-money-bill-wave'),
('Banking','BANK','#10b981','fa-landmark'),
('Core Admin','CORE','#ec4899','fa-shield-alt');

-- ========================
-- USERS
-- ========================

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    branch_id INT,
    department_id INT,
    managed_by INT NULL,
    phone VARCHAR(50),
    employee_id VARCHAR(50) UNIQUE,
    joining_date DATE,
    address TEXT,
    emergency_contact VARCHAR(100),
    ga_secret VARCHAR(100),
    ga_enabled TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    deactivated_at TIMESTAMP NULL,
    deactivated_by INT NULL,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (managed_by) REFERENCES users(id)
);

-- Employee ID Trigger

DELIMITER $$

CREATE TRIGGER trg_employee_id
BEFORE INSERT ON users
FOR EACH ROW
BEGIN

    DECLARE nxt INT;

    IF NEW.role_id IS NOT NULL THEN

        SET @prefix = (SELECT CASE role_name
            WHEN 'executive' THEN 'EXE'
            WHEN 'admin' THEN 'ADM'
            WHEN 'staff' THEN 'STF'
            ELSE 'EMP'
        END
        FROM roles
        WHERE id = NEW.role_id);

        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(employee_id,'-',-1) AS UNSIGNED)),0)+1
        INTO nxt
        FROM users
        WHERE employee_id LIKE CONCAT(@prefix,'-%');

        SET NEW.employee_id = CONCAT(@prefix,'-',LPAD(nxt,3,'0'));

    END IF;

END$$

DELIMITER ;
CREATE TABLE password_change_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    changed_by INT NULL,
    changed_for INT NOT NULL,
    
    -- optional: store IP or device info
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),

    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Index for performance (IMPORTANT for your query)
    INDEX idx_changed_for (changed_for),
    INDEX idx_changed_at (changed_at),

    -- Foreign keys
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (changed_for) REFERENCES users(id) ON DELETE CASCADE
);
-- ========================
-- COMPANIES
-- ========================

CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(200) NOT NULL,
    company_code VARCHAR(50) UNIQUE,
    pan_number VARCHAR(50),
    reg_number VARCHAR(50),
    company_type_id INT NOT NULL,
    return_type ENUM('D1','D2','D3','D4'),
    industry VARCHAR(100),
    address TEXT,
    contact_person VARCHAR(150),
    contact_email VARCHAR(150),
    contact_phone VARCHAR(50),
    branch_id INT,
    added_by INT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (company_type_id) REFERENCES company_types(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (added_by) REFERENCES users(id)
);

-- Company Code Trigger

DELIMITER $$

CREATE TRIGGER trg_company_code
BEFORE INSERT ON companies
FOR EACH ROW
BEGIN

    DECLARE nxt INT;

    SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(company_code,'-',-1) AS UNSIGNED)),0)+1
    INTO nxt
    FROM companies;

    SET NEW.company_code = CONCAT('CP-',LPAD(nxt,3,'0'));

END$$

DELIMITER ;

-- ========================
-- TASKS
-- ========================

CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_number VARCHAR(25) UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    department_id INT NOT NULL,
    branch_id INT NOT NULL,
    company_id INT,
    created_by INT NOT NULL,
    assigned_to INT,
    current_dept_id INT,
    status_id INT NOT NULL DEFAULT 1,
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    due_date DATE,
    fiscal_year VARCHAR(10),
    remarks TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (current_dept_id) REFERENCES departments(id),
    FOREIGN KEY (status_id) REFERENCES task_status(id)
);

-- Task Number Trigger

DROP TRIGGER IF EXISTS trg_task_number;

DELIMITER $$

CREATE TRIGGER trg_task_number
BEFORE INSERT ON tasks
FOR EACH ROW
BEGIN

    DECLARE nxt INT;
    DECLARE deptCode VARCHAR(20);

    -- Get department code
    SELECT dept_code 
    INTO deptCode
    FROM departments
    WHERE id = NEW.department_id;

    -- Get next number for that department
    SELECT COALESCE(
        MAX(CAST(SUBSTRING_INDEX(task_number,'-',-1) AS UNSIGNED)),0
    ) + 1
    INTO nxt
    FROM tasks
    WHERE task_number LIKE CONCAT('ASK',YEAR(NOW()),'-',deptCode,'-%');

    -- Generate task number
    SET NEW.task_number = CONCAT(
        'ASK',
        YEAR(NOW()),
        '-',
        deptCode,
        '-',
        LPAD(nxt,5,'0')
    );

    -- Set current department
    SET NEW.current_dept_id = NEW.department_id;

END$$

DELIMITER ;

-- ========================
-- WORKFLOW
-- ========================

CREATE TABLE task_workflow (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    action ENUM('created','assigned','status_changed','transferred_staff','transferred_dept','completed','remarked') NOT NULL,
    from_user_id INT,
    to_user_id INT,
    from_dept_id INT,
    to_dept_id INT,
    old_status VARCHAR(30),
    new_status VARCHAR(30),
    remarks TEXT,
    time_spent_min INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_task(task_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);
CREATE TABLE task_retail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL UNIQUE,
    company_id INT NOT NULL,
    firm_name VARCHAR(200) NOT NULL,
    company_type_id INT NOT NULL,
    file_type_id INT NOT NULL,
    pan_vat_id INT NOT NULL,
    vat_client_id INT NOT NULL,
    return_type ENUM('D1','D2','D3','D4') DEFAULT 'D1',
    fiscal_year VARCHAR(10),
    no_of_audit_year INT DEFAULT 1,
    pan_no VARCHAR(50),
    assigned_to INT,
    finalised_by INT,
    assigned_date DATE,
    audit_type_id INT,
    ecd DATE,
    opening_due DECIMAL(15,2) DEFAULT 0,
    work_status_id INT,
    finalisation_status_id INT,
    completed_date DATE,
    tax_clearance_status_id INT,
    backup_status_id INT,
    follow_up_date DATE,
    notes TEXT,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (company_type_id) REFERENCES company_types(id),
    FOREIGN KEY (file_type_id) REFERENCES file_types(id),
    FOREIGN KEY (pan_vat_id) REFERENCES pan_vat_types(id),
    FOREIGN KEY (vat_client_id) REFERENCES yes_no(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (finalised_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (audit_type_id) REFERENCES audit_types(id),
    FOREIGN KEY (work_status_id) REFERENCES task_status(id),
    FOREIGN KEY (finalisation_status_id) REFERENCES task_status(id),
    FOREIGN KEY (tax_clearance_status_id) REFERENCES task_status(id),
    FOREIGN KEY (backup_status_id) REFERENCES yes_no(id)
);
-- ========================
-- NOTIFICATIONS
-- ========================
ALTER TABLE task_retail    ADD COLUMN IF NOT EXISTS fiscal_year_id INT NULL AFTER fiscal_year;
ALTER TABLE task_tax       ADD COLUMN IF NOT EXISTS fiscal_year_id INT NULL AFTER fiscal_year;
ALTER TABLE task_finance   ADD COLUMN IF NOT EXISTS fiscal_year_id INT NULL AFTER fiscal_year;
ALTER TABLE task_corporate ADD COLUMN IF NOT EXISTS fiscal_year_id INT NULL AFTER fiscal_year;
ALTER TABLE task_banking   ADD COLUMN IF NOT EXISTS fiscal_year    VARCHAR(10) NULL AFTER completion_date;
ALTER TABLE task_banking   ADD COLUMN IF NOT EXISTS fiscal_year_id INT NULL AFTER fiscal_year;
ALTER TABLE task_corporate ADD COLUMN IF NOT EXISTS fiscal_year    VARCHAR(10) NULL AFTER pan_no;
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200),
    message TEXT,
    type ENUM('task','transfer','status','system','reminder') DEFAULT 'task',
    link VARCHAR(300),
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read(user_id,is_read)
);

-- ========================
-- LOG TABLES
-- ========================

CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(200),
    module VARCHAR(100),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE otp_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_code VARCHAR(10),
    type ENUM('login','setup','reset') DEFAULT 'login',
    ip_address VARCHAR(45),
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE password_change_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    changed_by INT,
    changed_for INT,
    ip_address VARCHAR(45),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sent_to VARCHAR(150),
    subject VARCHAR(255),
    body TEXT,
    status ENUM('sent','failed') DEFAULT 'sent',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE users ADD COLUMN ga_verified_at TIMESTAMP NULL AFTER ga_enabled;
-- ========================
-- TASK TAX TABLE
-- ========================

CREATE TABLE tax_office_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_name VARCHAR(50) UNIQUE NOT NULL,
    address VARCHAR(150) NOT NULL
);

INSERT INTO tax_office_types (office_name) VALUES
('IRD'),
('NCG'),
('ASK');

CREATE TABLE tax_type (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tax_type_name VARCHAR(50) UNIQUE NOT NULL
);

INSERT INTO tax_type (tax_type_name) VALUES
('D1'),
('D2'),
('D3'),
('E-TDS'),
('VCTS'),
('Personal Pan'),
('VAT Registration');

CREATE TABLE task_tax (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL UNIQUE,
    company_id INT NOT NULL,

    -- Company Info (from companies table via FK)
    firm_name VARCHAR(200),

    -- Tax Fields
    assigned_office_id INT,             -- FK tax_office_types (IRD/NCG/ASK)
    tax_type_id INT,                    -- FK tax_types (D1/D2/D3 etc)
    fiscal_year VARCHAR(10),
    submission_number VARCHAR(100),     -- Generated from external website
    udin_no VARCHAR(100),               -- Generated from ICAI/external website

    -- Business Info
    business_type VARCHAR(100),
    pan_number VARCHAR(50),

    -- People
    assigned_to INT,                    -- FK users
    file_received_by INT,               -- FK users
    updated_by INT,                     -- FK users
    verify_by INT,                      -- FK users

    -- Status & Progress
    status_id INT,                      -- FK task_status
    tax_clearance_status_id INT,        -- FK task_status

    -- Financial
    bills_issued DECIMAL(15,2) DEFAULT 0,
    fee_received DECIMAL(15,2) DEFAULT 0,
    tds_payment DECIMAL(15,2) DEFAULT 0,

    -- Dates
    assigned_date DATE,
    completed_date DATE,
    follow_up_date DATE,

    -- Notes
    remarks TEXT,
    notes TEXT,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (task_id)               REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id)            REFERENCES companies(id),
    FOREIGN KEY (assigned_office_id)    REFERENCES tax_office_types(id),
    FOREIGN KEY (tax_type_id)    REFERENCES tax_type(id),
    FOREIGN KEY (assigned_to)           REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (file_received_by)      REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by)            REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (verify_by)             REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (status_id)             REFERENCES task_status(id),
    FOREIGN KEY (tax_clearance_status_id) REFERENCES task_status(id)
);

-- ========================
-- BANK REFERENCE TABLE
-- (Admin/Executive can insert/view)
-- ========================

CREATE TABLE bank_references (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_name VARCHAR(150) NOT NULL UNIQUE,
    address VARCHAR(150) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Pre-populate common banks
INSERT INTO bank_references (bank_name) VALUES
('Citizen International Bank'),
('EBL Bardibash'),
('Everest Bank Hetauda'),
('Everest Bank Simara'),
('Nepal Bank Limited'),
('Rastriya Banijya Bank'),
('Nabil Bank'),
('Standard Chartered Bank'),
('Nepal Investment Bank'),
('Himalayan Bank');

CREATE TABLE auditors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auditor_name VARCHAR(150) NOT NULL,
    phone VARCHAR(50),
    email VARCHAR(150),
    address TEXT,
    firm_name VARCHAR(200),
    pan_number VARCHAR(50),
    cop_no VARCHAR(20),
    f_reg VARCHAR(30),
    ICAN_mem_no VARCHAR(30),
    class  ENUM('A','B','C','D') UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO auditors 
(auditor_name, phone, email, address, firm_name)
VALUES
('Ramesh Sharma','9841234567','ramesh@audit.com','Kathmandu','RS Audit Associates');
UPDATE task_banking
SET auditor_id = 1
WHERE id = 1;
-- Bank summary tracking table (matches your screenshot)
CREATE TABLE bank_summary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_reference_id INT NOT NULL,
    branch_id INT,
    fiscal_year VARCHAR(10),
    total_files INT DEFAULT 0,
    completed INT DEFAULT 0,
    hbc INT DEFAULT 0,
    pending INT DEFAULT 0,
    support INT DEFAULT 0,
    cancelled INT DEFAULT 0,
    is_checked TINYINT(1) DEFAULT 0,    -- TRUE/FALSE check column
    pct_of_total_files DECIMAL(6,2) GENERATED ALWAYS AS (
        IF(total_files > 0, (completed / total_files) * 100, 0)
    ) STORED,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_reference_id) REFERENCES bank_references(id),
    FOREIGN KEY (branch_id)         REFERENCES branches(id),
    FOREIGN KEY (updated_by)        REFERENCES users(id)
);
CREATE TABLE auditor_yearly_quota (
    auditor_id       INT NOT NULL,
    fiscal_year_id   INT NOT NULL,
    countable_count   INT NOT NULL DEFAULT 0,
    uncountable_count INT NOT NULL DEFAULT 0,
    max_countable_override INT NULL,

    PRIMARY KEY (auditor_id, fiscal_year_id),   -- composite PK, no surrogate id needed

    FOREIGN KEY (auditor_id)     REFERENCES auditors(id) ON DELETE CASCADE,
    FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id) ON DELETE CASCADE
);
DELIMITER $$

CREATE TRIGGER trg_auditor_quota_increment
AFTER INSERT ON tasks
FOR EACH ROW
BEGIN
    DECLARE v_fy_id  INT;
    DECLARE v_max    INT;
    DECLARE v_count  INT;

    IF NEW.auditor_id IS NOT NULL AND NEW.audit_nature IS NOT NULL THEN

        SELECT id INTO v_fy_id
        FROM fiscal_years WHERE is_current = 1 LIMIT 1;

        IF v_fy_id IS NOT NULL THEN

            INSERT INTO auditor_yearly_quota
                (auditor_id, fiscal_year_id, countable_count, uncountable_count)
            VALUES (
                NEW.auditor_id,
                v_fy_id,
                IF(NEW.audit_nature = 'countable', 1, 0),
                IF(NEW.audit_nature = 'uncountable', 1, 0)
            )
            ON DUPLICATE KEY UPDATE
                countable_count   = countable_count   + IF(NEW.audit_nature = 'countable', 1, 0),
                uncountable_count = uncountable_count + IF(NEW.audit_nature = 'uncountable', 1, 0);

            -- Enforce cap on countable only
            IF NEW.audit_nature = 'countable' THEN
                SELECT
                    q.countable_count,
                    COALESCE(q.max_countable_override, a.max_countable)
                INTO v_count, v_max
                FROM auditor_yearly_quota q
                JOIN auditors a ON a.id = q.auditor_id
                WHERE q.auditor_id = NEW.auditor_id
                  AND q.fiscal_year_id = v_fy_id;

                IF v_count > v_max THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Auditor countable task limit reached for this fiscal year';
                END IF;
            END IF;

        END IF;
    END IF;
END$$

DELIMITER ;
-- Client categories for banking
CREATE TABLE bank_client_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) UNIQUE NOT NULL
);

INSERT INTO bank_client_categories (category_name) VALUES
('Retail'),
('Bank'),
('Internal'),
('Consultancy');

-- ========================
-- TASK BANKING TABLE
-- ========================

CREATE TABLE task_banking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL UNIQUE,
    company_id INT NOT NULL,              -- FK companies (main client reference)

    -- Reference
    bank_reference_id INT,               -- FK bank_references

    -- Client Info — all pulled from companies table via company_id
    -- No duplicate fields, just store overrides if needed
    client_category_id INT,              -- FK bank_client_categories

    -- Assignment
    assigned_date DATE,
    ecd DATE,
    completion_date DATE,


    -- Checklist columns (matching screenshot exactly)
    sales_check INT DEFAULT NULL,        -- numeric as per screenshot
    audit_check INT DEFAULT NULL,
    provisional_financial_statement INT DEFAULT NULL,
    projected INT DEFAULT NULL,
    consulting INT DEFAULT NULL,
    nta INT DEFAULT NULL,
    salary_certificate INT DEFAULT NULL,
    ca_certification INT DEFAULT NULL,
    etds INT DEFAULT NULL,
    bill_issued TINYINT(1) DEFAULT 0,    -- Yes/No as per screenshot

    -- Notes
    remarks TEXT,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (task_id)              REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id)           REFERENCES companies(id),
    FOREIGN KEY (bank_reference_id)    REFERENCES bank_references(id),
    FOREIGN KEY (client_category_id)   REFERENCES bank_client_categories(id)
);

-- ========================
-- TASK FINANCE TABLE
-- (Post-completion: payments, service tax, clearance)
-- ========================


CREATE TABLE task_finance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL UNIQUE,
    company_id INT NOT NULL,

    fiscal_year VARCHAR(10),

    -- Payment Details
    total_amount DECIMAL(15,2) DEFAULT 0,
    paid_amount DECIMAL(15,2) DEFAULT 0,
    due_amount DECIMAL(15,2) GENERATED ALWAYS AS (total_amount - paid_amount) STORED,
    payment_date DATE,
    payment_method VARCHAR(50),         -- Cash/Cheque/Online

    -- Tax Clearance
    tax_clearance_status_id INT,        -- FK task_status
    tax_clearance_date DATE,
    
    -- Status
    payment_status_id INT,              -- FK task_status
    is_completed TINYINT(1) DEFAULT 0,


    -- Notes
    remarks TEXT,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (task_id)              REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id)           REFERENCES companies(id),
    FOREIGN KEY (tax_clearance_status_id) REFERENCES task_status(id),
    FOREIGN KEY (payment_status_id)    REFERENCES task_status(id)
);
CREATE TABLE corporate_grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_name VARCHAR(5) UNIQUE NOT NULL,
    min_profit DECIMAL(15,2) NOT NULL,
    max_profit DECIMAL(15,2) NULL,
    description VARCHAR(255),

    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_profit_range (min_profit, max_profit)
);
CREATE TABLE task_corporate (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL UNIQUE,
    company_id INT NOT NULL,

    -- Overrides (optional fields)
    firm_name VARCHAR(200),
    pan_no VARCHAR(50),

    -- Corporate Fields
    grade_id INT,

    assigned_to INT,
    finalised_by INT,

    completed_date DATE,
    remarks TEXT,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Keys
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (grade_id) REFERENCES corporate_grades(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (finalised_by) REFERENCES users(id) ON DELETE SET NULL
);
-- ========================
-- TASK COMMENTS TABLE
-- ========================

CREATE TABLE task_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT NULL,           -- allow NULL so SET NULL works
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_private TINYINT(1) DEFAULT 0,
    INDEX idx_task(task_id),
    INDEX idx_user(user_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Create Table
CREATE TABLE fiscal_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fy_code VARCHAR(10) NOT NULL,   -- Example: 2082/83
    fy_label VARCHAR(20),           -- Auto: FY 2082/83
    is_active TINYINT(1) DEFAULT 1,
    is_current TINYINT(1) DEFAULT 0,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Trigger: BEFORE INSERT
DELIMITER $$

CREATE TRIGGER before_fy_insert
BEFORE INSERT ON fiscal_years
FOR EACH ROW
BEGIN
    IF NEW.fy_code IS NOT NULL THEN
        SET NEW.fy_label = CONCAT('FY ', NEW.fy_code);
    END IF;
END$$

DELIMITER ;

-- Trigger: BEFORE UPDATE
DELIMITER $$

CREATE TRIGGER before_fy_update
BEFORE UPDATE ON fiscal_years
FOR EACH ROW
BEGIN
    IF NEW.fy_code IS NOT NULL THEN
        SET NEW.fy_label = CONCAT('FY ', NEW.fy_code);
    END IF;
END$$

DELIMITER $$

CREATE TRIGGER set_fiscal_year_task
BEFORE INSERT ON tasks
FOR EACH ROW
BEGIN
    IF NEW.fiscal_year_id IS NULL THEN
        SET NEW.fiscal_year_id = (
            SELECT id 
            FROM fiscal_years 
            WHERE is_current = 1 
            LIMIT 1
        );
    END IF;
END$$

DELIMITER ;

ALTER TABLE tasks 
ADD COLUMN auditor_id INT NULL AFTER assigned_to;

ALTER TABLE tasks 
ADD CONSTRAINT fk_tasks_auditor 
FOREIGN KEY (auditor_id) REFERENCES auditors(id) 
ON DELETE SET NULL;

CREATE TABLE industries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    industry_name VARCHAR(100) UNIQUE NOT NULL,
    is_active TINYINT(1) DEFAULT 1
);
INSERT INTO industries (industry_name) VALUES
('Manufacturing'),
('Trading'),
('Service'),
('Banking'),
('Finance'),
('Construction'),
('IT'),
('Healthcare'),
('Education'),
('Others');

ALTER TABLE companies 
DROP COLUMN industry;

ALTER TABLE companies 
ADD COLUMN industry_id INT AFTER company_type_id;

ALTER TABLE companies 
ADD CONSTRAINT fk_company_industry 
FOREIGN KEY (industry_id) REFERENCES industries(id);

ALTER TABLE tasks 
ADD COLUMN audit_nature ENUM('countable','uncountable') NOT NULL AFTER auditor_id;
ALTER TABLE auditors 
ADD COLUMN max_limit INT DEFAULT 100,
ADD COLUMN countable_count INT DEFAULT 0,
ADD COLUMN uncountable_count INT DEFAULT 0;