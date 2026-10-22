/*
 * Formulario de la cita (`/clinica/agenda/nueva` y `/editar`):
 *
 *  1. El precio del motivo (WEB-8.1c). Al elegir el motivo, el campo Monto muestra como referencia el precio que ese motivo
 *     tiene en el catálogo (`data-precio` de cada `<option>`) y, si el motivo no tiene precio, el monto pasa a ser obligatorio. Es sólo una pista: el servidor sigue
 *     resolviendo el monto vacío con el precio vigente del motivo, y lo escrito en Monto manda.
 *       <select id="motivo" data-motivo-select> <option data-precio="150.00" data-precio-texto="150,00">
 *       <input id="monto">   <span id="monto-obligatorio">
 *
 *  2. El buscador de pacientes (abajo).
 */
(function () {
    'use strict';

    const motivo = document.querySelector('[data-motivo-select]');
    const monto = document.getElementById('monto');
    const marca = document.getElementById('monto-obligatorio');
    if (!motivo || !monto) return;

    function mostrarPrecio() {
        const opcion = motivo.options[motivo.selectedIndex];
        const precio = opcion ? opcion.dataset.precio : '';
        // Un motivo elegido sin precio (data-precio vacío) obliga a escribir el monto; «Sin motivo» no.
        const sinPrecio = !!opcion && opcion.value !== '' && precio === '';

        monto.placeholder = sinPrecio ? 'Sin precio: escríbelo (0 si es gratis)' : (precio || '');
        monto.required = sinPrecio;
        if (marca) marca.hidden = !sinPrecio;
    }

    motivo.addEventListener('change', mostrarPrecio);
})();

/*
 * El buscador de pacientes del formulario de la cita (`/clinica/agenda/nueva` y `/editar`). Pide al servidor los
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
        // El campo es un combobox: el lector de pantalla se entera de que la lista se cerró.
        caja.setAttribute('aria-expanded', 'false');
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
            boton.setAttribute('role', 'option');
            boton.textContent = paciente.nombre
                + (paciente.cedula ? ' · ' + paciente.cedula : '')
                + (paciente.numhistoria ? ' · H. ' + paciente.numhistoria : ' · sin historia');
            boton.addEventListener('click', function () { elegir(paciente); });
            lista.appendChild(boton);
        });

        lista.hidden = false;
        caja.setAttribute('aria-expanded', 'true');
    }

    // Escape cierra la lista sin elegir a nadie, como cualquier combobox.
    caja.addEventListener('keydown', function (evento) {
        if (evento.key === 'Escape' && !lista.hidden) {
            limpiar();
        }
    });

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
