/**
 * **El aviso de cambios sin guardar de las secciones del internista** (MI-2 a MI-5): el formulario `[data-consulta-internista]`
 * muestra `#internista-form-sin-guardar` en cuanto cambia algún campo, pregunta antes de «Cancelar» y avisa al cerrar la
 * página. A diferencia del aviso de `consulta.js`, que mira un solo texto, éste compara **todos** los campos del formulario.
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

    const guardado = estado();
    let enviando = false;

    function sucio() {
        return !enviando && estado() !== guardado;
    }

    function pintarAviso() {
        if (aviso) aviso.hidden = !sucio();
    }

    formulario.addEventListener('input', pintarAviso);
    formulario.addEventListener('change', pintarAviso);
    formulario.addEventListener('submit', function () { enviando = true; });

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
