/*
 * Comportamientos compartidos de la web clínica (`/clinica`). Se carga en `clinica/layout.blade.php`.
 *
 * Las vistas no llevan JS inline: declaran lo que quieren con atributos `data-*` y este archivo los
 * resuelve para toda la aplicación (Docs/Web/guia-arquitectura-web.md).
 *
 *   data-autoenviar           en un <select>/<input>: al cambiar, envía su formulario.
 *   data-confirmar="texto"    en un <form>: pide confirmación antes de enviarlo.
 *   data-cerrar-dialogo       en un botón: cierra el <dialog> que lo contiene.
 *   <details class="menu-acciones">   el menú "⋯" cierra al hacer clic afuera o con Escape.
 */
(function () {
    'use strict';

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

    // ---- Diálogos nativos: el botón con data-cerrar-dialogo los cierra.
    document.addEventListener('click', function (evento) {
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
