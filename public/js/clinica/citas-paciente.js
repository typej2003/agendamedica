/*
 * "Citas del paciente" (`/clinica/pacientes/{paciente}/citas`, WEB-2.15): abre el diálogo del cambio de
 * cédula. La vista no lleva JS inline (Docs/Web/guia-arquitectura-web.md); lo que declara es el botón
 * `#abrir-cedula` y el diálogo `#dialogo-cedula`.
 *
 * Si la validación del cambio falló, la página lo abre sola al cargar: el error se muestra arriba
 * (`<x-ui.errores />`) pero el campo está dentro del diálogo, y sin esto el aviso quedaría detrás de una
 * ventana cerrada. El diálogo marca ese caso con `data-abrir-al-cargar`.
 */
(function () {
    'use strict';

    const dialogo = document.getElementById('dialogo-cedula');
    if (!dialogo) return;

    const abrir = function () {
        dialogo.showModal();
        const cedula = document.getElementById('cedula');
        if (cedula) cedula.focus();
    };

    const boton = document.getElementById('abrir-cedula');
    if (boton) boton.addEventListener('click', abrir);

    if (dialogo.dataset.abrirAlCargar === '1') abrir();
})();
