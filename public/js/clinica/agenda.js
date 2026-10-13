/*
 * Agenda de la web clínica (`/clinica/agenda`): los diálogos de cobro y de recordatorio y el arrastre
 * para reordenar la cola. Sin dependencias; todo se declara con atributos `data-*` en la vista.
 *
 *   <button data-dialogo="cobro"        data-url data-paciente data-pendiente data-requiere-monto data-detalle>
 *   <button data-dialogo="recordatorio" data-url data-paciente data-mensaje data-tiene-telefono data-tiene-correo>
 *   <tbody class="lista-cola" data-fecha data-reordenar data-csrf>  con filas  <tr draggable data-id data-numorden>
 */
(function () {
    'use strict';

    const $ = function (id) { return document.getElementById(id); };

    // ------------------------------------------------------------------------------------------ Cobro
    // El diálogo se llena desde los datos de la cita; el monto solo se pide si la cita todavía no tiene
    // precio (lo común en los datos del legado).
    function abrirCobro(boton) {
        const datos = boton.dataset;
        $('cobro-form').action = datos.url;
        $('cobro-paciente').textContent = datos.paciente;
        $('cobro-monto-fila').hidden = datos.requiereMonto !== '1';
        $('cobro-monto').value = '';
        $('cobro-recibido').value = datos.pendiente;
        $('cobro-detalle').textContent = datos.detalle;
        $('dialogo-cobro').showModal();
        $('cobro-recibido').focus();
    }

    // ---------------------------------------------------------------------------------- Recordatorio
    // Los tres canales se muestran siempre: el que el paciente no puede recibir queda deshabilitado y el
    // aviso dice cuál falta. Un SMS de más de 160 caracteres se avisa (antes se recortaba en silencio).
    function abrirRecordatorio(boton) {
        const datos = boton.dataset;
        $('mensaje-form').action = datos.url;
        $('mensaje-paciente').textContent = datos.paciente;
        $('mensaje-texto').value = datos.mensaje;

        const disponible = {
            sms: datos.tieneTelefono === '1',
            whatsapp: datos.tieneTelefono === '1',
            correo: datos.tieneCorreo === '1',
        };

        let elegido = false;
        document.querySelectorAll('#dialogo-recordatorio [data-canal]').forEach(function (radio) {
            const puede = disponible[radio.dataset.canal] === true;
            radio.disabled = !puede;
            radio.closest('.form-check').classList.toggle('text-muted', !puede);
            radio.checked = puede && !elegido;
            if (radio.checked) {
                elegido = true;
            }
        });

        const faltantes = [];
        if (!disponible.sms) {
            faltantes.push('teléfono');
        }
        if (!disponible.correo) {
            faltantes.push('correo');
        }
        $('mensaje-falta').textContent = faltantes.length === 0
            ? ''
            : 'Al paciente le falta ' + faltantes.join(' y ') + ' en la ficha.';

        $('mensaje-enviar').disabled = !elegido;
        actualizarContador();
        $('dialogo-recordatorio').showModal();
        $('mensaje-texto').focus();
    }

    function actualizarContador() {
        const texto = $('mensaje-texto').value;
        const canal = document.querySelector('#dialogo-recordatorio [data-canal]:checked');
        const aviso = canal && canal.value === 'sms' && texto.length > 160
            ? ' — pasa los 160 caracteres de un SMS'
            : '';
        $('mensaje-contador').textContent = texto.length + ' caracteres' + aviso;
    }

    document.addEventListener('click', function (evento) {
        const boton = evento.target.closest('[data-dialogo]');
        if (!boton) return;

        // El botón puede estar dentro del menú de acciones: se cierra antes de abrir el diálogo.
        const menu = boton.closest('details.menu-acciones');
        if (menu) menu.removeAttribute('open');

        if (boton.dataset.dialogo === 'cobro') abrirCobro(boton);
        if (boton.dataset.dialogo === 'recordatorio') abrirRecordatorio(boton);
    });

    if ($('mensaje-texto')) {
        $('mensaje-texto').addEventListener('input', actualizarContador);
        document.querySelectorAll('#dialogo-recordatorio [data-canal]').forEach(function (radio) {
            radio.addEventListener('change', actualizarContador);
        });
    }

    // ----------------------------------------------------------------------------------- Reordenar
    // El arrastre manda **el movimiento** (desde -> hasta), no el orden nuevo de cada fila; el servidor
    // corre las demás en una transacción. Es la misma operación de sync que el móvil (`ReordenarCola`),
    // no N updates.
    let arrastrada = null;

    document.querySelectorAll('.lista-cola').forEach(function (lista) {
        lista.querySelectorAll('tr[draggable="true"]').forEach(function (fila) {
            fila.addEventListener('dragstart', function () {
                arrastrada = fila;
                fila.classList.add('arrastrando');
            });
            fila.addEventListener('dragend', function () {
                fila.classList.remove('arrastrando');
            });
        });

        lista.addEventListener('dragover', function (evento) {
            evento.preventDefault();
        });

        lista.addEventListener('drop', function (evento) {
            evento.preventDefault();
            const destino = evento.target.closest('tr[draggable="true"]');
            if (!arrastrada || !destino || destino === arrastrada || arrastrada.parentElement !== lista) {
                return;
            }

            const desde = parseInt(arrastrada.dataset.numorden, 10);
            const hasta = parseInt(destino.dataset.numorden, 10);
            if (!Number.isInteger(desde) || !Number.isInteger(hasta) || desde === hasta) {
                return;
            }

            fetch(lista.dataset.reordenar, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': lista.dataset.csrf,
                },
                body: JSON.stringify({
                    record_id: parseInt(arrastrada.dataset.id, 10),
                    from: desde,
                    to: hasta,
                    fecha: lista.dataset.fecha,
                }),
            }).then(function () {
                window.location.reload();
            });
        });
    });
})();
