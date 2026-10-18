// Lienzos de dibujo clínico (A-13): el motor que comparten las **imágenes de mamas** (WEB-11.10) y el **cuello
// uterino de la colposcopía**. Se dibuja con el puntero (mouse, y el dedo en una tablet) con el color y el grosor
// elegidos; «Deshacer» quita el último trazo y «Borrar todo» limpia (y quita el dibujo guardado). Se guarda solo, 800
// ms después de soltar un trazo, como un **PNG transparente** (sólo el trazo; la imagen base es la que está debajo).
// Si ya había un dibujo guardado se carga como la primera capa.
//
// No sabe de mamas ni de colposcopía: cada pantalla lo inicializa con sus selectores y, si quiere, con datos extra
// para el guardado. Así el comportamiento es uno solo —lo que una pantalla arregle, la otra lo hereda—:
//
//   Lienzo.iniciar({
//       herramientas: '[data-mamas-herramientas]', // el bloque de color, grosor y estado
//       color: '[data-mamas-color]', grosor: '[data-mamas-grosor]', grosorValor: '[data-mamas-grosor-valor]',
//       estado: '[data-mamas-estado]', muestra: '[data-mamas-muestra]',
//       lienzo: '[data-mama-lienzo]',   // cada bloque: data-url-guardar y data-url-dibujo
//       canvas: 'canvas', deshacer: '[data-mama-deshacer]', borrar: '[data-mama-borrar]',
//       avisoBorrar: '¿Borrar todo el dibujo de esta mama?',
//   });
//
// Opcionales: `datos(bloque)` devuelve los campos extra del guardado —la colposcopía manda ahí su `base`—,
// `base` y `imagen` son los selectores del selector de imagen base y del `<img>` que se cambia con él. Al cambiar la
// base se guarda **sólo el dato**, sin volver a subir el trazo.
(function () {
    'use strict';

    function iniciar(opciones) {
        var herramientas = document.querySelector(opciones.herramientas);
        if (!herramientas) { return; }

        var inputColor = herramientas.querySelector(opciones.color);
        var inputGrosor = herramientas.querySelector(opciones.grosor);
        var salidaGrosor = herramientas.querySelector(opciones.grosorValor);
        var estado = herramientas.querySelector(opciones.estado);
        var token = document.querySelector('meta[name="csrf-token"]');
        var avisoBorrar = opciones.avisoBorrar || '¿Borrar todo el dibujo?';

        function decir(texto, error) {
            if (!estado) { return; }
            estado.textContent = texto;
            estado.classList.toggle('es-error', !!error);
        }

        herramientas.querySelectorAll(opciones.muestra).forEach(function (muestra) {
            // Cada pantalla dice de dónde sale el color de su muestra (`data-mamas-muestra`, `data-lienzo-muestra`…).
            var valorDe = typeof opciones.muestraValor === 'function'
                ? opciones.muestraValor
                : function (boton) { return boton.dataset.muestra; };
            var valor = valorDe(muestra);

            if (valor) { muestra.style.setProperty('--muestra', valor); }
            muestra.addEventListener('click', function () { if (valor) { inputColor.value = valor; } });
        });
        inputGrosor.addEventListener('input', function () { salidaGrosor.textContent = inputGrosor.value; });

        document.querySelectorAll(opciones.lienzo).forEach(function (bloque) {
            var canvas = bloque.querySelector(opciones.canvas || 'canvas');
            if (!canvas) { return; }

            var ctx = canvas.getContext('2d');
            var trazos = [];       // cada trazo: { color, grosor, puntos: [[x, y], ...] }
            var capaGuardada = null; // la imagen del dibujo ya guardado
            var actual = null;
            var pausa = null;

            /** Los campos extra del guardado de este lienzo (la base elegida, en la colposcopía). */
            function extra() {
                return typeof opciones.datos === 'function' ? (opciones.datos(bloque) || {}) : {};
            }

            /** La URL del guardado con los campos extra en la query (el DELETE no lleva cuerpo). */
            function conLosDatos(url) {
                var partes = [];
                var valores = extra();

                Object.keys(valores).forEach(function (clave) {
                    var valor = valores[clave];
                    if (valor !== null && valor !== undefined && valor !== '') {
                        partes.push(encodeURIComponent(clave) + '=' + encodeURIComponent(valor));
                    }
                });

                if (partes.length === 0) { return url; }

                return url + (url.indexOf('?') === -1 ? '?' : '&') + partes.join('&');
            }

            function redibujar() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                if (capaGuardada) { ctx.drawImage(capaGuardada, 0, 0, canvas.width, canvas.height); }
                trazos.forEach(pintar);
            }

            function pintar(trazo) {
                ctx.strokeStyle = trazo.color;
                ctx.fillStyle = trazo.color;
                ctx.lineWidth = trazo.grosor;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                if (trazo.puntos.length === 1) {
                    ctx.beginPath();
                    ctx.arc(trazo.puntos[0][0], trazo.puntos[0][1], trazo.grosor / 2, 0, Math.PI * 2);
                    ctx.fill();
                    return;
                }
                ctx.beginPath();
                trazo.puntos.forEach(function (p, i) { if (i === 0) { ctx.moveTo(p[0], p[1]); } else { ctx.lineTo(p[0], p[1]); } });
                ctx.stroke();
            }

            function punto(evento) {
                var caja = canvas.getBoundingClientRect();
                return [(evento.clientX - caja.left) * canvas.width / caja.width, (evento.clientY - caja.top) * canvas.height / caja.height];
            }

            function programarGuardado() {
                window.clearTimeout(pausa);
                decir('Cambios sin guardar…');
                pausa = window.setTimeout(guardar, 800);
            }

            /**
             * Guarda **sólo los datos** del lienzo, sin el trazo (la imagen base de la colposcopía). Es lo que se usa al
             * cambiar la base: el trazo guardado no se reescribe ni se pierde, y la elección queda registrada aunque el
             * médico todavía no haya dibujado.
             */
            function guardarDatos() {
                var datos = new FormData();
                var valores = extra();
                var cabeceras = { 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' };

                Object.keys(valores).forEach(function (clave) {
                    if (valores[clave] !== null && valores[clave] !== undefined) { datos.append(clave, valores[clave]); }
                });

                decir('Guardando…');

                fetch(bloque.dataset.urlGuardar, { method: 'POST', body: datos, credentials: 'same-origin', headers: cabeceras })
                    .then(function (r) { decir(r.ok ? 'Guardado' : 'No se pudo guardar', !r.ok); })
                    .catch(function () { decir('No se pudo guardar', true); });
            }

            function guardar() {
                var vacio = trazos.length === 0 && !capaGuardada;
                var cabeceras = { 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' };

                if (vacio) {
                    // Sin trazo se quita el guardado; los datos extra (la base) viajan igual para que se recuerden.
                    fetch(conLosDatos(bloque.dataset.urlGuardar), { method: 'DELETE', credentials: 'same-origin', headers: cabeceras })
                        .then(function (r) { decir(r.ok ? 'Guardado' : 'No se pudo guardar', !r.ok); })
                        .catch(function () { decir('No se pudo guardar', true); });
                    return;
                }

                canvas.toBlob(function (blob) {
                    var datos = new FormData();
                    var valores = extra();

                    datos.append('dibujo', blob, 'dibujo.png');
                    Object.keys(valores).forEach(function (clave) {
                        if (valores[clave] !== null && valores[clave] !== undefined) { datos.append(clave, valores[clave]); }
                    });

                    fetch(bloque.dataset.urlGuardar, { method: 'POST', body: datos, credentials: 'same-origin', headers: cabeceras })
                        .then(function (r) { decir(r.ok ? 'Guardado' : 'No se pudo guardar', !r.ok); })
                        .catch(function () { decir('No se pudo guardar', true); });
                }, 'image/png');
            }

            canvas.addEventListener('pointerdown', function (evento) {
                if (evento.button !== 0 && evento.pointerType === 'mouse') { return; }
                canvas.setPointerCapture(evento.pointerId);
                actual = { color: inputColor.value, grosor: parseInt(inputGrosor.value, 10), puntos: [punto(evento)] };
                trazos.push(actual);
                redibujar();
                evento.preventDefault();
            });
            canvas.addEventListener('pointermove', function (evento) {
                if (!actual) { return; }
                actual.puntos.push(punto(evento));
                redibujar();
            });
            function soltar() {
                if (!actual) { return; }
                actual = null;
                programarGuardado();
            }
            canvas.addEventListener('pointerup', soltar);
            canvas.addEventListener('pointercancel', soltar);

            bloque.querySelector(opciones.deshacer).addEventListener('click', function () {
                if (trazos.length === 0) { return; }
                trazos.pop();
                redibujar();
                programarGuardado();
            });
            bloque.querySelector(opciones.borrar).addEventListener('click', function () {
                if ((trazos.length > 0 || capaGuardada) && !window.confirm(avisoBorrar)) { return; }
                trazos = [];
                capaGuardada = null;
                redibujar();
                programarGuardado();
            });

            // El selector de imagen base (colposcopía): cambia el `<img>` de fondo y **vuelve a guardar** con la base
            // nueva, sin tocar el trazo (el guardado manda el mismo dibujo, que se conserva).
            if (opciones.base) {
                var selector = bloque.querySelector(opciones.base);
                var imagenBase = opciones.imagen ? bloque.querySelector(opciones.imagen) : null;

                if (selector) {
                    selector.addEventListener('change', function () {
                        var opcion = selector.options[selector.selectedIndex];
                        if (imagenBase && opcion && opcion.dataset.imagen) { imagenBase.src = opcion.dataset.imagen; }
                        // Sólo la base: el trazo guardado se queda como está (y un trazo sin guardar sigue su curso).
                        guardarDatos();
                    });
                }
            }

            if (bloque.dataset.urlDibujo) {
                var imagen = new Image();
                imagen.onload = function () { capaGuardada = imagen; redibujar(); };
                imagen.src = bloque.dataset.urlDibujo;
            }
        });
    }

    window.Lienzo = { iniciar: iniciar };
})();
