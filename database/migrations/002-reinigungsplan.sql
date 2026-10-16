-- Reinigungsrhythmus für bestehende Installationen ergänzen.
-- Die vorhandenen Bewohnergruppen bleiben erhalten.

CREATE TABLE IF NOT EXISTS `reinigungsplaene` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titel` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `startdatum` date NOT NULL,
  `intervall` int(10) unsigned NOT NULL,
  `einheit` varchar(16) NOT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `erstellt_am` timestamp NOT NULL DEFAULT current_timestamp(),
  `aktualisiert_am` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_reinigungsplan_intervall` CHECK (`intervall` BETWEEN 1 AND 365),
  CONSTRAINT `chk_reinigungsplan_einheit` CHECK (`einheit` IN ('tage', 'wochen', 'monate'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `reinigungsplan_gruppen` (
  `plan_id` int(11) NOT NULL,
  `gruppe_id` int(11) NOT NULL,
  `position` int(10) unsigned NOT NULL,
  PRIMARY KEY (`plan_id`, `position`),
  UNIQUE KEY `uniq_reinigungsplan_gruppe` (`plan_id`, `gruppe_id`),
  KEY `idx_reinigungsplan_gruppe` (`gruppe_id`),
  CONSTRAINT `fk_reinigungsplan_plan` FOREIGN KEY (`plan_id`) REFERENCES `reinigungsplaene` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reinigungsplan_gruppe` FOREIGN KEY (`gruppe_id`) REFERENCES `benutzer_gruppen` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
