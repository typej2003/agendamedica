/*
 * **Sedes y consultorios** (`/clinica/sedes`, 2026-10-20): los paneles laterales de la pantalla. La vista no lleva JS
 * inline (Docs/Web/guia-arquitectura-web.md); todo se declara con `data-*`:
 *
 *   data-panel-al-cargar="id"   en `.sedes-pantalla`: el panel que se abre al cargar (un error, `?panel=`, editar o la
 *                               pregunta de la sede recién creada). Lo decide `SedesYConsultoriosVista::panelAlCargar()`.
 *   data-panel="id"             en un enlace: abre ese panel **sin recargar** si está en modo `nuevo` (`data-modo`); si
 *                               tiene cargado otro registro (`editar`), el enlace navega y la pantalla vuelve limpia.
 *                               Con `data-sede`, deja esa sede elegida en el consultorio.
 *   data-panel-horario          en el botón «Horario» de un consultorio: le pone al panel del horario la ruta
 *                               (`data-accion`), el consultorio y el título.
 *   data-abrir-sede-rapida      en el panel del consultorio: abre la sede rápida **encima** (el consultorio queda
 *                               abierto debajo, con lo escrito). `form[data-sede-rapida]` la crea por `fetch` y la deja
 *                               elegida en el selector.
 *
 * Los enlaces son el respaldo: sin este script, cada uno lleva a la misma pantalla con su panel abierto.
 */
