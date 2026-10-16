<?php
// Reinigungspläne berechnen ihre Zuständigkeit aus dem gespeicherten Startdatum.

function reinigungsplan_parse_date(string $value): DateTimeImmutable {
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
        throw new InvalidArgumentException('Bitte ein gültiges Datum im Format JJJJ-MM-TT eingeben.');
    }
    if ((int)substr($value, 0, 4) < 1000) {
        throw new InvalidArgumentException('Das Datum muss zwischen den Jahren 1000 und 9999 liegen.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Berlin'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Bitte ein gültiges Datum eingeben.');
    }
    return $date;
}

/**
 * Jeder Monatsbeginn wird vom ursprünglichen Anker berechnet. Dadurch wird aus
 * 31. Januar, 28. Februar und 31. März keine dauerhaft auf den 28. verkürzte Folge.
 */
function reinigungsplan_period_start(DateTimeImmutable $anchor, int $interval, string $unit, int $index, ?DateTimeImmutable $latestStart = null): DateTimeImmutable {
    if ($index < 0 || $interval < 1 || $index > intdiv(PHP_INT_MAX, $interval)) {
        throw new InvalidArgumentException('Der Reinigungsrhythmus ist ungültig.');
    }
    $offset = $interval * $index;
    if ($unit === 'monate') {
        $monthIndex = ((int)$anchor->format('Y') * 12) + (int)$anchor->format('n') - 1;
        if ($offset > PHP_INT_MAX - $monthIndex) {
            throw new InvalidArgumentException('Der Reinigungsrhythmus ist zu groß.');
        }
        $monthIndex += $offset;
        $year = intdiv($monthIndex, 12);
        if ($year > 9999) {
            if ($latestStart !== null) {
                return $latestStart;
            }
            throw new InvalidArgumentException('Die Vorschau des Reinigungsrhythmus überschreitet das Jahr 9999. Bitte wähle ein früheres Startdatum oder ein kürzeres Intervall.');
        }
        $month = ($monthIndex % 12) + 1;
        $first = $anchor->setDate($year, $month, 1);
        $day = min((int)$anchor->format('j'), (int)$first->format('t'));
        $start = $first->setDate($year, $month, $day);
        return $latestStart !== null && $start > $latestStart ? $latestStart : $start;
    }
    if ($unit === 'wochen') {
        if ($offset > intdiv(PHP_INT_MAX, 7)) {
            throw new InvalidArgumentException('Der Reinigungsrhythmus ist zu groß.');
        }
        $offset *= 7;
    } elseif ($unit !== 'tage') {
        throw new InvalidArgumentException('Bitte Tage, Wochen oder Monate als Rhythmus wählen.');
    }
    $start = $anchor->modify('+' . $offset . ' days');
    if ($latestStart !== null && $start >= $latestStart) {
        return $latestStart;
    }
    if ((int)$start->format('Y') > 9999) {
        throw new InvalidArgumentException('Die Vorschau des Reinigungsrhythmus überschreitet das Jahr 9999. Bitte wähle ein früheres Startdatum oder ein kürzeres Intervall.');
    }
    return $start;
}

/**
 * Planfelder: startdatum, optionales enddatum, intervall, einheit.
 * Gruppen: id, name, in Reihenfolge.
 * Ergebnis: von und bis inklusive, gruppe_id, gruppe_name, aktuell, status.
 * Vor dem Start erscheinen die ersten Zeiträume, danach der aktuelle und die
 * folgenden. Das Enddatum gilt einschließlich und kürzt den letzten Zeitraum.
 * Nach dem Ende werden keine weiteren Zeiträume oder Wiederholungen geliefert.
 * Inaktive Gruppen bleiben zugeordnet, bis der Plan geändert wird.
 */
