$(function () {
    var table = $('#trip-split');
    var body = table.find('tbody');

    function showMessage(message) {
        body.empty().append(
            $('<tr>').append($('<td>', { colspan: 5, 'class': 'empty' }).text(message))
        );
    }

    function loadSplit() {
        return $.getJSON('api/trip_split.php', { trip_id: table.data('trip-id') })
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
                .append($('<td>', { colspan: 4 }).text('Итого по клиентам'))
                .append($('<td>', { 'class': 'r' }).text(money(totalCents / 100))));
            body.append($('<tr>', { 'class': 'total' })
                .append($('<td>', { colspan: 4 }).text('Стоимость рейса'))
                .append($('<td>', { 'class': 'r' }).text(money(data.cost))));
        })
        .fail(function (xhr) {
            showMessage(xhr.responseJSON && xhr.responseJSON.error
                ? xhr.responseJSON.error
                : 'Не удалось загрузить распределение стоимости. Обновите страницу.');
        });
    }

    $('#cargo-volumes').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        var data = form.serialize();
        var message = $('#cargo-volume-message');
        form.find('input, button').prop('disabled', true);
        message.text('Сохранение объёмов...');
        $.ajax({ url: form.attr('action'), method: 'POST', dataType: 'json', data: data })
            .done(function () {
                message.text('Объёмы сохранены. Проверьте и примените пересчёт счетов за месяц.');
                showMessage('Загрузка распределения...');
                loadSplit();
            })
            .fail(function (xhr) {
                message.text(xhr.responseJSON && xhr.responseJSON.error
                    ? xhr.responseJSON.error : 'Не удалось сохранить объёмы. Попробуйте ещё раз.');
            })
            .always(function () { form.find('input, button').prop('disabled', false); });
    });

    loadSplit();
});
