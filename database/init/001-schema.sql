-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: db
-- Erstellungszeit: 22. Jun 2026 um 10:06
-- Server-Version: 10.11.16-MariaDB-ubu2204
-- PHP-Version: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Datenbank: `hausordnung`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `abfahrten`
--

CREATE TABLE `abfahrten` (
  `id` bigint(20) NOT NULL,
  `haltestelle_id` int(11) NOT NULL,
  `linie_id` int(11) NOT NULL,
  `geplante_zeit` datetime NOT NULL,
  `tatsaechliche_zeit` datetime DEFAULT NULL,
  `richtung` varchar(255) DEFAULT NULL,
  `gleis` varchar(32) DEFAULT NULL,
  `verzoegerung_sekunden` int(11) DEFAULT NULL,
  `ausfall` tinyint(1) NOT NULL DEFAULT 0,
  `fahrt_id` varchar(512) DEFAULT NULL,
  `erstellt_am` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `abfahrten_haltestellen`
--

CREATE TABLE `abfahrten_haltestellen` (
  `id` int(11) NOT NULL,
  `externe_id` varchar(32) NOT NULL,
  `name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `abfahrten_linien`
--

CREATE TABLE `abfahrten_linien` (
  `id` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `modus` varchar(32) DEFAULT NULL,
  `produkt` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `app_config`
--

CREATE TABLE `app_config` (
  `id` int(11) NOT NULL,
  `config_key` varchar(64) NOT NULL,
  `config_value` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `aufgaben`
--

CREATE TABLE `aufgaben` (
  `id` int(11) NOT NULL,
  `titel` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `faellig_am` date DEFAULT NULL,
  `gruppe_id` int(11) DEFAULT NULL,
  `quelle_typ` varchar(32) DEFAULT NULL,
  `quelle_datum` date DEFAULT NULL,
  `erledigt_von` int(11) DEFAULT NULL,
  `erledigt_am` timestamp NULL DEFAULT NULL,
  `erstellt_am` timestamp NOT NULL DEFAULT current_timestamp(),
  `aktualisiert_am` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `aufgaben_erledigt`
--

CREATE TABLE `aufgaben_erledigt` (
  `id` bigint(20) NOT NULL,
  `aufgabe_id` int(11) NOT NULL,
  `benutzer_id` int(11) NOT NULL,
  `erledigt_am` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `benutzer`
--

CREATE TABLE `benutzer` (
  `id` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `gruppen_id` int(11) DEFAULT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `erstellt_am` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `benutzer_gruppen`
--

CREATE TABLE `benutzer_gruppen` (
  `id` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `erstellt_am` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `geraete`
--

CREATE TABLE `geraete` (
  `id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `token` varchar(128) DEFAULT NULL,
  `erstellt_am` timestamp NOT NULL DEFAULT current_timestamp(),
  `zuletzt_gesehen` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `geraete_daten`
--

CREATE TABLE `geraete_daten` (
  `id` bigint(20) NOT NULL,
  `geraet_id` int(11) NOT NULL,
  `zeit` timestamp NOT NULL DEFAULT current_timestamp(),
  `typ` varchar(64) DEFAULT NULL,
  `payload_json` longtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `geraete_registrierungen`
--

CREATE TABLE `geraete_registrierungen` (
  `id` int(11) NOT NULL,
  `device_id` varchar(64) NOT NULL,
  `device_name` varchar(128) NOT NULL,
  `benutzer_id` int(11) DEFAULT NULL,
  `erstellt_am` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `termine_abfall`
--

CREATE TABLE `termine_abfall` (
  `id` int(11) NOT NULL,
  `uid` varchar(128) NOT NULL,
  `datum` date NOT NULL,
  `summary` varchar(255) NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `erledigt_von` int(11) DEFAULT NULL,
  `erledigt_am` timestamp NULL DEFAULT NULL,
  `erstellt_am` timestamp NOT NULL DEFAULT current_timestamp(),
  `aktualisiert_am` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `zustaendig_gruppe_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `termine_abfall_erledigt`
--

CREATE TABLE `termine_abfall_erledigt` (
  `id` bigint(20) NOT NULL,
  `termin_id` int(11) NOT NULL,
  `benutzer_id` int(11) NOT NULL,
  `erledigt_am` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `termine_abfall_reingestellt`
--

CREATE TABLE `termine_abfall_reingestellt` (
  `id` bigint(20) NOT NULL,
  `termin_id` int(11) NOT NULL,
  `benutzer_id` int(11) NOT NULL,
  `reingestellt_am` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `termine_sonstige`
--

CREATE TABLE `termine_sonstige` (
  `id` int(11) NOT NULL,
  `datum` date NOT NULL,
  `titel` varchar(255) NOT NULL,
  `hinweis` text DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `requires_home` tinyint(1) NOT NULL DEFAULT 0,
  `quelle_typ` varchar(32) DEFAULT NULL,
  `quelle_datum` date DEFAULT NULL,
  `erstellt_am` timestamp NOT NULL DEFAULT current_timestamp(),
  `aktualisiert_am` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `termine_sonstige_zuhause`
--

CREATE TABLE `termine_sonstige_zuhause` (
  `id` bigint(20) NOT NULL,
  `termin_id` int(11) NOT NULL,
  `benutzer_id` int(11) NOT NULL,
  `gemeldet_am` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `wetter_forecast`
--

CREATE TABLE `wetter_forecast` (
  `id` bigint(20) NOT NULL,
  `forecast_time_utc` datetime NOT NULL,
  `temperatur_c` decimal(5,2) DEFAULT NULL,
  `wind_ms` decimal(5,2) DEFAULT NULL,
  `regen_3h_mm` decimal(6,2) DEFAULT NULL,
  `schnee_3h_mm` decimal(6,2) DEFAULT NULL,
  `wetter_main` varchar(64) DEFAULT NULL,
  `wetter_beschreibung` varchar(128) DEFAULT NULL,
  `payload_json` longtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `wetter_weather`
--

CREATE TABLE `wetter_weather` (
  `id` bigint(20) NOT NULL,
  `zeit` timestamp NOT NULL DEFAULT current_timestamp(),
  `temperatur_c` decimal(5,2) DEFAULT NULL,
  `gefuehlt_c` decimal(5,2) DEFAULT NULL,
  `luftdruck_hpa` int(11) DEFAULT NULL,
  `luftfeuchte_prozent` int(11) DEFAULT NULL,
  `wind_ms` decimal(5,2) DEFAULT NULL,
  `wind_boeen_ms` decimal(5,2) DEFAULT NULL,
  `wolken_prozent` int(11) DEFAULT NULL,
  `regen_1h_mm` decimal(6,2) DEFAULT NULL,
  `schnee_1h_mm` decimal(6,2) DEFAULT NULL,
  `wetter_main` varchar(64) DEFAULT NULL,
  `wetter_beschreibung` varchar(128) DEFAULT NULL,
  `payload_json` longtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `abfahrten`
--
ALTER TABLE `abfahrten`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_departure` (`haltestelle_id`,`linie_id`,`geplante_zeit`,`richtung`,`gleis`),
  ADD KEY `idx_stop_when` (`haltestelle_id`,`geplante_zeit`),
  ADD KEY `fk_abfahrten_linien` (`linie_id`);

--
-- Indizes für die Tabelle `abfahrten_haltestellen`
--
ALTER TABLE `abfahrten_haltestellen`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `externe_id` (`externe_id`);

--
-- Indizes für die Tabelle `abfahrten_linien`
--
ALTER TABLE `abfahrten_linien`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indizes für die Tabelle `app_config`
--
ALTER TABLE `app_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `config_key` (`config_key`);

--
-- Indizes für die Tabelle `aufgaben`
--
ALTER TABLE `aufgaben`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_aufgaben_quelle` (`quelle_typ`,`quelle_datum`),
  ADD KEY `idx_aufgaben_faellig` (`faellig_am`),
  ADD KEY `fk_aufgaben_benutzer` (`erledigt_von`),
  ADD KEY `fk_aufgaben_gruppe` (`gruppe_id`);

--
-- Indizes für die Tabelle `aufgaben_erledigt`
--
ALTER TABLE `aufgaben_erledigt`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_aufgabe_benutzer` (`aufgabe_id`,`benutzer_id`),
  ADD KEY `idx_aufgabe_zeit` (`aufgabe_id`,`erledigt_am`),
  ADD KEY `fk_aufgaben_erledigt_benutzer` (`benutzer_id`);

--
-- Indizes für die Tabelle `benutzer`
--
ALTER TABLE `benutzer`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `fk_benutzer_gruppe` (`gruppen_id`);

--
-- Indizes für die Tabelle `benutzer_gruppen`
--
ALTER TABLE `benutzer_gruppen`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indizes für die Tabelle `geraete`
--
ALTER TABLE `geraete`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indizes für die Tabelle `geraete_daten`
--
ALTER TABLE `geraete_daten`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_geraet_zeit` (`geraet_id`,`zeit`);

--
-- Indizes für die Tabelle `geraete_registrierungen`
--
ALTER TABLE `geraete_registrierungen`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `device_id` (`device_id`),
  ADD KEY `idx_geraete_reg_benutzer` (`benutzer_id`);

--
-- Indizes für die Tabelle `termine_abfall`
--
ALTER TABLE `termine_abfall`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uid` (`uid`),
  ADD KEY `idx_abfall_datum` (`datum`),
  ADD KEY `fk_abfall_termine_benutzer` (`erledigt_von`),
  ADD KEY `fk_abfall_termine_gruppe` (`zustaendig_gruppe_id`);

--
-- Indizes für die Tabelle `termine_abfall_erledigt`
--
ALTER TABLE `termine_abfall_erledigt`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_termin_benutzer` (`termin_id`,`benutzer_id`),
  ADD KEY `idx_termin_zeit` (`termin_id`,`erledigt_am`),
  ADD KEY `fk_abfall_erledigt_benutzer` (`benutzer_id`);

--
-- Indizes für die Tabelle `termine_abfall_reingestellt`
--
ALTER TABLE `termine_abfall_reingestellt`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_termin_benutzer_rein` (`termin_id`,`benutzer_id`),
  ADD KEY `idx_termin_zeit_rein` (`termin_id`,`reingestellt_am`),
  ADD KEY `fk_termine_abfall_rein_benutzer` (`benutzer_id`);

--
-- Indizes für die Tabelle `termine_sonstige`
--
ALTER TABLE `termine_sonstige`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_sonst_quelle` (`quelle_typ`,`quelle_datum`,`titel`),
  ADD KEY `idx_sonst_datum` (`datum`);

--
-- Indizes für die Tabelle `termine_sonstige_zuhause`
--
ALTER TABLE `termine_sonstige_zuhause`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_sonst_termin_benutzer` (`termin_id`,`benutzer_id`),
  ADD KEY `idx_sonst_termin` (`termin_id`),
  ADD KEY `idx_sonst_benutzer` (`benutzer_id`);

--
-- Indizes für die Tabelle `wetter_forecast`
--
ALTER TABLE `wetter_forecast`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_forecast_time` (`forecast_time_utc`),
  ADD KEY `idx_forecast_time` (`forecast_time_utc`);

--
-- Indizes für die Tabelle `wetter_weather`
--
ALTER TABLE `wetter_weather`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wetter_zeit` (`zeit`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `abfahrten`
--
ALTER TABLE `abfahrten`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `abfahrten_haltestellen`
--
ALTER TABLE `abfahrten_haltestellen`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `abfahrten_linien`
--
ALTER TABLE `abfahrten_linien`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `app_config`
--
ALTER TABLE `app_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `aufgaben`
--
ALTER TABLE `aufgaben`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `aufgaben_erledigt`
--
ALTER TABLE `aufgaben_erledigt`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `benutzer`
--
ALTER TABLE `benutzer`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `benutzer_gruppen`
--
ALTER TABLE `benutzer_gruppen`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `geraete`
--
ALTER TABLE `geraete`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `geraete_daten`
--
ALTER TABLE `geraete_daten`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `geraete_registrierungen`
--
ALTER TABLE `geraete_registrierungen`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `termine_abfall`
--
ALTER TABLE `termine_abfall`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `termine_abfall_erledigt`
--
ALTER TABLE `termine_abfall_erledigt`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `termine_abfall_reingestellt`
--
ALTER TABLE `termine_abfall_reingestellt`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `termine_sonstige`
--
ALTER TABLE `termine_sonstige`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `termine_sonstige_zuhause`
--
ALTER TABLE `termine_sonstige_zuhause`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `wetter_forecast`
--
ALTER TABLE `wetter_forecast`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `wetter_weather`
--
ALTER TABLE `wetter_weather`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `abfahrten`
--
ALTER TABLE `abfahrten`
  ADD CONSTRAINT `fk_abfahrten_haltestellen` FOREIGN KEY (`haltestelle_id`) REFERENCES `abfahrten_haltestellen` (`id`),
  ADD CONSTRAINT `fk_abfahrten_linien` FOREIGN KEY (`linie_id`) REFERENCES `abfahrten_linien` (`id`);

--
-- Constraints der Tabelle `aufgaben`
--
ALTER TABLE `aufgaben`
  ADD CONSTRAINT `fk_aufgaben_benutzer` FOREIGN KEY (`erledigt_von`) REFERENCES `benutzer` (`id`),
  ADD CONSTRAINT `fk_aufgaben_gruppe` FOREIGN KEY (`gruppe_id`) REFERENCES `benutzer_gruppen` (`id`);

--
-- Constraints der Tabelle `aufgaben_erledigt`
--
ALTER TABLE `aufgaben_erledigt`
  ADD CONSTRAINT `fk_aufgaben_erledigt_aufgabe` FOREIGN KEY (`aufgabe_id`) REFERENCES `aufgaben` (`id`),
  ADD CONSTRAINT `fk_aufgaben_erledigt_benutzer` FOREIGN KEY (`benutzer_id`) REFERENCES `benutzer` (`id`);

--
-- Constraints der Tabelle `benutzer`
--
ALTER TABLE `benutzer`
  ADD CONSTRAINT `fk_benutzer_gruppe` FOREIGN KEY (`gruppen_id`) REFERENCES `benutzer_gruppen` (`id`);

--
-- Constraints der Tabelle `geraete_daten`
--
ALTER TABLE `geraete_daten`
  ADD CONSTRAINT `fk_geraete_daten_geraete` FOREIGN KEY (`geraet_id`) REFERENCES `geraete` (`id`);

--
-- Constraints der Tabelle `geraete_registrierungen`
--
ALTER TABLE `geraete_registrierungen`
  ADD CONSTRAINT `fk_geraete_reg_benutzer` FOREIGN KEY (`benutzer_id`) REFERENCES `benutzer` (`id`);

--
-- Constraints der Tabelle `termine_abfall`
--
ALTER TABLE `termine_abfall`
  ADD CONSTRAINT `fk_abfall_termine_benutzer` FOREIGN KEY (`erledigt_von`) REFERENCES `benutzer` (`id`),
  ADD CONSTRAINT `fk_abfall_termine_gruppe` FOREIGN KEY (`zustaendig_gruppe_id`) REFERENCES `benutzer_gruppen` (`id`);

--
-- Constraints der Tabelle `termine_abfall_erledigt`
--
ALTER TABLE `termine_abfall_erledigt`
  ADD CONSTRAINT `fk_abfall_erledigt_benutzer` FOREIGN KEY (`benutzer_id`) REFERENCES `benutzer` (`id`),
  ADD CONSTRAINT `fk_abfall_erledigt_termin` FOREIGN KEY (`termin_id`) REFERENCES `termine_abfall` (`id`);

--
-- Constraints der Tabelle `termine_abfall_reingestellt`
--
ALTER TABLE `termine_abfall_reingestellt`
  ADD CONSTRAINT `fk_termine_abfall_rein_benutzer` FOREIGN KEY (`benutzer_id`) REFERENCES `benutzer` (`id`),
  ADD CONSTRAINT `fk_termine_abfall_rein_termin` FOREIGN KEY (`termin_id`) REFERENCES `termine_abfall` (`id`);

--
-- Constraints der Tabelle `termine_sonstige_zuhause`
--
ALTER TABLE `termine_sonstige_zuhause`
  ADD CONSTRAINT `fk_sonstige_zuhause_benutzer` FOREIGN KEY (`benutzer_id`) REFERENCES `benutzer` (`id`),
  ADD CONSTRAINT `fk_sonstige_zuhause_termin` FOREIGN KEY (`termin_id`) REFERENCES `termine_sonstige` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
