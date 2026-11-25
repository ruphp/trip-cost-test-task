<?php
require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_out(array('error' => 'Поддерживается только метод POST.'), 405);
}

try {
    $result = cargo_update_volumes($_POST['trip_id'] ?? null, $_POST['volumes'] ?? null);
} catch (InvalidArgumentException $e) {
    json_out(array('error' => $e->getMessage()), 400);
} catch (Throwable $e) {
    error_log((string)$e);
    json_out(array('error' => 'Не удалось сохранить объёмы грузов.'), 500);
}

json_out($result);
