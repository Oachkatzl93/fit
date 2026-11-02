<?php
declare(strict_types=1);

function calendar_tool_definitions(bool $authenticated): array
{
    $id = ['type' => 'string', 'pattern' => '^[1-9][0-9]{0,9}$', 'description' => 'Event-ID aus get_calendar, keine Aktivitäts-ID.'];
    $revision = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'revision aus dem unmittelbar zuvor abgerufenen get_calendar_event.'];
    $fields = [
        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
        'type' => ['type' => 'string', 'description' => 'intervals.icu-Sportart, z. B. Run, Ride, Swim oder WeightTraining.'],
        'start_date_local' => ['type' => 'string', 'description' => 'Lokale Zeit des Athleten: YYYY-MM-DDTHH:MM:SS, ohne Z oder Offset.'],
        'description' => ['type' => 'string', 'maxLength' => 30000, 'description' => 'Workout-Beschreibung, optional im nativen intervals.icu-Workout-Format.'],
        'moving_time' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 604800, 'description' => 'Sekunden.'],
        'distance' => ['type' => 'number', 'minimum' => 0, 'maximum' => 2000000, 'description' => 'Meter.'],
        'icu_training_load' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5000],
    ];
    $payload = ['type' => 'object', 'properties' => $fields, 'additionalProperties' => false];
    $race = $payload;
    $race['properties']['priority'] = ['type' => 'string', 'enum' => ['A', 'B', 'C'], 'description' => 'A: Hauptwettkampf; B: wichtiger Vorbereitungswettkampf; C: nachrangiger/Testwettkampf.'];
    $race['required'] = ['name', 'type', 'start_date_local', 'priority'];
    $defs = [
        ['get_calendar_event', 'Liest ein einzelnes Kalenderereignis des verbundenen Athleten samt revision für Änderungen. Vor update/delete immer frisch abrufen.', ['event_id' => $id], ['event_id'], false, false],
        ['create_calendar_workout', 'Legt genau ein geplantes Workout im eigenen Kalender an. Kategorie immer WORKOUT, frühestens heute. Nicht automatisch wiederholen; nach unklarem Ergebnis zuerst Kalender prüfen.', ['workout' => $payload + ['required' => ['name', 'type', 'start_date_local', 'description']]], ['workout'], true, false],
        ['create_calendar_race', 'Legt genau einen Wettkampf im eigenen Kalender ab heute an. Priorität A/B/C wird zu RACE_A/RACE_B/RACE_C. Keine unbekannte Priorität erfinden. Nach unklarem Ergebnis Kalender prüfen, nicht blind wiederholen.', ['race' => $race], ['race'], true, false],
        ['update_calendar_workout', 'Bearbeitet oder verschiebt genau ein geplantes Workout des verbundenen Athleten. Nur angegebene Felder ändern. Zuerst get_calendar_event abrufen und dessen revision verwenden. Keine Wettkämpfe, Notizen, Aktivitäten oder gesperrten Workouts.', ['event_id' => $id, 'expected_revision' => $revision, 'changes' => $payload + ['minProperties' => 1]], ['event_id', 'expected_revision', 'changes'], true, true],
        ['delete_calendar_workout', 'Löscht genau ein geplantes Workout im eigenen Kalender. Nur bei ausdrücklichem Löschauftrag. Vorher get_calendar_event abrufen; keine Serienlöschung.', ['event_id' => $id, 'expected_revision' => $revision], ['event_id', 'expected_revision'], true, true],
    ];
    return array_map(static fn(array $d): array => [
        'name' => $d[0], 'description' => $d[1],
        'inputSchema' => ['type' => 'object', 'properties' => $d[2], 'required' => $d[3], 'additionalProperties' => false],
        'annotations' => ['readOnlyHint' => !$d[4], 'destructiveHint' => in_array($d[0], ['update_calendar_workout', 'delete_calendar_workout'], true), 'idempotentHint' => $d[5] || !$d[4], 'openWorldHint' => true],
        ...($authenticated ? ['securitySchemes' => [['type' => 'oauth2', 'scopes' => $d[4] ? ['coach:read', 'coach:calendar:write'] : ['coach:read']]]] : []),
    ], $defs);
}

