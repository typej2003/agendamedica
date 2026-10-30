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
 *  - Es **modal** (2026-10-20): mientras está abierto, un velo gris semitransparente cubre la pantalla (con
 *    un hueco sobre el elemento señalado) y se come los clics, la rueda y el toque; el foco queda atrapado
 *    en el globo (`Tab` gira entre sus botones) y las teclas de desplazamiento no mueven la página. Así el
 *    usuario sólo puede seguir, volver o saltar. El scroll que hace el propio tour (`scrollIntoView`) sí
 *    funciona: es programático.
 *  - Se cierra con «Saltar», con «Listo» y con `Esc` (la salida de teclado de cualquier diálogo modal).
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

    // El velo y el foco viven en `<body>`, igual que el globo mientras el tour está abierto: dentro de `<main>`
    // quedarían por debajo de la barra lateral si algún contenedor arma su propio contexto de apilamiento.
    const velo = document.createElement('div');
    velo.className = 'tour-velo';
    velo.setAttribute('data-tour-velo', '');
    velo.setAttribute('aria-hidden', 'true');
    velo.hidden = true;

    const foco = document.createElement('div');
    foco.className = 'tour-foco';
    foco.setAttribute('aria-hidden', 'true');
    foco.hidden = true;

    // Un clic en el velo no hace nada y tampoco le quita el foco al globo (el `mousedown` lo mandaría a `<body>`).
    velo.addEventListener('mousedown', function (evento) { evento.preventDefault(); });

    /**
     * **Mientras el tutorial está abierto, la aplicación no se puede tocar** (2026-10-30; Alexander lo
     * reportó dos veces).
     *
     * El paso resaltado se pinta **por encima del velo** para que se vea, y eso lo dejaba clickeable: si el
     * médico apretaba «Crear sede» desde el tutorial, el diálogo se abría **detrás** del velo y la pantalla
     * quedaba trabada —no se podía escribir un espacio ni moverse con las flechas—. No era un problema del
     * formulario: era el tutorial dejando pasar un clic.
     *
     * Se corta el clic **en captura**, antes de que llegue al control: fuera del globo (y del botón de
     * reabrir) no pasa nada. Además se marca `inert` el armazón, así el teclado tampoco llega a los campos de
     * abajo. Para interactuar hay que **terminar el tutorial o saltarlo**, que es lo que corresponde.
     */
    const bloquearLaAplicacion = function (bloquear) {
        var armazon = document.getElementById('wrapper');
        var barra = document.querySelector('.site-header, .topbar, nav.navbar');

        [armazon, barra].forEach(function (elemento) {
            if (!elemento) return;
            if (bloquear) {
                elemento.setAttribute('inert', '');
                elemento.setAttribute('aria-hidden', 'true');
            } else {
                elemento.removeAttribute('inert');
                elemento.removeAttribute('aria-hidden');
            }
        });
    };

    document.addEventListener('click', function (evento) {
        if (!abierto) return;

        var destino = evento.target;
        if (raiz.contains(destino) || (botonReabrir && botonReabrir.contains(destino))) return;

        evento.preventDefault();
        evento.stopPropagation();
    }, true);

    document.body.appendChild(velo);
    document.body.appendChild(foco);

    const TECLAS_DE_DESPLAZAMIENTO = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'PageUp', 'PageDown', 'Home', 'End', ' '];

    const MARGEN = 8;
    const SEPARACION = 12;

    let visibles = [];
    let indice = 0;
    let resaltado = null;
    let abierto = false;
    let marcado = false;
    let pendienteDeUbicar = false;
    let focoAnterior = null;

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

    /** El hueco del velo: un recuadro sobre el elemento señalado cuya sombra enorme es el gris de alrededor. */
    const ubicarFoco = function (caja) {
        const holgura = 4;
        foco.style.left = Math.round(caja.left - holgura) + 'px';
        foco.style.top = Math.round(caja.top - holgura) + 'px';
        foco.style.width = Math.round(caja.width + holgura * 2) + 'px';
        foco.style.height = Math.round(caja.height + holgura * 2) + 'px';
    };

    const ubicar = function () {
        if (!abierto || !resaltado) return;

        const caja = resaltado.getBoundingClientRect();
        ubicarFoco(caja);
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
        velo.hidden = true;
        foco.hidden = true;
        document.documentElement.classList.remove('tour-activo');
        raiz.setAttribute('data-abierto', '0');
        bloquearLaAplicacion(false);

        // El foco vuelve a donde estaba (el botón de reabrir, si se abrió desde ahí).
        if (focoAnterior && document.contains(focoAnterior) && typeof focoAnterior.focus === 'function') {
            focoAnterior.focus({ preventScroll: true });
        }
        focoAnterior = null;

        if (marcar) marcarVisto();
    };

    const abrir = function () {
        if (!recolectar()) return; // ningún elemento a la vista: no se muestra ni se marca

        if (!abierto) {
            focoAnterior = document.activeElement;
        }

        abierto = true;
        raiz.setAttribute('data-abierto', '1');
        if (globo.parentElement !== document.body) document.body.appendChild(globo);
        document.documentElement.classList.add('tour-activo');
        bloquearLaAplicacion(true);
        velo.hidden = false;
        foco.hidden = false;
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
            return;
        }

        // El foco no sale del globo: `Tab` y `Shift+Tab` giran entre sus botones habilitados.
        if (evento.key === 'Tab') {
            const enfocables = Array.prototype.filter.call(
                globo.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
                function (el) { return !el.disabled && el.offsetParent !== null; }
            );
            if (!enfocables.length) {
                evento.preventDefault();
                globo.focus({ preventScroll: true });
                return;
            }
            const primero = enfocables[0];
            const ultimo = enfocables[enfocables.length - 1];
            const dentro = globo.contains(document.activeElement);
            if (evento.shiftKey && (!dentro || document.activeElement === primero || document.activeElement === globo)) {
                evento.preventDefault();
                ultimo.focus({ preventScroll: true });
            } else if (!evento.shiftKey && (!dentro || document.activeElement === ultimo)) {
                evento.preventDefault();
                primero.focus({ preventScroll: true });
            }
            return;
        }

        // Las teclas de desplazamiento no mueven la página. El espacio sobre un botón del globo sí lo activa.
        if (TECLAS_DE_DESPLAZAMIENTO.indexOf(evento.key) !== -1) {
            const esBoton = evento.key === ' ' && evento.target && evento.target.closest
                && evento.target.closest('button') && globo.contains(evento.target);
            if (!esBoton) evento.preventDefault();
        }
    }, true);

    // Si el foco se escapa (un clic programático, un `autofocus` tardío), vuelve al globo.
    document.addEventListener('focusin', function (evento) {
        if (abierto && !globo.contains(evento.target)) {
            globo.focus({ preventScroll: true });
        }
    });

    // La rueda y el toque no desplazan nada mientras el tour está abierto (salvo dentro del globo).
    const bloquearDesplazamiento = function (evento) {
        if (abierto && !globo.contains(evento.target)) evento.preventDefault();
    };
    document.addEventListener('wheel', bloquearDesplazamiento, { passive: false });
    document.addEventListener('touchmove', bloquearDesplazamiento, { passive: false });

    // El globo sigue al elemento: al hacer scroll (también dentro de un contenedor) y al cambiar el tamaño.
    window.addEventListener('scroll', pedirUbicacion, true);
    window.addEventListener('resize', pedirUbicacion);

    // La primera vez se abre solo; `data-mostrar` lo decide el servidor con la marca de `tours_vistos`.
    if (raiz.getAttribute('data-mostrar') === '1') {
        window.requestAnimationFrame(abrir);
    }
}());
