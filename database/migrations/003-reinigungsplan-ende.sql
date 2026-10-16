-- Optionales Enddatum für Reinigungspläne bestehender Installationen ergänzen.
-- Ein leeres Enddatum erhält den bisherigen unbegrenzten Rhythmus.

ALTER TABLE `reinigungsplaene`
  ADD COLUMN IF NOT EXISTS `enddatum` date DEFAULT NULL AFTER `startdatum`;