function calendar_revision(array $event): string
{
    $canonical = static function (mixed $value) use (&$canonical): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map($canonical, $value);
    };
    return hash('sha256', json_encode($canonical($event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}

function calendar_event_result(array $event): array
{
    return ['event' => select_fields($event, [
        'id', 'athlete_id', 'category', 'type', 'name', 'description', 'start_date_local', 'end_date_local',
        'moving_time', 'distance', 'icu_training_load', 'icu_intensity', 'workout_doc', 'updated', 'uid',
        'athlete_cannot_edit', 'structure_read_only', 'plan_folder_id', 'plan_workout_id',
    ]), 'revision' => calendar_revision($event)];
}

function calendar_event_path(mixed $id, IntervalsClient $client): string
{
    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id) || (int) $id > 2147483647) {
        throw new InvalidArgumentException('Gültige event_id aus get_calendar erforderlich.');
    }
    return '/athlete/' . rawurlencode($client->athleteId()) . '/events/' . $id;
}

function read_calendar_event(string $id, IntervalsClient $client): array
{
    $event = $client->get(calendar_event_path($id, $client));
    if ((string) ($event['id'] ?? '') !== $id || !isset($event['athlete_id'])
        || ltrim((string) $event['athlete_id'], 'i') !== ltrim($client->athleteId(), 'i')) {
        throw new RuntimeException('Kalenderereignis gehört nicht eindeutig zum verbundenen Athleten.');
    }
    return $event;
}

function validate_workout_fields(mixed $input, bool $create, IntervalsClient $client): array
{
    if ($input instanceof stdClass) {
        $input = (array) $input;
    }
    $allowed = ['name', 'type', 'start_date_local', 'description', 'moving_time', 'distance', 'icu_training_load'];
    if (!is_array($input) || $input === [] || array_diff(array_keys($input), $allowed) !== []) {
        throw new InvalidArgumentException('workout/changes muss ein nicht leeres Objekt mit erlaubten Workout-Feldern sein.');
    }
    if ($create && array_diff(['name', 'type', 'start_date_local', 'description'], array_keys($input)) !== []) {
        throw new InvalidArgumentException('name, type, start_date_local und description sind erforderlich.');
    }
    foreach ($input as $key => $value) {
        if (in_array($key, ['name', 'type', 'start_date_local', 'description'], true)) {
            $max = $key === 'description' ? 30000 : 200;
            if (!is_string($value) || strlen($value) > $max || ($key !== 'description' && trim($value) === '') || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Ungültiger Textwert: ' . $key);
            }
        } else {
            $max = match ($key) { 'distance' => 2000000, 'moving_time' => 604800, default => 5000 };
            if (($key !== 'distance' && !is_int($value)) || (!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > $max) {
                throw new InvalidArgumentException('Ungültiger Zahlenwert: ' . $key);
            }
        }
    }
    if (isset($input['type']) && !preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/D', $input['type'])) {
        throw new InvalidArgumentException('Sportart muss ein intervals.icu-Typ sein, z. B. Run oder Ride.');
    }
    if (isset($input['start_date_local'])) {
        future_workout_date($input['start_date_local'], $client);
    }
    return $input;
}

function future_workout_date(string $value, IntervalsClient $client): void
{
    $zone = new DateTimeZone($client->timezone());
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, $zone);
    if (!$date || $date->format('Y-m-d\TH:i:s') !== $value || $date < new DateTimeImmutable('today', $zone)) {
        throw new InvalidArgumentException('Workout-Datum muss YYYY-MM-DDTHH:MM:SS in der Athleten-Zeitzone und frühestens heute sein.');
    }
}

