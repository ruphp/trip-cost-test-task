$(function () {
    var form = $('#billing-preview-form');
    var periodInput = $('#billing-period');
    var apply = $('#billing-apply');
    var table = $('#billing-preview');
    var message = $('#billing-message');
    var previewPeriod = null;

    function updatePeriod() {
        previewPeriod = null;
        apply.prop('disabled', true);
        table.prop('hidden', true);
        message.text('');
        $('#billing-invoices-link').attr('href', 'invoices.php?period=' + encodeURIComponent(periodInput.val()));
        var count = 0;
        $('#billing-volume-warning li').each(function () {
            var matches = $(this).data('period') === periodInput.val();
            $(this).prop('hidden', !matches);
            if (matches) count++;
        });
        $('#billing-volume-warning').prop('hidden', count === 0);
    }

    function busy(value) {
        form.find('input, button').prop('disabled', value);
        apply.prop('disabled', value || previewPeriod === null);
    }

    function fail(xhr) {
        previewPeriod = null;
        apply.prop('disabled', true);
        table.prop('hidden', true);
        message.text(xhr.responseJSON && xhr.responseJSON.error
            ? xhr.responseJSON.error : 'Не удалось выполнить запрос. Попробуйте ещё раз.');
    }

    function render(data) {
        var body = table.find('tbody').empty();
        $.each(data.clients, function (_, client) {
            var row = $('<tr>').append($('<td>').text(client.name));
            var invoice = $('<td>');
            if (client.invoice_id !== null) {
                $('<a>').attr('href', 'invoice.php?id=' + client.invoice_id)
                    .text('№ ' + client.invoice_id).appendTo(invoice);
            } else {
                invoice.text('Будет создан');
            }
            row.append(invoice);
            $.each([client.before, client.after, client.diff], function (_, amount) {
                $('<td>', { 'class': 'r' }).text(money(amount)).appendTo(row);
            });
            body.append(row);
        });
        if (!data.clients.length) {
            body.append($('<tr>').append($('<td>', { colspan: 5, 'class': 'empty' }).text('Счетов и грузов за месяц нет')));
        }
        body.append($('<tr>', { 'class': 'total' })
            .append($('<td>', { colspan: 2 }).text('Итого'))
            .append($('<td>', { 'class': 'r' }).text(money(data.total_before)))
            .append($('<td>', { 'class': 'r' }).text(money(data.total_after)))
            .append($('<td>', { 'class': 'r' }).text(money((Math.round(Number(data.total_after) * 100)
                - Math.round(Number(data.total_before) * 100)) / 100))));
        table.prop('hidden', false);
    }

    periodInput.on('input change', updatePeriod);
    form.on('submit', function (event) {
        event.preventDefault();
        var period = periodInput.val();
        updatePeriod();
        busy(true);
        message.text('Загрузка предпросмотра...');
        $.getJSON('api/billing_preview.php', { period: period })
            .done(function (data) {
                render(data);
                previewPeriod = data.clients.length ? period : null;
                message.text('Предпросмотр готов. Для сохранения нажмите «Применить пересчёт».');
            })
            .fail(fail)
            .always(function () { busy(false); });
    });

    apply.on('click', function () {
        if (previewPeriod === null || previewPeriod !== periodInput.val()) return;
        if (!window.confirm('Применить пересчёт счетов за ' + previewPeriod + ' по объёму?')) return;
        busy(true);
        message.text('Применение пересчёта...');
        $.ajax({ url: 'api/billing_apply.php', method: 'POST', dataType: 'json', data: { period: previewPeriod } })
            .done(function (data) {
                render(data);
                previewPeriod = null;
                message.text('Пересчёт применён. Создано: ' + data.created + ', обновлено: '
                    + data.updated + ', без изменений: ' + data.unchanged + '.');
            })
            .fail(fail)
            .always(function () { busy(false); });
    });

    updatePeriod();
    form.trigger('submit');
});
