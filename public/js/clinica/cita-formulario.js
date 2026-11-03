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

/*
 * Un solo envío por vez (2026-10-27, las citas repetidas de la Dra. Cadenas): con una conexión lenta el segundo clic en «Guardar»
 * mandaba otro pedido y la cita se creaba dos veces. Al enviar se desactivan los botones del formulario (en el siguiente turno del
 * navegador, para que el botón pulsado alcance a mandar su `decision`) y vuelven a activarse si la página se restaura con «Atrás».
 */
(function () {
    'use strict';

    document.querySelectorAll('form[method="POST"]').forEach(function (formulario) {
        formulario.addEventListener('submit', function () {
            setTimeout(function () {
                formulario.querySelectorAll('button:not([type="button"])').forEach(function (boton) { boton.disabled = true; });
            }, 0);
        });
    });

    window.addEventListener('pageshow', function (evento) {
        if (!evento.persisted) return;
        document.querySelectorAll('form[method="POST"] button:disabled').forEach(function (boton) { boton.disabled = false; });
    });
})();

/*
 * El alta del paciente nuevo en el diálogo lateral (`#dialogo-paciente-nuevo`, 2026-11-03).
 *
 *  - La abre el «+» del buscador con `data-abrir-dialogo` (shell.js) y, si la validación falló, la abre
 *    sola `data-abrir-al-cargar="1"`.
 *  - En pediatría hay dos pestañas (Paciente / Representante): acá se cambian y, si el servidor dejó un
 *    error, se abre la pestaña donde está.
 *  - «Usar estos datos» sólo cierra el diálogo y resume el paciente nuevo debajo del buscador; el alta se
 *    guarda con «Agendar la cita», que es el POST del formulario.
 */
(function () {
    'use strict';

    const dialogo = document.getElementById('dialogo-paciente-nuevo');
    if (!dialogo) return;

    const paneles = Array.prototype.slice.call(dialogo.querySelectorAll('[data-paciente-panel]'));
    const pestañas = Array.prototype.slice.call(dialogo.querySelectorAll('[data-paciente-tab]'));

    function activar(nombre) {
        pestañas.forEach(function (pestaña) {
            const activa = pestaña.dataset.pacienteTab === nombre;
            pestaña.classList.toggle('active', activa);
            pestaña.setAttribute('aria-selected', activa ? 'true' : 'false');
        });

        paneles.forEach(function (panel) {
            const visible = panel.dataset.pacientePanel === nombre;
            panel.hidden = !visible;
            panel.classList.toggle('show', visible);
            panel.classList.toggle('active', visible);
        });
    }

    pestañas.forEach(function (pestaña) {
        pestaña.addEventListener('click', function () { activar(pestaña.dataset.pacienteTab); });
    });

    function primerError() {
        return dialogo.querySelector('.text-danger, .is-invalid');
    }

    function abrir() {
        if (typeof dialogo.showModal !== 'function' || dialogo.open) return;
        dialogo.showModal();

        const error = primerError();
        const panel = error ? error.closest('[data-paciente-panel]') : null;
        if (panel) activar(panel.dataset.pacientePanel);

        const primero = (panel || dialogo).querySelector('input:not([type="hidden"]):not([type="checkbox"]), select, textarea');
        if (primero) primero.focus();
    }

    if (dialogo.dataset.abrirAlCargar === '1') {
        abrir();
    }

    // «Usar estos datos» cierra (lo hace shell.js con `data-cerrar-dialogo`) y **deja al paciente a la
    // vista en el paso 2**: el buscador muestra su nombre y el resumen dice que es nuevo. El `paciente_id`
    // sigue vacío a propósito: la ficha se crea recién al guardar la cita (cancelar no deja pacientes
    // sueltos), y el alta viaja en los campos `nuevo_*`.
    const listo = dialogo.querySelector('[data-paciente-nuevo-listo]');
    if (listo) {
        listo.addEventListener('click', function () {
            const nombres = document.getElementById('nuevo_nombres');
            const apellidos = document.getElementById('nuevo_apellidos');
            const elegido = document.getElementById('paciente-elegido');
            const buscador = document.getElementById('paciente-busqueda');
            if (!nombres || !elegido) return;

            const completo = [nombres.value, apellidos ? apellidos.value : '']
                .map(function (parte) { return (parte || '').trim(); })
                .filter(Boolean)
                .join(' ');

            if (completo !== '' && !document.getElementById('paciente-id').value) {
                if (buscador) buscador.value = completo;
                elegido.textContent = 'Paciente nuevo: ' + completo + ' (sin historia)';
            }
        });
    }

    // Enter dentro del diálogo **no** manda la cita (el diálogo vive en el POST de la cita): cierra el
    // diálogo como «Usar estos datos» y deja lo escrito. Un textarea sí conserva el salto de línea.
    dialogo.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Enter' || evento.target.tagName === 'TEXTAREA') return;
        evento.preventDefault();
        if (listo) listo.click();
    });

    // El «S.C.» del representante: al marcarlo se vacía la cédula y se deshabilita; al desmarcarlo vuelve.
    const sinCedula = document.getElementById('representante_sin_cedula');
    const cedula = document.getElementById('representante_cedula');
    if (sinCedula && cedula) {
        const aplicar = function () {
            cedula.disabled = sinCedula.checked;
            if (sinCedula.checked) cedula.value = '';
        };
        sinCedula.addEventListener('change', aplicar);
        aplicar();
    }

    // «Madre/Padre» propone el parentesco del catálogo (M/P) sin pisar otra elección (abuelo, tío…).
    const tipo = dialogo.querySelectorAll('input[name="representante_tipo"]');
    const parentesco = document.getElementById('representante_parentesco');
    if (parentesco) {
        tipo.forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked) return;
                const actual = parentesco.value;
                if (actual !== '' && actual !== 'M' && actual !== 'P') return;
                parentesco.value = radio.value === 'M' ? 'P' : 'M';
            });
        });
    }
})();
