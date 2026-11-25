<?php
require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_out(array('error' => 'Поддерживается только метод POST.'), 405);
}

try {
    $result = billing_apply_period($_POST['period'] ?? null);
} catch (InvalidArgumentException $e) {
    json_out(array('error' => $e->getMessage()), 400);
} catch (DomainException $e) {
    json_out(array('error' => $e->getMessage()), 422);
} catch (Throwable $e) {
    error_log((string)$e);
    json_out(array('error' => 'Не удалось применить пересчёт счетов.'), 500);
}

json_out($result);