(function () {
    'use strict';

    const pantalla = document.querySelector('.sedes-pantalla');
    if (!pantalla) return;

    const $ = function (id) { return document.getElementById(id); };

    const abrir = function (dialogo) {
        if (dialogo && typeof dialogo.showModal === 'function' && !dialogo.open) dialogo.showModal();
    };

    // Al cerrar un panel que abrió la URL (`?panel=`, `?sede=`, `?consultorio=`), la dirección vuelve a la de la lista:
    // recargar no tiene que reabrirlo.
    const limpiarUrl = function () {
        const limpia = pantalla.dataset.urlLimpia;
        if (limpia && window.location.search.match(/[?&](panel|sede|consultorio|en|editar)=/)) {
            window.history.replaceState(null, '', limpia);
        }
    };
    document.querySelectorAll('dialog.dialogo').forEach(function (dialogo) {
        dialogo.addEventListener('close', limpiarUrl);
    });

    // ---- Enlaces que abren un panel sin recargar.
    document.addEventListener('click', function (evento) {
        const enlace = evento.target.closest('a[data-panel]');
        if (!enlace) return;

        const dialogo = $(enlace.dataset.panel);
        if (!dialogo || dialogo.dataset.modo !== 'nuevo') return;

        evento.preventDefault();

        // La pregunta «¿Agregar un consultorio?» cede su lugar al panel del consultorio.
        const desde = enlace.closest('dialog');
        if (desde && desde !== dialogo) desde.close();

        if (enlace.dataset.sede) {
            const selector = $('consultorio-sede');
            if (selector) selector.value = enlace.dataset.sede;
        }

        abrir(dialogo);
    });

    // ---- El panel del horario: uno para toda la pantalla, el botón de cada consultorio lo apunta al suyo.
    const horario = $('dialogo-horario');
    if (horario) {
        const formulario = horario.querySelector('form');

        document.addEventListener('click', function (evento) {
            const boton = evento.target.closest('[data-panel-horario]');
            if (!boton) return;

            // Otro consultorio: lo escrito (y los errores) del anterior no le corresponden.
            if (formulario.elements.consultorio.value !== boton.dataset.consultorio) {
                formulario.querySelectorAll('input[type=checkbox]').forEach(function (casilla) { casilla.checked = false; });
                formulario.querySelectorAll('input.hora-ampm, input[type=number]').forEach(function (campo) {
                    campo.value = '';
                    campo.classList.remove('is-invalid');
                });
                formulario.querySelectorAll('input[type=hidden][name^=hora_]').forEach(function (campo) { campo.value = ''; });
                formulario.querySelectorAll('.campo-error').forEach(function (error) { error.remove(); });
            }

            formulario.action = boton.dataset.accion;
            formulario.elements.consultorio.value = boton.dataset.consultorio;
            const titulo = horario.querySelector('.dialogo-titulo');
            if (titulo) titulo.textContent = boton.dataset.titulo;

            abrir(horario);
        });
    }

    // ---- La sede rápida, desde el panel del consultorio.
    const rapida = $('dialogo-sede-rapida');
    const formularioRapido = rapida ? rapida.querySelector('form[data-sede-rapida]') : null;
    const selectorDeSede = $('consultorio-sede');
    const avisoDeSede = document.querySelector('[data-aviso-sede]');

    const limpiarErrores = function () {
        formularioRapido.querySelectorAll('[data-error-de]').forEach(function (error) {
            error.textContent = '';
            error.hidden = true;
        });
        formularioRapido.querySelectorAll('.is-invalid').forEach(function (campo) { campo.classList.remove('is-invalid'); });
        const general = formularioRapido.querySelector('[data-error-general]');
        general.textContent = '';
        general.hidden = true;
    };

    document.addEventListener('click', function (evento) {
        if (!evento.target.closest('[data-abrir-sede-rapida]') || !rapida) return;
        abrir(rapida);
    });

    if (formularioRapido && selectorDeSede) {
        formularioRapido.addEventListener('submit', function (evento) {
            evento.preventDefault();
            limpiarErrores();

            const enviar = formularioRapido.querySelector('button:not([type=button])');
            enviar.disabled = true;

            fetch(formularioRapido.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(formularioRapido),
                credentials: 'same-origin',
            }).then(function (respuesta) {
                return respuesta.json().catch(function () { return {}; }).then(function (datos) {
                    return { estado: respuesta.status, datos: datos };
                });
            }).then(function (resultado) {
                if (resultado.estado === 422) {
                    let primero = null;
                    Object.keys(resultado.datos.errors || {}).forEach(function (campo) {
                        const error = formularioRapido.querySelector('[data-error-de="' + campo + '"]');
                        if (error) {
                            error.textContent = resultado.datos.errors[campo][0];
                            error.hidden = false;
                        }
                        const control = formularioRapido.querySelector('[name="' + campo + '"]');
                        if (control) {
                            control.classList.add('is-invalid');
                            primero = primero || control;
                        }
                    });
                    if (primero) primero.focus();
                    return;
                }

                if (resultado.estado < 200 || resultado.estado >= 300 || !resultado.datos.id) {
                    throw new Error('respuesta inesperada');
                }

                // La sede nueva entra al selector (en su lugar alfabético) y queda elegida.
                const opcion = new Option(resultado.datos.nombre, String(resultado.datos.id), true, true);
                const siguiente = Array.prototype.find.call(selectorDeSede.options, function (otra) {
                    return otra.value !== '' && otra.text.localeCompare(resultado.datos.nombre, 'es') > 0;
                });
                selectorDeSede.add(opcion, siguiente || null);
                selectorDeSede.value = String(resultado.datos.id);
                selectorDeSede.classList.remove('is-invalid');

                if (avisoDeSede) avisoDeSede.textContent = 'Consultorio «' + resultado.datos.nombre + '» registrado.';

                formularioRapido.reset();
                rapida.close();
                selectorDeSede.focus();
            }).catch(function () {
                const general = formularioRapido.querySelector('[data-error-general]');
                general.textContent = 'No se pudo registrar la sede. Revisa la conexión y vuelve a intentarlo.';
                general.hidden = false;
            }).finally(function () {
                enviar.disabled = false;
            });
        });
    }

    // ---- El panel que pide la pantalla al cargar.
    const alCargar = pantalla.dataset.panelAlCargar;
    if (alCargar) abrir($(alCargar));
})();
