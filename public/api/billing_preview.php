<?php
require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_out(array('error' => 'Поддерживается только метод GET.'), 405);
}

try {
    $result = billing_preview_period($_GET['period'] ?? null);
} catch (InvalidArgumentException $e) {
    json_out(array('error' => $e->getMessage()), 400);
} catch (DomainException $e) {
    json_out(array('error' => $e->getMessage()), 422);
} catch (Throwable $e) {
    error_log((string)$e);
    json_out(array('error' => 'Не удалось рассчитать счета за месяц.'), 500);
}

json_out($result);
