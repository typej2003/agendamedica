/*
 * Buscador de pacientes de la vista de día (WEB-2.14). Es el mismo patrón del buscador del alta de cita
 * (`cita-formulario.js`): 250 ms de espera, dos caracteres como mínimo y `fetch` con
 * `Accept: application/json`. La diferencia es qué hace al elegir: en el alta el resultado se marca, acá
 * cada fila lleva su **"Hacer cita"** al formulario con el paciente ya elegido.
 *
 *   <div id="agenda-paciente-busqueda" data-url="…/pacientes/buscar" data-nueva="…/agenda/nueva?fecha=…">
 *   <template id="agenda-paciente-plantilla"> con .resultado-paciente-datos y un <a> "Hacer cita"
 */
(function () {
    'use strict';

    const caja = document.getElementById('agenda-paciente-busqueda');
    if (!caja) return;

    const texto = document.getElementById('agenda-paciente-texto');
    const lista = document.getElementById('agenda-paciente-resultados');
    const ayuda = document.getElementById('agenda-paciente-ayuda');
    const plantilla = document.getElementById('agenda-paciente-plantilla');
    const base = caja.dataset.url;
    // El `paciente_id` lo agrega el alta: la pantalla ya acepta el paciente elegido, así que el buscador
    // no inventa un camino nuevo.
    const nueva = caja.dataset.nueva + (caja.dataset.nueva.indexOf('?') === -1 ? '?' : '&');
    const ayudaInicial = ayuda.textContent;
    let temporizador = null;

    function limpiar() {
        lista.innerHTML = '';
        lista.hidden = true;
    }

    function pintar(pacientes) {
        lista.innerHTML = '';

        if (!pacientes.length) {
            limpiar();
            ayuda.textContent = 'Ningún paciente del consultorio coincide con lo buscado.';
            return;
        }

        pacientes.forEach(function (paciente) {
            const fila = plantilla.content.firstElementChild.cloneNode(true);
            fila.querySelector('.resultado-paciente-datos').textContent = paciente.nombre
                + (paciente.cedula ? ' · ' + paciente.cedula : '')
                + (paciente.numhistoria ? ' · H. ' + paciente.numhistoria : ' · sin historia');
            fila.querySelector('a').href = nueva + 'paciente_id=' + paciente.id;
            lista.appendChild(fila);
        });

        lista.hidden = false;
        ayuda.textContent = pacientes.length + (pacientes.length === 1 ? ' paciente' : ' pacientes')
            + ' — elige «Hacer cita» para agendarle.';
    }

    function buscar() {
        const consulta = texto.value.trim();
        if (consulta.length < 2) {
            limpiar();
            return;
        }

        fetch(base + '?q=' + encodeURIComponent(consulta), { headers: { 'Accept': 'application/json' } })
            .then(function (respuesta) { return respuesta.json(); })
            .then(pintar)
            .catch(function () {
                limpiar();
                ayuda.textContent = 'No se pudo buscar: revisa la conexión con el servidor.';
            });
    }

    texto.addEventListener('input', function () {
        clearTimeout(temporizador);
        if (texto.value.trim().length < 2) {
            limpiar();
            ayuda.textContent = ayudaInicial;
        }
        temporizador = setTimeout(buscar, 250);
    });

    document.addEventListener('click', function (evento) {
        if (evento.target !== texto && !lista.contains(evento.target)) {
            limpiar();
        }
    });
})();
