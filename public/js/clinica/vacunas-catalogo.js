/*
 * Catálogo de vacunas del médico (`/clinica/catalogos/vacunas`, WEB-7.4.4e): abre solos los diálogos que la pantalla pide con
 * `data-abrir-al-cargar="1"` (el de la vacuna con `?nuevo=1`, `?editar=` o un error de validación; el de las dosis con `?dosis_de=`).
 * La vista no lleva JS inline (Docs/Web/guia-arquitectura-web.md). «Cancelar» del alta lo cierra con `data-cerrar-dialogo` (`shell.js`); los
 * demás cierres son enlaces que vuelven a la lista, así que la URL deja de pedir el diálogo.
 */
(function () {
    'use strict';

    document.querySelectorAll('dialog[data-abrir-al-cargar="1"]').forEach(function (dialogo) {
        if (typeof dialogo.showModal !== 'function' || dialogo.open) return;

        dialogo.showModal();
        const primero = dialogo.querySelector('[data-foco-inicial]');
        if (primero) primero.focus();
    });
})();
