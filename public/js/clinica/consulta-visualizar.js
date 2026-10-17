// «Visualizar» (WEB-11.24): la vista previa de un documento de la consulta (solicitud, récipe o eco) en un diálogo, sin salir de la consulta.
//
//   data-visualizar="<url>"   en un botón: abre el diálogo `#dialogo-visualizar` con la hoja de impresión en un iframe (`?incrustado=1`).
//   data-titulo="…"           el título del diálogo.
//
// «Imprimir» imprime lo que se ve en el iframe (la hoja ya tiene su `@page`; «Incluir imágenes» de una eco decide si sale la hoja de
// imágenes). «Modificar» cierra el diálogo y vuelve al formulario. Si el médico guardó algo antes de abrir, la vista previa lo refleja:
// se pide al abrir, no se guarda en caché.
(function () {
    'use strict';

    var dialogo = document.getElementById('dialogo-visualizar');
    if (!dialogo) { return; }

    var marco = dialogo.querySelector('[data-visualizar-marco]');
    var titulo = dialogo.querySelector('[data-visualizar-titulo]');

    function incrustada(url) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'incrustado=1';
    }

    document.addEventListener('click', function (evento) {
        var abrir = evento.target.closest('[data-visualizar]');
        if (!abrir) { return; }

        evento.preventDefault();
        titulo.textContent = abrir.dataset.titulo || 'Vista previa';
        marco.src = incrustada(abrir.dataset.visualizar);

        if (typeof dialogo.showModal === 'function' && !dialogo.open) { dialogo.showModal(); }
    });

    dialogo.querySelector('[data-visualizar-imprimir]').addEventListener('click', function () {
        if (marco.contentWindow) {
            marco.contentWindow.focus();
            marco.contentWindow.print();
        }
    });

    dialogo.querySelector('[data-visualizar-modificar]').addEventListener('click', function () { dialogo.close(); });

    // Al cerrar se vacía el marco: la próxima vez la hoja se pide de nuevo.
    dialogo.addEventListener('close', function () { marco.removeAttribute('src'); });
})();
