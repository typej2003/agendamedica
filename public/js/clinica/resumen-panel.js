// El cajón con la historia y el resumen del paciente (O-30), en las pantallas donde se redacta un documento.
//
// El cajón es el `<dialog>` lateral de la guía de UI §9.3 (el mismo de las plantillas): `Esc`, el fondo y el
// foco los resuelve el navegador y el cierre lo resuelve `shell.js` (`data-cerrar-dialogo`). Acá queda sólo lo
// que es suyo:
//
//  - Abrirlo desde el botón del encabezado (`[data-abrir-resumen]`, con la dirección de la historia en
//    `data-url`). **No se abre solo**: no hay `autofocus`, ni sesión, ni `localStorage`; es una acción del
//    médico y no un estado guardado (el resumen son datos clínicos del paciente).
//  - Traer el resumen **la primera vez que se abre**, con `?panel=1`: es la lectura de la pantalla de la
//    historia (la misma vista, sin el armazón), así que este script no arma ningún resumen. Se vuelve a pedir
//    en cada apertura para que lo que se vea sea lo de ese momento, y mientras llega se mantiene lo anterior.
//  - Abrir aparte los enlaces del resumen («ver la ficha», «ver consulta», «imprimir»): la consulta no se
//    abandona y no se pierde lo escrito.
(function () {
    'use strict';

    var boton = document.querySelector('[data-abrir-resumen]');
    var dialogo = document.getElementById('dialogo-resumen');

    if (!boton || !dialogo || typeof dialogo.showModal !== 'function') { return; }

    var cuerpo = dialogo.querySelector('[data-resumen-cuerpo]');
    var url = boton.dataset.url;
    var cargando = false;

    if (!cuerpo || !url) { return; }

    function decir(texto, error) {
        cuerpo.textContent = texto;
        cuerpo.classList.toggle('es-error', !!error);
    }

    /** Los enlaces del resumen abren en otra pestaña: la consulta no se abandona. */
    function aparte() {
        cuerpo.querySelectorAll('a[href]').forEach(function (enlace) {
            enlace.target = '_blank';
            enlace.rel = 'noopener';
        });
    }

    function cargar() {
        if (cargando) { return; }
        cargando = true;

        var direccion = url + (url.indexOf('?') === -1 ? '?' : '&') + 'panel=1';

        fetch(direccion, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
        }).then(function (respuesta) {
            if (!respuesta.ok) { throw new Error(String(respuesta.status)); }
            return respuesta.text();
        }).then(function (html) {
            cuerpo.innerHTML = html;
            cuerpo.classList.remove('es-error');
            aparte();
        }).catch(function () {
            decir('No se pudo cargar la historia. Abrila en otra pestaña con el botón de abajo.', true);
        }).then(function () {
            cargando = false;
        });
    }

    boton.addEventListener('click', function () {
        dialogo.showModal();
        cargar();
    });
})();
