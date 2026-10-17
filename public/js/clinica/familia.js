/*
 * Familia del niño en la ficha (`/clinica/pacientes/{paciente}`, WEB-7.4.2b): abre el diálogo donde se cargan o se corrigen los padres y los
 * teléfonos. La vista no lleva JS inline (Docs/Web/guia-arquitectura-web.md): declara el botón con `data-dialogo="dialogo-familia"` y el
 * `<x-ui.dialogo id="dialogo-familia">`. Si la validación falló, el diálogo marca `data-abrir-al-cargar="1"` y se abre solo para que los
 * errores no queden detrás de una ventana cerrada (mismo patrón que `representante.js`).
 */
(function () {
    'use strict';

    const dialogo = document.getElementById('dialogo-familia');
    if (!dialogo) return;

    const abrir = function () {
        dialogo.showModal();
        const madre = document.getElementById('familia-nom_madre');
        if (madre) madre.focus();
    };

    document.querySelectorAll('[data-dialogo="dialogo-familia"]').forEach(function (boton) {
        boton.addEventListener('click', abrir);
    });

    if (dialogo.dataset.abrirAlCargar === '1') abrir();
})();
