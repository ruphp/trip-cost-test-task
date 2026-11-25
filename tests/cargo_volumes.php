<?php
// Run: php tests/cargo_volumes.php. Does not use the application database.
require __DIR__ . '/../lib/cargo.php';

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(file_get_contents(__DIR__ . '/../data/schema.sql'));
function db() { global $database; return $database; }
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
function snapshot() { return db()->query('SELECT * FROM cargo ORDER BY id')->fetchAll(); }

$database->exec("INSERT INTO clients (id, name) VALUES (1, 'A')");
$database->exec("INSERT INTO trips (id, number, departure_date, cost, status) VALUES
    (1, 'T1', '2026-10-01', 100, 'delivered'), (2, 'T2', '2026-10-01', 100, 'delivered')");
$database->exec('INSERT INTO cargo (id, trip_id, client_id, weight_kg, volume_m3) VALUES
    (1, 1, 1, 10, NULL), (2, 1, 1, 20, 2), (3, 2, 1, 30, 3)');

$result = cargo_update_volumes('1', array(1 => '1,25', 2 => '0'));
check($result === array('trip_id' => 1, 'saved' => 2), 'Save result');
$rows = snapshot();
check($rows[0]['volume_m3'] == 1.25 && $rows[1]['volume_m3'] == 0, 'Decimal and zero');
check($rows[2]['volume_m3'] == 3 && $rows[0]['weight_kg'] == 10, 'Other data changed');
cargo_update_volumes(1, array(1 => ''));
check(snapshot()[0]['volume_m3'] === null, 'Blank value should remain unfilled');

foreach (array(array(1 => '5', 3 => '7'), array(1 => '-1'), array(1 => 'abc'), array(1 => array()),
    array(1 => '1e5'), array(), array(99 => '1')) as $invalid) {
    $before = snapshot();
    try {
        cargo_update_volumes(1, $invalid);
        throw new RuntimeException('Invalid input accepted');
    } catch (InvalidArgumentException $e) {
        check(snapshot() === $before, 'Invalid input caused a partial update');
    }
}

$before = snapshot();
$database->exec("CREATE TRIGGER fail_volume BEFORE UPDATE ON cargo WHEN NEW.id = 2
    BEGIN SELECT RAISE(ABORT, 'test failure'); END");
try {
    cargo_update_volumes(1, array(1 => '8', 2 => '9'));
    throw new RuntimeException('Expected update failure');
} catch (PDOException $e) {
    check(snapshot() === $before && !$database->inTransaction(), 'Failed update was not rolled back');
}
echo "OK: volume save, decimals, blanks, nonnegative validation, trip ownership, rollback\n";
