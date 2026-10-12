/*
 * Formato de impresión del médico (WEB-8.2). Las vistas no llevan JS: declaran lo que quieren con `data-*` y este archivo lo
 * resuelve. Hace dos cosas:
 *
 * 1. **El aviso de "hay cambios sin guardar"** de cada sección (`form[data-formato-form]`): cada sección se guarda con su
 *    botón, así que la pantalla tiene que decir cuándo hay algo cambiado que todavía no está guardado —y que la vista
 *    previa de al lado muestra lo guardado, no lo escrito— y el navegador tiene que avisar antes de perderlo. El aviso es el
 *    `[data-formato-aviso]` del propio formulario, que se alterna con `hidden`; el texto que avisa el navegador no se puede
 *    personalizar, el de la pantalla sí (`data-aviso`).
 *
 * 2. **Imprimir prueba** (`[data-imprimir-prueba]`): imprime la hoja de ejemplo que ya está en la vista previa (el `<iframe>`
 *    carga la misma página que imprimiría un récipe, sin su barra), con su `@page` de A4 horizontal. Si el marco todavía no
 *    cargó, abre la hoja completa en otra pestaña.
 *
 * Sin JavaScript la pantalla sigue sirviendo: cada sección es un formulario común.
 */
(function () {
    'use strict';

    // ---- Cambios sin guardar, por sección.
    var formularios = Array.prototype.slice.call(document.querySelectorAll('form[data-formato-form]'));
    var sucio = new Set();
    var enviando = false;

    function marcar(formulario) {
        sucio.add(formulario);
        var aviso = formulario.querySelector('[data-formato-aviso]');
        if (aviso) aviso.hidden = false;
    }

    formularios.forEach(function (formulario) {
        // `input` cubre el texto y las casillas; `change` los archivos, los selectores y los radios.
        formulario.addEventListener('input', function () { marcar(formulario); });
        formulario.addEventListener('change', function () { marcar(formulario); });
        formulario.addEventListener('submit', function () {
            // Guardar es la salida correcta: ni el aviso del navegador ni el de la pantalla tienen nada que decir.
            enviando = true;
            sucio.delete(formulario);
        });
    });

    window.addEventListener('beforeunload', function (evento) {
        if (enviando || sucio.size === 0) return;
        evento.preventDefault();
        evento.returnValue = '';
    });

    // El "Restablecer valores" de cada sección es otro formulario: confirmarlo (`data-confirmar`, de shell.js) ya avisa, así
    // que sale del aviso del navegador sin esperar a que `enviando` se active (el `submit` llega después del confirm).
    document.addEventListener('submit', function (evento) {
        if (evento.target.matches('form:not([data-formato-form])') && !evento.defaultPrevented) {
            enviando = true;
        }
    });

    // ---- Imprimir prueba.
    var imprimir = document.querySelector('[data-imprimir-prueba]');
    var marco = document.getElementById('formato-previa');

    if (imprimir) {
        imprimir.addEventListener('click', function () {
            try {
                if (marco && marco.contentWindow && marco.contentDocument && marco.contentDocument.readyState === 'complete') {
                    marco.contentWindow.focus();
                    marco.contentWindow.print();
                    return;
                }
            } catch (e) { /* el marco no es accesible: se abre la hoja completa */ }

            var completa = document.querySelector('a[href*="vista-previa"]');
            if (completa) window.open(completa.href, '_blank', 'noopener');
        });
    }
}());
