<?php

/** Сохранить объёмы грузов одного рейса. Пустое поле сохраняется как NULL. */
function cargo_update_volumes($trip_id, $volumes)
{
    $trip_id = filter_var($trip_id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
    if ($trip_id === false || !is_array($volumes) || !$volumes) {
        throw new InvalidArgumentException('Укажите рейс и объёмы грузов.');
    }

    $db = db();
    $st = $db->prepare('SELECT id FROM cargo WHERE trip_id = ?');
    $st->execute(array($trip_id));
    $cargo_ids = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
    $values = array();
    foreach ($volumes as $cargo_id => $raw_volume) {
        if (!isset($cargo_ids[$cargo_id])) {
            throw new InvalidArgumentException('Груз не принадлежит указанному рейсу.');
        }
        if (!is_string($raw_volume)) {
            throw new InvalidArgumentException('Объём должен быть неотрицательным числом.');
        }
        $volume = str_replace(',', '.', trim($raw_volume));
        if ($volume === '') {
            $values[$cargo_id] = null;
            continue;
        }
        if (!preg_match('/\A(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)\z/', $volume) || !is_finite((float)$volume)) {
            throw new InvalidArgumentException('Объём должен быть неотрицательным числом.');
        }
        $values[$cargo_id] = (float)$volume;
    }

    $db->beginTransaction();
    try {
        $st = $db->prepare('UPDATE cargo SET volume_m3 = ? WHERE id = ? AND trip_id = ?');
        foreach ($values as $cargo_id => $volume) {
            $st->execute(array($volume, $cargo_id, $trip_id));
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return array('trip_id' => $trip_id, 'saved' => count($values));
}
