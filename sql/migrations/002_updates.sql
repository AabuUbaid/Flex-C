-- History of versions uploaded through the Console.
CREATE TABLE IF NOT EXISTS app_updates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_version VARCHAR(20) NOT NULL,
  to_version VARCHAR(20) NOT NULL,
  status ENUM('applied','failed','rolled_back') NOT NULL,
  staff_id INT UNSIGNED NULL,
  files_changed INT NOT NULL DEFAULT 0,
  backup_file VARCHAR(190) NULL,
  migrations VARCHAR(1000) NULL,
  message VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_updates_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
