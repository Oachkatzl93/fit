<?php
declare(strict_types=1);

define('COACH_TIMEZONE', 'Europe/Vienna');
define('INTERVALS_ATHLETE_ID', 'i12345');
require __DIR__ . '/../server/lib/intervals.php';
require __DIR__ . '/../server/lib/tools.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $fn, string $class = InvalidArgumentException::class): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($e instanceof $class, 'Falscher Fehlertyp: ' . get_class($e));
        return;
    }
    throw new RuntimeException('Erwarteter Fehler fehlt.');
}

class FixtureClient extends IntervalsClient
{
    public array $calls = [];
    public string $owner = 'i12345';

    public function get(string $path, array $query = []): array
    {
        $this->calls[] = [$path, $query];
        return match ($path) {
            '/athlete/i12345' => ['id' => 'i12345', 'icu_api_key' => 'SECRET', 'email' => 'PRIVATE', 'sportSettings' => [['types' => ['Run'], 'threshold_pace' => 4.2, 'athlete_id' => 'PRIVATE']]],
            '/athlete/i12345/activities' => [['id' => 'i1', 'icu_intensity' => 85, 'average_speed' => 4, 'description' => 'Notiz', 'icu_average_watts' => 200]],
            '/athlete/i12345/wellness' => [['id' => '2026-10-21', 'ctl' => 50, 'atl' => 65, 'hrv' => null, 'sleepSecs' => 28000]],
            '/athlete/i12345/events' => [['id' => 1, 'category' => 'RACE', 'description' => 'Wettkampf', 'start_date_local' => '2026-10-22T08:00:00']],
            '/activity/i1' => ['id' => 'i1', 'icu_athlete_id' => $this->owner],
            '/activity/i1/intervals' => ['icu_intervals' => [['moving_time' => 60, 'average_watts' => 250]]],
            default => throw new RuntimeException('Unerwarteter API-Pfad.'),
        };
    }
}

$range = date_range(['oldest' => '2024-02-01', 'newest' => '2024-04-30']);
check($range['newest'] === '2024-04-30', '90-Tage-Grenze inklusive Schaltjahr');
rejects(fn() => date_range(['oldest' => '2024-02-01', 'newest' => '2024-05-01']));
rejects(fn() => date_range(['oldest' => '2026-02-30']));
rejects(fn() => date_range(['oldest' => '2026-1-01']));
rejects(fn() => date_range(['oldest' => '2026-10-22', 'newest' => '2026-10-21']));
$today = new DateTimeImmutable('today', new DateTimeZone(COACH_TIMEZONE));
check(date_range([])['oldest'] === $today->modify('-13 days')->format('Y-m-d'), '14-Tage-Default');
check(date_range([], true)['newest'] === $today->modify('+14 days')->format('Y-m-d'), 'Kalender-Default');
$client = new FixtureClient();
$profile = call_tool('get_athlete_profile', [], $client);
check(!array_key_exists('icu_api_key', $profile) && !array_key_exists('email', $profile), 'Profil enthält private Felder');
check(!isset($profile['sportSettings'][0]['athlete_id']), 'Sport-Einstellungen nicht gekürzt');
check($profile['sportSettings'][0]['threshold_pace'] === 4.2, 'Schwellenpace fehlt');
$activities = call_tool('list_activities', ['oldest' => '2026-10-01', 'newest' => '2026-10-21'], $client);
check($client->calls[1][1]['newest'] === '2026-10-21T23:59:59', 'Letzter Tag unvollständig');
check($activities['activities'][0]['intensity_factor'] === 0.85, 'IF-Skalierung');
check($activities['activities'][0]['average_pace_seconds_per_km'] === 250.0, 'Pace-Berechnung');
$wellness = call_tool('get_wellness', [], $client)['wellness'][0];
check($wellness['form_absolute'] === -15.0 && array_key_exists('hrv', $wellness) && $wellness['hrv'] === null, 'Form oder fehlende HRV');
check(call_tool('get_calendar', [], $client)['events'][0]['category'] === 'RACE', 'Wettkampf fehlt');
check(count(call_tool('get_activity', ['activity_id' => 'i1'], $client)['intervals']['icu_intervals']) === 1, 'Intervalle fehlen');
$client->owner = 'i999';
$before = count($client->calls);
rejects(fn() => call_tool('get_activity', ['activity_id' => 'i1'], $client), RuntimeException::class);
check(count($client->calls) === $before + 1, 'Fremde Intervalle wurden abgerufen');
rejects(fn() => call_tool('get_activity', ['activity_id' => '../athlete'], $client));
rejects(fn() => call_tool('get_activity', [], $client));
rejects(fn() => call_tool('get_wellness', ['oldest' => null], $client));
rejects(fn() => call_tool('get_wellness', ['unexpected' => 'x'], $client));
rejects(fn() => call_tool('unknown', [], $client));
echo "OK Datumsgrenzen, Defaults, Feldauswahl, Einheiten, fünf Handler, Zugehörigkeit und Validierung\n";