function reinigungsplan_periods(array $plan, array $orderedGroups, ?DateTimeImmutable $today = null, int $limit = 4): array {
    $anchor = reinigungsplan_parse_date((string)($plan['startdatum'] ?? ''));
    $endValue = $plan['enddatum'] ?? null;
    if ($endValue !== null && !is_string($endValue)) {
        throw new InvalidArgumentException('Bitte ein gültiges Enddatum eingeben.');
    }
    $end = $endValue === null || $endValue === '' ? null : reinigungsplan_parse_date($endValue);
    if ($end !== null && $end < $anchor) {
        throw new InvalidArgumentException('Das Enddatum darf nicht vor dem Startdatum liegen.');
    }
    // Die exklusive Grenze erlaubt auch einen letzten Tag am 31.12.9999.
    $exclusiveEnd = $end !== null ? $end->modify('+1 day') : null;
    $interval = filter_var($plan['intervall'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
    $unit = (string)($plan['einheit'] ?? '');
    if ($interval === false || !in_array($unit, ['tage', 'wochen', 'monate'], true)) {
        throw new InvalidArgumentException('Der gespeicherte Reinigungsrhythmus ist ungültig.');
    }
    if (count($orderedGroups) === 0) {
        throw new InvalidArgumentException('Dem Reinigungsplan sind keine Bewohnergruppen zugeordnet.');
    }
    $groups = array_values($orderedGroups);
    $seen = [];
    foreach ($groups as $group) {
        $id = filter_var($group['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !isset($group['name']) || trim((string)$group['name']) === '' || isset($seen[$id])) {
            throw new InvalidArgumentException('Die Bewohnergruppen des Reinigungsplans sind ungültig oder doppelt zugeordnet.');
        }
        $seen[$id] = true;
    }
    if ($limit < 1) {
        return [];
    }
    $timezone = new DateTimeZone('Europe/Berlin');
    $today = ($today ?? new DateTimeImmutable('today', $timezone))->setTimezone($timezone)->setTime(0, 0);
    if ($end !== null && $today > $end) {
        return [];
    }
    $index = 0;
    if ($today >= $anchor) {
        if ($unit === 'monate') {
            $elapsedMonths = ((int)$today->format('Y') - (int)$anchor->format('Y')) * 12
                + (int)$today->format('n') - (int)$anchor->format('n');
            $index = intdiv($elapsedMonths, $interval);
        } else {
            $elapsedDays = (int)$anchor->diff($today)->format('%a');
            $index = $unit === 'wochen' ? intdiv(intdiv($elapsedDays, 7), $interval) : intdiv($elapsedDays, $interval);
        }
        while ($index > 0 && reinigungsplan_period_start($anchor, $interval, $unit, $index, $exclusiveEnd) > $today) {
            $index--;
        }
        while (reinigungsplan_period_start($anchor, $interval, $unit, $index + 1, $exclusiveEnd) <= $today) {
            $index++;
        }
    }
    $periods = [];
    for ($count = 0; $count < $limit; $count++, $index++) {
        $start = reinigungsplan_period_start($anchor, $interval, $unit, $index, $exclusiveEnd);
        if ($end !== null && $start > $end) {
            break;
        }
        $next = reinigungsplan_period_start($anchor, $interval, $unit, $index + 1, $exclusiveEnd);
        $group = $groups[$index % count($groups)];
        $current = $start <= $today && $today < $next;
        $periods[] = [
            'von' => $start->format('Y-m-d'),
            'bis' => $next->modify('-1 day')->format('Y-m-d'),
            'gruppe_id' => (int)$group['id'],
            'gruppe_name' => (string)$group['name'],
            'aktuell' => $current,
            'status' => $current ? 'current' : 'upcoming',
        ];
    }
    return $periods;
}

/** Pläne mit DB-Feldnamen sowie gruppen und berechneten perioden laden. */
function reinigungsplan_load(PDO $pdo, bool $onlyActive = true): array {
    $plans = $pdo->query(
        'SELECT id, titel, details, startdatum, enddatum, intervall, einheit, aktiv, erstellt_am, aktualisiert_am
         FROM reinigungsplaene' . ($onlyActive ? ' WHERE aktiv = 1' : '') . ' ORDER BY titel ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $groupRows = $pdo->query(
        'SELECT pg.plan_id, pg.position, g.id, g.name, g.aktiv
         FROM reinigungsplan_gruppen pg
         INNER JOIN benutzer_gruppen g ON g.id = pg.gruppe_id
         ORDER BY pg.plan_id ASC, pg.position ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $groupsByPlan = [];
    foreach ($groupRows as $group) {
        $groupsByPlan[(int)$group['plan_id']][] = [
            'id' => (int)$group['id'],
            'name' => $group['name'],
            'aktiv' => (int)$group['aktiv'],
            'position' => (int)$group['position'],
        ];
    }
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
    foreach ($plans as &$plan) {
        $plan['id'] = (int)$plan['id'];
        $plan['intervall'] = (int)$plan['intervall'];
        $plan['aktiv'] = (int)$plan['aktiv'];
        $plan['gruppen'] = $groupsByPlan[$plan['id']] ?? [];
        $plan['perioden'] = reinigungsplan_periods($plan, $plan['gruppen'], $today);
        $plan['beendet'] = $plan['enddatum'] !== null && $plan['enddatum'] < $today->format('Y-m-d');
    }
    unset($plan);
    return $plans;
}

function reinigungsplan_schema_missing(Throwable $error): bool {
    if (!$error instanceof PDOException) {
        return false;
    }
    $sqlState = (string)($error->errorInfo[0] ?? $error->getCode());
    $driverCode = (int)($error->errorInfo[1] ?? 0);
    return in_array($sqlState, ['42S02', '42S22'], true) || in_array($driverCode, [1146, 1054], true);
}
