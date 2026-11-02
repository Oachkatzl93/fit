<?php
declare(strict_types=1);

require_once __DIR__ . '/calendar.php';

function tool_definitions(bool $authenticated = false): array
{
    $date = ['type' => 'string', 'format' => 'date', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'];
    $range = ['type' => 'object', 'properties' => ['oldest' => $date, 'newest' => $date], 'additionalProperties' => false];
    $definitions = [
        ['get_athlete_profile', 'Profil mit Gewicht, FTP, Schwellen und sportartspezifischen Zonen; nur lesen.', ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false]],
        ['list_activities', 'Training im inklusiven Datumsbereich (max. 90 Tage). Standard: heute und vorherige 13 Tage in der Zeitzone des Kontos. Dauer in Sekunden, Distanz in Metern, Geschwindigkeit m/s, icu_intensity in Prozent, intensity_factor als Verhältnis.', $range],
        ['get_activity', 'Details und Intervalle einer eigenen Aktivität. activity_id aus list_activities verwenden. Dauer Sekunden, Distanz Meter, Leistung Watt, Herzfrequenz bpm.', ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,80}$']], 'required' => ['activity_id'], 'additionalProperties' => false]],
        ['get_wellness', 'Fitness CTL, Ermüdung ATL, Form CTL minus ATL (absolut), HRV, Ruhepuls, Schlaf und Gewicht. Standard: letzte 14 Tage; inklusiv max. 90 Tage. sleepSecs Sekunden, Gewicht kg, HRV ms.', $range],
        ['get_calendar', 'Kalender mit geplanten Workouts, Wettkämpfen und Notizen. Standard: heute bis heute +14 Tage; inklusiv max. 90 Tage.', $range],
    ];
    return array_merge(array_map(static fn(array $d): array => [
        'name' => $d[0], 'description' => $d[1], 'inputSchema' => $d[2],
        'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true],
        ...($authenticated ? ['securitySchemes' => [['type' => 'oauth2', 'scopes' => ['coach:read']]]] : []),
    ], $definitions), calendar_tool_definitions($authenticated));
}

function call_tool(string $name, array $args, IntervalsClient $client): array
{
    if (in_array($name, array_column(calendar_tool_definitions(false), 'name'), true)) {
        return call_calendar_tool($name, $args, $client);
    }
    $allowed = match ($name) {
        'get_athlete_profile' => [],
        'get_activity' => ['activity_id'],
        'list_activities', 'get_wellness', 'get_calendar' => ['oldest', 'newest'],
        default => throw new InvalidArgumentException('Unbekanntes Tool.'),
    };
    if (array_diff(array_keys($args), $allowed) !== []) {
        throw new InvalidArgumentException('Unbekannte Tool-Argumente.');
    }
    foreach ($args as $value) {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Tool-Argumente müssen Strings sein.');
        }
    }
    $athlete = '/athlete/' . rawurlencode($client->athleteId());
    if ($name === 'get_athlete_profile') {
        $profile = $client->get($athlete);
        $result = select_fields($profile, ['id', 'name', 'timezone', 'weight', 'icu_weight', 'icu_resting_hr', 'icu_form_as_percent']);
        $result['sportSettings'] = array_map(static fn(array $s): array => select_fields($s, [
            'id', 'types', 'ftp', 'indoor_ftp', 'lthr', 'max_hr', 'threshold_pace', 'pace_units',
            'power_zones', 'power_zone_names', 'hr_zones', 'hr_zone_names', 'pace_zones', 'pace_zone_names',
        ]), $profile['sportSettings'] ?? []);
        return $result;
    }
    if ($name === 'get_activity') {
        $id = $args['activity_id'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id)) {
            throw new InvalidArgumentException('activity_id aus list_activities ist erforderlich.');
        }
        $path = '/activity/' . rawurlencode($id);
        $activity = $client->get($path);
        if (!isset($activity['icu_athlete_id']) || ltrim((string) $activity['icu_athlete_id'], 'i') !== ltrim($client->athleteId(), 'i')) {
            throw new RuntimeException('Aktivität kann dem konfigurierten Athleten nicht zugeordnet werden.');
        }
        return ['activity' => activity_summary($activity), 'intervals' => $client->get($path . '/intervals')];
    }
    $range = date_range($args, $name === 'get_calendar', $client->timezone());
    // Activities akzeptieren Zeitstempel; so ist auch der gesamte letzte Tag enthalten.
    $query = $range;
    if ($name === 'list_activities') {
        $query['newest'] .= 'T23:59:59';
        $rows = $client->get($athlete . '/activities', $query);
        return ['range' => $range, 'activities' => array_map('activity_summary', $rows)];
    }
    if ($name === 'get_wellness') {
        $rows = $client->get($athlete . '/wellness', $query);
        return ['range' => $range, 'wellness' => array_map(static function (array $r): array {
            $out = select_fields($r, ['id', 'ctl', 'atl', 'rampRate', 'weight', 'restingHR', 'hrv', 'hrvSDNN', 'sleepSecs', 'sleepScore', 'avgSleepingHR', 'fatigue', 'soreness', 'stress', 'mood', 'motivation', 'readiness', 'injury', 'comments', 'sportInfo']);
            if (isset($r['ctl'], $r['atl'])) {
                $out['form_absolute'] = round($r['ctl'] - $r['atl'], 2);
            }
            return $out;
        }, $rows)];
    }
    $rows = $client->get($athlete . '/events', $query);
    return ['range' => $range, 'events' => array_map(static fn(array $r): array => select_fields($r, [
        'id', 'category', 'type', 'name', 'description', 'start_date_local', 'end_date_local',
        'moving_time', 'distance', 'icu_training_load', 'icu_intensity', 'workout_doc',
    ]), $rows)];
}
