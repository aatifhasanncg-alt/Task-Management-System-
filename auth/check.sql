-- CREATE DATABASE IF NOT EXISTS mis_ask CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE askgloba_task;

-- Core lookup tables
CREATE TABLE roles (id INT AUTO_INCREMENT PRIMARY KEY, role_name VARCHAR(50) NOT NULL UNIQUE);
CREATE TABLE branches (id INT AUTO_INCREMENT PRIMARY KEY, branch_name VARCHAR(100) NOT NULL, city VARCHAR(100), address TEXT, phone VARCHAR(60), email VARCHAR(150), is_head_office TINYINT(1) DEFAULT 0, is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE departments (id INT AUTO_INCREMENT PRIMARY KEY, dept_name VARCHAR(100) NOT NULL, dept_code VARCHAR(20) UNIQUE, color VARCHAR(20) DEFAULT '#c9a84c', icon VARCHAR(50) DEFAULT 'fa-briefcase', is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE industries (id INT AUTO_INCREMENT PRIMARY KEY, industry_name VARCHAR(100) NOT NULL UNIQUE, is_active TINYINT(1) DEFAULT 1);
CREATE TABLE company_types (id INT AUTO_INCREMENT PRIMARY KEY, type_name VARCHAR(50) NOT NULL UNIQUE);
CREATE TABLE file_types (id INT AUTO_INCREMENT PRIMARY KEY, type_name VARCHAR(50) UNIQUE);
CREATE TABLE pan_vat_types (id INT AUTO_INCREMENT PRIMARY KEY, type_name VARCHAR(10) UNIQUE);
CREATE TABLE yes_no (id INT AUTO_INCREMENT PRIMARY KEY, value ENUM('Yes','No') UNIQUE);
CREATE TABLE audit_types (id INT AUTO_INCREMENT PRIMARY KEY, type_name VARCHAR(50) UNIQUE);
CREATE TABLE tax_office_types (id INT AUTO_INCREMENT PRIMARY KEY, office_name VARCHAR(50) NOT NULL UNIQUE, address VARCHAR(150) NOT NULL);
CREATE TABLE tax_type (id INT AUTO_INCREMENT PRIMARY KEY, tax_type_name VARCHAR(50) NOT NULL UNIQUE);
CREATE TABLE bank_client_categories (id INT AUTO_INCREMENT PRIMARY KEY, category_name VARCHAR(100) NOT NULL UNIQUE);
CREATE TABLE corporate_grades (id INT AUTO_INCREMENT PRIMARY KEY, grade_name VARCHAR(5) NOT NULL UNIQUE, min_profit DECIMAL(15,2) NOT NULL, max_profit DECIMAL(15,2), description VARCHAR(255), is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);

-- Fiscal years
CREATE TABLE fiscal_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fy_code VARCHAR(10) NOT NULL,
    fy_label VARCHAR(20),
    is_active TINYINT(1) DEFAULT 1,
    is_current TINYINT(1) DEFAULT 0,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Users
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    branch_id INT,
    department_id INT,
    managed_by INT,
    phone VARCHAR(50),
    employee_id VARCHAR(50) UNIQUE,
    joining_date DATE,
    address TEXT,
    emergency_contact VARCHAR(100),
    ga_secret VARCHAR(100),
    ga_enabled TINYINT(1) DEFAULT 0,
    ga_verified_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    deactivated_at TIMESTAMP NULL,
    deactivated_by INT,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_ip VARCHAR(45),
    active_at DATETIME,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (managed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE TABLE `retired_employee_ids` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `role_id` int(11) NOT NULL,
  `retired_at` timestamp NULL DEFAULT current_timestamp(),
  `reason` varchar(100) DEFAULT 'role_change'
);
ALTER TABLE `retired_employee_ids`
  ADD CONSTRAINT `retired_employee_ids_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `retired_employee_ids_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);


CREATE TABLE `admin_department_access` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL
);

