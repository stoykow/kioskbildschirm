-- MariaDB schema for departures cache

CREATE TABLE IF NOT EXISTS abfahrten_haltestellen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  externe_id VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS abfahrten_linien (
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
  CONSTRAINT fk_abfahrten_haltestellen FOREIGN KEY (haltestelle_id) REFERENCES abfahrten_haltestellen(id),
  CONSTRAINT fk_abfahrten_linien FOREIGN KEY (linie_id) REFERENCES abfahrten_linien(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS geraete (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL UNIQUE,
  token VARCHAR(128) NULL,
  erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  zuletzt_gesehen TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS geraete_daten (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  geraet_id INT NOT NULL,
  zeit TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  typ VARCHAR(64) NULL,
  payload_json LONGTEXT NOT NULL,
  INDEX idx_geraet_zeit (geraet_id, zeit),
  CONSTRAINT fk_geraete_daten_geraete FOREIGN KEY (geraet_id) REFERENCES geraete(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_config (
  id INT AUTO_INCREMENT PRIMARY KEY,
  config_key VARCHAR(64) NOT NULL UNIQUE,
  config_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS geraete_registrierungen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(64) NOT NULL UNIQUE,
  device_name VARCHAR(128) NOT NULL,
  benutzer_id INT NULL,
  erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_geraete_reg_benutzer (benutzer_id),
  CONSTRAINT fk_geraete_reg_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Abfallkalender: Benutzer, Gruppen und Termine (mit Erledigt-Markierung)

CREATE TABLE IF NOT EXISTS benutzer_gruppen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS benutzer (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  gruppen_id INT NULL,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_benutzer_gruppe FOREIGN KEY (gruppen_id) REFERENCES benutzer_gruppen(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS termine_abfall (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(128) NOT NULL UNIQUE,
  datum DATE NOT NULL,
  summary VARCHAR(255) NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  zustaendig_gruppe_id INT NULL,
  erledigt_von INT NULL,
  erledigt_am TIMESTAMP NULL,
  erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_abfall_datum (datum),
  CONSTRAINT fk_termine_abfall_benutzer FOREIGN KEY (erledigt_von) REFERENCES benutzer(id),
  CONSTRAINT fk_termine_abfall_gruppe FOREIGN KEY (zustaendig_gruppe_id) REFERENCES benutzer_gruppen(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS termine_abfall_erledigt (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  termin_id INT NOT NULL,
  benutzer_id INT NOT NULL,
  erledigt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_termin_benutzer (termin_id, benutzer_id),
  INDEX idx_termin_zeit (termin_id, erledigt_am),
  CONSTRAINT fk_termine_abfall_erledigt_termin FOREIGN KEY (termin_id) REFERENCES termine_abfall(id),
  CONSTRAINT fk_termine_abfall_erledigt_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS termine_sonstige (
  id INT AUTO_INCREMENT PRIMARY KEY,
  datum DATE NOT NULL,
  titel VARCHAR(255) NOT NULL,
  hinweis TEXT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  requires_home TINYINT(1) NOT NULL DEFAULT 0,
  quelle_typ VARCHAR(32) NULL,
  quelle_datum DATE NULL,
  erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sonst_quelle (quelle_typ, quelle_datum, titel),
  INDEX idx_sonst_datum (datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS termine_sonstige_zuhause (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  termin_id INT NOT NULL,
  benutzer_id INT NOT NULL,
  gemeldet_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sonst_termin_benutzer (termin_id, benutzer_id),
  INDEX idx_sonst_termin (termin_id),
  INDEX idx_sonst_benutzer (benutzer_id),
  CONSTRAINT fk_sonstige_zuhause_termin FOREIGN KEY (termin_id) REFERENCES termine_sonstige(id),
  CONSTRAINT fk_sonstige_zuhause_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aufgaben (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titel VARCHAR(255) NOT NULL,
  details TEXT NULL,
  faellig_am DATE NULL,
  gruppe_id INT NULL,
  quelle_typ VARCHAR(32) NULL,
  quelle_datum DATE NULL,
  erledigt_von INT NULL,
  erledigt_am TIMESTAMP NULL,
  erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_aufgaben_faellig (faellig_am),
  UNIQUE KEY uniq_aufgaben_quelle (quelle_typ, quelle_datum),
  CONSTRAINT fk_aufgaben_benutzer FOREIGN KEY (erledigt_von) REFERENCES benutzer(id),
  CONSTRAINT fk_aufgaben_gruppe FOREIGN KEY (gruppe_id) REFERENCES benutzer_gruppen(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aufgaben_erledigt (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  aufgabe_id INT NOT NULL,
  benutzer_id INT NOT NULL,
  erledigt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_aufgabe_benutzer (aufgabe_id, benutzer_id),
  INDEX idx_aufgabe_zeit (aufgabe_id, erledigt_am),
  CONSTRAINT fk_aufgaben_erledigt_aufgabe FOREIGN KEY (aufgabe_id) REFERENCES aufgaben(id),
  CONSTRAINT fk_aufgaben_erledigt_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wetter_weather (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  zeit TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  temperatur_c DECIMAL(5,2) NULL,
  gefuehlt_c DECIMAL(5,2) NULL,
  luftdruck_hpa INT NULL,
  luftfeuchte_prozent INT NULL,
  wind_ms DECIMAL(5,2) NULL,
  wind_boeen_ms DECIMAL(5,2) NULL,
  wolken_prozent INT NULL,
  regen_1h_mm DECIMAL(6,2) NULL,
  schnee_1h_mm DECIMAL(6,2) NULL,
  wetter_main VARCHAR(64) NULL,
  wetter_beschreibung VARCHAR(128) NULL,
  payload_json LONGTEXT NOT NULL,
  INDEX idx_wetter_zeit (zeit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wetter_forecast (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  forecast_time_utc DATETIME NOT NULL,
  temperatur_c DECIMAL(5,2) NULL,
  wind_ms DECIMAL(5,2) NULL,
  regen_3h_mm DECIMAL(6,2) NULL,
  schnee_3h_mm DECIMAL(6,2) NULL,
  wetter_main VARCHAR(64) NULL,
  wetter_beschreibung VARCHAR(128) NULL,
  payload_json LONGTEXT NOT NULL,
  UNIQUE KEY uniq_forecast_time (forecast_time_utc),
  INDEX idx_forecast_time (forecast_time_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Beispiel-Daten (optional)
INSERT INTO benutzer_gruppen (name) VALUES
  ('Daniel & Niko'),
  ('Nadia & Dimitar'),
  ('Elena & David')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO benutzer (name, gruppen_id)
SELECT 'Daniel', (SELECT id FROM benutzer_gruppen WHERE name = 'Daniel & Niko' LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM benutzer WHERE name = 'Daniel');

INSERT INTO benutzer (name, gruppen_id)
SELECT 'Niko', (SELECT id FROM benutzer_gruppen WHERE name = 'Daniel & Niko' LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM benutzer WHERE name = 'Niko');

INSERT INTO benutzer (name, gruppen_id)
SELECT 'Nadia', (SELECT id FROM benutzer_gruppen WHERE name = 'Nadia & Dimitar' LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM benutzer WHERE name = 'Nadia');

INSERT INTO benutzer (name, gruppen_id)
SELECT 'Dimitar', (SELECT id FROM benutzer_gruppen WHERE name = 'Nadia & Dimitar' LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM benutzer WHERE name = 'Dimitar');

INSERT INTO benutzer (name, gruppen_id)
SELECT 'Elena', (SELECT id FROM benutzer_gruppen WHERE name = 'Elena & David' LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM benutzer WHERE name = 'Elena');

INSERT INTO benutzer (name, gruppen_id)
SELECT 'David', (SELECT id FROM benutzer_gruppen WHERE name = 'Elena & David' LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM benutzer WHERE name = 'David');

INSERT INTO aufgaben (titel, details, gruppe_id)
SELECT 'Hausflur reinigen', 'Staubsaugen + wischen',
  (SELECT id FROM benutzer_gruppen WHERE name = 'Daniel & Niko' LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM aufgaben WHERE titel = 'Hausflur reinigen');

