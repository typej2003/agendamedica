/*
 * Las solicitudes de la consulta (WEB-5.3a): constancia, reposo, referencia e informe médico.
 *
 * Las vistas no llevan JS: declaran lo que quieren con `data-*` y este archivo lo resuelve. Hace cuatro cosas:
 *
 * 1. **El aviso de «hay cambios sin guardar»** de cada formulario `[data-solicitud-form]`: compara los campos con lo que el
 *    servidor pintó, muestra su `[data-solicitud-aviso]` y hace que el navegador avise antes de perder lo escrito. Guardar
 *    (o eliminar, `[data-solicitud-salir]`, después de la confirmación) no avisa.
 * 2. **Copiar al campo**: `[data-copiar-a="#campo"]` pone el `data-texto` del botón en el campo (una plantilla o un informe
 *    anterior; `data-copiar-para` + `data-para` hacen lo mismo con el «Para»). Copiar es escribir: no guarda nada y el aviso
 *    de cambios sin guardar aparece. `[data-restaurar]` es lo mismo para «usar los diagnósticos de la consulta».
 * 3. **Las fechas del reposo**: al escribir los días o la fecha de inicio recalcula el último día de reposo (inicio + días − 1)
 *    y el reintegro (inicio + días), la misma cuenta que el servidor (`ReposoDeLaConsulta`). No se guarda: es de pantalla.
 * 4. **El buscador de médicos** de las referencias (`#solicitudes-referencias`): pide el catálogo (250 ms de espera) y pinta
 *    un formulario «Referir» por resultado; y abre el diálogo del alta del médico que falta.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------- Cambios sin guardar

    var formularios = Array.prototype.slice.call(document.querySelectorAll('[data-solicitud-form]'));
    var saliendo = false;

    function campos(formulario) {
        return Array.prototype.slice.call(formulario.querySelectorAll('input[name], textarea[name], select[name]'))
            .filter(function (campo) { return campo.type !== 'hidden'; });
    }

    var estado = formularios.map(function (formulario) {
        var lista = campos(formulario);
        return {
            formulario: formulario,
            campos: lista,
            guardados: lista.map(function (campo) { return campo.value; }),
            aviso: formulario.querySelector('[data-solicitud-aviso]'),
            mensaje: formulario.dataset.aviso || 'Hay cambios sin guardar.'
        };
    });

    function sucio(e) {
        return e.campos.some(function (campo, i) { return campo.value !== e.guardados[i]; });
    }

    function pintar(e) {
        if (e.aviso) e.aviso.hidden = !sucio(e);
    }

    estado.forEach(function (e) {
        e.campos.forEach(function (campo) {
            campo.addEventListener('input', function () { pintar(e); });
            campo.addEventListener('change', function () { pintar(e); });
        });
        e.formulario.addEventListener('submit', function () { saliendo = true; });
    });

    // Eliminar pide confirmación (shell.js, en `document`): si la persona confirma, la página se va y no hay nada que avisar.
    // Este oyente se registra después del de shell.js, así que ve `defaultPrevented` si canceló.
    document.addEventListener('submit', function (evento) {
        if (evento.target.matches && evento.target.matches('[data-solicitud-salir]') && !evento.defaultPrevented) {
            saliendo = true;
        }
    });

    window.addEventListener('beforeunload', function (evento) {
        if (saliendo) return;
        if (estado.some(sucio)) {
            evento.preventDefault();
            evento.returnValue = '';
        }
    });

    // ------------------------------------------------------------------------ Copiar al campo

    function escribir(selector, texto) {
        var campo = document.querySelector(selector);
        if (!campo) return;
        campo.value = texto;
        campo.dispatchEvent(new Event('input', { bubbles: true }));
    }

    document.addEventListener('click', function (evento) {
        var boton = evento.target.closest('[data-copiar-a], [data-restaurar]');
        if (!boton) return;

        escribir(boton.dataset.copiarA || boton.dataset.restaurar, boton.dataset.texto || '');

        if (boton.dataset.copiarPara) {
            escribir(boton.dataset.copiarPara, boton.dataset.para || '');
        }
    });

    // --------------------------------------------------------------------- Fechas del reposo

    var dias = document.querySelector('[data-reposo-dias]');
    var desde = document.querySelector('[data-reposo-desde]');
    var salidaHasta = document.querySelector('[data-reposo-hasta]');
    var salidaReintegro = document.querySelector('[data-reposo-reintegro]');

    function dosDigitos(n) { return (n < 10 ? '0' : '') + n; }

    // Por calendario (UTC), no sumando horas: un cambio de horario no corre el día.
    function sumarDias(texto, cantidad) {
        var partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(texto);
        if (!partes) return null;
        var fecha = new Date(Date.UTC(+partes[1], +partes[2] - 1, +partes[3] + cantidad));
        return dosDigitos(fecha.getUTCDate()) + '/' + dosDigitos(fecha.getUTCMonth() + 1) + '/' + fecha.getUTCFullYear();
    }

    function recalcular() {
        var n = parseInt(dias.value, 10);
        var valido = n >= 1 && desde.value !== '';
        salidaHasta.textContent = (valido && sumarDias(desde.value, n - 1)) || '—';
        salidaReintegro.textContent = (valido && sumarDias(desde.value, n)) || '—';
    }

    if (dias && desde && salidaHasta && salidaReintegro) {
        dias.addEventListener('input', recalcular);
        desde.addEventListener('input', recalcular);
        desde.addEventListener('change', recalcular);
    }

    // ------------------------------------------------------------- Buscador de médicos (referencias)

    var raiz = document.getElementById('solicitudes-referencias');

    if (raiz) {
        var caja = document.getElementById('referencia-texto');
        var lista = document.getElementById('referencia-resultados');
        var ayuda = document.getElementById('referencia-ayuda');
        var plantilla = document.getElementById('referencia-plantilla');
        var dialogo = document.getElementById('dialogo-medico');
        var botonNuevo = document.getElementById('referencia-nuevo-boton');
        var ayudaInicial = ayuda.textContent;
        var temporizador = null;

        var limpiar = function () {
            lista.innerHTML = '';
            lista.hidden = true;
        };

        var detalle = function (medico) {
            return [medico.especialidad, medico.clinica, medico.telefono].filter(Boolean).join(' · ');
        };

        var pintarResultados = function (datos) {
            lista.innerHTML = '';

            datos.medicos.forEach(function (medico) {
                var fila = plantilla.content.firstElementChild.cloneNode(true);
                var texto = fila.querySelector('.resultado-paciente-datos');
                texto.textContent = medico.nombre + (detalle(medico) ? ' · ' + detalle(medico) : '');

                if (medico.yaEsta) {
                    texto.insertAdjacentText('beforeend', ' · ya está referido');
                    fila.querySelector('form').remove();
                } else {
                    fila.querySelector('input[name=cedula]').value = medico.cedula;
                }

                lista.appendChild(fila);
            });

            if (datos.medicos.length === 0) {
                limpiar();
                ayuda.textContent = 'Tu catálogo no tiene médicos que coincidan con «' + datos.buscar + '». Puedes agregarlo abajo.';
                return;
            }

            lista.hidden = false;
            ayuda.textContent = datos.medicos.length + (datos.medicos.length === 1 ? ' médico' : ' médicos')
                + ' que coinciden con «' + datos.buscar + '».';
        };

        var buscar = function () {
            var consulta = caja.value.trim();

            if (consulta === '') {
                limpiar();
                ayuda.textContent = ayudaInicial;
                return;
            }

            fetch(raiz.dataset.urlBuscar + '?q=' + encodeURIComponent(consulta), { headers: { 'Accept': 'application/json' } })
                .then(function (respuesta) { return respuesta.json(); })
                .then(function (datos) {
                    // Una respuesta vieja no pisa lo que se escribió después.
                    if (datos.buscar !== caja.value.trim()) return;
                    pintarResultados(datos);
                })
                .catch(function () {
                    ayuda.textContent = 'No se pudo buscar ahora: revisa tu conexión.';
                });
        };

        caja.addEventListener('input', function () {
            clearTimeout(temporizador);
            temporizador = setTimeout(buscar, 250);
        });

        document.addEventListener('click', function (evento) {
            if (!evento.target.closest('#referencias-busqueda')) limpiar();
        });

        if (dialogo && typeof dialogo.showModal === 'function') {
            if (botonNuevo) botonNuevo.addEventListener('click', function () { dialogo.showModal(); });
            if (raiz.dataset.altaAbierta === '1') dialogo.showModal();
        }
    }
}());
