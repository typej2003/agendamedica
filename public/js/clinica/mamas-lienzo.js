// Imágenes de mamas (WEB-11.10): el lienzo de cada mama. Desde A-13 el motor de dibujo es el compartido
// (`public/js/clinica/lienzo.js`), que también usa la colposcopía; acá sólo se le pasan los selectores de las mamas,
// así que su comportamiento —color y grosor, deshacer, borrar todo, autoguardado a los 800 ms como PNG transparente y
// la capa guardada como primera capa— es exactamente el de antes.
(function () {
    'use strict';

    if (!window.Lienzo) { return; }

    window.Lienzo.iniciar({
        herramientas: '[data-mamas-herramientas]',
        color: '[data-mamas-color]',
        grosor: '[data-mamas-grosor]',
        grosorValor: '[data-mamas-grosor-valor]',
        estado: '[data-mamas-estado]',
        muestra: '[data-mamas-muestra]',
        muestraValor: function (boton) { return boton.dataset.mamasMuestra; },
        lienzo: '[data-mama-lienzo]',
        canvas: 'canvas',
        deshacer: '[data-mama-deshacer]',
        borrar: '[data-mama-borrar]',
        avisoBorrar: '¿Borrar todo el dibujo de esta mama?',
    });
})();
