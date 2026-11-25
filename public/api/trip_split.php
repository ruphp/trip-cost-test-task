<?php
require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_out(array('error' => 'Поддерживается только метод GET.'), 405);
}

$trip_id = filter_var($_GET['trip_id'] ?? null, FILTER_VALIDATE_INT, array(
    'options' => array('min_range' => 1),
));
if ($trip_id === false) {
    json_out(array('error' => 'Укажите положительное целое trip_id.'), 400);
}

try {
    $clients = calc_trip_split($trip_id);
} catch (DomainException $e) {
    json_out(array('error' => $e->getMessage()), 422);
}

if ($clients === null) {
    json_out(array('error' => 'Рейс не найден.'), 404);
}

$st = db()->prepare('SELECT number, cost FROM trips WHERE id = ?');
$st->execute(array($trip_id));
$trip = $st->fetch();
foreach ($clients as &$client) {
    $client['amount'] = number_format($client['amount'], 2, '.', '');
}
unset($client);

json_out(array(
    'trip_id' => $trip_id,
    'number' => $trip['number'],
    'cost' => number_format($trip['cost'], 2, '.', ''),
    'clients' => array_values($clients),
));
