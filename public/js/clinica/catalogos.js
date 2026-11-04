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

/*
 * El diálogo del catálogo inicial (2026-11-03): «Todos» marca y desmarca **lo que se ve**, el buscador filtra la lista
 * (el catálogo de diagnósticos trae más de mil filas) y **lo que se ve es lo que se agrega**: la fila filtrada no viaja
 * en el formulario, así buscar «fibro» y leer «Agregar (10)» no termina agregando las mil trescientas. El botón dice
 * cuántos van y se apaga si no hay ninguno. Sin JS el formulario igual funciona: todos los ítems salen marcados, sin
 * filtro, y el diálogo se abre con el botón, que es un `data-abrir-dialogo` del armazón.
 */
(function () {
    'use strict';

    const raiz = document.querySelector('[data-catalogo-inicial]');
    if (!raiz) return;

    const todos = raiz.querySelector('[data-catalogo-inicial-todos]');
    const buscar = raiz.querySelector('[data-catalogo-inicial-buscar]');
    const cuantos = raiz.querySelector('[data-catalogo-inicial-cuantos]');
    const sinCoincidencias = raiz.querySelector('[data-catalogo-inicial-sin-coincidencias]');
    const agregar = raiz.querySelector('[data-catalogo-inicial-agregar]');
    const items = Array.from(raiz.querySelectorAll('[data-catalogo-inicial-item]'));
    if (!todos || !agregar) return;

    // Sin tildes ni mayúsculas, como el buscador de la tabla.
    const normalizar = function (texto) {
        return (texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    };

    // Lo que se ve (no está filtrado) y lo que viaja al servidor (marcado y visible).
    const visibles = function () {
        return items.filter(function (item) { return !item.disabled; });
    };
    const elegidos = function () {
        return items.filter(function (item) { return item.checked && !item.disabled; });
    };

    const pintar = function () {
        const visiblesAhora = visibles();
        const elegidosAhora = elegidos();
        const marcadosVisibles = visiblesAhora.filter(function (item) { return item.checked; }).length;

        agregar.disabled = elegidosAhora.length === 0;
        agregar.textContent = elegidosAhora.length === visiblesAhora.length && visiblesAhora.length === items.length
            ? 'Agregar todos'
            : 'Agregar (' + elegidosAhora.length + ')';
        todos.checked = visiblesAhora.length > 0 && marcadosVisibles === visiblesAhora.length;
        todos.indeterminate = marcadosVisibles > 0 && marcadosVisibles < visiblesAhora.length;

        if (cuantos) {
            cuantos.textContent = visiblesAhora.length === items.length
                ? items.length
                : visiblesAhora.length + ' de ' + items.length;
        }

        if (sinCoincidencias) {
            sinCoincidencias.classList.toggle('d-none', visiblesAhora.length > 0);
        }
    };

    const filtrar = function () {
        const buscado = normalizar(buscar ? buscar.value : '');

        items.forEach(function (item) {
            const fila = item.closest('.form-check');
            const coincide = buscado === '' || normalizar(fila.textContent).includes(buscado);

            fila.hidden = !coincide;
            // Un control `disabled` no viaja en el `submit`: lo filtrado queda fuera del alta, con su marca intacta.
            item.disabled = !coincide;
        });

        pintar();
    };

    todos.addEventListener('change', function () {
        visibles().forEach(function (item) { item.checked = todos.checked; });
        pintar();
    });

    items.forEach(function (item) { item.addEventListener('change', pintar); });

    if (buscar) buscar.addEventListener('input', filtrar);

    pintar();
})();
