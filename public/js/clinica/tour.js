/*
 * **El tour guiado de la pantalla** (A-15).
 *
 * Se carga sólo en las pantallas que tienen uno (lo decide `TourDeLaPantallaComposer`). No sabe nada de
 * textos ni de pantallas: recorre los `[data-tour-paso]` que pintó la vista, busca el elemento de cada
 * `data-selector` y muestra título y texto en un globo. Agregar un paso es una línea en
 * `App\Clinica\Tours\ToursDisponibles` más el `data-tour` en la vista.
 *
 * Reglas del comportamiento:
 *  - Se abre solo la primera vez (`data-mostrar="1"`); después sólo con «¿Cómo funciona esta pantalla?».
 *  - Los pasos cuyo elemento no existe o no está a la vista (una fila que no hay, el menú lateral en el
 *    celular) se saltan: la lista se arma en el navegador. Si no queda ninguno, no se muestra.
 *  - El globo no tapa el elemento (va arriba o abajo) y se reubica al hacer scroll o al cambiar el tamaño.
 *  - Se cierra con «Saltar», con «Listo», con `Esc` y con el botón de reabrir (que lo vuelve a empezar).
 *  - Al terminar o saltar avisa por `POST` (nunca por GET) para no volver a mostrarlo. `Esc` cuenta como
 *    saltar: si no, el tour reaparecería en cada visita de quien lo cerró con el teclado.
 */
