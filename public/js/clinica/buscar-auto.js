/*
 * Los buscadores de lista buscan solos (2026-10-20): sin Enter, con debounce.
 *
 * Un `<form data-buscar-auto>` (el `<x-ui.buscador>` y el filtro de las dos listas de la consulta) pide la misma
 * dirección que pediría su botón «Buscar» unos 350 ms después de la última tecla y cambia **sólo la región de
 * resultados** (`data-buscar-region`, por defecto `[data-buscar-resultados]`) por la de la respuesta: el campo no se
 * recarga, así que no pierde el foco ni el cursor. La dirección de la barra se actualiza (`replaceState`) para que
 * recargar o compartir la página conserve la búsqueda. Sin JavaScript, o si la petición falla, el formulario se envía como
 * siempre: el botón «Buscar» sigue ahí (se esconde sólo cuando este script se activa).
 *
 * «Agregar»: si lo escrito no coincide **exactamente** con el nombre de ninguna fila (sin mayúsculas ni tildes), aparece
 * el botón `[data-buscar-agregar]` del formulario. Según lo que declare:
 *  - `data-agregar-dialogo` + `data-prefill-campo`: abre ese diálogo de alta con el campo ya escrito con la búsqueda;
 *  - `data-agregar-campo` (sin diálogo): el alta ya está en la pantalla (un formulario aparte); escribe la búsqueda en ese campo,
 *    lo lleva a la vista y le da el foco;
 *  - `data-agregar-url`: es un enlace a la pantalla de alta, con la búsqueda en el parámetro `data-agregar-parametro`
 *    (por defecto `nombre`).
 * El nombre de cada fila es su `<strong>` o, si no lo tiene, su texto propio (sin el de los elementos hijos: chips, detalles)
 * de cada elemento que elige `data-buscar-filas` (por defecto `td.celda-principal`).
 *
 * Después de cambiar la región se dispara `buscar-auto:cargado` (en la región, burbujea) para quien tenga que volver a
 * atar algo de lo que acaba de llegar.
 */
