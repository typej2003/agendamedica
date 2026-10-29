/**
 * **El aviso de cambios sin guardar de las secciones del internista** (MI-2 a MI-5): el formulario `[data-consulta-internista]`
 * muestra `#internista-form-sin-guardar` en cuanto cambia algún campo, pregunta antes de «Cancelar» y avisa al cerrar la
 * página. A diferencia del aviso de `consulta.js`, que mira un solo texto, éste compara **todos** los campos del formulario.
 *
 * Desde el 2026-10-29 también escucha `consulta:guardado`: «Guardar todo» del diálogo de salida guarda por `fetch` y, sin
 * enterarse, el aviso seguía encendido y el diálogo no avanzaba.
 */
(function () {
    'use strict';

    const formulario = document.querySelector('[data-consulta-internista]');
    if (!formulario) return;

    const aviso = document.getElementById(formulario.id + '-sin-guardar');

    function estado() {
        return Array.from(formulario.elements)
            .filter(function (campo) { return campo.name && campo.type !== 'hidden'; })
            .map(function (campo) { return campo.name + '=' + (campo.type === 'checkbox' ? campo.checked : campo.value); })
            .join('&');
    }

    let lineaBase = estado();
    let enviando = false;

    function sucio() {
        return !enviando && estado() !== lineaBase;
    }

    function pintarAviso() {
        if (aviso) aviso.hidden = !sucio();
    }

    formulario.addEventListener('input', pintarAviso);
    formulario.addEventListener('change', pintarAviso);
    formulario.addEventListener('submit', function () { enviando = true; });

    // «Guardar todo» del diálogo de salida (`consulta-salida.js`) guarda este formulario por `fetch`, sin recargar la
    // página: el nuevo punto de partida es lo que hay ahora. Sin esto el aviso de `beforeunload` seguía diciendo que
    // había cambios, y el diálogo no avanzaba aunque el servidor ya lo hubiera guardado (Alexander, 2026-10-29).
    formulario.addEventListener('consulta:guardado', function () {
        lineaBase = estado();
        pintarAviso();
    });

    const cancelar = formulario.querySelector('a[href]');
    if (cancelar) {
        cancelar.addEventListener('click', function (evento) {
            if (sucio() && !window.confirm(formulario.dataset.aviso)) {
                evento.preventDefault();
            }
        });
    }

    window.addEventListener('beforeunload', function (evento) {
        if (!sucio()) return;
        evento.preventDefault();
        evento.returnValue = formulario.dataset.aviso;
    });
})();
