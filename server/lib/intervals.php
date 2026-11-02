<?php
declare(strict_types=1);

final class IntervalsApiException extends RuntimeException
{
    public function __construct(public int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

class IntervalsClient
{
    public function __construct(
        private ?string $apiKey = null,
        private ?string $athlete = null,
        private ?string $zone = null,
        private ?bool $calendarWrites = null
    ) {
    }

    public function athleteId(): string
    {
        return $this->athlete ?? INTERVALS_ATHLETE_ID;
    }

    public function timezone(): string
    {
        return $this->zone ?? (defined('COACH_TIMEZONE') ? COACH_TIMEZONE : 'Europe/Vienna');
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    public function canWriteCalendar(): bool
    {
        return $this->calendarWrites ?? (defined('ENABLE_CALENDAR_WRITES') && ENABLE_CALENDAR_WRITES === true);
    }

    public function write(string $method, string $path, array $data = [], array $query = []): array
    {
        if (!$this->canWriteCalendar()) {
            throw new RuntimeException('Kalender-Schreibzugriff ist nicht freigegeben. Konfiguration bzw. OAuth-Verbindung mit coach:calendar:write prüfen.');
        }
        $base = '/athlete/' . rawurlencode($this->athleteId()) . '/events';
        $single = (bool) preg_match('~^' . preg_quote($base, '~') . '/[1-9][0-9]{0,9}$~D', $path);
        if (!(($method === 'POST' && $path === $base) || (in_array($method, ['PUT', 'DELETE'], true) && $single))) {
            throw new InvalidArgumentException('Nur einzelne geplante Kalender-Workouts und Wettkämpfe dürfen geschrieben werden.');
        }
        if ($method === 'DELETE') {
            $query = ['others' => 'false'];
        } elseif ($method === 'POST') {
            $query = ['upsertOnUid' => 'false'];
        } else {
            $query = [];
        }
        return $this->request($method, $path, $query, $data);
    }

    protected function request(string $method, string $path, array $query = [], array $data = []): array
    {
        $url = 'https://intervals.icu/api/v1' . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => 'API_KEY:' . ($this->apiKey ?? INTERVALS_API_KEY),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($method !== 'DELETE') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
            }
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // Keine fremden Fehlertexte, URLs oder Credentials zurückgeben.
        if ($body === false) {
            throw new RuntimeException($method === 'GET'
                ? 'intervals.icu ist nicht erreichbar oder hat das Zeitlimit überschritten.'
                : 'Schreibergebnis unklar (Verbindung/Zeitlimit). Kalender erneut abrufen; nicht ungeprüft wiederholen.');
        }
        if ($status < 200 || $status >= 300) {
            $hint = match ($status) {
                401, 403 => 'API-Key oder Zugriffsrechte prüfen.',
                404 => 'Athlet, Aktivität oder Kalenderereignis nicht gefunden.',
                429 => 'Zu viele Anfragen; später erneut versuchen.',
                default => 'Bitte später erneut versuchen.',
            };
            throw new IntervalsApiException($status, "intervals.icu HTTP $status: $hint" . ($method !== 'GET' && $status >= 500 ? ' Schreibergebnis unklar; Kalender vor Wiederholung prüfen.' : ''));
        }
        if ($method !== 'GET' && trim($body) === '') {
            return [];
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('intervals.icu hat ungültiges JSON geliefert.');
        }
        if (!is_array($data)) {
            throw new RuntimeException('intervals.icu hat ein unerwartetes Ergebnis geliefert.');
        }
        return $data;
    }
}

function select_fields(array $row, array $fields): array
{
    return array_intersect_key($row, array_flip($fields));
}

function date_range(array $args, bool $calendar = false, ?string $timezone = null): array
{
    $zone = new DateTimeZone($timezone ?? (defined('COACH_TIMEZONE') ? COACH_TIMEZONE : 'Europe/Vienna'));
    $today = new DateTimeImmutable('today', $zone);
    $oldest = $args['oldest'] ?? $today->modify($calendar ? '+0 days' : '-13 days')->format('Y-m-d');
    $newest = $args['newest'] ?? $today->modify($calendar ? '+14 days' : '+0 days')->format('Y-m-d');
    $dates = [];
    foreach ([$oldest, $newest] as $value) {
        $d = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone) : false;
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Datum muss ein gültiges YYYY-MM-DD sein.');
        }
        $dates[] = $d;
    }
    if ($dates[0] > $dates[1] || (int) $dates[0]->diff($dates[1])->days > 89) {
        throw new InvalidArgumentException('Zeitraum muss aufsteigend sein und darf einschließlich beider Tage höchstens 90 Tage umfassen.');
    }
    return ['oldest' => $oldest, 'newest' => $newest];
}

function activity_summary(array $row): array
{
    $result = select_fields($row, [
        'id', 'icu_athlete_id', 'start_date_local', 'type', 'name', 'description',
        'moving_time', 'elapsed_time', 'distance', 'total_elevation_gain',
        'icu_training_load', 'icu_intensity', 'average_heartrate', 'max_heartrate',
        'icu_average_watts', 'icu_weighted_avg_watts', 'average_speed', 'pace',
        'icu_ftp', 'lthr', 'threshold_pace', 'icu_rpe', 'perceived_exertion', 'feel',
        'icu_zone_times', 'icu_hr_zone_times', 'pace_zone_times', 'source',
    ]);
    if (isset($row['icu_intensity']) && is_numeric($row['icu_intensity'])) {
        $result['intensity_factor'] = $row['icu_intensity'] / 100;
    }
    if (isset($row['average_speed']) && $row['average_speed'] > 0) {
        $result['average_pace_seconds_per_km'] = round(1000 / $row['average_speed'], 1);
    }
    return $result;
}
