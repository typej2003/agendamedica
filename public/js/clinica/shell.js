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

    // ---- El menú «…» de una fila de esas tablas: se posiciona contra la ventana para que el contenedor que
    // recorta no lo corte (si no, el menú de la última fila aparecía recortado o había que scrollear para verlo).
    const flotarMenu = function (detalles) {
        const lista = detalles.querySelector('.menu-lista');
        const disparador = detalles.querySelector('summary');
        if (!lista || !disparador) return;

        const recortado = detalles.closest('.tabla-desborda');

        if (!detalles.open || !recortado) {
            lista.classList.remove('menu-lista-flotante');
            lista.style.removeProperty('left');
            lista.style.removeProperty('top');
            return;
        }

        const caja = disparador.getBoundingClientRect();
        lista.classList.add('menu-lista-flotante');
        lista.style.left = Math.max(8, Math.round(caja.right - lista.offsetWidth)) + 'px';
        lista.style.top = Math.round(caja.bottom + 4) + 'px';
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