(function () {
    'use strict';

    const ESPERA = 350;

    function normalizar(texto) {
        return String(texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function nombreDe(celda) {
        const fuerte = celda.querySelector('strong');
        if (fuerte) return fuerte.textContent;

        const propio = Array.prototype.filter.call(celda.childNodes, function (nodo) { return nodo.nodeType === 3; })
            .map(function (nodo) { return nodo.textContent; }).join(' ');

        return propio.trim() !== '' ? propio : celda.textContent;
    }

    function iniciar(formulario) {
        const campo = formulario.querySelector('input[type="search"]');
        if (!campo) return;

        const selectorRegion = formulario.dataset.buscarRegion || '[data-buscar-resultados]';
        const enviar = formulario.querySelector('[data-buscar-enviar]');
        const limpiar = formulario.querySelector('[data-buscar-limpiar]');
        const agregar = formulario.querySelector('[data-buscar-agregar]');
        const selectorFilas = formulario.dataset.buscarFilas || 'td.celda-principal';

        let temporizador = null;
        let pedida = campo.value.trim();
        let numero = 0;
        let aborto = null;

        if (enviar) enviar.hidden = true;

        function region() {
            return document.querySelector(selectorRegion);
        }

        function direccion() {
            const url = new URL(formulario.getAttribute('action') || window.location.pathname, window.location.href);
            const datos = new FormData(formulario);
            datos.forEach(function (valor, nombre) {
                if (typeof valor === 'string' && valor.trim() !== '' && nombre !== '_token') url.searchParams.set(nombre, valor.trim());
            });
            return url;
        }

        function actualizarAgregar() {
            if (!agregar) return;

            const escrito = campo.value.trim();
            const zona = region();

            if (escrito === '' || !zona) {
                agregar.hidden = true;
                return;
            }

            const buscado = normalizar(escrito);
            const hayExacta = Array.prototype.some.call(zona.querySelectorAll(selectorFilas), function (celda) {
                return normalizar(nombreDe(celda)) === buscado;
            });

            agregar.hidden = hayExacta;
            if (hayExacta) return;

            const rotulo = agregar.querySelector('[data-buscar-agregar-texto]');
            if (rotulo) rotulo.textContent = escrito;
            agregar.dataset.prefillValor = escrito;

            if (agregar.dataset.agregarUrl) {
                const url = new URL(agregar.dataset.agregarUrl, window.location.href);
                url.searchParams.set(agregar.dataset.agregarParametro || 'nombre', escrito);
                agregar.setAttribute('href', url.pathname + url.search);
            }
        }

        function alternarLimpiar() {
            if (limpiar) limpiar.hidden = campo.value.trim() === '';
        }

        function buscar() {
            const valor = campo.value.trim();
            if (valor === pedida) return;
            pedida = valor;

            const miNumero = ++numero;
            if (aborto) aborto.abort();
            aborto = typeof AbortController === 'function' ? new AbortController() : null;

            const url = direccion();
            alternarLimpiar();

            fetch(url.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                credentials: 'same-origin',
                signal: aborto ? aborto.signal : undefined,
            })
                .then(function (respuesta) {
                    if (!respuesta.ok || respuesta.redirected) throw new Error('http ' + respuesta.status);
                    return respuesta.text();
                })
                .then(function (html) {
                    // Una respuesta vieja no pisa lo que se escribió después.
                    if (miNumero !== numero) return;

                    const nueva = new DOMParser().parseFromString(html, 'text/html').querySelector(selectorRegion);
                    const zona = region();
                    if (!nueva || !zona) throw new Error('sin región');

                    zona.innerHTML = nueva.innerHTML;
                    window.history.replaceState(null, '', url.pathname + url.search);
                    actualizarAgregar();
                    zona.dispatchEvent(new CustomEvent('buscar-auto:cargado', { bubbles: true }));
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') return;
                    // Algo falló (sesión vencida, red): se hace la búsqueda de siempre.
                    formulario.submit();
                });
        }

        campo.addEventListener('input', function () {
            window.clearTimeout(temporizador);
            alternarLimpiar();
            temporizador = window.setTimeout(buscar, ESPERA);
        });

        // Enter busca de una vez, sin recargar.
        formulario.addEventListener('submit', function (evento) {
            evento.preventDefault();
            window.clearTimeout(temporizador);
            pedida = null;
            buscar();
        });

        if (agregar && agregar.dataset.agregarDialogo) {
            agregar.addEventListener('click', function () {
                const dialogo = document.getElementById(agregar.dataset.agregarDialogo);
                if (!dialogo || typeof dialogo.showModal !== 'function') return;

                const destino = document.getElementById(agregar.dataset.prefillCampo);
                if (destino && agregar.dataset.prefillValor) {
                    destino.value = destino.maxLength > 0 ? agregar.dataset.prefillValor.slice(0, destino.maxLength) : agregar.dataset.prefillValor;
                }

                // Al guardar, el diálogo vuelve a la lista: que sea a la búsqueda de ahora y a su primera página.
                const vuelta = dialogo.querySelector('input[type="hidden"][name="buscar"]');
                if (vuelta) vuelta.value = campo.value.trim();
                const pagina = dialogo.querySelector('input[type="hidden"][name="page"]');
                if (pagina) pagina.value = '1';

                if (!dialogo.open) dialogo.showModal();
                const primero = destino || dialogo.querySelector('[data-foco-inicial]');
                if (primero) primero.focus();
            });
        }

        if (agregar && agregar.dataset.agregarCampo) {
            agregar.addEventListener('click', function () {
                const destino = document.getElementById(agregar.dataset.agregarCampo);
                if (!destino) return;

                destino.value = destino.maxLength > 0 ? agregar.dataset.prefillValor.slice(0, destino.maxLength) : agregar.dataset.prefillValor;
                destino.scrollIntoView({ block: 'center' });
                destino.focus();
            });
        }

        actualizarAgregar();
    }

    document.querySelectorAll('form[data-buscar-auto]').forEach(iniciar);
})();
