/*
 * Las órdenes de la consulta (WEB-5.4): la orden de exámenes de laboratorio y la solicitud de estudios (pestañas Laboratorio y Estudios
 * de las Solicitudes, ítem 11 del panel).
 *
 * Las vistas no llevan JS: declaran lo que quieren con `data-*` y este archivo lo resuelve. Sólo corre si el editor está en pantalla
 * (`#orden-editor`). Hace cuatro cosas, cada una atada a un bloque de la pantalla:
 *
 * 1. **La lista de la orden** (`#orden-items`): se agregan filas clonando la plantilla de la vista (el script no arma HTML), un examen
 *    entra **una sola vez** (la clave del escritorio es historia + consulta + código) y «Quitar» saca la fila del formulario. En
 *    laboratorio las filas nuevas se acomodan por tipo y nombre (el orden de la hoja); en estudios van al final (el orden es el de la lista).
 *    Nada se guarda hasta el botón: lo que queda en la lista es lo que queda guardado.
 *
 * 2. **El buscador** (`#orden-busqueda`): pide el catálogo del médico mientras se escribe (250 ms de espera, como los otros buscadores),
 *    con el tipo elegido si hay filtro, y pinta los resultados con la plantilla de la vista. Elegir uno lo agrega a la lista.
 *
 * 3. **Las rutinas** (`[data-orden-rutina]`): pide al servidor todos los exámenes de un tipo y los agrega de una vez, sin repetir los
 *    que ya están. Si son muchos (más que `data-umbral`) pregunta antes.
 *
 * 4. **El aviso de «hay cambios sin guardar»** y el del navegador al salir: compara la lista y el resumen con lo que pintó el servidor.
 *    Guardar, o eliminar (después de la confirmación), no avisa.
 */
