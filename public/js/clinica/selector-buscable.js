/**
 * Selector con busqueda (combobox) - script de `<x-ui.selector-buscable>`.
 *
 * Se inicializa solo: cada `[data-selector-buscable]` que exista o aparezca despues (un dialogo de Livewire, por
 * ejemplo) se activa una vez. El valor vive en un `<input type="hidden">` (el que viaja en el POST o el que escucha
 * `wire:model`); el campo visible solo muestra la etiqueta. Cada cambio dispara `input` y `change` en el oculto y
 * el evento `selector:cambio` en la raiz.
 *
 * Datos en la raiz (`data-*`): `data-modo` = `local` (opciones incrustadas, se filtran aqui) | `remoto` (se piden a
 * `data-url` con `?q=&limite=`; respuesta `{resultados: [{value, label, detail}]}`); `data-min-chars`, `data-limite`,
 * `data-libre` (el valor es el texto escrito), `data-crear-url` + `data-csrf` (+ `data-crear-extra`, JSON
 * `{name,label,options:[{value,label}]}`) para la opcion "Crear".
 */
(function () {
    'use strict';

    var DEBOUNCE = 250;

    function normalizar(texto) {
        return String(texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function crear(nombre, clase, texto) {
        var el = document.createElement(nombre);
        if (clase) el.className = clase;
        if (texto !== undefined) el.textContent = texto;
        return el;
    }

    function iniciar(raiz) {
        if (raiz.dataset.listo) return;
        raiz.dataset.listo = '1';

        var entrada = raiz.querySelector('.selector-entrada');
        var oculto = raiz.querySelector('.selector-valor');
        var limpiar = raiz.querySelector('.selector-limpiar');
        var popup = raiz.querySelector('.selector-popup');
        var lista = raiz.querySelector('.selector-lista');
        var estado = raiz.querySelector('.selector-estado');
        if (!entrada || !oculto || !lista) return;

        var local = raiz.dataset.modo === 'local';
        var libre = raiz.dataset.libre === '1';
        var minimo = parseInt(raiz.dataset.minChars || '2', 10);
        var limite = parseInt(raiz.dataset.limite || '20', 10);
        var url = raiz.dataset.url || '';
        var crearUrl = raiz.dataset.crearUrl || '';
        var crearEtiqueta = raiz.dataset.crearEtiqueta || 'Crear';
        var extra = null;
        try { extra = raiz.dataset.crearExtra ? JSON.parse(raiz.dataset.crearExtra) : null; } catch (e) { extra = null; }
        var opciones = [];
        var datos = raiz.querySelector('script[data-opciones]');
        if (datos) { try { opciones = JSON.parse(datos.textContent) || []; } catch (e) { opciones = []; } }
        var requerido = entrada.hasAttribute('data-requerido');

        var etiquetaInicial = entrada.value;
        var valorInicial = oculto.value;
        var items = [];
        var activo = -1;
        var navegado = false;
        var temporizador = null;
        var peticion = null;
        var seleccion = oculto.value !== '' && !libre ? { value: oculto.value, label: entrada.value } : null;
        var itemCrear = null;

        function validez() {
            if (!requerido || libre) { entrada.setCustomValidity(''); return; }
            entrada.setCustomValidity(oculto.value === '' ? 'Elige una opción de la lista.' : '');
        }

        function emitir() {
            oculto.dispatchEvent(new Event('input', { bubbles: true }));
            oculto.dispatchEvent(new Event('change', { bubbles: true }));
            raiz.dispatchEvent(new CustomEvent('selector:cambio', { bubbles: true, detail: { value: oculto.value, label: entrada.value } }));
        }

        function pintarLimpiar() {
            if (limpiar) limpiar.hidden = entrada.disabled || (oculto.value === '' && entrada.value === '');
        }

        function abierto() { return popup && !popup.hidden; }

        function cerrar() {
            if (!popup) return;
            popup.hidden = true;
            entrada.setAttribute('aria-expanded', 'false');
            entrada.removeAttribute('aria-activedescendant');
            activo = -1;
            navegado = false;
        }

        function abrir() {
            if (!popup) return;
            popup.hidden = false;
            entrada.setAttribute('aria-expanded', 'true');
        }

        function mensaje(texto, conReintento) {
            estado.textContent = '';
            estado.hidden = !texto;
            if (!texto) return;
            estado.appendChild(document.createTextNode(texto));
            if (conReintento) {
                var b = crear('button', 'btn btn-link btn-sm p-0 ms-2', 'Reintentar');
                b.type = 'button';
                b.addEventListener('click', function () { buscar(entrada.value); entrada.focus(); });
                estado.appendChild(b);
            }
        }

        function marcar(i) {
            var nodos = lista.querySelectorAll('[role=option]');
            nodos.forEach(function (n, k) {
                var on = k === i;
                n.classList.toggle('active', on);
                n.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on) { entrada.setAttribute('aria-activedescendant', n.id); n.scrollIntoView({ block: 'nearest' }); }
            });
            if (i < 0) entrada.removeAttribute('aria-activedescendant');
            activo = i;
        }

        function pintar(resultados, texto) {
            items = resultados.slice();
            itemCrear = null;
            var t = normalizar(texto);
            if (crearUrl && !libre && t.length >= 2) {
                var exacto = items.some(function (it) { return normalizar(it.label) === t || normalizar(it.label.split(' \u2014 ')[0]) === t; });
                if (!exacto) {
                    itemCrear = { crear: true, label: crearEtiqueta + ' \u00ab' + texto.trim() + '\u00bb', texto: texto.trim() };
                    items.unshift(itemCrear);
                }
            }
            lista.textContent = '';
            items.forEach(function (it, i) {
                var li = crear('li', 'selector-opcion list-group-item list-group-item-action' + (it.crear ? ' selector-crear' : ''));
                li.id = entrada.id + '-op-' + i;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', 'false');
                if (it.crear) {
                    var mas = crear('i', 'bi bi-plus-circle me-2');
                    mas.setAttribute('aria-hidden', 'true');
                    li.appendChild(mas);
                }
                li.appendChild(crear('span', 'selector-etiqueta', it.label));
                if (it.detail) li.appendChild(crear('small', 'selector-detalle text-muted ms-2', it.detail));
                li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                li.addEventListener('click', function () { elegir(it); });
                lista.appendChild(li);
            });
            if (!items.length) mensaje('Sin resultados', false); else mensaje('', false);
            abrir();
            marcar(-1);
        }

        function filtrarLocal(texto) {
            var t = normalizar(texto);
            var empieza = [], contiene = [];
            opciones.forEach(function (o) {
                var base = normalizar(o.label);
                var todo = normalizar(o.label + ' ' + (o.detail || ''));
                if (t === '' || base.indexOf(t) === 0) empieza.push(o); else if (todo.indexOf(t) !== -1) contiene.push(o);
            });
            return empieza.concat(contiene).slice(0, limite);
        }

        function buscar(texto) {
            clearTimeout(temporizador);
            if (peticion) { peticion.abort(); peticion = null; }
            if (local) { pintar(filtrarLocal(texto), texto); return; }
            if (normalizar(texto).length < minimo) {
                items = []; lista.textContent = ''; itemCrear = null;
                mensaje('Escribe al menos ' + minimo + ' letras para buscar.', false);
                abrir();
                return;
            }
            temporizador = setTimeout(function () {
                var control = new AbortController();
                peticion = control;
                lista.textContent = '';
                mensaje('Buscando\u2026', false);
                abrir();
                var sep = url.indexOf('?') === -1 ? '?' : '&';
                fetch(url + sep + 'q=' + encodeURIComponent(texto.trim()) + '&limite=' + limite, {
                    signal: control.signal,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) {
                    if (!r.ok) throw new Error('http ' + r.status);
                    return r.json();
                }).then(function (json) {
                    if (peticion !== control) return;
                    peticion = null;
                    pintar(json.resultados || [], texto);
                }).catch(function (e) {
                    if (e && e.name === 'AbortError') return;
                    peticion = null;
                    lista.textContent = '';
                    mensaje('No se pudo buscar.', true);
                });
            }, DEBOUNCE);
        }

        function fijar(value, label) {
            oculto.value = value;
            entrada.value = label;
            seleccion = value === '' ? null : { value: value, label: label };
            validez();
            pintarLimpiar();
            emitir();
        }

        function elegir(it) {
            if (it.crear) { iniciarCreacion(it.texto); return; }
            fijar(String(it.value), String(it.label) + (it.detail && !libre ? ' — ' + it.detail : ''));
            cerrar();
        }

        function iniciarCreacion(texto) {
            if (!extra) { crearCiudad(texto, null); return; }
            lista.textContent = '';
            mensaje('', false);
            var caja = crear('div', 'selector-crear-form p-2');
            caja.appendChild(crear('div', 'fw-semibold mb-1', crearEtiqueta + ' \u00ab' + texto + '\u00bb'));
            var etiqueta = crear('label', 'form-label small mb-1', extra.label);
            var id = entrada.id + '-extra';
            etiqueta.setAttribute('for', id);
            var sel = crear('select', 'form-select form-select-sm mb-2');
            sel.id = id;
            var vacia = crear('option', '', 'Elige\u2026'); vacia.value = '';
            sel.appendChild(vacia);
            (extra.options || []).forEach(function (o) { var op = crear('option', '', o.label); op.value = o.value; sel.appendChild(op); });
            var aviso = crear('div', 'campo-error mb-1');
            aviso.hidden = true;
            var ok = crear('button', 'btn btn-primary btn-sm me-2', crearEtiqueta);
            ok.type = 'button';
            var no = crear('button', 'btn btn-outline-secondary btn-sm', 'Cancelar');
            no.type = 'button';
            ok.addEventListener('click', function () {
                if (!sel.value) { aviso.textContent = 'Elige ' + extra.label.toLowerCase() + '.'; aviso.hidden = false; sel.focus(); return; }
                crearCiudad(texto, sel.value, aviso, ok);
            });
            no.addEventListener('click', function () { cerrar(); entrada.focus(); });
            caja.appendChild(etiqueta); caja.appendChild(sel); caja.appendChild(aviso); caja.appendChild(ok); caja.appendChild(no);
            lista.appendChild(caja);
            abrir();
            sel.focus();
        }

        function crearCiudad(texto, extraValor, aviso, boton) {
            var cuerpo = { nombre: texto };
            if (extra && extraValor) cuerpo[extra.name] = extraValor;
            if (boton) boton.disabled = true;
            fetch(crearUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': raiz.dataset.csrf || '' },
                body: JSON.stringify(cuerpo)
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, json: j }; }); })
              .then(function (r) {
                  if (boton) boton.disabled = false;
                  if (!r.ok) {
                      var m = r.status === 403 ? 'No tienes permiso para crear.' : 'No se pudo crear.';
                      if (r.json && r.json.errors) { var k = Object.keys(r.json.errors)[0]; m = r.json.errors[k][0]; }
                      if (aviso) { aviso.textContent = m; aviso.hidden = false; } else { mensaje(m, false); abrir(); }
                      return;
                  }
                  fijar(String(r.json.value), r.json.label);
                  cerrar();
                  if (r.json.creada === false) { raiz.dataset.aviso = 'existia'; }
              }).catch(function () {
                  if (boton) boton.disabled = false;
                  var m = 'No se pudo crear. Reintenta.';
                  if (aviso) { aviso.textContent = m; aviso.hidden = false; } else { mensaje(m, false); abrir(); }
              });
        }

        entrada.addEventListener('input', function () {
            if (libre) { oculto.value = entrada.value; emitir(); }
            else if (oculto.value !== '') { oculto.value = ''; seleccion = null; emitir(); }
            validez();
            pintarLimpiar();
            buscar(entrada.value);
        });

        entrada.addEventListener('focus', function () { if (local || normalizar(entrada.value).length >= minimo) { if (!libre || entrada.value !== '') buscar(entrada.value); } });
        entrada.addEventListener('click', function () { if (!abierto()) buscar(entrada.value); });

        entrada.addEventListener('keydown', function (e) {
            var n = items.length;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!abierto()) { buscar(entrada.value); return; }
                if (!n) return;
                navegado = true;
                var i = e.key === 'ArrowDown' ? (activo + 1) % n : (activo <= 0 ? n - 1 : activo - 1);
                marcar(i);
            } else if (e.key === 'Enter') {
                if (abierto() && activo >= 0) { e.preventDefault(); elegir(items[activo]); }
                else if (abierto() && items.length === 1 && !libre && itemCrear === null) { e.preventDefault(); elegir(items[0]); }
            } else if (e.key === 'Escape') {
                if (abierto()) { e.preventDefault(); e.stopPropagation(); cerrar(); }
            } else if (e.key === 'Tab') {
                if (abierto() && navegado && activo >= 0 && !items[activo].crear) elegir(items[activo]);
            }
        });

        raiz.addEventListener('focusout', function (e) {
            if (e.relatedTarget && raiz.contains(e.relatedTarget)) return;
            setTimeout(function () {
                if (raiz.contains(document.activeElement)) return;
                cerrar();
                if (peticion) { peticion.abort(); peticion = null; }
                clearTimeout(temporizador);
                if (!libre) entrada.value = seleccion ? seleccion.label : '';
                validez();
                pintarLimpiar();
            }, 0);
        });

        if (limpiar) {
            limpiar.addEventListener('click', function () {
                fijar('', '');
                cerrar();
                entrada.focus();
            });
        }

        var formulario = raiz.closest('form');
        if (formulario) {
            formulario.addEventListener('reset', function () {
                setTimeout(function () {
                    var igual = oculto.value === valorInicial;
                    entrada.value = igual ? etiquetaInicial : '';
                    oculto.value = igual ? valorInicial : '';
                    seleccion = oculto.value !== '' && !libre ? { value: oculto.value, label: entrada.value } : null;
                    cerrar(); validez(); pintarLimpiar();
                }, 0);
            });
        }

        validez();
        pintarLimpiar();
    }

    function barrer(nodo) {
        if (nodo.nodeType !== 1) return;
        if (nodo.matches && nodo.matches('[data-selector-buscable]')) iniciar(nodo);
        if (nodo.querySelectorAll) nodo.querySelectorAll('[data-selector-buscable]').forEach(iniciar);
    }

    function arrancar() {
        barrer(document.body);
        new MutationObserver(function (cambios) {
            cambios.forEach(function (c) { c.addedNodes.forEach(barrer); });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar); else arrancar();
})();