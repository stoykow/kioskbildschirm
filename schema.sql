-- MariaDB schema for departures cache

CREATE TABLE IF NOT EXISTS haltestellen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  externe_id VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS linien (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  modus VARCHAR(32) NULL,
  produkt VARCHAR(32) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS abfahrten (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  haltestelle_id INT NOT NULL,
  linie_id INT NOT NULL,
  geplante_zeit DATETIME NOT NULL,
  tatsaechliche_zeit DATETIME NULL,
  richtung VARCHAR(255) NULL,
  gleis VARCHAR(32) NULL,
  verzoegerung_sekunden INT NULL,
  ausfall TINYINT(1) NOT NULL DEFAULT 0,
  fahrt_id VARCHAR(255) NULL,
  erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_departure (haltestelle_id, linie_id, geplante_zeit, richtung, gleis),
  INDEX idx_stop_when (haltestelle_id, geplante_zeit),
  CONSTRAINT fk_abfahrten_haltestellen FOREIGN KEY (haltestelle_id) REFERENCES haltestellen(id),
  CONSTRAINT fk_abfahrten_linien FOREIGN KEY (linie_id) REFERENCES linien(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
