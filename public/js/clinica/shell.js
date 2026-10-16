/*
 * Comportamientos compartidos de la web clínica (`/clinica`). Se carga en `clinica/layout.blade.php`.
 *
 * Las vistas no llevan JS inline: declaran lo que quieren con atributos `data-*` y este archivo los
 * resuelve para toda la aplicación (Docs/Web/guia-arquitectura-web.md).
 *
 *   data-autoenviar           en un <select>/<input>: al cambiar, envía su formulario.
 *   data-confirmar="texto"    en un <form>: pide confirmación antes de enviarlo.
 *   data-atender              en el <form> de "Atender": abre el diálogo de la consulta (WEB-4.1) en vez
 *                             de enviarlo, para elegir entre la consulta de hoy y una nueva.
 *   data-cerrar-dialogo       en un botón: cierra el <dialog> que lo contiene.
 *   data-abrir-dialogo="id"   en un botón: abre ese <dialog> (showModal).
 *   <details class="menu-acciones">   el menú "⋯" cierra al hacer clic afuera o con Escape.
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
            // lo dice y no ofrece "consulta nueva" sin avisar qué implica.
            aviso.hidden = !editar;
            aviso.textContent = editar
                ? 'Esta cita ya está atendida. Se abre la consulta de ese día para seguir cargándola.'
                : '';
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
})();