(function () {
    'use strict';

    var raiz = document.getElementById('orden-editor');

    if (!raiz) return;

    var formulario = document.getElementById('orden-form');
    var lista = document.getElementById('orden-items');
    var vacio = document.getElementById('orden-vacio');
    var cuenta = document.getElementById('orden-cuenta');
    var observacion = document.getElementById('orden-observacion');
    var aviso = formulario.querySelector('[data-orden-aviso]');
    var plantillaFila = document.getElementById('orden-fila-plantilla');

    var texto = document.getElementById('orden-texto');
    var tipo = document.getElementById('orden-tipo');
    var resultados = document.getElementById('orden-resultados');
    var ayuda = document.getElementById('orden-ayuda');
    var plantillaResultado = document.getElementById('orden-resultado-plantilla');

    var campo = raiz.dataset.campo;
    var agrupa = raiz.dataset.agrupa === '1';
    var cosas = raiz.dataset.cosas;
    var umbral = parseInt(raiz.dataset.umbral, 10) || 40;
    var saliendo = false;
    var temporizador = null;
    var ultimaConsulta = 0;

    // ----------------------------------------------------------------------------------------- La lista

    function filas() {
        return Array.prototype.slice.call(lista.querySelectorAll('[data-orden-fila]'));
    }

    function codigos() {
        return filas().map(function (fila) { return fila.dataset.codigo; });
    }

    function instantanea() {
        return JSON.stringify([codigos(), observacion ? observacion.value : '']);
    }

    var inicial = instantanea();

    function sucio() {
        return instantanea() !== inicial;
    }

    function plural(n, uno, varios) {
        return n + ' ' + (n === 1 ? uno : varios);
    }

    function actualizar() {
        var n = filas().length;
        var singular = cosas === 'estudios' ? 'estudio' : 'examen';
        var variosTexto = cosas === 'estudios' ? 'estudios' : 'exámenes';
        var lugar = cosas === 'estudios' ? 'en la solicitud' : 'en la orden';

        if (vacio) vacio.hidden = n > 0;
        lista.hidden = n === 0;

        if (cuenta) {
            cuenta.textContent = n === 0
                ? (cosas === 'estudios' ? 'La solicitud no tiene estudios.' : 'La orden no tiene exámenes.')
                : plural(n, singular, variosTexto) + ' ' + lugar + '.';
        }

        if (aviso) aviso.hidden = !sucio();
    }

    function normalizar(texto) {
        return String(texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function clave(fila) {
        var nombre = fila.querySelector('[data-orden-nombre]');
        var detalle = fila.querySelector('[data-orden-detalle]');

        return normalizar(detalle ? detalle.textContent : '') + '|' + normalizar(nombre ? nombre.textContent : '');
    }

    function agregar(examen) {
        if (codigos().indexOf(examen.codigo) !== -1) return false;

        var fila = plantillaFila.content.firstElementChild.cloneNode(true);
        fila.dataset.codigo = examen.codigo;
        fila.querySelector('input[type=hidden]').value = examen.codigo;
        fila.querySelector('[data-orden-nombre]').textContent = examen.nombre;
        fila.querySelector('[data-orden-detalle]').textContent = examen.tipo || '';

        var quitar = fila.querySelector('[data-orden-quitar]');
        quitar.title = 'Quitar «' + examen.nombre + '» de la orden';
        quitar.setAttribute('aria-label', 'Quitar ' + examen.nombre + ' de la orden');

        // Laboratorio: por tipo y nombre (el orden de la hoja). Estudios: al final.
        var antes = null;

        if (agrupa) {
            var nueva = clave(fila);
            antes = filas().filter(function (otra) { return clave(otra) > nueva; })[0] || null;
        }

        lista.insertBefore(fila, antes);
        actualizar();

        return true;
    }

    lista.addEventListener('click', function (evento) {
        var boton = evento.target.closest('[data-orden-quitar]');
        if (!boton) return;

        var fila = boton.closest('[data-orden-fila]');
        var vecina = fila.nextElementSibling || fila.previousElementSibling;
        fila.remove();
        actualizar();

        // El foco no se pierde: pasa a «Quitar» de la fila vecina (o al buscador si no queda ninguna).
        var siguiente = vecina && vecina.querySelector('[data-orden-quitar]');
        if (siguiente) siguiente.focus();
        else if (texto) texto.focus();
    });

    if (observacion) {
        observacion.addEventListener('input', actualizar);
    }

    // --------------------------------------------------------------------------------------- Buscador

    function limpiar() {
        resultados.innerHTML = '';
        resultados.hidden = true;
    }

    function pintar(datos) {
        resultados.innerHTML = '';
        var yaEstan = codigos();

        datos.examenes.forEach(function (examen) {
            var fila = plantillaResultado.content.firstElementChild.cloneNode(true);
            fila.querySelector('[data-resultado-nombre]').textContent = examen.nombre;
            fila.querySelector('[data-resultado-detalle]').textContent = examen.tipo || '';
            // La fila del catálogo va en una línea: si el nombre no entra se trunca, y el completo queda en el `title`.
            fila.title = examen.tipo ? examen.nombre + ' · ' + examen.tipo : examen.nombre;

            if (yaEstan.indexOf(examen.codigo) !== -1) {
                fila.disabled = true;
                fila.querySelector('[data-resultado-texto]').textContent = 'Ya está';
                fila.querySelector('[data-resultado-accion]').className = 'texto-tenue small';
            } else {
                fila.addEventListener('click', function () {
                    agregar(examen);
                    fila.disabled = true;
                    fila.querySelector('[data-resultado-texto]').textContent = 'Ya está';
                    fila.querySelector('[data-resultado-accion]').className = 'texto-tenue small';
                    ayuda.textContent = '«' + examen.nombre + '» entró a la lista. Se guarda con el botón.';
                });
            }

            resultados.appendChild(fila);
        });

        if (datos.examenes.length === 0) {
            limpiar();
            ayuda.textContent = 'Tu catálogo no tiene ' + cosas + ' que coincidan'
                + (datos.buscar !== '' ? ' con «' + datos.buscar + '»' : '') + '.';
            return;
        }

        resultados.hidden = false;
        ayuda.textContent = datos.total > datos.examenes.length
            ? 'Mostrando ' + datos.examenes.length + ' de ' + datos.total + ': escribe más para acotar.'
            : plural(datos.total, cosas === 'estudios' ? 'estudio coincide' : 'examen coincide', cosas === 'estudios' ? 'estudios coinciden' : 'exámenes coinciden') + '.';
    }

    function buscar() {
        var consulta = texto.value.trim();
        var elegido = tipo ? tipo.value : '';

        if (consulta === '' && elegido === '') {
            limpiar();
            ayuda.textContent = ayuda.dataset.ayuda;
            return;
        }

        var numero = ++ultimaConsulta;
        var url = raiz.dataset.urlCatalogo + '?q=' + encodeURIComponent(consulta) + (elegido !== '' ? '&tipo=' + encodeURIComponent(elegido) : '');

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (respuesta) { return respuesta.json(); })
            .then(function (datos) {
                // Una respuesta vieja no pisa lo que se escribió después.
                if (numero !== ultimaConsulta) return;
                pintar(datos);
            })
            .catch(function () {
                ayuda.textContent = 'No se pudo buscar ahora: revisa tu conexión.';
            });
    }

    if (texto) {
        texto.addEventListener('input', function () {
            clearTimeout(temporizador);
            temporizador = setTimeout(buscar, 250);
        });

        if (tipo) {
            tipo.addEventListener('change', buscar);
        }

        document.addEventListener('click', function (evento) {
            if (!evento.target.closest('#orden-busqueda')) limpiar();
        });
    }

    // ---------------------------------------------------------------------------------------- Rutinas

    document.addEventListener('click', function (evento) {
        var boton = evento.target.closest('[data-orden-rutina]');
        if (!boton || !raiz.contains(boton)) return;

        var cantidad = parseInt(boton.dataset.cantidad, 10) || 0;
        var nombre = boton.dataset.nombre;

        if (cantidad > umbral && !window.confirm('La rutina «' + nombre + '» agrega hasta ' + cantidad + ' exámenes a la orden. ¿Continuar?')) {
            return;
        }

        boton.disabled = true;

        fetch(raiz.dataset.urlRutina.replace('__TIPO__', encodeURIComponent(boton.getAttribute('data-orden-rutina'))), { headers: { 'Accept': 'application/json' } })
            .then(function (respuesta) {
                if (!respuesta.ok) throw new Error('rutina');
                return respuesta.json();
            })
            .then(function (datos) {
                var nuevos = 0;

                datos.examenes.forEach(function (examen) {
                    if (agregar(examen)) nuevos++;
                });

                var ya = datos.examenes.length - nuevos;
                ayuda.textContent = 'Rutina «' + datos.nombre + '»: ' + plural(nuevos, 'examen agregado', 'exámenes agregados')
                    + (ya > 0 ? ' (' + plural(ya, 'ya estaba', 'ya estaban') + ')' : '') + '. Se guarda con el botón.';
            })
            .catch(function () {
                ayuda.textContent = 'No se pudo traer la rutina: revisa tu conexión.';
            })
            .then(function () {
                boton.disabled = false;
            });
    });

    // ------------------------------------------------------------------------ Cambios sin guardar

    formulario.addEventListener('submit', function () { saliendo = true; });

    // Eliminar pide confirmación (shell.js, en `document`): si se confirma, la página se va y no hay nada que avisar. Este oyente se
    // registra después del de shell.js, así que ve `defaultPrevented` si se canceló.
    document.addEventListener('submit', function (evento) {
        if (evento.target.matches && evento.target.matches('[data-orden-salir]') && !evento.defaultPrevented) {
            saliendo = true;
        }
    });

    window.addEventListener('beforeunload', function (evento) {
        if (saliendo || !sucio()) return;
        evento.preventDefault();
        evento.returnValue = '';
    });

    actualizar();

    // «Exámenes | Rutinas» (WEB-11.20): dos chips que muestran una cosa a la vez. Sin JavaScript las dos se ven juntas.
    var modos = document.querySelector('[data-orden-modos]');
    var sueltos = document.getElementById('orden-modo-sueltos');
    var rutinas = document.getElementById('orden-rutinas');

    if (modos && sueltos && rutinas) {
        modos.hidden = false;
        rutinas.hidden = true;

        modos.addEventListener('click', function (evento) {
            var boton = evento.target.closest('[data-orden-modo]');
            if (!boton) return;

            var esRutinas = boton.dataset.ordenModo === 'rutinas';
            sueltos.hidden = esRutinas;
            rutinas.hidden = !esRutinas;
            modos.querySelectorAll('[data-orden-modo]').forEach(function (otro) {
                var activo = otro === boton;
                otro.classList.toggle('active', activo);
                otro.setAttribute('aria-pressed', activo ? 'true' : 'false');
            });
        });
    }
}());
