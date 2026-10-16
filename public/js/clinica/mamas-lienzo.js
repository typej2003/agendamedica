// Imágenes de mamas (WEB-11.10): el lienzo de cada mama. Se dibuja con el puntero (mouse, y el dedo en una tablet), con el color y el
// grosor elegidos; "Deshacer" quita el último trazo y "Borrar todo" limpia (y quita el dibujo guardado). Se guarda solo, 800 ms
// después de soltar un trazo, como un PNG transparente (sólo el trazo; la imagen base es la de debajo). Si ya había un dibujo
// guardado se carga como la primera capa.
(function () {
    'use strict';

    var herramientas = document.querySelector('[data-mamas-herramientas]');
    if (!herramientas) { return; }

    var inputColor = herramientas.querySelector('[data-mamas-color]');
    var inputGrosor = herramientas.querySelector('[data-mamas-grosor]');
    var salidaGrosor = herramientas.querySelector('[data-mamas-grosor-valor]');
    var estado = herramientas.querySelector('[data-mamas-estado]');
    var token = document.querySelector('meta[name="csrf-token"]');

    function decir(texto, error) {
        estado.textContent = texto;
        estado.classList.toggle('es-error', !!error);
    }

    herramientas.querySelectorAll('[data-mamas-muestra]').forEach(function (muestra) {
        muestra.style.setProperty('--muestra', muestra.dataset.mamasMuestra);
        muestra.addEventListener('click', function () { inputColor.value = muestra.dataset.mamasMuestra; });
    });
    inputGrosor.addEventListener('input', function () { salidaGrosor.textContent = inputGrosor.value; });

    document.querySelectorAll('[data-mama-lienzo]').forEach(function (bloque) {
        var canvas = bloque.querySelector('canvas');
        var ctx = canvas.getContext('2d');
        var trazos = [];       // cada trazo: { color, grosor, puntos: [[x, y], ...] }
        var capaGuardada = null; // la imagen del dibujo ya guardado
        var actual = null;
        var pausa = null;

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

        function guardar() {
            var vacio = trazos.length === 0 && !capaGuardada;
            var datos = new FormData();
            var cabeceras = { 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' };

            if (vacio) {
                fetch(bloque.dataset.urlGuardar, { method: 'DELETE', credentials: 'same-origin', headers: cabeceras })
                    .then(function (r) { decir(r.ok ? 'Guardado' : 'No se pudo guardar', !r.ok); })
                    .catch(function () { decir('No se pudo guardar', true); });
                return;
            }

            canvas.toBlob(function (blob) {
                datos.append('dibujo', blob, 'dibujo.png');
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

        bloque.querySelector('[data-mama-deshacer]').addEventListener('click', function () {
            if (trazos.length === 0) { return; }
            trazos.pop();
            redibujar();
            programarGuardado();
        });
        bloque.querySelector('[data-mama-borrar]').addEventListener('click', function () {
            if ((trazos.length > 0 || capaGuardada) && !window.confirm('¿Borrar todo el dibujo de esta mama?')) { return; }
            trazos = [];
            capaGuardada = null;
            redibujar();
            programarGuardado();
        });

        if (bloque.dataset.urlDibujo) {
            var imagen = new Image();
            imagen.onload = function () { capaGuardada = imagen; redibujar(); };
            imagen.src = bloque.dataset.urlDibujo;
        }
    });
})();
