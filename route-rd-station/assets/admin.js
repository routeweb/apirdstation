(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var addButton = document.getElementById('route-rd-add-mapping');
        var table = document.querySelector('.route-rd-mapping tbody');

        if (!addButton || !table) {
            return;
        }

        addButton.addEventListener('click', function () {
            var index = table.querySelectorAll('tr').length + Date.now();
            var row = document.createElement('tr');
            row.innerHTML =
                '<td><input type="text" name="route_rd_settings[field_mappings][' + index + '][source]" value=""></td>' +
                '<td><input type="text" name="route_rd_settings[field_mappings][' + index + '][target]" value=""></td>' +
                '<td><button type="button" class="button route-rd-remove-mapping">Remover</button></td>';
            table.appendChild(row);
        });

        table.addEventListener('click', function (event) {
            if (event.target.classList.contains('route-rd-remove-mapping')) {
                event.target.closest('tr').remove();
            }
        });
    });
})();
