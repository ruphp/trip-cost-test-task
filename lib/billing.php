<?php

/**
 * Доли клиентов в стоимости рейса.
 *
 * Стоимость рейса делится между клиентами пропорционально весу их груза.
 * Возвращает [client_id => [name, weight_kg, share, amount]], share: доля от 0 до 1.
 */
function calc_share($trip_id)
{
    $trip_id = (int)$trip_id;
    $trip = db()->query("SELECT cost FROM trips WHERE id = $trip_id")->fetch();
    $rows = db()->query("SELECT c.client_id, cl.name, SUM(c.weight_kg) AS weight_kg
        FROM cargo c
        JOIN clients cl ON cl.id = c.client_id
        WHERE c.trip_id = $trip_id
        GROUP BY c.client_id, cl.name")->fetchAll();

    $total_weight = 0;
    foreach ($rows as $row) {
        $total_weight += $row['weight_kg'];
    }

    $result = array();
    foreach ($rows as $row) {
        $share = $total_weight > 0 ? $row['weight_kg'] / $total_weight : 0;
        $result[$row['client_id']] = array(
            'name' => $row['name'],
            'weight_kg' => $row['weight_kg'],
            'share' => $share,
            'amount' => round($trip['cost'] * $share, 2),
        );
    }

    return $result;
}

/**
 * Распределить стоимость рейса пропорционально объёму грузов клиентов.
 *
 * Возвращает [client_id => [client_id, name, cargo_count, weight_kg, volume_m3, share, amount]].
 * share - доля от 0 до 1. Суммы распределяются в копейках методом наибольшего остатка.
 * При равных остатках дополнительную копейку получает клиент с меньшим client_id.
 * Возвращает null, если рейс не найден.
 * При нулевом общем объёме или отрицательной стоимости/объёме выбрасывает DomainException.
 */
function calc_trip_split($trip_id)
{
    $trip_id = (int)$trip_id;
    $trip = db()->query("SELECT cost FROM trips WHERE id = $trip_id")->fetch();
    if (!$trip) {
        return null;
    }

    $rows = db()->query("SELECT c.client_id, cl.name, COUNT(*) AS cargo_count,
            COALESCE(SUM(c.volume_m3), 0) AS volume_m3,
            MIN(c.volume_m3) AS min_volume_m3
        FROM cargo c
        JOIN clients cl ON cl.id = c.client_id
        WHERE c.trip_id = $trip_id
        GROUP BY c.client_id, cl.name
        ORDER BY c.client_id")->fetchAll();

    if ($trip['cost'] < 0) {
        throw new DomainException('Стоимость рейса не может быть отрицательной.');
    }

    $total_volume = 0;
    foreach ($rows as $row) {
        if ($row['min_volume_m3'] < 0) {
            throw new DomainException('Объём груза не может быть отрицательным.');
        }
        $total_volume += $row['volume_m3'];
    }
    if ($total_volume <= 0) {
        throw new DomainException('Для распределения стоимости нужен положительный общий объём.');
    }

    $total_cents = (int)round($trip['cost'] * 100);
    $remaining_cents = $total_cents;
    $result = array();
    $remainders = array();
    foreach ($rows as $row) {
        $client_id = (int)$row['client_id'];
        $share = $row['volume_m3'] / $total_volume;
        $exact_cents = $total_cents * $share;
        $amount_cents = (int)floor($exact_cents);
        $remaining_cents -= $amount_cents;
        $result[$client_id] = array(
            'client_id' => $client_id,
            'name' => $row['name'],
            'cargo_count' => (int)$row['cargo_count'],
            'volume_m3' => $row['volume_m3'],
            'share' => $share,
            'amount' => $amount_cents,
        );
        $remainders[] = array('client_id' => $client_id, 'remainder' => $exact_cents - $amount_cents);
    }

    usort($remainders, function ($a, $b) {
        $order = $b['remainder'] <=> $a['remainder'];
        return $order !== 0 ? $order : $a['client_id'] <=> $b['client_id'];
    });
    for ($i = 0; $i < $remaining_cents; $i++) {
        $result[$remainders[$i]['client_id']]['amount']++;
    }
    foreach ($result as &$client) {
        $client['amount'] /= 100;
    }
    unset($client);

    return $result;
}

/**
 * Сформировать счета клиентов за месяц.
 *
 * Для каждого клиента берутся рейсы месяца (по дате отправления, кроме отменённых) и его доля в каждом из них.
 * Если счёт за месяц уже есть, его строки пересчитываются заново.
 */
function billing_run_period($period)
{
    $db = db();
    $created = 0;
    $updated = 0;

    $db->beginTransaction();
    foreach (get_clients() as $client) {
        $trips = $db->query("SELECT DISTINCT t.id FROM trips t
            JOIN cargo c ON c.trip_id = t.id
            WHERE c.client_id = {$client['id']}
              AND t.departure_date LIKE '{$period}%'
              AND t.status <> 'cancelled'
            ORDER BY t.departure_date")->fetchAll(PDO::FETCH_COLUMN);
        if (!$trips) {
            continue;
        }

        $invoice = $db->query("SELECT * FROM invoices WHERE client_id = {$client['id']} AND period = '{$period}'")->fetch();
        if ($invoice) {
            $invoice_id = $invoice['id'];
            $db->exec("DELETE FROM invoice_lines WHERE invoice_id = $invoice_id");
            $updated++;
        } else {
            $db->exec("INSERT INTO invoices (client_id, period, status, total, created_at)
                VALUES ({$client['id']}, '{$period}', 'draft', 0, datetime('now', 'localtime'))");
            $invoice_id = $db->lastInsertId();
            $created++;
        }

        $total = 0;
        foreach ($trips as $trip_id) {
            $shares = calc_share($trip_id);
            $amount = $shares[$client['id']]['amount'];
            $db->exec("INSERT INTO invoice_lines (invoice_id, trip_id, amount) VALUES ($invoice_id, $trip_id, $amount)");
            $total += $amount;
        }
        $db->exec("UPDATE invoices SET total = $total WHERE id = $invoice_id");
    }
    $db->commit();

    return array('created' => $created, 'updated' => $updated);
}
