$(function () {
    var table = $('#trip-split');
    var body = table.find('tbody');

    function showMessage(message) {
        body.empty().append(
            $('<tr>').append($('<td>', { colspan: 6, 'class': 'empty' }).text(message))
        );
    }

    $.getJSON('api/trip_split.php', { trip_id: table.data('trip-id') })
        .done(function (data) {
            if (!data.clients.length) {
                showMessage('Грузов нет');
                return;
            }

            var totalCents = 0;
            body.empty();
            $.each(data.clients, function (_, client) {
                var row = $('<tr>');
                $('<td>').text(client.name).appendTo(row);
                $.each([
                    num(client.cargo_count),
                    num(client.volume_m3),
                    num(client.share * 100),
                    money(client.amount)
                ], function (_, value) {
                    $('<td>', { 'class': 'r' }).text(value).appendTo(row);
                });
                body.append(row);
                totalCents += Math.round(client.amount * 100);
            });

            body.append($('<tr>', { 'class': 'total' })
                .append($('<td>', { colspan: 4 }).text('Итого'))
                .append($('<td>', { 'class': 'r' }).text(money(totalCents / 100))));
        })
        .fail(function (xhr) {
            showMessage(xhr.responseJSON && xhr.responseJSON.error
                ? xhr.responseJSON.error
                : 'Не удалось загрузить распределение стоимости. Обновите страницу.');
        });
});
