/*
 * «¿Atender ahora?» en la ficha del paciente (O-05, 2026-10-17).
 *
 * El diálogo lo pinta `clinica/pacientes/_dialogo-atender.blade.php` y la vista no lleva JS inline
 * (Docs/Web/guia-arquitectura-web.md). El único comportamiento propio es **abrirlo al cargar**: la ficha a la
 * que se llega es la que resultó del alta o de asignar la historia, y la pregunta tiene que estar delante.
 * El resto —cerrar con «Ahora no» y enviar el POST— lo resuelven `data-cerrar-dialogo` (shell.js) y el
 * formulario.
 *
 * Se abre sólo si el diálogo declara `data-abrir-al-cargar="1"`: cuando la ficha se mira en otro momento no
 * está en la página.
 */
(function () {
    'use strict';

    const dialogo = document.getElementById('dialogo-atender-paciente');
    if (!dialogo) return;

    if (dialogo.dataset.abrirAlCargar === '1' && typeof dialogo.showModal === 'function') {
        dialogo.showModal();
    }
})();
