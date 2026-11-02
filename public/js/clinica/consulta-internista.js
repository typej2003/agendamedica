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

    // **El texto de la opción elegida** (O-47): al cambiar una escala de *Estimaciones*, su texto pasa al cuadro
    // «Descripción» de esa escala (`…_resultado`), igual que el `doubleclicked` del escritorio. No guarda nada: deja
    // el texto escrito para que se guarde con la sección. Se respeta lo que el médico haya escrito a mano: sólo se
    // reemplaza si el cuadro está vacío o si todavía tiene el texto de otra opción del mismo catálogo.
    const escalas = Array.from(formulario.querySelectorAll('select[data-descripcion-de]'));
    const emergencia = document.getElementById('interna-pre_aneste_emergencia');

    function marcaDeEmergencia() {
        return emergencia && emergencia.checked ? 'en Emergencia, ' : '';
    }

    function textosDeLaEscala(select) {
        return Array.from(select.options)
            .map(function (opcion) { return (opcion.dataset.texto || '').replace('{emergencia}', marcaDeEmergencia()); })
            .filter(function (texto) { return texto !== ''; });
    }

    function cargarDescripcion(select) {
        const destino = document.getElementById(select.dataset.descripcionDe);
        if (!destino) return;

        const opcion = select.options[select.selectedIndex];
        const texto = ((opcion && opcion.dataset.texto) || '').replace('{emergencia}', marcaDeEmergencia());
        if (texto === '') return;

        const actual = destino.value.trim();
        const esDelCatalogo = textosDeLaEscala(select).indexOf(actual) !== -1;

        // Un texto escrito a mano no se pisa; uno vacío o generado por otra opción sí.
        if (actual !== '' && !esDelCatalogo && actual !== texto) return;

        destino.value = texto;
        destino.dispatchEvent(new Event('input', { bubbles: true }));
    }

    escalas.forEach(function (select) {
        select.addEventListener('change', function () { cargarDescripcion(select); });
    });

    // La clase ASA dice «en Emergencia,» según esa casilla: al cambiarla se vuelve a cargar el texto vigente.
    if (emergencia) {
        emergencia.addEventListener('change', function () {
            escalas
                .filter(function (select) { return select.dataset.descripcionDe === 'interna-pre_aneste_resultado'; })
                .forEach(cargarDescripcion);
        });
    }

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