function call_calendar_tool(string $name, array $args, IntervalsClient $client): array
{
    $keys = match ($name) {
        'get_calendar_event' => ['event_id'],
        'create_calendar_workout' => ['workout'],
        'create_calendar_race' => ['race'],
        'update_calendar_workout' => ['event_id', 'expected_revision', 'changes'],
        'delete_calendar_workout' => ['event_id', 'expected_revision'],
    };
    if (array_diff(array_keys($args), $keys) !== [] || array_diff($keys, array_keys($args)) !== []) {
        throw new InvalidArgumentException('Erforderliche Kalender-Argumente fehlen oder sind unbekannt.');
    }
    if ($name !== 'get_calendar_event' && !$client->canWriteCalendar()) {
        throw new RuntimeException('Kalender-Schreibzugriff ist nicht freigegeben. Konfiguration bzw. OAuth-Verbindung mit coach:calendar:write prüfen.');
    }
    if (in_array($name, ['create_calendar_workout', 'create_calendar_race'], true)) {
        $category = 'WORKOUT';
        if ($name === 'create_calendar_race') {
            $race = $args['race'] instanceof stdClass ? (array) $args['race'] : $args['race'];
            if (!is_array($race) || array_diff(['name', 'type', 'start_date_local', 'priority'], array_keys($race)) !== []
                || !in_array($race['priority'], ['A', 'B', 'C'], true)) {
                throw new InvalidArgumentException('Wettkampf benötigt name, type, start_date_local und priority A/B/C.');
            }
            $category = 'RACE_' . $race['priority'];
            unset($race['priority']);
            $data = validate_workout_fields($race, false, $client);
        } else {
            $data = validate_workout_fields($args['workout'], true, $client);
        }
        $created = $client->write('POST', '/athlete/' . rawurlencode($client->athleteId()) . '/events', ['category' => $category] + $data);
        try {
            $id = (string) ($created['id'] ?? '');
            return calendar_event_result(read_calendar_event($id, $client));
        } catch (Throwable $e) {
            throw new RuntimeException('Anlage wurde gesendet, konnte aber nicht verifiziert werden. Kalender vor erneuter Anlage prüfen.');
        }
    }
    calendar_event_path($args['event_id'], $client);
    $event = read_calendar_event($args['event_id'], $client);
    if ($name === 'get_calendar_event') {
        return calendar_event_result($event);
    }
    if (($event['category'] ?? '') !== 'WORKOUT' || !empty($event['athlete_cannot_edit']) || !empty($event['structure_read_only'])) {
        throw new RuntimeException('Nur bearbeitbare geplante WORKOUT-Ereignisse dürfen geändert oder gelöscht werden.');
    }
    future_workout_date($event['start_date_local'] ?? '', $client);
    if (!is_string($args['expected_revision']) || !preg_match('/^[a-f0-9]{64}$/D', $args['expected_revision'])
        || !hash_equals(calendar_revision($event), $args['expected_revision'])) {
        throw new RuntimeException('Kalendereintrag hat sich geändert. Neu abrufen und Änderung erneut abstimmen.');
    }
    $path = calendar_event_path($args['event_id'], $client);
    if ($name === 'update_calendar_workout') {
        $changes = validate_workout_fields($args['changes'], false, $client);
        if (isset($changes['start_date_local'], $event['end_date_local'])) {
            $zone = new DateTimeZone($client->timezone());
            $oldStart = new DateTimeImmutable($event['start_date_local'], $zone);
            $oldEnd = new DateTimeImmutable($event['end_date_local'], $zone);
            if ($oldEnd < $oldStart) {
                throw new RuntimeException('Kalendereintrag hat eine ungültige Zeitspanne. Vor dem Verschieben klären.');
            }
            $changes['end_date_local'] = (new DateTimeImmutable($changes['start_date_local'], $zone))
                ->add($oldStart->diff($oldEnd))->format('Y-m-d\TH:i:s');
        }
        $client->write('PUT', $path, $changes);
        try {
            return calendar_event_result(read_calendar_event($args['event_id'], $client));
        } catch (Throwable $e) {
            throw new RuntimeException('Änderung wurde gesendet, Ergebnis aber nicht verifiziert. Kalender neu abrufen.');
        }
    }
    $client->write('DELETE', $path);
    try {
        $client->get($path);
    } catch (IntervalsApiException $e) {
        if ($e->httpStatus === 404) {
            return ['deleted_event_id' => $args['event_id'], 'verified_absent' => true];
        }
        throw new RuntimeException('Löschung wurde gesendet, Ergebnis aber nicht verifiziert. Kalender neu abrufen.');
    } catch (RuntimeException $e) {
        throw new RuntimeException('Löschung wurde gesendet, Ergebnis aber nicht verifiziert. Kalender neu abrufen.');
    }
    throw new RuntimeException('Löschung wurde gesendet; Ereignis ist weiterhin sichtbar. Kalender vor erneutem Versuch prüfen.');
}
