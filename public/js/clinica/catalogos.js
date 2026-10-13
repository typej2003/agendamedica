/*
 * Los catálogos del médico (`/clinica/catalogos/...`, WEB-8.1a): abre el diálogo del medicamento. La vista no lleva
 * JS inline (Docs/Web/guia-arquitectura-web.md): el diálogo declara `data-abrir-al-cargar="1"` cuando la pantalla
 * llegó para editar un medicamento (`?editar=`), para crear uno (`?nuevo=1`) o con un error de validación. El
 * aviso de ese error está arriba (`<x-ui.errores />`) y debajo de cada campo, pero el campo está dentro del
 * diálogo: sin esto el mensaje quedaría detrás de una ventana cerrada (mismo patrón que el representante del
 * paciente, `representante.js`).
 *
 * Cerrar el diálogo con Escape o con «Cancelar» sin guardar deja la pantalla como está: la lista no cambió.
 */
(function () {
    'use strict';

    // WEB-8.1b: los catálogos nuevos marcan su diálogo con `data-catalogo-dialogo` y su campo inicial con
    // `data-foco-inicial`; el de medicamentos conserva su id.
    const dialogo = document.querySelector('dialog[data-catalogo-dialogo]') || document.getElementById('dialogo-medicamento');
    if (!dialogo) return;

    const abrir = function () {
        dialogo.showModal();
        const primero = dialogo.querySelector('[data-foco-inicial]') || document.getElementById('medicamento-comercial');
        if (primero) primero.focus();
    };

    document.querySelectorAll('[data-dialogo="' + dialogo.id + '"]').forEach(function (boton) {
        boton.addEventListener('click', abrir);
    });

    if (dialogo.dataset.abrirAlCargar === '1') abrir();
})();
