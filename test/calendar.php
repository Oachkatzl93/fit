<?php
declare(strict_types=1);
require __DIR__ . '/../server/lib/intervals.php';
require __DIR__ . '/../server/lib/tools.php';
require __DIR__ . '/../server/lib/rpc.php';

function ensure(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function fails(callable $work): void
{
    try {
        $work();
    } catch (InvalidArgumentException | RuntimeException $e) {
        return;
    }
    throw new LogicException('Erwarteter Kalenderfehler fehlt.');
}

class CalendarFixture extends IntervalsClient
{
    public array $events = [];
    public array $calls = [];
    public bool $failReadback = false;
    protected function request(string $method, string $path, array $query = [], array $data = []): array
    {
        $this->calls[] = [$method, $path, $query, $data];
        $base = '/athlete/' . $this->athleteId() . '/events';
        if ($method === 'POST' && $path === $base) {
            ensure(($query['upsertOnUid'] ?? '') === 'false', 'Keine fremden UIDs überschreiben');
            $id = (string) (100 + count($this->events));
            return $this->events[$id] = ['id' => (int) $id, 'athlete_id' => $this->athleteId()] + $data;
        }
        $id = basename($path);
        if (!str_starts_with($path, $base . '/') || !isset($this->events[$id])) {
            throw new IntervalsApiException(404, 'Nicht gefunden.');
        }
        if ($method === 'GET') {
            if ($this->failReadback) {
                throw new RuntimeException('Fixture timeout');
            }
            return $this->events[$id];
        }
        if ($method === 'PUT') {
            return $this->events[$id] = array_replace($this->events[$id], $data);
        }
        if ($method === 'DELETE') {
            ensure($query === ['others' => 'false'], 'Serienlöschung muss ausgeschaltet sein');
            unset($this->events[$id]);
            return [];
        }
        throw new LogicException('Unerwarteter Request.');
    }
}

$client = new CalendarFixture('fixture-key', 'i101', 'Europe/Vienna', true);
$tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Vienna')))->format('Y-m-d');
$later = (new DateTimeImmutable('tomorrow +1 day', new DateTimeZone('Europe/Vienna')))->format('Y-m-d');
$workout = ['name' => 'Lockerer Lauf', 'type' => 'Run', 'description' => '- 30m Z2', 'start_date_local' => $tomorrow . 'T09:00:00', 'moving_time' => 1800];
$created = call_tool('create_calendar_workout', ['workout' => (object) $workout], $client);
$id = (string) $created['event']['id'];
ensure($created['event']['category'] === 'WORKOUT' && $created['event']['athlete_id'] === 'i101', 'Anlage im falschen Konto/Kategorie');
ensure(strlen($created['revision']) === 64, 'Revision fehlt');
$client->events[$id]['end_date_local'] = $tomorrow . 'T10:00:00';
$client->events[$id]['indoor'] = true;
$before = call_tool('get_calendar_event', ['event_id' => $id], $client);
$changed = call_tool('update_calendar_workout', ['event_id' => $id, 'expected_revision' => $before['revision'], 'changes' => ['name' => 'Verschobener Lauf', 'start_date_local' => $later . 'T09:00:00']], $client);
ensure($changed['event']['start_date_local'] === $later . 'T09:00:00' && $changed['event']['end_date_local'] === $later . 'T10:00:00', 'Verschiebung verliert Zeitspanne');
ensure($changed['event']['description'] === $workout['description'] && $client->events[$id]['indoor'] === true, 'Nicht beauftragte Felder überschrieben');
$writeCount = count(array_filter($client->calls, fn($c) => $c[0] !== 'GET'));
fails(fn() => call_tool('update_calendar_workout', ['event_id' => $id, 'expected_revision' => $before['revision'], 'changes' => ['name' => 'Veralteter Stand']], $client));
ensure(count(array_filter($client->calls, fn($c) => $c[0] !== 'GET')) === $writeCount, 'Konflikt löst Schreibaufruf aus');

foreach ([['athlete_id' => 'i202'], ['category' => 'RACE_A'], ['athlete_cannot_edit' => true], ['structure_read_only' => true], ['start_date_local' => '2000-01-01T00:00:00']] as $override) {
    $client->events['50'] = array_replace($client->events[$id], ['id' => 50], $override);
    fails(fn() => call_tool('delete_calendar_workout', ['event_id' => '50', 'expected_revision' => calendar_revision($client->events['50'])], $client));
}
ensure(count(array_filter($client->calls, fn($c) => $c[0] !== 'GET')) === $writeCount, 'Fremde/gesperrte/alte Events werden geändert');
foreach ([['athlete_id' => 'i202'], ['name' => null], ['moving_time' => -1], ['distance' => '1000'], ['start_date_local' => '2030-02-30T09:00:00'], ['start_date_local' => '2030-01-01T09:00:00Z']] as $override) {
    fails(fn() => call_tool('create_calendar_workout', ['workout' => array_replace($workout, $override)], $client));
}
fails(fn() => call_tool('get_calendar_event', ['event_id' => '../50'], $client));
fails(fn() => $client->write('PUT', '/activity/i1', ['name' => 'No']));
fails(fn() => $client->write('DELETE', '/athlete/i101/events'));
fails(fn() => $client->write('PUT', '/athlete/i202/events/50', ['name' => 'No']));
$readOnly = new CalendarFixture('fixture-key', 'i101', 'Europe/Vienna', false);
fails(fn() => call_tool('create_calendar_workout', ['workout' => $workout], $readOnly));
ensure($readOnly->calls === [], 'Lesezugang löst API-Aufruf aus');
$rpc = dispatch(json_decode(json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'create_calendar_workout', 'arguments' => ['workout' => $workout]]])), ['2025-11-25'], $client, true);
ensure($rpc['result']['isError'] === false, 'Verschachtelte MCP-Argumente nicht verarbeitet');
$definitions = array_column(tool_definitions(true), null, 'name');
ensure($definitions['create_calendar_workout']['annotations']['readOnlyHint'] === false && $definitions['delete_calendar_workout']['annotations']['destructiveHint'] === true, 'Falsche Schreibannotationen');
ensure(in_array('coach:calendar:write', $definitions['update_calendar_workout']['securitySchemes'][0]['scopes'], true), 'Schreibscope fehlt');
$deleted = call_tool('delete_calendar_workout', ['event_id' => $id, 'expected_revision' => $changed['revision']], $client);
ensure($deleted['verified_absent'] === true && !isset($client->events[$id]), 'Löschung nicht geprüft');
$raceInput = ['name' => 'Zielwettkampf', 'type' => 'Run', 'start_date_local' => $later . 'T09:00:00', 'priority' => 'A', 'distance' => 42195];
foreach (['A', 'B', 'C'] as $priority) {
    $race = call_tool('create_calendar_race', ['race' => (object) array_replace($raceInput, ['priority' => $priority])], $client);
    ensure($race['event']['category'] === 'RACE_' . $priority && $race['event']['distance'] === 42195, 'Wettkampf-Priorität oder Distanz falsch');
    $raceId = (string) $race['event']['id'];
    fails(fn() => call_tool('delete_calendar_workout', ['event_id' => $raceId, 'expected_revision' => $race['revision']], $client));
    fails(fn() => call_tool('update_calendar_workout', ['event_id' => $raceId, 'expected_revision' => $race['revision'], 'changes' => ['name' => 'Nicht beauftragt']], $client));
}
foreach ([['priority' => 'D'], ['priority' => null], ['athlete_id' => 'i202'], ['category' => 'NOTE'], ['start_date_local' => '2000-01-01T00:00:00']] as $override) {
    fails(fn() => call_tool('create_calendar_race', ['race' => array_replace($raceInput, $override)], $client));
}
$missingPriority = $raceInput;
unset($missingPriority['priority']);
fails(fn() => call_tool('create_calendar_race', ['race' => $missingPriority], $client));
fails(fn() => call_tool('create_calendar_race', ['race' => $raceInput], $readOnly));
ensure($readOnly->calls === [], 'Lesezugang legt Wettkampf an');
$raceDefinition = array_column(tool_definitions(true), null, 'name')['create_calendar_race'];
ensure($raceDefinition['annotations']['readOnlyHint'] === false && $raceDefinition['annotations']['destructiveHint'] === false && $raceDefinition['annotations']['idempotentHint'] === false, 'Falsche Wettkampf-Annotationen');
$client->failReadback = true;
$postsBefore = count(array_filter($client->calls, fn($c) => $c[0] === 'POST'));
fails(fn() => call_tool('create_calendar_workout', ['workout' => $workout], $client));
ensure(count(array_filter($client->calls, fn($c) => $c[0] === 'POST')) === $postsBefore + 1, 'Anlage wird nach Fehler automatisch wiederholt');
echo "OK Kalender-Anlage, Teiländerung, Verschieben, Revision, Kontosperren, Validierung, Rechte und verifiziertes Löschen\n";
