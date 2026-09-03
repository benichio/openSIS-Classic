CREATE TABLE IF NOT EXISTS billing_configuration (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  config_key VARCHAR(120) NOT NULL,
  config_value TEXT NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY billing_configuration_school_key (school_id, config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('PERSON','COMPANY') NOT NULL DEFAULT 'PERSON',
  first_name VARCHAR(100) NULL,
  last_name VARCHAR(150) NULL,
  business_name VARCHAR(180) NULL,
  tax_id VARCHAR(32) NULL,
  address VARCHAR(255) NULL,
  postal_code VARCHAR(16) NULL,
  city VARCHAR(100) NULL,
  province VARCHAR(100) NULL,
  country VARCHAR(80) NOT NULL DEFAULT 'ES',
  email VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  invoice_preference ENUM('PER_STUDENT','FAMILY') NOT NULL DEFAULT 'PER_STUDENT',
  active CHAR(1) NOT NULL DEFAULT 'Y',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY billing_accounts_tax_id (tax_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_account_students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  student_id INT NOT NULL,
  relationship VARCHAR(60) NULL,
  is_primary CHAR(1) NOT NULL DEFAULT 'Y',
  start_date DATE NULL,
  end_date DATE NULL,
  UNIQUE KEY billing_account_students_unique (account_id, student_id),
  KEY billing_account_students_student (student_id),
  CONSTRAINT billing_account_students_account_fk FOREIGN KEY (account_id) REFERENCES billing_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  default_price DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  unit ENUM('MONTH','HOUR','UNIT') NOT NULL DEFAULT 'UNIT',
  active CHAR(1) NOT NULL DEFAULT 'Y',
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY billing_services_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_tax_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  description VARCHAR(255) NOT NULL,
  rate DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  exempt CHAR(1) NOT NULL DEFAULT 'N',
  exemption_reason VARCHAR(255) NULL,
  legal_text TEXT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  active CHAR(1) NOT NULL DEFAULT 'Y',
  UNIQUE KEY billing_tax_rules_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  service_id INT NOT NULL,
  course_period_id INT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  price_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  price_source ENUM('SERVICE','CUSTOM') NOT NULL DEFAULT 'SERVICE',
  academic_syear INT NOT NULL DEFAULT 0,
  group_weekly_hours DECIMAL(6,2) NULL,
  promotion_id INT NULL,
  template_id INT NULL,
  modality ENUM('GROUP_MONTHLY','O2O_HOURLY','ONE_TIME','ANNUAL','OTHER') NOT NULL DEFAULT 'OTHER',
  o2o_first_class_date DATE NULL,
  o2o_duration_minutes INT NULL,
  o2o_classes_per_week TINYINT NULL,
  o2o_weekdays VARCHAR(20) NULL,
  tax_rule_id INT NULL,
  status ENUM('ACTIVE','SUSPENDED','ENDED') NOT NULL DEFAULT 'ACTIVE',
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY billing_contracts_student (student_id),
  KEY billing_contracts_service (service_id),
  CONSTRAINT billing_contracts_service_fk FOREIGN KEY (service_id) REFERENCES billing_services(id),
  CONSTRAINT billing_contracts_tax_fk FOREIGN KEY (tax_rule_id) REFERENCES billing_tax_rules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_contract_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  active CHAR(1) NOT NULL DEFAULT 'Y',
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_contract_prices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  valid_from DATE NOT NULL,
  old_price DECIMAL(13,2) NULL,
  new_price DECIMAL(13,2) NOT NULL,
  changed_by INT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT billing_contract_prices_contract_fk FOREIGN KEY (contract_id) REFERENCES billing_contracts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_promotions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(160) NOT NULL,
  type ENUM('PERCENT','FIXED','FREE_MONTHS','TEMPORARY','PERMANENT','SIBLING','CUSTOM') NOT NULL,
  value DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  start_date DATE NULL,
  end_date DATE NULL,
  active CHAR(1) NOT NULL DEFAULT 'Y',
  UNIQUE KEY billing_promotions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_promotion_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  promotion_id INT NOT NULL,
  target_type ENUM('STUDENT','ACCOUNT','SERVICE','CONTRACT') NOT NULL,
  target_id INT NOT NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  CONSTRAINT billing_promotion_assignments_promotion_fk FOREIGN KEY (promotion_id) REFERENCES billing_promotions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_o2o_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  contract_id INT NULL,
  o2o_month_id INT NULL,
  teacher_id INT NULL,
  session_date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  scheduled_minutes INT NOT NULL DEFAULT 0,
  billable_minutes INT NOT NULL DEFAULT 0,
  status ENUM('PLANNED','COMPLETED','STUDENT_ABSENCE_BILLABLE','STUDENT_ABSENCE_NOT_BILLABLE','TEACHER_CANCELLED','CENTER_CANCELLED','RESCHEDULED') NOT NULL DEFAULT 'PLANNED',
  source_type VARCHAR(60) NULL,
  source_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY billing_o2o_sessions_student_date (student_id, session_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  syear INT NOT NULL,
  run_code VARCHAR(30) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('DRAFT','CALCULATED','REVIEWED','INVOICED','CLOSED') NOT NULL DEFAULT 'DRAFT',
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY billing_runs_period (school_id, period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_run_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  student_id INT NOT NULL,
  account_id INT NULL,
  invoice_id INT NULL,
  status ENUM('BILLABLE','EMPTY','ERROR','INVOICED') NOT NULL,
  message VARCHAR(255) NULL,
  group_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  o2o_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  other_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  calculation_payload MEDIUMTEXT NULL,
  UNIQUE KEY billing_run_items_student (run_id, student_id),
  CONSTRAINT billing_run_items_run_fk FOREIGN KEY (run_id) REFERENCES billing_runs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NULL,
  student_id INT NOT NULL,
  account_id INT NOT NULL,
  series VARCHAR(10) NOT NULL DEFAULT 'F',
  invoice_number VARCHAR(40) NULL,
  issue_date DATE NULL,
  operation_date DATE NOT NULL,
  school_year INT NOT NULL,
  student_snapshot TEXT NOT NULL,
  account_snapshot TEXT NOT NULL,
  issuer_snapshot TEXT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  taxable_base DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  discount_total DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  tax_total DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  status ENUM('DRAFT','READY','ISSUED','RECTIFIED','CANCELLED_BY_RECTIFICATION') NOT NULL DEFAULT 'DRAFT',
  payment_status ENUM('UNPAID','PARTIALLY_PAID','PAID','OVERPAID','REFUNDED') NOT NULL DEFAULT 'UNPAID',
  original_invoice_id INT NULL,
  created_by INT NULL,
  issued_by INT NULL,
  fiscal_hash VARCHAR(128) NULL,
  pdf_path VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_at TIMESTAMP NULL,
  UNIQUE KEY billing_invoices_number (series, invoice_number),
  KEY billing_invoices_student (student_id),
  KEY billing_invoices_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_invoice_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  line_type VARCHAR(40) NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(13,2) NOT NULL DEFAULT 1.00,
  unit VARCHAR(20) NOT NULL DEFAULT 'ud',
  unit_price DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  base_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  tax_rule_id INT NULL,
  tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  tax_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
  origin_type VARCHAR(60) NULL,
  origin_id INT NULL,
  price_rule VARCHAR(80) NULL,
  CONSTRAINT billing_invoice_lines_invoice_fk FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_invoice_sequences (
  id INT AUTO_INCREMENT PRIMARY KEY,
  series VARCHAR(10) NOT NULL,
  fiscal_year INT NOT NULL,
  next_number INT NOT NULL DEFAULT 1,
  UNIQUE KEY billing_invoice_sequences_unique (series, fiscal_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_invoice_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  record_type ENUM('INVOICE_ISSUED','INVOICE_CANCELLED','RECTIFICATION') NOT NULL,
  recorded_at DATETIME NOT NULL,
  hash VARCHAR(128) NOT NULL,
  previous_hash VARCHAR(128) NULL,
  version VARCHAR(30) NOT NULL,
  fiscal_payload MEDIUMTEXT NULL,
  aeat_status VARCHAR(30) NOT NULL DEFAULT 'NOT_SENT',
  aeat_response_code VARCHAR(60) NULL,
  sent_at DATETIME NULL,
  CONSTRAINT billing_invoice_records_invoice_fk FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  student_id INT NULL,
  amount DECIMAL(13,2) NOT NULL,
  payment_date DATE NOT NULL,
  method ENUM('CASH','TRANSFER','CARD','DIRECT_DEBIT','OTHER') NOT NULL,
  reference VARCHAR(120) NULL,
  status ENUM('PENDING','CONFIRMED','CANCELLED','RETURNED') NOT NULL DEFAULT 'CONFIRMED',
  origin VARCHAR(60) NOT NULL DEFAULT 'MANUAL',
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_payment_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT NOT NULL,
  invoice_id INT NOT NULL,
  amount DECIMAL(13,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT billing_payment_allocations_payment_fk FOREIGN KEY (payment_id) REFERENCES billing_payments(id),
  CONSTRAINT billing_payment_allocations_invoice_fk FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_payment_instructions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  method ENUM('DIRECT_DEBIT') NOT NULL DEFAULT 'DIRECT_DEBIT',
  status ENUM('PENDING','ACTIVE','CANCELLED','EXPIRED') NOT NULL DEFAULT 'PENDING',
  mandate_reference VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  event_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(80) NOT NULL,
  entity_id INT NULL,
  old_values MEDIUMTEXT NULL,
  new_values MEDIUMTEXT NULL,
  ip_address VARCHAR(45) NULL,
  origin VARCHAR(60) NOT NULL DEFAULT 'openSIS',
  KEY billing_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT IGNORE INTO billing_services (code, name, default_price, unit, sort_order) VALUES
('GROUP_MONTHLY', 'Mensualidad de grupo', 0.00, 'MONTH', 10),
('O2O_HOURLY', 'Clases individuales O2O', 0.00, 'HOUR', 20),
('ENROLLMENT_FEE', 'Matricula', 0.00, 'UNIT', 30),
('RENEWAL_FEE', 'Renovacion', 0.00, 'UNIT', 40),
('ANNUAL_FEE', 'Cuota anual', 0.00, 'UNIT', 50),
('MATERIAL', 'Material', 0.00, 'UNIT', 60),
('OTHER', 'Otros conceptos', 0.00, 'UNIT', 70);

INSERT IGNORE INTO billing_tax_rules (code, description, rate, exempt, exemption_reason, legal_text, start_date) VALUES
('EDU_EXEMPT', 'Servicios educativos exentos', 0.000, 'Y', 'Educacion reglada/no reglada segun confirmacion fiscal', 'Operacion exenta de IVA conforme al articulo 20.Uno.9 de la Ley 37/1992. Confirmar texto con gestoria.', '2026-01-01'),
('GENERAL_21', 'IVA general', 21.000, 'N', NULL, NULL, '2026-01-01');

INSERT IGNORE INTO billing_invoice_sequences (series, fiscal_year, next_number) VALUES
('F', 2026, 1),
('R', 2026, 1);

INSERT IGNORE INTO profile_exceptions (PROFILE_ID, MODNAME, CAN_USE, CAN_EDIT) VALUES
(0, 'billing/Dashboard.php', 'Y', 'Y'),
(0, 'billing/Configuration.php', 'Y', 'Y'),
(0, 'billing/Accounts.php', 'Y', 'Y'),
(0, 'billing/Services.php', 'Y', 'Y'),
(0, 'billing/Contracts.php', 'Y', 'Y'),
(0, 'billing/Promotions.php', 'Y', 'Y'),
(0, 'billing/O2OSessions.php', 'Y', 'Y'),
(0, 'billing/BillingRun.php', 'Y', 'Y'),
(0, 'billing/DraftInvoices.php', 'Y', 'Y'),
(0, 'billing/Invoices.php', 'Y', 'Y'),
(0, 'billing/InvoiceView.php', 'Y', 'Y'),
(0, 'billing/Rectifications.php', 'Y', 'Y'),
(0, 'billing/Payments.php', 'Y', 'Y'),
(0, 'billing/Accountant.php', 'Y', 'Y'),
(0, 'billing/Reports.php', 'Y', 'Y'),
(1, 'billing/Dashboard.php', 'Y', 'Y'),
(1, 'billing/Configuration.php', 'Y', 'Y'),
(1, 'billing/Accounts.php', 'Y', 'Y'),
(1, 'billing/Services.php', 'Y', 'Y'),
(1, 'billing/Contracts.php', 'Y', 'Y'),
(1, 'billing/Promotions.php', 'Y', 'Y'),
(1, 'billing/O2OSessions.php', 'Y', 'Y'),
(1, 'billing/BillingRun.php', 'Y', 'Y'),
(1, 'billing/DraftInvoices.php', 'Y', 'Y'),
(1, 'billing/Invoices.php', 'Y', 'Y'),
(1, 'billing/InvoiceView.php', 'Y', 'Y'),
(1, 'billing/Rectifications.php', 'Y', 'Y'),
(1, 'billing/Payments.php', 'Y', 'Y'),
(1, 'billing/Accountant.php', 'Y', 'Y'),
(1, 'billing/Reports.php', 'Y', 'Y'),
(4, 'billing/Invoices.php', 'Y', 'N'),
(4, 'billing/InvoiceView.php', 'Y', 'N');
