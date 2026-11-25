<?php
// Run: php tests/billing_period.php. Uses only an in-memory database.
require __DIR__ . '/../lib/billing.php';

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(file_get_contents(__DIR__ . '/../data/schema.sql'));

function db()
{
    global $database;
    return $database;
}

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function snapshot()
{
    return array(
        db()->query('SELECT * FROM invoices ORDER BY id')->fetchAll(),
        db()->query('SELECT * FROM invoice_lines ORDER BY id')->fetchAll(),
    );
}

$database->exec("INSERT INTO clients (id, name) VALUES (1, 'A'), (2, 'B'), (3, 'C'), (4, 'D')");
$database->exec("INSERT INTO trips (id, number, departure_date, cost, status) VALUES
    (1, 'T1', '2026-10-01', 100.01, 'delivered'),
    (2, 'T2', '2026-10-31 23:59:59', 50, 'planned'),
    (3, 'T3', '2026-10-10', 99, 'cancelled'),
    (4, 'T4', '2026-11-01', 20, 'delivered'),
    (5, 'T5', '2026-10-02', 200, 'planned')");
$database->exec("INSERT INTO cargo (trip_id, client_id, weight_kg, volume_m3) VALUES
    (1, 1, 100, 1), (1, 2, 1, 2), (2, 2, 100, 1), (2, 3, 1, 1),
    (3, 4, 1, 0), (4, 1, 1, 1)");
$database->exec("INSERT INTO invoices (id, client_id, period, total, status, created_at, sent_at, exported_at) VALUES
    (1, 1, '2026-10', 90, 'sent', 'created', 'sent', 'exported'),
    (2, 2, '2026-10', 60, 'draft', 'created', NULL, NULL),
    (3, 4, '2026-10', 99, 'draft', 'created', NULL, NULL),
    (4, 1, '2026-11', 20, 'draft', 'created', NULL, NULL)");
$database->exec('INSERT INTO invoice_lines (invoice_id, trip_id, amount) VALUES
    (1, 1, 90), (2, 1, 10), (2, 2, 50), (3, 3, 99), (4, 4, 20)');

$before = snapshot();
$preview = billing_preview_period('2026-10');
check(snapshot() === $before, 'Preview changed the database');
check($preview['total_before'] === '249.00' && $preview['total_after'] === '150.01', 'Preview totals');
check(array_column($preview['clients'], 'after') === array('33.34', '91.67', '25.00', '0.00'), 'Volume allocation');
check($preview['clients'][2]['invoice_id'] === null, 'Missing invoice must be null');
check($preview['clients'][0]['diff'] === '-56.66', 'Signed difference');

// Fail after earlier clients have already been updated to verify a real rollback.
$database->exec("CREATE TRIGGER fail_line BEFORE INSERT ON invoice_lines
    WHEN (SELECT client_id FROM invoices WHERE id = NEW.invoice_id) = 3
    BEGIN SELECT RAISE(ABORT, 'test failure'); END");
try {
    billing_apply_period('2026-10');
    throw new RuntimeException('Expected insert failure');
} catch (PDOException $e) {
    check(snapshot() === $before && !$database->inTransaction(), 'Partial writes after failure');
}
$database->exec('DROP TRIGGER fail_line');

$applied = billing_apply_period('2026-10');
check($applied['created'] === 1 && $applied['updated'] === 3 && $applied['unchanged'] === 0, 'Apply counts');
check(array_column($applied['clients'], 'after') === array_column($preview['clients'], 'after'), 'Preview/apply mismatch');
check($applied['clients'][2]['invoice_id'] !== null, 'Created invoice ID missing');
check($database->query('SELECT COUNT(*) FROM invoice_lines WHERE invoice_id = 3')->fetchColumn() == 0, 'Stale lines');
$invoice = $database->query('SELECT * FROM invoices WHERE id = 1')->fetch();
check($invoice['status'] === 'sent' && $invoice['sent_at'] === 'sent' && $invoice['exported_at'] === 'exported'
    && $invoice['created_at'] === 'created', 'Invoice metadata changed');
check($database->query('SELECT * FROM invoices WHERE id = 4')->fetch() === $before[0][3], 'Other period changed');
check($database->query('SELECT * FROM invoice_lines WHERE invoice_id = 4')->fetchAll() === array($before[1][4]), 'Other period lines changed');

$after = snapshot();
$repeat = billing_apply_period('2026-10');
check($repeat['created'] === 0 && $repeat['updated'] === 0 && $repeat['unchanged'] === 4, 'Repeat counts');
check(snapshot() === $after, 'Repeated apply changed data or line IDs');
check(billing_preview_period('2026-10')['total_before'] === '150.01', 'Preview after apply');

// Matching totals are insufficient: changed lines must also be detected.
$database->exec('UPDATE invoice_lines SET amount = amount + 1 WHERE invoice_id = 2 AND trip_id = 1');
$database->exec('UPDATE invoice_lines SET amount = amount - 1 WHERE invoice_id = 2 AND trip_id = 2');
check(billing_apply_period('2026-10')['updated'] === 1, 'Changed lines with matching total were ignored');

foreach (array(null, array(), '', '2026-00', '2026-13', '2026-1', '0000-01', '2026-10x') as $invalid) {
    try {
        billing_preview_period($invalid);
        throw new RuntimeException('Invalid period accepted');
    } catch (InvalidArgumentException $e) {
        // Expected.
    }
}
check(billing_preview_period('2027-01')['clients'] === array(), 'Empty period');
check(billing_apply_period('2027-01')['created'] === 0, 'Empty period created invoices');

$before = snapshot();
$database->exec('UPDATE cargo SET volume_m3 = 0 WHERE trip_id = 1');
try {
    billing_apply_period('2026-10');
    throw new RuntimeException('Zero volume accepted');
} catch (DomainException $e) {
    check(snapshot() === $before, 'Invalid volume changed invoices');
}

echo "OK: preview, volume split, cents, create/update/clear, metadata, period isolation, idempotency, rollback, validation\n";