(function () {
    'use strict';

    const raiz = document.querySelector('[data-tour-raiz]');
    if (!raiz) return;

    const globo = raiz.querySelector('[data-tour-globo]');
    const titulo = raiz.querySelector('[data-tour-titulo]');
    const texto = raiz.querySelector('[data-tour-texto]');
    const cuenta = raiz.querySelector('[data-tour-cuenta]');
    const botonAnterior = raiz.querySelector('[data-tour-anterior]');
    const botonSiguiente = raiz.querySelector('[data-tour-siguiente]');
    const botonSaltar = raiz.querySelector('[data-tour-saltar]');
    // El botón de reabrir no siempre vive dentro de la raíz: en la consulta va en la fila de su encabezado,
    // junto a Guardar y Terminar (O-31), para no gastar un renglón. Por eso, si no está en la raíz, se busca
    // en la pantalla.
    const botonReabrir = raiz.querySelector('[data-tour-reabrir]') || document.querySelector('[data-tour-reabrir]');
    const pasos = Array.prototype.slice.call(raiz.querySelectorAll('[data-tour-paso]'));
    const url = raiz.getAttribute('data-url');
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    if (!globo || !pasos.length) return;

    const MARGEN = 8;
    const SEPARACION = 12;

    let visibles = [];
    let indice = 0;
    let resaltado = null;
    let abierto = false;
    let marcado = false;
    let pendienteDeUbicar = false;

    // ---------------------------------------------------------------- Utilidades de la pantalla

    /** Abre los `<details>` cerrados que envuelven al elemento (el submenú de Catálogos, por ejemplo). */
    const abrirDetalles = function (elemento) {
        let detalle = elemento.closest('details');
        while (detalle) {
            if (!detalle.open) detalle.open = true;
            detalle = detalle.parentElement ? detalle.parentElement.closest('details') : null;
        }
    };

    /**
     * ¿El elemento se puede señalar? Tiene que existir, no estar oculto y no estar fuera de la pantalla:
     * el menú lateral del celular se dibuja fuera de la ventana y no hay scroll que lo traiga, así que sus
     * pasos se saltan en vez de mostrar un globo apuntando al vacío.
     */
    const estaALaVista = function (elemento) {
        const estilo = window.getComputedStyle(elemento);
        if (estilo.display === 'none' || estilo.visibility === 'hidden') return false;

        const caja = elemento.getBoundingClientRect();
        if (caja.width < 2 || caja.height < 2) return false;

        return caja.right > 0 && caja.left < window.innerWidth;
    };

    const buscarObjetivo = function (selector) {
        let lista;
        try {
            lista = document.querySelectorAll(selector);
        } catch (e) {
            return null; // un selector mal escrito no puede romper la pantalla
        }

        for (let i = 0; i < lista.length; i++) {
            abrirDetalles(lista[i]);
            if (estaALaVista(lista[i])) return lista[i];
        }

        return null;
    };

    /** Arma la lista de pasos que se pueden mostrar en esta pantalla y en este tamaño. */
    const recolectar = function () {
        visibles = [];

        pasos.forEach(function (paso) {
            const objetivo = buscarObjetivo(paso.getAttribute('data-selector') || '');
            if (objetivo) visibles.push({ paso: paso, objetivo: objetivo });
        });

        return visibles.length;
    };

    // ---------------------------------------------------------------- Posición del globo

    const ubicar = function () {
        if (!abierto || !resaltado) return;

        const caja = resaltado.getBoundingClientRect();
        const ancho = globo.offsetWidth;
        const alto = globo.offsetHeight;

        let arriba = caja.top - alto - SEPARACION;
        let abajo = caja.bottom + SEPARACION;

        // Arriba si entra; si no, abajo; si tampoco entra (el elemento ocupa la pantalla), el lado con más
        // lugar, siempre dentro de la ventana.
        let top;
        if (arriba >= MARGEN) {
            top = arriba;
        } else if (abajo + alto <= window.innerHeight - MARGEN) {
            top = abajo;
        } else {
            top = caja.top > window.innerHeight - caja.bottom ? arriba : abajo;
        }

        const left = caja.left + caja.width / 2 - ancho / 2;

        // Horizontales: centrado en el elemento; si no entra, al lado (el menú lateral, por ejemplo) y, si
        // tampoco, pegado al margen. Así el globo no se monta sobre la barra cuando señala un ítem del menú.
        let izquierda = Math.max(MARGEN, Math.min(left, window.innerWidth - ancho - MARGEN));
        if (left < MARGEN && caja.right + SEPARACION + ancho <= window.innerWidth - MARGEN) {
            izquierda = caja.right + SEPARACION;
        } else if (left + ancho > window.innerWidth - MARGEN && caja.left - SEPARACION - ancho >= MARGEN) {
            izquierda = caja.left - SEPARACION - ancho;
        }

        globo.style.left = Math.round(izquierda) + 'px';
        globo.style.top = Math.round(Math.max(MARGEN, Math.min(top, window.innerHeight - alto - MARGEN))) + 'px';
    };

    const pedirUbicacion = function () {
        if (pendienteDeUbicar) return;
        pendienteDeUbicar = true;
        window.requestAnimationFrame(function () {
            pendienteDeUbicar = false;
            ubicar();
        });
    };

    const llevarALaVista = function (elemento) {
        const caja = elemento.getBoundingClientRect();
        if (caja.top >= 64 && caja.bottom <= window.innerHeight - 16) return;

        const reducido = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        elemento.scrollIntoView({ block: 'center', inline: 'nearest', behavior: reducido ? 'auto' : 'smooth' });
    };

    // ---------------------------------------------------------------- Mostrar los pasos

    const mostrar = function (numero) {
        indice = Math.max(0, Math.min(numero, visibles.length - 1));

        const actual = visibles[indice];

        if (resaltado) resaltado.classList.remove('tour-resaltado');
        resaltado = actual.objetivo;
        resaltado.classList.add('tour-resaltado');

        const tituloDelPaso = actual.paso.querySelector('.tour-paso-titulo');
        const textoDelPaso = actual.paso.querySelector('.tour-paso-texto');

        titulo.textContent = tituloDelPaso ? tituloDelPaso.textContent.trim() : '';
        texto.textContent = textoDelPaso ? textoDelPaso.textContent.trim() : '';
        cuenta.textContent = 'Paso ' + (indice + 1) + ' de ' + visibles.length;

        botonAnterior.disabled = indice === 0;
        botonSiguiente.textContent = indice === visibles.length - 1 ? 'Listo' : 'Siguiente';

        globo.hidden = false;
        llevarALaVista(resaltado);
        ubicar();
    };

    // ---------------------------------------------------------------- Abrir y cerrar

    /** Avisa que el tour quedó visto. Una sola vez por carga: reabrir y volver a terminar no duplica nada. */
    const marcarVisto = function () {
        if (marcado || !url) return;
        marcado = true;

        try {
            window.fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            }).catch(function () { /* sin conexión: se vuelve a mostrar la próxima vez */ });
        } catch (e) {
            /* Sin `fetch` no hay marca; el tour se vuelve a mostrar, que es preferible a romper la pantalla. */
        }
    };

    const cerrar = function (marcar) {
        if (!abierto) return;

        abierto = false;
        if (resaltado) {
            resaltado.classList.remove('tour-resaltado');
            resaltado = null;
        }

        globo.hidden = true;
        raiz.setAttribute('data-abierto', '0');

        if (marcar) marcarVisto();
    };

    const abrir = function () {
        if (!recolectar()) return; // ningún elemento a la vista: no se muestra ni se marca

        abierto = true;
        raiz.setAttribute('data-abierto', '1');
        mostrar(0);

        // El foco entra al globo (es un `role="dialog"`): así se oye el primer paso y `Tab` lleva a sus botones.
        globo.focus({ preventScroll: true });
    };

    // ---------------------------------------------------------------- Eventos

    botonSiguiente.addEventListener('click', function () {
        if (indice >= visibles.length - 1) {
            cerrar(true);
        } else {
            mostrar(indice + 1);
        }
    });

    botonAnterior.addEventListener('click', function () {
        mostrar(indice - 1);
    });

    botonSaltar.addEventListener('click', function () {
        cerrar(true);
    });

    if (botonReabrir) {
        botonReabrir.addEventListener('click', function () {
            abrir();
        });
    }

    document.addEventListener('keydown', function (evento) {
        if (!abierto) return;

        if (evento.key === 'Escape') {
            evento.preventDefault();
            cerrar(true);
        }
    });

    // El globo sigue al elemento: al hacer scroll (también dentro de un contenedor) y al cambiar el tamaño.
    window.addEventListener('scroll', pedirUbicacion, true);
    window.addEventListener('resize', pedirUbicacion);

    // La primera vez se abre solo; `data-mostrar` lo decide el servidor con la marca de `tours_vistos`.
    if (raiz.getAttribute('data-mostrar') === '1') {
        window.requestAnimationFrame(abrir);
    }
}());
