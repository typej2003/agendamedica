/*
 * Comportamientos compartidos de la web clínica (`/clinica`). Se carga en `clinica/layout.blade.php`.
 *
 * Las vistas no llevan JS inline: declaran lo que quieren con atributos `data-*` y este archivo los
 * resuelve para toda la aplicación (Docs/Web/guia-arquitectura-web.md).
 *
 *   data-autoenviar           en un <select>/<input>: al cambiar, envía su formulario.
 *   data-confirmar="texto"    en un <form>: pide confirmación antes de enviarlo.
 *   data-atender              en el <form> de "Atender": abre el diálogo de la consulta (WEB-4.1) en vez
 *                             de enviarlo. Con la cita ya atendida (`data-editar="1"`) el diálogo ofrece
 *                             además numerar la consulta siguiente.
 *   data-cerrar-dialogo       en un botón: cierra el <dialog> que lo contiene.
 *   data-abrir-dialogo="id"   en un botón: abre ese <dialog> (showModal).
 *   <details class="menu-acciones">   el menú "⋯" cierra al hacer clic afuera o con Escape.
 *   .tabla-envoltura / .table-responsive   la tabla que no entra se desplaza dentro de su tarjeta (el
 *                             `overflow-x` está en el CSS) y acá se le pone `tabindex`, `role="region"` y
 *                             `aria-label` **sólo cuando desborda**, para que se pueda recorrer con el teclado.
 *                             El menú "⋯" de esas tablas se posiciona contra la ventana para que el recorte no
 *                             lo corte.
 */
