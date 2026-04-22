CREATE TABLE IF NOT EXISTS app_settings (
  `key` VARCHAR(64) PRIMARY KEY,
  `value` MEDIUMTEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cards_header (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bird_id VARCHAR(16) NOT NULL,
  card_code VARCHAR(32) NULL,
  sex VARCHAR(8) NULL,
  ring_position VARCHAR(16) NULL,
  ring_number VARCHAR(32) NULL,
  ringing_age VARCHAR(16) NULL,
  ringing_date VARCHAR(32) NULL,
  ringing_nest VARCHAR(32) NULL,
  scull_length VARCHAR(32) NULL,
  scull_repeat TEXT NULL,
  source_image_filename VARCHAR(255) NOT NULL,
  source_image_path VARCHAR(1024) NOT NULL,
  uncertainties TEXT NULL,
  raw_response_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cards_header_bird_id (bird_id)
);

CREATE TABLE IF NOT EXISTS cards_content (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  header_id BIGINT UNSIGNED NOT NULL,
  bird_id VARCHAR(16) NOT NULL,
  row_no INT UNSIGNED NOT NULL,
  ring_position VARCHAR(16) NULL,
  ring_number VARCHAR(32) NULL,
  obs_status VARCHAR(16) NULL,
  obs_year VARCHAR(8) NULL,
  obs_nest VARCHAR(32) NULL,
  obs_notes TEXT NULL,
  decoding_status VARCHAR(16) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cards_content_header FOREIGN KEY (header_id) REFERENCES cards_header(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cards_recovery (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  header_id BIGINT UNSIGNED NOT NULL,
  bird_id VARCHAR(16) NOT NULL,
  row_no INT UNSIGNED NOT NULL,
  ring_number VARCHAR(32) NULL,
  recovery_status VARCHAR(16) NULL,
  recovery_date VARCHAR(128) NULL,
  recovery_location VARCHAR(255) NULL,
  recovery_person VARCHAR(255) NULL,
  recovery_notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cards_recovery_header FOREIGN KEY (header_id) REFERENCES cards_header(id) ON DELETE CASCADE
);
