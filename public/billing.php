<?php
require __DIR__ . '/../lib/bootstrap.php';

$default_period = db()->query("SELECT MAX(period) FROM (
    SELECT substr(departure_date, 1, 7) AS period FROM trips
    UNION SELECT period FROM invoices
)")->fetchColumn() ?: date('Y-m');
$period = $_GET['period'] ?? $default_period;
if (!is_string($period) || !preg_match('/\A(?!0000)[0-9]{4}-(0[1-9]|1[0-2])\z/', $period)) {
    $period = $default_period;
}
$check_trips = db()->query("SELECT t.id, t.number, substr(t.departure_date, 1, 7) AS period
    FROM trips t JOIN cargo c ON c.trip_id = t.id
    WHERE t.status <> 'cancelled'
    GROUP BY t.id
    HAVING COUNT(*) > COUNT(c.volume_m3) OR COALESCE(SUM(c.volume_m3), 0) <= 0
    ORDER BY t.departure_date, t.id")->fetchAll();

page_header('Пересчёт счетов');
?>
<h1>Пересчёт счетов по объёму</h1>
<form class="actions" id="billing-preview-form">
    <label for="billing-period">Месяц:</label>
    <input type="month" id="billing-period" name="period" value="<?= h($period) ?>" required>
    <button type="submit">Предпросмотр счетов</button>
</form>
<div id="billing-volume-warning" class="msg" hidden>
    <p>Проверьте объёмы грузов перед пересчётом. Откройте рейс, заполните поля и сохраните объёмы:</p>
    <ul>
        <?php foreach ($check_trips as $check_trip): ?>
            <li data-period="<?= h($check_trip['period']) ?>">
                <a href="trip.php?id=<?= (int)$check_trip['id'] ?>">Рейс <?= h($check_trip['number']) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<p id="billing-message" role="status" aria-live="polite"></p>
<table class="grid" id="billing-preview" hidden>
    <thead>
    <tr>
        <th>Клиент</th>
        <th>Счёт</th>
        <th class="r">Было, ₽</th>
        <th class="r">Стало, ₽</th>
        <th class="r">Разница, ₽</th>
    </tr>
    </thead>
    <tbody></tbody>
</table>
<p class="actions">
    <button type="button" id="billing-apply" disabled>Применить пересчёт</button>
    <a id="billing-invoices-link" href="invoices.php?period=<?= h($period) ?>">Счета за месяц</a>
</p>
<noscript><p>Для предпросмотра и применения пересчёта включите JavaScript.</p></noscript>
<script src="js/billing.js"></script>
<?php page_footer();
