/*
 * Imprimir el récipe (WEB-5.2). Lo cargan la vista previa de la hoja (`clinica/imprimir/recipe`) y las pantallas
 * que abren el diálogo "Imprimir récipe" (la sección de récipes de la consulta y la lista de récipes de una cita).
 * Las vistas no llevan JS inline: declaran lo que quieren con atributos `data-*`.
 *
 *   data-imprimir             en un botón: abre el diálogo de impresión del navegador (`window.print()`).
 *   data-abrir-opciones       en un botón de la vista previa: abre el diálogo de las opciones de la hoja.
 *   data-imprimir-recipe      en un botón de la consulta o de la lista de una cita: abre el diálogo "Imprimir récipe"
 *                             y le dice a qué vista previa seguir (`data-siguiente`, una URL de la web clínica).
 *   data-cerrar-dialogo       en un botón: cierra el <dialog> que lo contiene (`shell.js` lo hace en las pantallas con
 *                             armazón; la vista previa no lo carga).
 *   data-recipe-hojas         el contenedor de las hojas: se achica en pantalla para que la hoja (297 mm) entre en el
 *                             ancho disponible. Al imprimir la hoja vuelve a su tamaño real (lo fija el CSS).
 */
(function () {
    'use strict';

    // ---- Incrustada en el diálogo «Visualizar» de la consulta (WEB-11.24): `?incrustado=1` oculta la barra que sobra.
    if (/[?&]incrustado=1/.test(window.location.search)) {
        document.body.classList.add('es-incrustado');
    }

    // ---- Imprimir: la barra de la vista previa.
    document.querySelectorAll('[data-imprimir]').forEach(function (boton) {
        boton.addEventListener('click', function () { window.print(); });
    });

    // ---- «Incluir imágenes» (WEB-11.13): muestra u oculta la hoja de las imágenes de una eco; la impresión respeta lo que se ve.
    document.querySelectorAll('[data-incluir-imagenes]').forEach(function (casilla) {
        function aplicar() {
            document.querySelectorAll('[data-hoja-imagenes]').forEach(function (hoja) { hoja.hidden = !casilla.checked; });
        }
        casilla.addEventListener('change', aplicar);
        aplicar();
    });

    // ---- Los diálogos de las opciones.
    document.querySelectorAll('[data-abrir-opciones]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            var dialogo = document.getElementById('dialogo-opciones');
            if (dialogo && typeof dialogo.showModal === 'function') dialogo.showModal();
        });
    });

    document.addEventListener('click', function (evento) {
        var abrir = evento.target.closest('[data-imprimir-recipe]');
        if (abrir) {
            var dialogo = document.getElementById('dialogo-imprimir-recipe');
            if (!dialogo || typeof dialogo.showModal !== 'function') return;

            var campo = dialogo.querySelector('[data-siguiente]');
            if (campo) campo.value = abrir.dataset.siguiente || '';

            var titulo = dialogo.querySelector('[data-titulo-del-recipe]');
            if (titulo) titulo.textContent = abrir.dataset.titulo || '';

            dialogo.showModal();
            return;
        }

        var cierre = evento.target.closest('[data-cerrar-dialogo]');
        if (cierre) {
            var caja = cierre.closest('dialog');
            if (caja) caja.close();
        }
    });

    // ---- La vista previa: que la hoja entre en el ancho de la pantalla (en el celular no hay 297 mm).
    var hojas = document.querySelector('[data-recipe-hojas]');

    function ajustar() {
        if (!hojas) return;

        hojas.style.setProperty('--escala', '1');

        var hoja = hojas.querySelector('.recipe-hoja, .informe-hoja');
        if (!hoja) return;

        var natural = hoja.offsetWidth;
        var disponible = document.documentElement.clientWidth - 16;
        var escala = natural > 0 ? Math.min(1, disponible / natural) : 1;

        hojas.style.setProperty('--escala', escala.toFixed(4));
    }

    if (hojas) {
        ajustar();
        window.addEventListener('resize', ajustar);
        window.addEventListener('beforeprint', function () { hojas.style.setProperty('--escala', '1'); });
        window.addEventListener('afterprint', ajustar);
    }
}());
