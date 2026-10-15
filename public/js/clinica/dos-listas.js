// «Dos listas» (WEB-11.3): el doble clic en una fila del catálogo (`[data-pasar]`) acciona su botón «Pasar»
// (`[data-pasar-boton]`), como el doble clic del escritorio. Sin JavaScript el botón sigue funcionando.
(function () {
    'use strict';

    document.addEventListener('dblclick', function (evento) {
        var fila = evento.target.closest('[data-pasar]');
        if (!fila || evento.target.closest('button, a, input, select, textarea')) { return; }

        var boton = fila.querySelector('[data-pasar-boton]');
        if (boton && !boton.disabled) { boton.click(); }
    });
})();
