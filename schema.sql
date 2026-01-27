-- MariaDB schema for departures cache

CREATE TABLE IF NOT EXISTS stops (
  id INT AUTO_INCREMENT PRIMARY KEY,
  external_id VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  mode VARCHAR(32) NULL,
  product VARCHAR(32) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS departures (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  stop_id INT NOT NULL,
  line_id INT NOT NULL,
  planned_when DATETIME NOT NULL,
  when_actual DATETIME NULL,
  direction VARCHAR(255) NULL,
  platform VARCHAR(32) NULL,
  delay_seconds INT NULL,
  cancelled TINYINT(1) NOT NULL DEFAULT 0,
  trip_id VARCHAR(128) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_departure (stop_id, line_id, planned_when, direction, platform),
  INDEX idx_stop_when (stop_id, planned_when),
  CONSTRAINT fk_departures_stop FOREIGN KEY (stop_id) REFERENCES stops(id),
  CONSTRAINT fk_departures_line FOREIGN KEY (line_id) REFERENCES lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