(function () {
    'use strict';

    const $ = function (id) { return document.getElementById(id); };

    // ---- Enviar un formulario NO es abandonar la página con cambios sin guardar.
    // Todas las pantallas con «cambios sin guardar» ponen su propio `beforeunload` (consulta*.js,
    // formato-impresion.js, plantillas-mensaje.js), pero ninguna lo desarmaba al enviar su formulario: el
    // navegador mostraba «¿Abandonar sitio?» al pulsar Guardar. Este es el único lugar que lo resuelve:
    // `shell.js` se carga primero, así que su `beforeunload` corre antes que los demás y, si hay un envío en
    // curso, corta la propagación (los de la pantalla no llegan a pedir el aviso). Cubre el `submit` (botón,
    // botón con `form=`, Enter, `requestSubmit()`) y `form.submit()`, que no dispara `submit` y se envuelve.
    // Si algún `submit` se cancela (confirmación negada, envío por fetch) la bandera se baja. Una página
    // recargada por errores de validación es un documento nuevo: sus avisos nacen activos otra vez.
    let enviandoFormulario = false;
    const bajarEnvio = function () { enviandoFormulario = false; };

    document.addEventListener('submit', function (evento) {
        enviandoFormulario = true;
        window.setTimeout(function () {
            if (evento.defaultPrevented) bajarEnvio();
        }, 0);
    });

    const enviarOriginal = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        enviandoFormulario = true;
        return enviarOriginal.apply(this, arguments);
    };

    window.addEventListener('beforeunload', function (evento) {
        // `ddrSalidaConfirmada`: el médico ya eligió en el diálogo de salida de la consulta (`consulta-salida.js`).
        if (enviandoFormulario || window.ddrSalidaConfirmada) evento.stopImmediatePropagation();
    });
    // Volver con «atrás» desde el caché de la página: el envío ya terminó.
    window.addEventListener('pageshow', bajarEnvio);

    // ---- Al cerrar sesión se borran los borradores locales de la consulta (`consulta-borradores.js`) y el candado de
    // dispositivo no se toca (es del navegador, no del usuario). Un equipo compartido no debe heredar lo escrito por otro.
    document.addEventListener('submit', function (evento) {
        const accion = evento.target && evento.target.getAttribute ? (evento.target.getAttribute('action') || '') : '';
        if (!/\/logout\/?$/.test(accion.split('?')[0])) return;
        try {
            const sobran = [];
            for (let i = 0; i < window.localStorage.length; i++) {
                const llave = window.localStorage.key(i);
                if (llave && llave.indexOf('ddr:borrador:') === 0) sobran.push(llave);
            }
            sobran.forEach(function (llave) { window.localStorage.removeItem(llave); });
        } catch (e) { /* sin almacenamiento */ }
    });

    // ---- Menú lateral en celular: se desliza sobre el contenido en vez de empujarlo.
    const menu = document.getElementById('clinica-sidebar');
    const fondo = document.getElementById('clinica-sidebar-fondo');
    const boton = document.getElementById('clinica-menu-boton');

    if (menu && fondo && boton) {
        const abrir = function (estado) {
            menu.classList.toggle('abierto', estado);
            fondo.hidden = !estado;
            boton.setAttribute('aria-expanded', estado ? 'true' : 'false');
        };

        boton.addEventListener('click', function () { abrir(!menu.classList.contains('abierto')); });
        fondo.addEventListener('click', function () { abrir(false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') abrir(false); });
    }

    // ---- Menú lateral plegado (escritorio): sólo iconos. La preferencia se recuerda en este navegador; si el
    // almacenamiento no está disponible (ventana privada, bloqueado) el menú simplemente arranca desplegado.
    const plegar = document.querySelector('[data-plegar-barra]');
    const LLAVE_BARRA = 'ddr.barra.plegada';

    if (menu && plegar) {
        const aplicarPliegue = function (plegado, recordar) {
            menu.classList.toggle('minimizado', plegado);
            const texto = plegado ? 'Desplegar el menú' : 'Plegar el menú';
            plegar.setAttribute('aria-expanded', plegado ? 'false' : 'true');
            plegar.setAttribute('aria-label', texto);
            plegar.setAttribute('title', texto);
            if (recordar) {
                try { window.localStorage.setItem(LLAVE_BARRA, plegado ? '1' : '0'); } catch (e) { /* sin almacenamiento */ }
            }
        };

        let guardado = false;
        try { guardado = window.localStorage.getItem(LLAVE_BARRA) === '1'; } catch (e) { /* sin almacenamiento */ }
        if (guardado) aplicarPliegue(true, false);

        plegar.addEventListener('click', function () {
            aplicarPliegue(!menu.classList.contains('minimizado'), true);
        });
    }

    // ---- Controles que envían su formulario al cambiar.
    document.addEventListener('change', function (evento) {
        const control = evento.target.closest('[data-autoenviar]');
        if (control && control.form) {
            control.form.submit();
        }
    });

    // ---- Formularios que piden confirmación.
    document.addEventListener('submit', function (evento) {
        const mensaje = evento.target.dataset ? evento.target.dataset.confirmar : null;
        if (mensaje && !window.confirm(mensaje)) {
            evento.preventDefault();
        }
    });

    // ---- "Atender" abre la consulta (WEB-4.1).
    // El formulario de la fila no se envía: abre el diálogo de la página, que es donde el médico elige
    // entre la consulta que ya existe hoy ("re-atender", la pregunta del legado) y una nueva. El envío
    // del diálogo agrega solo `nueva=1` cuando corresponde; el resto del POST es el mismo.
    if ($('dialogo-atender') && $('atender-form')) {
        const aviso = $('atender-aviso');
        const textoAyuda = $('atender-form').querySelector('.form-text');

        document.addEventListener('click', function (evento) {
            const formulario = evento.target.closest('form[data-atender]');
            if (!formulario) return;

            const boton = evento.target.closest('button');
            if (!boton || boton.disabled) {
                return;
            }

            // El botón puede estar dentro del menú de acciones: se cierra antes de abrir el diálogo.
            const menu = formulario.closest('details.menu-acciones');
            if (menu) menu.removeAttribute('open');

            evento.preventDefault();

            const editar = formulario.dataset.editar === '1';
            $('atender-form').action = formulario.action;
            $('atender-paciente').textContent = formulario.dataset.paciente || '';

            // Con la cita ya atendida no hay nada que marcar (y el servidor tampoco lo hace): el diálogo
            // lo dice y ofrece la consulta siguiente. Con la cita sin atender, el caso normal, sólo quedan
            // Cancelar y Atender: "Consulta nueva" no se muestra (revisión de Alexander, 2026-10-17).
            aviso.hidden = !editar;
            aviso.textContent = editar
                ? 'Esta cita ya está atendida. Se abre la consulta de ese día para seguir cargándola; «Consulta nueva» numera la consulta siguiente de la historia.'
                : '';
            $('atender-nueva').hidden = !editar;
            $('atender-abrir').textContent = editar ? 'Abrir la consulta' : 'Atender y abrir la consulta';
            if (textoAyuda) {
                textoAyuda.hidden = editar;
            }

            $('dialogo-atender').showModal();
        });
    }

    // ---- Diálogos nativos: el botón con data-cerrar-dialogo los cierra y el de data-abrir-dialogo abre el indicado.
    document.addEventListener('click', function (evento) {
        const apertura = evento.target.closest('[data-abrir-dialogo]');
        if (apertura) {
            const destino = $(apertura.dataset.abrirDialogo);
            if (destino && typeof destino.showModal === 'function') destino.showModal();
        }

        const cierre = evento.target.closest('[data-cerrar-dialogo]');
        if (cierre) {
            const dialogo = cierre.closest('dialog');
            if (dialogo) dialogo.close();
        }
    });

    // ---- El menú lateral muestra siempre la opción activa (2026-10-23): si la lista es más larga que el alto
    // disponible, sube hasta ella en vez de dejarla escondida abajo.
    (function () {
        const activa = document.querySelector('#clinica-sidebar .nav-link.active');
        if (activa && typeof activa.scrollIntoView === 'function') activa.scrollIntoView({ block: 'nearest' });
    })();

    // ---- Los paneles de plantillas (2026-10-23): un clic en el fondo, a la izquierda del cajón, lo cierra, igual que
    // «Cerrar». Sólo si no hay nada a medias: un título de «Guardar como plantilla» a medio escribir lo mantiene abierto
    // (el buscador no cuenta, no es trabajo del médico).
    document.addEventListener('click', function (evento) {
        const dialogo = evento.target;
        if (!(dialogo instanceof HTMLDialogElement) || !dialogo.open || !dialogo.querySelector('[data-plantillas-panel]')) return;

        const aMedias = Array.from(dialogo.querySelectorAll('input, textarea, select')).some(function (campo) {
            if (campo.hasAttribute('data-plantillas-buscar') || campo.type === 'hidden' || campo.type === 'search') return false;
            return campo.value !== campo.defaultValue;
        });

        if (!aMedias) dialogo.close();
    });

    // ---- Menú de acciones (<details class="menu-acciones">): uno abierto a la vez, y se cierra afuera.
    const cerrarMenus = function (salvo) {
        document.querySelectorAll('details.menu-acciones[open]').forEach(function (abierto) {
            if (abierto !== salvo) abierto.removeAttribute('open');
        });
    };

    document.addEventListener('click', function (evento) {
        cerrarMenus(evento.target.closest('details.menu-acciones'));
    });

    document.addEventListener('keydown', function (evento) {
        if (evento.key === 'Escape') cerrarMenus(null);
    });

    // ---- Tablas con desplazamiento horizontal (`<x-ui.tabla>`, A-16).
    // La tabla que no entra se desliza dentro de su tarjeta en vez de arrastrar la página (el `overflow-x` vive en
    // `.tabla-envoltura`, en doctorisimo-ui.css). Un contenedor que scrollea tiene que poder recorrerse con el
    // teclado y anunciarse: el `tabindex`, el `role` y el `aria-label` se ponen **sólo cuando hay desborde**, para
    // no dejar una parada de tabulación de más en las tablas que entran.
    const envolturas = Array.prototype.slice.call(document.querySelectorAll('.tabla-envoltura, .table-responsive'));

    const marcarEnvoltura = function (envoltura) {
        const desborda = envoltura.scrollWidth > envoltura.clientWidth + 1;

        envoltura.classList.toggle('tabla-desborda', desborda);

        if (desborda) {
            if (!envoltura.hasAttribute('tabindex')) envoltura.setAttribute('tabindex', '0');
            if (!envoltura.hasAttribute('role')) envoltura.setAttribute('role', 'region');
            if (!envoltura.hasAttribute('aria-label')) {
                const encabezado = envoltura.querySelector('table thead th');
                envoltura.setAttribute('aria-label',
                    'Tabla de ' + (encabezado ? encabezado.textContent.trim().toLowerCase() : 'datos')
                    + ': se recorre en horizontal.');
            }
        } else {
            envoltura.removeAttribute('tabindex');
            envoltura.removeAttribute('role');
            envoltura.removeAttribute('aria-label');
        }
    };

    envolturas.forEach(marcarEnvoltura);

    if (typeof window.ResizeObserver === 'function') {
        const observador = new window.ResizeObserver(function (entradas) {
            entradas.forEach(function (entrada) { marcarEnvoltura(entrada.target); });
        });
        // Se observa el contenedor **y la tabla**: el contenedor mide lo que mide su columna, pero la tabla cambia de
        // ancho cuando termina de cargar la tipografía o cuando el contenido se acomoda, y es eso lo que decide si
        // desborda.
        envolturas.forEach(function (envoltura) {
            observador.observe(envoltura);
            if (envoltura.firstElementChild) observador.observe(envoltura.firstElementChild);
        });
    }

    window.addEventListener('resize', function () { envolturas.forEach(marcarEnvoltura); });
    window.addEventListener('load', function () { envolturas.forEach(marcarEnvoltura); });
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(function () { envolturas.forEach(marcarEnvoltura); });
    }

    // ---- El menú «…» de una fila de tabla: se posiciona contra la ventana para que el contenedor que recorta no lo
    // corte. (`overflow-x: auto` también recorta **en vertical**, así que el menú de una fila cerca del final de la
    // tabla se veía cortado: en la agenda, con pocas citas, las opciones directamente no aparecían —2026-10-19—.)
    const RECORTA_MENU = '.tabla-envoltura, .table-responsive';

    const flotarMenu = function (detalles) {
        const lista = detalles.querySelector('.menu-lista');
        const disparador = detalles.querySelector('summary');
        if (!lista || !disparador) return;

        // Alcanza con estar dentro de una envoltura que recorta: que la tabla desborde o no en horizontal es otra
        // cosa (eso decide el `tabindex` de la tabla, no el menú).
        const recortado = detalles.closest(RECORTA_MENU);

        // El `<details>` abierto tiene que quedar por encima de lo que lo rodea, adentro o no de una tabla.
        nivelar(detalles);

        if (!detalles.open || !recortado) {
            lista.classList.remove('menu-lista-flotante');
            lista.style.removeProperty('left');
            lista.style.removeProperty('top');
            return;
        }

        const caja = disparador.getBoundingClientRect();

        lista.classList.add('menu-lista-flotante');
        const ancho = lista.offsetWidth;
        const alto = lista.offsetHeight;

        // Pegado al borde derecho del botón, sin salirse de la ventana.
        const izquierda = Math.min(Math.max(8, caja.right - ancho), window.innerWidth - ancho - 8);

        // Debajo del botón; si no entra en la ventana (última fila de una tabla corta), se abre hacia arriba.
        let arriba = caja.bottom + 4;
        if (alto > 0 && arriba + alto > window.innerHeight - 8) {
            arriba = Math.max(8, caja.top - alto - 4);
        }

        lista.style.left = Math.round(izquierda) + 'px';
        lista.style.top = Math.round(arriba) + 'px';
    };

    /**
     * Un `<details>` dentro de una tabla es un **contexto de apilamiento** de la tabla: el menú flotante, que es
     * `position: fixed`, igual quedaba **detrás** del encabezado fijo o de las filas de al lado si algún antecesor
     * arma uno. Subir el `z-index` del propio `<details>` cuando está abierto es lo que lo saca adelante; el de la
     * lista queda por encima del encabezado pegajoso (`.tabla-agenda thead th`).
     */
    const nivelar = function (detalles) {
        detalles.style.zIndex = detalles.open ? '1070' : '';
    };

    // El evento `toggle` de `<details>` no burbujea: se escucha en la fase de captura.
    document.addEventListener('toggle', function (evento) {
        if (evento.target.matches && evento.target.matches('details.menu-acciones')) flotarMenu(evento.target);
    }, true);

    // Un menú flotante queda pegado a un botón que se mueve: al desplazarse (la página o la tabla) se cierra.
    document.addEventListener('scroll', function () {
        if (document.querySelector('.menu-lista-flotante')) cerrarMenus(null);
    }, true);
})();

// ---- El diálogo guiado de la puesta en marcha: se abre solo al cargar la pantalla (`data-abrir-al-cargar`).
// Es **sólo ese diálogo**: los demás (nueva secretaria, medicamento, dosis…) declaran `data-abrir-al-cargar="0|1"` y los
// abre el script de su pantalla. Buscar cualquier `dialog[data-abrir-al-cargar]` abría el primero aunque dijera «0».
document.addEventListener('DOMContentLoaded', () => {
    const guiado = document.querySelector('dialog#dialogo-paso-esencial[data-abrir-al-cargar]');
    if (guiado && typeof guiado.showModal === 'function' && !guiado.open) guiado.showModal();
});

// ---- El «i» de `<x-ui.info>`: un clic abre el cuadro con el texto, otro clic, Esc o un clic afuera lo cierran.
// Delegado en `document`: sirve también a lo que se pinte después de cargar la página.
(function () {
    const cerrar = function (excepto) {
        document.querySelectorAll('.info-pista .info-boton[aria-expanded="true"]').forEach(function (boton) {
            if (boton === excepto) return;
            boton.setAttribute('aria-expanded', 'false');
            const texto = boton.parentNode.querySelector('.info-texto');
            if (texto) texto.hidden = true;
        });
    };

    document.addEventListener('click', function (evento) {
        const boton = evento.target.closest ? evento.target.closest('[data-info]') : null;
        if (!boton) {
            if (!evento.target.closest || !evento.target.closest('.info-texto')) cerrar(null);
            return;
        }
        evento.preventDefault();
        const texto = boton.parentNode.querySelector('.info-texto');
        if (!texto) return;
        const abrir = texto.hidden;
        cerrar(boton);
        texto.hidden = !abrir;
        boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    });

    document.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Escape') return;
        const abierto = document.querySelector('.info-pista .info-boton[aria-expanded="true"]');
        if (!abierto) return;
        cerrar(null);
        abierto.focus();
    });
})();
