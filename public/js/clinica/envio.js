/*
 * Envío del día (`/clinica/agenda/envio`): el canal manda — los pacientes sin el dato de ese canal no se
 * pueden seleccionar (y la fila dice por qué) — y un SMS de más de 160 caracteres se avisa.
 *
 *   <tr data-telefono="1|0" data-correo="1|0"> con un <input class="envio-cita">
 *   <input type="radio" data-canal="sms|whatsapp|correo">
 */
(function () {
    'use strict';

    const filas = Array.from(document.querySelectorAll('tr[data-telefono]'));
    const mensaje = document.getElementById('envio-mensaje');
    if (!mensaje) return;

    const casillas = function () { return Array.from(document.querySelectorAll('.envio-cita')); };

    function canalElegido() {
        const elegido = document.querySelector('[data-canal]:checked');
        return elegido ? elegido.value : 'sms';
    }

    function actualizarResumen() {
        const marcadas = casillas().filter(function (casilla) { return casilla.checked; }).length;
        document.getElementById('envio-resumen').textContent = marcadas + ' de ' + filas.length + ' seleccionados';
    }

    function actualizarContador() {
        const largo = mensaje.value.length;
        const aviso = canalElegido() === 'sms' && largo > 160 ? ' — pasa los 160 caracteres de un SMS' : '';
        document.getElementById('envio-contador').textContent = largo + ' caracteres' + aviso;
    }

    function aplicarCanal() {
        const atributo = canalElegido() === 'correo' ? 'correo' : 'telefono';

        filas.forEach(function (fila) {
            const puede = fila.dataset[atributo] === '1';
            const casilla = fila.querySelector('.envio-cita');
            const estabaFuera = casilla.disabled;

            casilla.disabled = !puede;
            fila.classList.toggle('text-muted', !puede);

            // Al cambiar de canal, el que vuelve a poder recibir entra marcado; el que ya estaba
            // disponible conserva lo que el usuario eligió.
            if (!puede) {
                casilla.checked = false;
            } else if (estabaFuera) {
                casilla.checked = true;
            }
        });

        actualizarResumen();
    }

    document.querySelectorAll('[data-canal]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            aplicarCanal();
            actualizarContador();
        });
    });

    const todos = document.getElementById('envio-todos');
    if (todos) {
        todos.addEventListener('change', function (evento) {
            casillas().forEach(function (casilla) {
                if (!casilla.disabled) {
                    casilla.checked = evento.target.checked;
                }
            });
            actualizarResumen();
        });
    }

    casillas().forEach(function (casilla) { casilla.addEventListener('change', actualizarResumen); });
    mensaje.addEventListener('input', actualizarContador);

    aplicarCanal();
    actualizarContador();
})();
