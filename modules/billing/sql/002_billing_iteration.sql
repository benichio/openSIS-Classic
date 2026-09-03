ALTER TABLE billing_contracts
  ADD COLUMN IF NOT EXISTS price_source ENUM('SERVICE','CUSTOM') NOT NULL DEFAULT 'SERVICE' AFTER price_amount,
  ADD COLUMN IF NOT EXISTS academic_syear INT NOT NULL DEFAULT 0 AFTER price_source,
  ADD COLUMN IF NOT EXISTS group_weekly_hours DECIMAL(6,2) NULL AFTER academic_syear,
  ADD COLUMN IF NOT EXISTS promotion_id INT NULL AFTER group_weekly_hours,
  ADD COLUMN IF NOT EXISTS template_id INT NULL AFTER promotion_id,
  ADD COLUMN IF NOT EXISTS o2o_first_class_date DATE NULL AFTER modality,
  ADD COLUMN IF NOT EXISTS o2o_duration_minutes INT NULL AFTER o2o_first_class_date,
  ADD COLUMN IF NOT EXISTS o2o_classes_per_week TINYINT NULL AFTER o2o_duration_minutes,
  ADD COLUMN IF NOT EXISTS o2o_weekdays VARCHAR(20) NULL AFTER o2o_classes_per_week;

ALTER TABLE billing_o2o_sessions
  ADD COLUMN IF NOT EXISTS contract_id INT NULL AFTER student_id,
  ADD COLUMN IF NOT EXISTS o2o_month_id INT NULL AFTER contract_id;


CREATE TABLE IF NOT EXISTS billing_contract_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  active CHAR(1) NOT NULL DEFAULT 'Y',
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS billing_o2o_months (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('GENERATED','LOCKED') NOT NULL DEFAULT 'GENERATED',
  generated_by INT NULL,
  generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY billing_o2o_months_contract_period (contract_id, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
