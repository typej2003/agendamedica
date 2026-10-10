/*
 * Formulario de la cita (`/clinica/agenda/nueva` y `/editar`): el buscador de pacientes. Pide al servidor los
 * que coinciden y deja el elegido en el campo oculto. Sin él, el formulario se puede completar igual con el
 * alta de un paciente nuevo.
 *
 *   <input id="paciente-busqueda" data-url="…/pacientes/buscar">   <div id="paciente-resultados" hidden>
 *   <input type="hidden" name="paciente_id" id="paciente-id">      <p id="paciente-elegido">
 */
(function () {
    'use strict';

    const caja = document.getElementById('paciente-busqueda');
    if (!caja) return;

    const lista = document.getElementById('paciente-resultados');
    const elegido = document.getElementById('paciente-elegido');
    const url = caja.dataset.url;
    let temporizador = null;

    function limpiar() {
        lista.innerHTML = '';
        lista.hidden = true;
    }

    function descripcion(paciente) {
        return paciente.nombre + (paciente.numhistoria ? ' · H. ' + paciente.numhistoria : ' · sin historia');
    }

    function elegir(paciente) {
        document.querySelectorAll('input[name="paciente_id"]').forEach(function (oculto) {
            oculto.value = paciente.id;
        });
        caja.value = paciente.nombre;
        elegido.textContent = 'Elegido: ' + descripcion(paciente);
        limpiar();
    }

    function pintar(pacientes) {
        lista.innerHTML = '';

        if (!pacientes.length) {
            limpiar();
            return;
        }

        pacientes.forEach(function (paciente) {
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'list-group-item list-group-item-action small';
            boton.textContent = paciente.nombre
                + (paciente.cedula ? ' · ' + paciente.cedula : '')
                + (paciente.numhistoria ? ' · H. ' + paciente.numhistoria : ' · sin historia');
            boton.addEventListener('click', function () { elegir(paciente); });
            lista.appendChild(boton);
        });

        lista.hidden = false;
    }

    caja.addEventListener('input', function () {
        document.querySelectorAll('input[name="paciente_id"]').forEach(function (oculto) {
            oculto.value = '';
        });

        clearTimeout(temporizador);
        const texto = caja.value.trim();

        if (texto.length < 2) {
            limpiar();
            return;
        }

        temporizador = setTimeout(function () {
            fetch(url + '?q=' + encodeURIComponent(texto), { headers: { 'Accept': 'application/json' } })
                .then(function (respuesta) { return respuesta.json(); })
                .then(pintar)
                .catch(limpiar);
        }, 250);
    });

    document.addEventListener('click', function (evento) {
        if (evento.target !== caja && !lista.contains(evento.target)) {
            limpiar();
        }
    });
})();
