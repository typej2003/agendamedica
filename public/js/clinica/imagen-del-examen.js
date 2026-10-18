// **La imagen del examen con sus dos modos** (O-09): el radiobutón entre la *imagen predeterminada* (el esquema) y el
// *videocolposcopio* (la foto real del estudio), y el lienzo de cada uno.
//
// El dibujo es el motor compartido (`public/js/clinica/lienzo.js`), el mismo de las mamas y del cuello uterino: acá sólo
// se le pasan los selectores, la base elegida del esquema y —cuando el lienzo es una foto— nada más, porque la URL ya
// identifica la foto.
//
// Lo propio de esta pantalla es:
//
//   1. **Mostrar un solo modo**: los dos paneles están en el HTML y se alterna con el atributo `hidden`, así cambiar de
//      modo no recarga la pantalla (recargar perdería el texto del informe que todavía no se guardó).
//   2. **Guardar la elección sin recargar** (`POST` JSON a la ruta de la elección): es lo que decide qué se imprime.
//      Sin JS, el botón «Ver» envía el mismo formulario y el servidor vuelve a pintar la tarjeta en el modo elegido.
//   3. **Elegir la foto que se marca** cuando el estudio tiene más de una: se ve el lienzo de esa foto y se recuerda.
(function () {
    'use strict';

    var formulario = document.querySelector('[data-imagen-modo]');
    var panelEsquema = document.querySelector('[data-imagen-panel="esquema"]');
    var panelFoto = document.querySelector('[data-imagen-panel="foto"]');
    var selectorFoto = document.querySelector('[data-imagen-foto]');
    var elegida = formulario ? formulario.querySelector('[data-imagen-elegida]') : null;
    var avisoModo = formulario ? formulario.querySelector('[data-imagen-modo-estado]') : null;
    var token = document.querySelector('meta[name="csrf-token"]');

    if (window.Lienzo) {
        window.Lienzo.iniciar({
            herramientas: '[data-lienzo-herramientas]',
            color: '[data-lienzo-color]',
            grosor: '[data-lienzo-grosor]',
            grosorValor: '[data-lienzo-grosor-valor]',
            estado: '[data-lienzo-estado]',
            muestra: '[data-lienzo-muestra]',
            lienzo: '[data-lienzo]',
            canvas: '[data-lienzo-canvas]',
            deshacer: '[data-lienzo-deshacer]',
            borrar: '[data-lienzo-borrar]',
            avisoBorrar: '¿Borrar todo el trazo?',
            base: '[data-lienzo-base]',
            imagen: '[data-lienzo-imagen]',
            // La base elegida viaja en el POST del trazo y en la query del DELETE (que es el que quita el dibujo). Un
            // lienzo que no elige base —el de una foto— no manda nada.
            datos: function (bloque) {
                var selector = bloque.querySelector('[data-lienzo-base]');

                return selector ? { base: selector.value } : {};
            },
        });
    }

    if (!formulario) { return; }

    var botonVer = formulario.querySelector('[data-imagen-modo-ver]');
    if (botonVer) { botonVer.hidden = true; }

    function decir(texto, error) {
        if (!avisoModo) { return; }
        avisoModo.textContent = texto;
        avisoModo.classList.toggle('es-error', !!error);
    }

    function marcado() {
        var opcion = formulario.querySelector('[data-imagen-modo-opcion]:checked');

        return opcion ? opcion.value : 'esquema';
    }

    function fotoElegida() {
        if (selectorFoto) { return selectorFoto.value; }

        return elegida ? elegida.value : '';
    }

    /** El panel de cada modo: se ve sólo el elegido. */
    function mostrarLosPaneles() {
        var modo = marcado();

        if (panelEsquema) { panelEsquema.hidden = modo !== 'esquema'; }
        if (panelFoto) { panelFoto.hidden = modo !== 'foto'; }
    }

    /** Guarda la elección (el modo y la foto) sin recargar la pantalla. */
    function guardar(modo, foto) {
        var datos = new FormData();

        datos.append('modo', modo);
        if (foto) { datos.append('imagen_id', foto); }

        decir('Guardando…');

        fetch(formulario.action, {
            method: 'POST',
            body: datos,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' },
        })
            .then(function (respuesta) {
                decir(respuesta.ok ? 'Guardado' : 'No se pudo guardar la elección', !respuesta.ok);
            })
            .catch(function () { decir('No se pudo guardar la elección', true); });
    }

    formulario.querySelectorAll('[data-imagen-modo-opcion]').forEach(function (opcion) {
        opcion.addEventListener('change', function () {
            mostrarLosPaneles();
            guardar(marcado(), fotoElegida());
        });
    });

    // Sin JS el formulario se envía (y el servidor vuelve a pintar la tarjeta en el modo elegido); con JS se guarda sin
    // salir de la pantalla.
    formulario.addEventListener('submit', function (evento) {
        evento.preventDefault();
        mostrarLosPaneles();
        guardar(marcado(), fotoElegida());
    });

    // Varias fotos: se marca **una**. Se ve su lienzo y se recuerda cuál es la que se imprime.
    if (selectorFoto) {
        selectorFoto.addEventListener('change', function () {
            var foto = parseInt(selectorFoto.value, 10);

            document.querySelectorAll('[data-lienzo][data-foto]').forEach(function (bloque) {
                bloque.hidden = parseInt(bloque.dataset.foto, 10) !== foto;
            });

            if (elegida) { elegida.value = foto; }

            guardar('foto', foto);
        });
    }
})();
