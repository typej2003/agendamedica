/*
 * Calendario de vacunas del niño (`/clinica/pacientes/{paciente}/vacunas`, WEB-7.4.4b): el botón «Registrar» de cada dosis llena y abre el diálogo
 * de registro. La vista no lleva JS inline: el botón declara la dosis con `data-registrar-dosis` y sus `data-*` (codigo, numero, rotulo, planeada,
 * nombre) y el diálogo es `<x-ui.dialogo id="dialogo-registrar-dosis">`. «Cancelar» lo cierra con `data-cerrar-dialogo` (`shell.js`).
 */
(function () {
    'use strict';

    const dialogo = document.getElementById('dialogo-registrar-dosis');
    if (!dialogo) return;

    document.querySelectorAll('[data-registrar-dosis]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            ['codigo', 'numero', 'rotulo', 'planeada'].forEach(function (campo) {
                const entrada = dialogo.querySelector('[data-dosis-campo="' + campo + '"]');
                if (entrada) entrada.value = boton.dataset[campo] || '';
            });

            const titulo = dialogo.querySelector('[data-dosis-titulo]');
            if (titulo) titulo.textContent = (boton.dataset.nombre || '') + ' · ' + (boton.dataset.rotulo || '');

            dialogo.showModal();
            const fecha = document.getElementById('dosis-fecha');
            if (fecha) fecha.focus();
        });
    });
})();
