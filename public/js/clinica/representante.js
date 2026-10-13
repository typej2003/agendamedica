/*
 * Representante del paciente en la ficha (`/clinica/pacientes/{paciente}`, WEB-3.4): abre el diálogo donde
 * se carga o se corrige. La vista no lleva JS inline (Docs/Web/guia-arquitectura-web.md): lo que declara es
 * el botón con `data-dialogo="dialogo-representante"` en el bloque y el `<x-ui.dialogo id="dialogo-representante">`.
 *
 * Si la validación falló, la página lo abre sola al cargar: los errores se muestran arriba
 * (`<x-ui.errores />`) y también debajo de cada campo, pero el campo está dentro del diálogo, así que sin
 * esto el aviso quedaría detrás de una ventana cerrada. El diálogo marca ese caso con
 * `data-abrir-al-cargar` (mismo patrón que el cambio de cédula, `citas-paciente.js`).
 */
(function () {
    'use strict';

    const dialogo = document.getElementById('dialogo-representante');
    if (!dialogo) return;

    const abrir = function () {
        dialogo.showModal();
        const nombre = document.getElementById('representante-nombre');
        if (nombre) nombre.focus();
    };

    document.querySelectorAll('[data-dialogo="dialogo-representante"]').forEach(function (boton) {
        boton.addEventListener('click', abrir);
    });

    if (dialogo.dataset.abrirAlCargar === '1') abrir();
})();
