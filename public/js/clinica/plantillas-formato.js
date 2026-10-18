// Plantillas del **formato** del estudio ecográfico (O-17, revisión de Alexander del 2026-10-17).
//
// El panel vive en un cajón lateral (`<x-ui.dialogo lateral>`) que abren y cierran `shell.js`
// (`data-abrir-dialogo` / `data-cerrar-dialogo`); acá queda lo suyo:
//
//  - **Aplicar**: completa SÓLO los campos vacíos del formulario del estudio. Nunca pisa lo que el médico
//    escribió, así que se puede aplicar y seguir escribiendo. Las medidas y las fechas no vienen en la
//    plantilla (las filtra el servidor con el mapa del dato), así que tampoco se tocan.
//  - **El aviso**: cuántos campos se completaron y cuántos ya tenían dato («no se pisó nada»). Es lo que
//    pide O-17 para que nadie confunda la medida de una paciente con la de otra.
//  - **Guardar como plantilla**: manda los campos descriptivos del formulario (los que el servidor declara en
//    `data-formato-campos`) al endpoint en JSON, sin recargar la consulta, y suma la plantilla a la lista.
//
// La cuenta de «vacío» es la misma que `PlantillasDelFormato::aplicar()`: `trim()` del valor; el servidor es
// la autoridad (filtra qué se guarda) y esto es la aplicación en pantalla.
(function () {
    'use strict';

    function normal(texto) {
        return (texto || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    /** El valor de un control del estudio, tal como lo compararía el servidor. */
    function valorDe(campo) {
        return campo ? String(campo.value === null || campo.value === undefined ? '' : campo.value).trim() : '';
    }

    document.querySelectorAll('[data-formato-panel]').forEach(function (panel) {
        // El cajón es un hermano del panel (no un hijo: el `<dialog>` va fuera del `div` que arma el
        // disparador). Se lo alcanza por el botón que lo abre (`aria-controls`), que es el mismo id de
        // `x-ui.dialogo`.
        var disparador = panel.dataset.formatoBoton ? document.getElementById(panel.dataset.formatoBoton) : null;
        var dialogo = disparador ? document.getElementById(disparador.getAttribute('aria-controls')) : null;
        var raiz = dialogo || panel;

        var lista = raiz.querySelector('[data-formato-lista]');
        if (!lista) { return; }

        // El formulario del estudio, alcanzado por su `action` (cada tipo tiene su propio id).
        var formulario = null;
        if (panel.dataset.formatoForm) {
            try { formulario = document.querySelector(panel.dataset.formatoForm); } catch (error) { formulario = null; }
        }

        var campos = [];
        try { campos = JSON.parse(panel.dataset.formatoCampos || '[]'); } catch (error) { campos = []; }

        var buscar = raiz.querySelector('[data-formato-buscar]');
        var vacio = raiz.querySelector('[data-formato-vacio]');
        var sinPlantillas = raiz.querySelector('[data-formato-sin-plantillas]');
        var sinResultados = raiz.querySelector('[data-formato-sin-resultados]');
        var mensaje = raiz.querySelector('[data-formato-mensaje]');
        var cuenta = disparador ? disparador.querySelector('[data-formato-cuenta]') : null;

        function decir(texto) {
            if (!mensaje) { return; }
            mensaje.hidden = false;
            mensaje.textContent = texto;
        }

        function filtrar() {
            var q = normal(buscar ? buscar.value.trim() : '');
            var visibles = 0;
            lista.querySelectorAll('[data-formato-fila]').forEach(function (fila) {
                var boton = fila.querySelector('[data-formato-titulo]');
                var coincide = q === '' || normal(boton.dataset.formatoTitulo).indexOf(q) !== -1;
                fila.hidden = !coincide;
                if (coincide) { visibles++; }
            });
            var hayFilas = lista.querySelectorAll('[data-formato-fila]').length > 0;
            if (vacio) { vacio.hidden = visibles > 0; }
            if (sinPlantillas) { sinPlantillas.hidden = hayFilas; }
            if (sinResultados) { sinResultados.hidden = !hayFilas; }
        }
        if (buscar) { buscar.addEventListener('input', filtrar); }

        function contar() {
            if (!cuenta) { return; }
            var total = lista.querySelectorAll('[data-formato-fila]').length;
            cuenta.textContent = total > 0 ? '(' + total + ')' : '';
        }

        /** Aplica una plantilla: sólo los campos vacíos, y cuenta lo que no se pisó. */
        function aplicar(boton) {
            if (!formulario) {
                decir('No se encontró el formulario de este estudio: recargá la página.');
                return;
            }

            var valores = {};
            try { valores = JSON.parse(boton.dataset.formatoValores || '{}'); } catch (error) { valores = {}; }

            var escritos = 0;
            var respetados = 0;

            Object.keys(valores).forEach(function (columna) {
                var campo = formulario.querySelector('[name="' + columna + '"]');
                if (!campo) { return; }

                var valor = String(valores[columna] === null || valores[columna] === undefined ? '' : valores[columna]).trim();
                if (valor === '') { return; }

                if (valorDe(campo) !== '') {
                    respetados++;
                    return;
                }

                // Un desplegable sólo acepta un código de su lista: si la plantilla trae uno que no está (un
                // dato viejo del escritorio), no se inventa la opción.
                if (campo.tagName === 'SELECT') {
                    var existe = Array.prototype.some.call(campo.options, function (opcion) { return opcion.value === valor; });
                    if (!existe) { return; }
                }

                campo.value = valor;
                campo.dispatchEvent(new Event('input', { bubbles: true }));
                campo.dispatchEvent(new Event('change', { bubbles: true }));
                escritos++;
            });

            if (escritos === 0 && respetados === 0) {
                decir('La plantilla «' + boton.dataset.formatoTitulo + '» no tiene campos en este estudio.');
            } else if (escritos === 0) {
                decir('No se pisó nada: los ' + respetados + ' campos de la plantilla ya tenían dato en este estudio.');
            } else if (respetados === 0) {
                decir('Se completaron ' + escritos + ' campos vacíos con «' + boton.dataset.formatoTitulo + '».');
            } else {
                decir('Se completaron ' + escritos + ' campos vacíos; ' + respetados
                    + ' ya tenían dato y no se pisaron.');
            }
        }

        lista.addEventListener('click', function (evento) {
            var boton = evento.target.closest('[data-formato-titulo]');
            if (boton) { aplicar(boton); }
        });

        // Enter en el buscador o en el título no debe enviar el formulario del estudio.
        raiz.addEventListener('keydown', function (evento) {
            if (evento.key === 'Enter' && evento.target.matches('input')) { evento.preventDefault(); }
        });

        // Guardar la plantilla nueva con los campos descriptivos que hay en pantalla.
        var titulo = raiz.querySelector('[data-formato-titulo-plantilla]');
        var confirmar = raiz.querySelector('[data-formato-confirmar]');
        var guardado = raiz.querySelector('[data-formato-guardado]');

        function decirGuardado(texto, error) {
            if (!guardado) { return; }
            guardado.hidden = false;
            guardado.textContent = texto;
            guardado.classList.toggle('text-danger', !!error);
        }

        if (confirmar) {
            confirmar.addEventListener('click', function () {
                if (!formulario) { decirGuardado('No se encontró el formulario de este estudio.', true); return; }
                if (titulo.value.trim() === '') { decirGuardado('Escribe el título de la plantilla.', true); titulo.focus(); return; }

                var valores = {};
                campos.forEach(function (columna) {
                    var campo = formulario.querySelector('[name="' + columna + '"]');
                    if (campo) { valores[columna] = campo.value; }
                });

                var token = document.querySelector('meta[name="csrf-token"]');
                confirmar.disabled = true;
                fetch(panel.dataset.urlGuardar, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token ? token.content : '',
                    },
                    body: JSON.stringify({ titulo: titulo.value, valores: valores }),
                }).then(function (respuesta) {
                    return respuesta.json().then(function (datos) { return { ok: respuesta.ok, datos: datos }; });
                }).then(function (r) {
                    if (!r.ok) {
                        decirGuardado(r.datos.mensaje || (r.datos.errors && r.datos.errors.titulo && r.datos.errors.titulo[0]) || 'No se pudo guardar la plantilla.', true);
                        return;
                    }

                    // La plantilla que ya existía con ese título se reemplazó: se quita de la lista para no
                    // mostrarla dos veces.
                    lista.querySelectorAll('[data-formato-titulo]').forEach(function (boton) {
                        if (normal(boton.dataset.formatoTitulo) === normal(r.datos.titulo)) {
                            boton.closest('[data-formato-fila]').remove();
                        }
                    });

                    var fila = document.createElement('li');
                    fila.setAttribute('data-formato-fila', '');
                    var boton = document.createElement('button');
                    boton.type = 'button';
                    boton.className = 'plantillas-item';
                    boton.dataset.formatoTitulo = r.datos.titulo;
                    boton.dataset.formatoValores = JSON.stringify(r.datos.valores || {});
                    var icono = document.createElement('i');
                    icono.className = 'bi bi-layout-text-window-reverse';
                    icono.setAttribute('aria-hidden', 'true');
                    var rotulo = document.createElement('span');
                    rotulo.textContent = r.datos.titulo;
                    boton.appendChild(icono);
                    boton.appendChild(rotulo);
                    fila.appendChild(boton);
                    lista.appendChild(fila);
                    titulo.value = '';
                    if (buscar) { buscar.value = ''; }
                    filtrar();
                    contar();
                    decirGuardado('Plantilla «' + r.datos.titulo + '» guardada.', false);
                }).catch(function () {
                    decirGuardado('No se pudo guardar la plantilla. Revisá la conexión.', true);
                }).then(function () { confirmar.disabled = false; });
            });
        }
    });
})();
