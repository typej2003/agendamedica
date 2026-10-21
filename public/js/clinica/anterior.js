/*
 * La casilla «Anterior» de la consulta (WEB-11.4; casilla como la del escritorio, pedido de Alexander del 2026-10-20). La vista
 * (`<x-ui.anterior>`) declara lo que quiere con `data-*` y este archivo lo resuelve. La casilla dice «lo de la consulta anterior está
 * en ésta»: marcarla lo trae sin repetir y desmarcarla saca lo que coincide con la anterior.
 *
 *  - `[data-anterior-lista]` (motivos, diagnósticos: guardan al instante): al cambiar la casilla se envía su formulario. Antes de
 *    quitar, pregunta (`data-confirmar-quitar`); si el médico cancela, la casilla vuelve a quedar marcada.
 *  - `[data-anterior-texto]` (enfermedad actual, tratamiento): pone el texto de la anterior en el campo `data-destino`. Con el campo
 *    vacío lo escribe; con texto, pregunta y lo **agrega al final** (nunca reemplaza lo escrito). Desmarcar saca ese texto del campo.
 *    El cambio dispara `input`, así que se guarda con el autoguardado de la sección (`consulta-barra.js`).
 *
 * La del editor de récipes (`[data-anterior-recipe]`) la resuelve `consulta-recipes.js`, que es quien maneja las filas del récipe.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-anterior-lista]').forEach(function (formulario) {
        var casilla = formulario.querySelector('input[type="checkbox"]');
        if (!casilla) { return; }

        casilla.addEventListener('change', function () {
            if (!casilla.checked && !window.confirm(formulario.dataset.confirmarQuitar)) {
                casilla.checked = true;
                return;
            }
            formulario.submit();
        });
    });

    document.querySelectorAll('[data-anterior-texto]').forEach(function (casilla) {
        var campo = document.querySelector(casilla.dataset.destino || '');
        var texto = casilla.dataset.texto || '';
        if (!campo || texto === '') { return; }

        function contiene() { return campo.value.indexOf(texto) >= 0; }

        casilla.checked = contiene();
        campo.addEventListener('input', function () { casilla.checked = contiene(); });

        casilla.addEventListener('change', function () {
            var valor = campo.value;

            if (casilla.checked) {
                if (contiene()) { return; }
                if (valor.trim() === '') {
                    valor = texto;
                } else if (window.confirm('El campo ya tiene texto. ¿Agregar al final el de la consulta ' + casilla.dataset.deCual + '?')) {
                    valor = valor.replace(/\s+$/, '') + '\n\n' + texto;
                } else {
                    casilla.checked = false;
                    return;
                }
            } else {
                var desde = valor.indexOf(texto);
                if (desde < 0) { return; }
                var antes = valor.slice(0, desde).replace(/\s+$/, '');
                var despues = valor.slice(desde + texto.length).replace(/^\s+/, '');
                valor = antes + (antes !== '' && despues !== '' ? '\n\n' : '') + despues;
            }

            campo.value = valor;
            campo.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
})();
