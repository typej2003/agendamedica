// La colposcopía con imagen editable (A-13): los dos lienzos del cuello uterino —nulípara y multípara— sobre su
// imagen base, con las herramientas compartidas de `public/js/clinica/lienzo.js`. Lo propio de esta pantalla es la
// **imagen base**: cada lienzo elige entre la vectorial y la del escritorio; el motor manda `base` en cada guardado y,
// al cambiarla, guarda sólo el dato (el trazo ya guardado no se reescribe ni se pierde).
(function () {
    'use strict';

    if (!window.Lienzo) { return; }

    window.Lienzo.iniciar({
        herramientas: '[data-lienzo-herramientas]',
        color: '[data-lienzo-color]',
        grosor: '[data-lienzo-grosor]',
        grosorValor: '[data-lienzo-grosor-valor]',
        estado: '[data-lienzo-estado]',
        muestra: '[data-lienzo-muestra]',
        lienzo: '[data-lienzo]',
        canvas: '[data-lienzo-canvas]',
        deshacer: '[data-lienzo-deshacer]',
        borrar: '[data-lienzo-borrar]',
        avisoBorrar: '¿Borrar todo el dibujo de este cuello uterino?',
        base: '[data-lienzo-base]',
        imagen: '[data-lienzo-imagen]',
        // La base elegida viaja en el POST del trazo y en la query del DELETE (que es el que quita el dibujo).
        datos: function (bloque) {
            var selector = bloque.querySelector('[data-lienzo-base]');

            return { base: selector ? selector.value : '' };
        },
    });
})();