CREATE TABLE `user_role_history` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` int(11) NOT NULL,
  `old_role_id` int(11) NOT NULL,
  `new_role_id` int(11) NOT NULL,
  `old_employee_id` varchar(50) DEFAULT NULL,
  `new_employee_id` varchar(50) DEFAULT NULL,
  `old_branch_id` int(11) DEFAULT NULL,
  `new_branch_id` int(11) DEFAULT NULL,
  `changed_by` int(11) NOT NULL,
  `changed_at` timestamp NULL DEFAULT current_timestamp(),
  `reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
ALTER TABLE `user_role_history`
  ADD KEY `user_id` (`user_id`),
  ADD KEY `old_role_id` (`old_role_id`),
  ADD KEY `new_role_id` (`new_role_id`),
  ADD KEY `changed_by` (`changed_by`);
  ALTER TABLE `user_role_history`
  ADD CONSTRAINT `user_role_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `user_role_history_ibfk_2` FOREIGN KEY (`old_role_id`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `user_role_history_ibfk_3` FOREIGN KEY (`new_role_id`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `user_role_history_ibfk_4` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

CREATE TABLE `admin_branch_access` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL
);
CREATE TABLE `staff_branch_log` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `staff_id` int(11) NOT NULL,
  `old_branch_id` int(11) DEFAULT NULL,
  `new_branch_id` int(11) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
);
-- Auditors
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
    class ENUM('CA','B','C','D') NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    max_countable INT NOT NULL DEFAULT 100,
    max_uncountable INT
);

CREATE TABLE auditor_yearly_quota (
    auditor_id INT NOT NULL,
    fiscal_year_id INT NOT NULL,
    countable_count INT NOT NULL DEFAULT 0,
    uncountable_count INT NOT NULL DEFAULT 0,
    max_countable_override INT,
    PRIMARY KEY (auditor_id, fiscal_year_id),
    UNIQUE KEY unique_auditor_fy (auditor_id, fiscal_year_id),
    FOREIGN KEY (auditor_id) REFERENCES auditors(id) ON DELETE CASCADE,
    FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id) ON DELETE CASCADE
);

-- Companies
CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(200) NOT NULL,
    company_code VARCHAR(50),
    pan_number VARCHAR(9),
    reg_number VARCHAR(50),
    company_type_id INT NOT NULL,
    industry_id INT,
    return_type ENUM('D1','D2','D3','D4'),
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
    FOREIGN KEY (industry_id) REFERENCES industries(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Bank references & summary
CREATE TABLE bank_references (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_name VARCHAR(150) NOT NULL,
    address VARCHAR(150) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

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
    is_checked TINYINT(1) DEFAULT 0,
    pct_of_total_files DECIMAL(6,2) GENERATED ALWAYS AS (IF(total_files > 0, completed / total_files * 100, 0)) STORED,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bank_branch_fy (bank_reference_id, branch_id, fiscal_year),
    FOREIGN KEY (bank_reference_id) REFERENCES bank_references(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);

-- Task status
CREATE TABLE task_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    status_name VARCHAR(50) UNIQUE,
    color VARCHAR(20) DEFAULT '#9ca3af',
    bg_color VARCHAR(20) DEFAULT '#f3f4f6',
    icon VARCHAR(50) DEFAULT 'fa-circle'
);

-- Tasks (main)
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
    auditor_id INT,
    audit_nature ENUM('countable','uncountable','N/A'),
    parent_task_id INT,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (current_dept_id) REFERENCES departments(id),
    FOREIGN KEY (status_id) REFERENCES task_status(id),
    FOREIGN KEY (auditor_id) REFERENCES auditors(id),
    FOREIGN KEY (parent_task_id) REFERENCES tasks(id) ON DELETE SET NULL
);

-- Department-specific task detail tables
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
    fiscal_year_id INT,
    no_of_audit_year INT DEFAULT 1,
    pan_no VARCHAR(50),
    assigned_to INT,
    finalised_by INT,
    assigned_date DATE,
    audit_type_id INT,
    ecd DATE,
    opening_due DECIMAL(15,2) DEFAULT 0.00,
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

CREATE TABLE task_tax (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL UNIQUE,
    company_id INT NOT NULL,
    firm_name VARCHAR(200),
    assigned_office_id INT,
    assigned_office_address VARCHAR(200),
    tax_type_id INT,
    fiscal_year VARCHAR(10),
    fiscal_year_id INT,
    submission_number VARCHAR(100),
    udin_no VARCHAR(100),
    business_type VARCHAR(100),
    pan_number VARCHAR(50),
    assigned_to INT,
    file_received_by INT,
    updated_by INT,
    verify_by INT,
    tax_clearance_status_id INT,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    completed_date DATE,
    remarks TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    follow_up_date DATE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (assigned_office_id) REFERENCES tax_office_types(id),
    FOREIGN KEY (tax_type_id) REFERENCES tax_type(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (file_received_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (verify_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (tax_clearance_status_id) REFERENCES task_status(id)
);

CREATE TABLE task_banking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL UNIQUE,
    company_id INT,
    bank_reference_id INT,
    client_category_id INT,
    assigned_date DATE,
    ecd DATE,
    completion_date DATE,
    fiscal_year VARCHAR(10),
    fiscal_year_id INT,
    sales_check INT,
    audit_check INT,
    provisional_financial_statement INT,
    projected INT,
    consulting INT,
    nta INT,
    salary_certificate INT,
    ca_certification INT,
    etds INT,
    od DECIMAL(15,2) COMMENT 'Overdraft amount in lakh',
    term DECIMAL(15,2) COMMENT 'Term loan amount in lakh',
    interest_rate DECIMAL(5,2) COMMENT 'Interest rate %',
    bill_issued TINYINT(1) DEFAULT 0,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (bank_reference_id) REFERENCES bank_references(id),
    FOREIGN KEY (client_category_id) REFERENCES bank_client_categories(id)
);

CREATE TABLE task_corporate (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL UNIQUE,
    company_id INT NOT NULL,
    firm_name VARCHAR(200),
    pan_no VARCHAR(50),
    grade_id INT,
    assigned_to INT,
    finalised_by INT,
    completed_date DATE,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (grade_id) REFERENCES corporate_grades(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (finalised_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE task_finance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL UNIQUE,
    company_id INT NOT NULL,
    fiscal_year VARCHAR(10),
    total_amount DECIMAL(15,2) DEFAULT 0.00,
    paid_amount DECIMAL(15,2) DEFAULT 0.00,
    due_amount DECIMAL(15,2) GENERATED ALWAYS AS (total_amount - paid_amount) STORED,
    payment_date DATE,
    payment_method VARCHAR(50),
    tax_clearance_status_id INT,
    tax_clearance_date DATE,
    payment_status_id INT,
    is_completed TINYINT(1) DEFAULT 0,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (tax_clearance_status_id) REFERENCES task_status(id),
    FOREIGN KEY (payment_status_id) REFERENCES task_status(id)
);

-- Supporting task tables
CREATE TABLE task_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_private TINYINT(1) DEFAULT 0,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

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
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE task_followups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    followup_date DATE NOT NULL,
    notes VARCHAR(500),
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Logging / auth tables
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(200),
    module VARCHAR(100),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200),
    message TEXT,
    type ENUM('task','transfer','status','system','reminder') DEFAULT 'task',
    link VARCHAR(300),
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_read (user_id, is_read)
);

CREATE TABLE email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sent_to VARCHAR(150),
    subject VARCHAR(255),
    body TEXT,
    status ENUM('sent','failed') DEFAULT 'sent',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE otp_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_code VARCHAR(10),
    type ENUM('login','setup','reset') DEFAULT 'login',
    ip_address VARCHAR(45),
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    device_name VARCHAR(100),
    browser VARCHAR(100),
    os VARCHAR(100),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE password_change_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    changed_by INT,
    changed_for INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (changed_for) REFERENCES users(id) ON DELETE CASCADE
);
-- Auto-generate company code (CP-001, CP-002...)
DELIMITER $$
CREATE TRIGGER trg_company_code BEFORE INSERT ON companies FOR EACH ROW
BEGIN
    DECLARE nxt INT;
    SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(company_code,'-',-1) AS UNSIGNED)),0)+1
    INTO nxt FROM companies;
    SET NEW.company_code = CONCAT('CP-',LPAD(nxt,3,'0'));
END$$
DELIMITER ;

-- Auto-generate task number (ASK2026-TAX-00001, etc.)
DELIMITER $$
CREATE TRIGGER trg_task_number BEFORE INSERT ON tasks FOR EACH ROW
BEGIN
    DECLARE nxt INT;
    DECLARE deptCode VARCHAR(20);
    SELECT dept_code INTO deptCode FROM departments WHERE id = NEW.department_id;
    SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(task_number,'-',-1) AS UNSIGNED)),0)+1
    INTO nxt FROM tasks
    WHERE task_number LIKE CONCAT('ASK',YEAR(NOW()),'-',deptCode,'-%');
    SET NEW.task_number = CONCAT('ASK',YEAR(NOW()),'-',deptCode,'-',LPAD(nxt,5,'0'));
    SET NEW.current_dept_id = NEW.department_id;
END$$
DELIMITER ;

-- Auto-generate employee ID (EXE-001, ADM-001, STF-001...)
DELIMITER $$
CREATE TRIGGER trg_employee_id BEFORE INSERT ON users FOR EACH ROW
BEGIN
    DECLARE nxt INT;
    IF NEW.role_id IS NOT NULL THEN
        SET @prefix = (SELECT CASE role_name
            WHEN 'executive' THEN 'EXE'
            WHEN 'admin' THEN 'ADM'
            WHEN 'staff' THEN 'STF'
            ELSE 'EMP'
        END FROM roles WHERE id = NEW.role_id);
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(employee_id,'-',-1) AS UNSIGNED)),0)+1
        INTO nxt FROM users WHERE employee_id LIKE CONCAT(@prefix,'-%');
        SET NEW.employee_id = CONCAT(@prefix,'-',LPAD(nxt,3,'0'));
    END IF;
END$$
DELIMITER ;

-- Auto-generate fiscal year label
DELIMITER $$
CREATE TRIGGER before_fy_insert BEFORE INSERT ON fiscal_years FOR EACH ROW
BEGIN
    IF NEW.fy_code IS NOT NULL THEN
        SET NEW.fy_label = CONCAT('FY ', NEW.fy_code);
    END IF;
END$$
DELIMITER ;

CREATE TRIGGER before_fy_update BEFORE UPDATE ON fiscal_years FOR EACH ROW
BEGIN
    IF NEW.fy_code IS NOT NULL THEN
        SET NEW.fy_label = CONCAT('FY ', NEW.fy_code);
    END IF;
END$$
DELIMITER ;

-- Auditor quota enforcement
DELIMITER $$
CREATE TRIGGER trg_auditor_quota_increment AFTER INSERT ON tasks FOR EACH ROW
BEGIN
    DECLARE v_fy_id INT;
    DECLARE v_max INT;
    DECLARE v_count INT;
    IF NEW.auditor_id IS NOT NULL AND NEW.audit_nature IS NOT NULL THEN
        SELECT id INTO v_fy_id FROM fiscal_years WHERE is_current = 1 LIMIT 1;
        IF v_fy_id IS NOT NULL THEN
            INSERT INTO auditor_yearly_quota (auditor_id, fiscal_year_id, countable_count, uncountable_count)
            VALUES (
                NEW.auditor_id, v_fy_id,
                IF(NEW.audit_nature = 'countable', 1, 0),
                IF(NEW.audit_nature = 'uncountable', 1, 0)
            )
            ON DUPLICATE KEY UPDATE
                countable_count   = countable_count   + IF(NEW.audit_nature = 'countable', 1, 0),
                uncountable_count = uncountable_count + IF(NEW.audit_nature = 'uncountable', 1, 0);
            IF NEW.audit_nature = 'countable' THEN
                SELECT q.countable_count,
                       COALESCE(q.max_countable_override, a.max_countable)
                INTO v_count, v_max
                FROM auditor_yearly_quota q
                JOIN auditors a ON a.id = q.auditor_id
                WHERE q.auditor_id = NEW.auditor_id AND q.fiscal_year_id = v_fy_id;
                IF v_count > v_max THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Auditor countable task limit reached for this fiscal year';
                END IF;
            END IF;
        END IF;
    END IF;
END$$
DELIMITER ;

-- Bank summary auto-check flag
DELIMITER $$
CREATE TRIGGER trg_bank_summary_check BEFORE UPDATE ON bank_summary FOR EACH ROW
    SET NEW.is_checked = (
        NEW.total_files > 0 AND
        (NEW.completed + NEW.hbc + NEW.pending + NEW.support + NEW.cancelled) = NEW.total_files
    )$$
DELIMITER ;
-- Roles
INSERT INTO roles (id, role_name) VALUES (1,'executive'),(2,'admin'),(3,'staff');

-- Branches
INSERT INTO branches (id, branch_name, city, address, phone, email, is_head_office) VALUES
(1,'Hetauda Branch','Hetauda','Hetauda-10, Makawanpur','057-524773','hetauda@askglobal.com.np',1),
(2,'Kawasoti Branch','Kawasoti','Kawasoti-03, Nawalpur','+977-9855030793','kawasoti@askglobal.com.np',0),
(3,'Simara Branch','Simara','Jeetpursimara-02, Bara','053-520680','simara@askglobal.com.np',0);

-- Departments
INSERT INTO departments (id, dept_name, dept_code, color, icon) VALUES
(1,'Tax','TAX','#f59e0b','fa-receipt'),
(2,'Retail','RETAIL','#3b82f6','fa-store'),
(3,'Corporate','CORP','#8b5cf6','fa-building'),
(4,'Finance','FIN','#ef4444','fa-money-bill-wave'),
(5,'Banking','BANK','#10b981','fa-landmark'),
(6,'Core Admin','CORE','#ec4899','fa-shield-alt');

-- Task statuses
INSERT INTO task_status (id, status_name, color, bg_color, icon) VALUES
(1,'Not Started','#6b7280','#f3f4f6','fa-circle'),
(2,'WIP','#3b82f6','#eff6ff','fa-spinner'),
(3,'Pending','#f59e0b','#fffbeb','fa-clock'),
(4,'HBC','#ef4444','#fef2f2','fa-ban'),
(5,'Next Year','#64748b','#f8fafc','fa-calendar-xmark'),
(6,'Corporate Team','#8b5cf6','#f5f3ff','fa-building'),
(7,'NON Performance','#9ca3af','#f3f4f6','fa-ban'),
(8,'Done','#10b981','#f0fdf4','fa-check-circle'),
(9,'Cancle','#3c3d3e','#b0b2b5','fa-triangle-exclamation'),
(12,'Support','#2f562f','#bdffd6','fa-user-group');

-- Fiscal years
INSERT INTO fiscal_years (fy_code, is_active, is_current, start_date, end_date) VALUES
('2082/83',1,0,'2025-07-17','2026-07-16'),
('2081/82',1,1,'2024-07-17','2025-07-16'),
('2080/81',1,0,'2023-07-17','2024-07-16'),
('2079/80',1,0,'2022-07-17','2023-07-16');

-- Lookup data
INSERT INTO company_types (id, type_name) VALUES
(1,'Pvt Ltd'),(2,'Public Company'),(3,'partnership'),(4,'Proprietorship'),
(5,'NPO'),(6,'Cooperative'),(7,'Samuha'),(8,'JV');

INSERT INTO pan_vat_types (id, type_name) VALUES (1,'PAN'),(2,'VAT');
INSERT INTO yes_no (id, value) VALUES (1,'Yes'),(2,'No');
INSERT INTO audit_types (id, type_name) VALUES (1,'Retail'),(2,'Corporate');
INSERT INTO file_types (id, type_name) VALUES (1,'New'),(2,'Old');
INSERT INTO bank_client_categories (id, category_name) VALUES (1,'Retail'),(2,'Bank'),(3,'Internal'),(4,'Consultancy');

INSERT INTO tax_type (id, tax_type_name) VALUES
(1,'D1'),(2,'D2'),(3,'D3'),(4,'E-TDS'),(5,'VCTS'),
(6,'Personal Pan'),(7,'Registration'),(8,'Renewal '),(9,'Share Lagat'),
(10,'Closer'),(11,'Liqudation'),(12,'Oth Return Filing');

INSERT INTO tax_office_types (id, office_name, address) VALUES
(1,'IRD',''),(2,'NCG',''),(3,'OCR',''),(4,'Gharalu & Banijya',''),
(5,'Nagarpalika',''),(6,'Other',''),(7,'PPMO','');