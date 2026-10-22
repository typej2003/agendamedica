/*
 * Borradores locales de la consulta (localStorage).
 *
 * Guarda en ESTE navegador lo que el médico escribe en los formularios de la consulta y todavía no guardó, para que un
 * corte de luz, de red o una pestaña cerrada sin querer no se lleven el texto. Es una red de seguridad, no un sustituto
 * del guardado: el dato de verdad sigue siendo el del servidor (offline-first, Docs/Wiki/12-arquitectura-offline-sync.md).
 *
 * Reglas:
 * - Clave: `ddr:borrador:<id de la consulta>:<formulario>`. Nunca lleva el nombre, la historia ni la cédula del paciente;
 *   el formulario se identifica por su `data-borrador-id`, su `id` o la ruta de su `action`.
 * - Se guardan campos de texto, números, fechas, `select`, casillas y radios. NO se guardan archivos, contraseñas, campos
 *   ocultos (token CSRF, `_method`, estado de los editores especiales) ni formularios GET. Un formulario con
 *   `data-sin-borrador` queda fuera.
 * - Con 800 ms de pausa se escribe el borrador. Si lo escrito vuelve a ser igual a lo del servidor, el borrador se borra.
 * - Al cargar: si hay un borrador distinto de lo que muestra el servidor, aparece un aviso discreto encima del formulario
 *   con «Restaurar» y «Descartar». Nada se restaura solo. Si el servidor cambió desde que se hizo el borrador (la «huella»
 *   del formulario al hacerlo es distinta de la de ahora), el aviso lo advierte.
 * - El borrador se borra al guardar con éxito. El envío es un `submit` normal con redirección: al enviar se marca
 *   `enviando` y la página que llega limpia los borradores marcados si no trae errores; con errores de validación se
 *   conservan. El autoguardado por `fetch` (`consulta-barra.js`) avisa con el evento `consulta:guardado`.
 * - Caducan a los 7 días (limpieza al cargar); `shell.js` los borra todos al cerrar sesión. Todo acceso a localStorage va
 *   en try/catch: si está bloqueado la pantalla funciona igual, sin borradores.
 */
(function () {
    'use strict';

    var barra = document.querySelector('[data-consulta-id]');
    if (!barra) { return; }

    var consultaId = String(barra.getAttribute('data-consulta-id') || '').replace(/[^0-9]/g, '');
    if (!consultaId) { return; }

    var PREFIJO = 'ddr:borrador:';
    var MIS = PREFIJO + consultaId + ':';
    var CADUCIDAD_MS = 7 * 24 * 60 * 60 * 1000;
    var PAUSA_MS = 800;
    var ENVIO_MS = 2 * 60 * 1000;

    // ------------------------------------------------------------------ localStorage a prueba de fallos
    function leer(clave) {
        try { return window.localStorage.getItem(clave); } catch (e) { return null; }
    }
    function escribir(clave, valor) {
        try { window.localStorage.setItem(clave, valor); return true; } catch (e) { return false; }
    }
    function quitar(clave) {
        try { window.localStorage.removeItem(clave); } catch (e) { /* sin almacenamiento */ }
    }
    function claves() {
        var lista = [];
        try {
            for (var i = 0; i < window.localStorage.length; i += 1) {
                var k = window.localStorage.key(i);
                if (k && k.indexOf(PREFIJO) === 0) { lista.push(k); }
            }
        } catch (e) { /* sin almacenamiento */ }
        return lista;
    }
    function leerBorrador(clave) {
        var crudo = leer(clave);
        if (!crudo) { return null; }
        try {
            var b = JSON.parse(crudo);
            return b && typeof b === 'object' && b.valores ? b : null;
        } catch (e) { return null; }
    }

    // ------------------------------------------------------------------ qué campos entran
    var EXCLUIDOS = ['hidden', 'password', 'file', 'submit', 'button', 'image', 'reset'];

    function guardable(campo) {
        if (!campo.name || campo.disabled || campo.readOnly) { return false; }
        if (campo.name === '_token' || campo.name === '_method') { return false; }
        if (campo.tagName === 'INPUT' && EXCLUIDOS.indexOf((campo.type || 'text').toLowerCase()) !== -1) { return false; }
        if (campo.tagName !== 'INPUT' && campo.tagName !== 'TEXTAREA' && campo.tagName !== 'SELECT') { return false; }
        return true;
    }

    function formularioValido(form) {
        if (!form || form.tagName !== 'FORM') { return false; }
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return false; }
        if (form.hasAttribute('data-sin-borrador') || form.hasAttribute('data-barra-terminar')) { return false; }
        if (form.closest('#clinica-sidebar, header')) { return false; }
        // Los formularios con archivos son de los editores de imágenes: no se guardan (el archivo no entra en localStorage).
        if (form.querySelector('input[type="file"]')) { return false; }
        return Array.prototype.some.call(form.elements, guardable);
    }

    function camposDe(form) {
        return Array.prototype.filter.call(form.elements, guardable);
    }

    // ------------------------------------------------------------------ identificar el formulario
    var ids = new WeakMap();

    function idDe(form) {
        if (ids.has(form)) { return ids.get(form); }

        var base = form.getAttribute('data-borrador-id') || form.id || '';
        if (!base) {
            try {
                var url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
                base = url.pathname + url.search;
            } catch (e) { base = 'form'; }
        }
        base = base.replace(/^\/clinica\//, '').replace(/[^A-Za-z0-9_\-\/?=&.]/g, '_').slice(0, 120);

        // Dos formularios con el mismo destino (p. ej. filas de una lista): se distinguen por su orden.
        var mismos = 0;
        Array.prototype.forEach.call(document.forms, function (f) {
            if (f !== form && ids.has(f) && ids.get(f).split('#')[0] === base) { mismos += 1; }
        });
        var id = mismos ? base + '#' + mismos : base;

        ids.set(form, id);
        return id;
    }

    function claveDe(form) { return MIS + idDe(form); }

    // ------------------------------------------------------------------ valores del formulario
    function valoresDeSelect(select) {
        return Array.prototype.filter.call(select.options, function (o) { return o.selected; })
            .map(function (o) { return o.value; });
    }

    function valoresDe(form) {
        var cuenta = {};
        var valores = {};

        camposDe(form).forEach(function (campo) {
            var n = cuenta[campo.name] = (cuenta[campo.name] || 0) + 1;
            var llave = campo.name + '#' + n;
            var tipo = (campo.type || '').toLowerCase();

            if (tipo === 'checkbox' || tipo === 'radio') {
                valores[llave] = campo.checked;
            } else if (campo.tagName === 'SELECT' && campo.multiple) {
                valores[llave] = valoresDeSelect(campo);
            } else {
                valores[llave] = campo.value;
            }
        });

        return valores;
    }

    function aplicar(form, valores) {
        var cuenta = {};

        camposDe(form).forEach(function (campo) {
            var n = cuenta[campo.name] = (cuenta[campo.name] || 0) + 1;
            var llave = campo.name + '#' + n;
            if (!Object.prototype.hasOwnProperty.call(valores, llave)) { return; }

            var valor = valores[llave];
            var tipo = (campo.type || '').toLowerCase();

            if (tipo === 'checkbox' || tipo === 'radio') {
                if (campo.checked === !!valor) { return; }
                campo.checked = !!valor;
            } else if (campo.tagName === 'SELECT' && campo.multiple) {
                var antes = JSON.stringify(valoresDeSelect(campo));
                Array.prototype.forEach.call(campo.options, function (o) { o.selected = valor.indexOf(o.value) !== -1; });
                if (antes === JSON.stringify(valoresDeSelect(campo))) { return; }
            } else {
                if (campo.value === valor) { return; }
                campo.value = valor;
            }

            // Para que los avisos de «cambios sin guardar», el autoguardado y las máscaras se enteren.
            campo.dispatchEvent(new Event('input', { bubbles: true }));
            campo.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function serializar(valores) { return JSON.stringify(valores); }

    // ------------------------------------------------------------------ el estado del servidor (huella)
    // La «versión del servidor» es lo que el formulario mostraba al cargar la página.
    var bases = new WeakMap();

    function baseDe(form) {
        if (!bases.has(form)) { bases.set(form, serializar(valoresDe(form))); }
        return bases.get(form);
    }

    // ------------------------------------------------------------------ guardar el borrador
    var temporizadores = new WeakMap();

    // Tras «Descartar» en el diálogo de salida no se escribe nada durante unos segundos: un `change` por el desenfoque o un
    // temporizador pendiente volverían a crear el borrador que se acaba de borrar.
    var congelado = false;

    function guardarBorrador(form) {
        if (congelado) { return; }
        var valores = valoresDe(form);
        var clave = claveDe(form);

        if (serializar(valores) === baseDe(form)) {
            quitar(clave);
            return;
        }

        escribir(clave, JSON.stringify({ v: 1, t: Date.now(), base: baseDe(form), valores: valores, enviando: 0 }));
    }

    function programar(evento) {
        var campo = evento.target;
        var form = campo && campo.form;
        if (!form || !guardable(campo) || !formularioValido(form)) { return; }

        window.clearTimeout(temporizadores.get(form));
        temporizadores.set(form, window.setTimeout(function () { guardarBorrador(form); }, PAUSA_MS));
    }

    // ------------------------------------------------------------------ avisos de recuperación
    function hace(t) {
        var seg = Math.max(0, Math.round((Date.now() - t) / 1000));
        if (seg < 60) { return 'unos segundos'; }
        var min = Math.round(seg / 60);
        if (min < 60) { return min + (min === 1 ? ' minuto' : ' minutos'); }
        var h = Math.round(min / 60);
        if (h < 24) { return h + (h === 1 ? ' hora' : ' horas'); }
        var d = Math.round(h / 24);
        return d + (d === 1 ? ' día' : ' días');
    }

    function mostrarAviso(form, borrador) {
        var clave = claveDe(form);
        var desactualizado = borrador.base !== baseDe(form);

        var aviso = document.createElement('div');
        aviso.className = 'alert alert-info py-2 d-flex flex-wrap align-items-center gap-2 mb-2';
        aviso.setAttribute('role', 'status');
        aviso.setAttribute('data-borrador-aviso', '');

        var icono = document.createElement('i');
        icono.className = 'bi bi-clock-history';
        icono.setAttribute('aria-hidden', 'true');
        aviso.appendChild(icono);

        var texto = document.createElement('span');
        texto.textContent = 'Recuperamos un borrador sin guardar de hace ' + hace(borrador.t) + '.'
            + (desactualizado ? ' Esta información cambió desde entonces: el borrador puede estar desactualizado.' : '');
        aviso.appendChild(texto);

        var acciones = document.createElement('span');
        acciones.className = 'ms-auto d-flex gap-2';

        var restaurar = document.createElement('button');
        restaurar.type = 'button';
        restaurar.className = 'btn btn-sm btn-outline-primary';
        restaurar.setAttribute('data-borrador-restaurar', '');
        restaurar.textContent = 'Restaurar';
        restaurar.addEventListener('click', function () {
            aplicar(form, borrador.valores);
            aviso.remove();
            guardarBorrador(form);
        });

        var descartar = document.createElement('button');
        descartar.type = 'button';
        descartar.className = 'btn btn-sm btn-outline-secondary';
        descartar.setAttribute('data-borrador-descartar', '');
        descartar.textContent = 'Descartar';
        descartar.addEventListener('click', function () {
            quitar(clave);
            aviso.remove();
        });

        acciones.appendChild(restaurar);
        acciones.appendChild(descartar);
        aviso.appendChild(acciones);

        form.parentNode.insertBefore(aviso, form);
    }

    // ------------------------------------------------------------------ envío y limpieza
    var ERRORES = '.alert[role="alert"], .is-invalid, .invalid-feedback';

    function inicializar() {
        // 1) Caducados.
        claves().forEach(function (clave) {
            var b = leerBorrador(clave);
            if (!b || !b.t || Date.now() - b.t > CADUCIDAD_MS) { quitar(clave); }
        });

        // 2) Los borradores de esta consulta que quedaron «enviando»: sin errores de validación el guardado salió bien.
        var hayErrores = !!document.querySelector(ERRORES);
        claves().forEach(function (clave) {
            if (clave.indexOf(MIS) !== 0) { return; }
            var b = leerBorrador(clave);
            if (!b || !b.enviando) { return; }

            if (!hayErrores && Date.now() - b.enviando < ENVIO_MS) {
                quitar(clave);
            } else {
                b.enviando = 0;
                escribir(clave, JSON.stringify(b));
            }
        });

        // 3) Avisos de recuperación.
        Array.prototype.forEach.call(document.forms, function (form) {
            if (!formularioValido(form)) { return; }

            var base = baseDe(form);
            var clave = claveDe(form);
            var b = leerBorrador(clave);
            if (!b) { return; }

            if (serializar(b.valores) === base) { quitar(clave); return; }
            mostrarAviso(form, b);
        });
    }

    document.addEventListener('input', programar);
    document.addEventListener('change', programar);

    // Al enviar (submit normal con redirección) el borrador se marca; el guardado exitoso lo limpia la página que llega.
    document.addEventListener('submit', function (evento) {
        var form = evento.target;
        if (!formularioValido(form)) { return; }

        window.clearTimeout(temporizadores.get(form));
        var clave = claveDe(form);

        // Un envío con cambios que aún no llegaron al borrador (menos de 800 ms desde el último tecleo).
        guardarBorrador(form);
        var b = leerBorrador(clave);
        if (!b) { return; }

        window.setTimeout(function () {
            // Una confirmación negada o un envío por fetch cancelan el submit: no hay nada que marcar.
            if (evento.defaultPrevented) { return; }
            b.enviando = Date.now();
            escribir(clave, JSON.stringify(b));
        }, 0);
    });

    // El autoguardado por fetch de `consulta-barra.js` dejó el formulario guardado en el servidor.
    document.addEventListener('consulta:guardado', function (evento) {
        var form = evento.target;
        if (!form || form.tagName !== 'FORM') { return; }
        quitar(claveDe(form));
        bases.set(form, serializar(valoresDe(form)));
    }, true);

    // API mínima para `consulta-salida.js` (el diálogo «Guardar todo / Descartar / Seguir editando»): qué formularios
    // están sucios (distintos de lo que mostró el servidor), marcar uno como guardado y descartar todos los borradores.
    window.ConsultaBorradores = {
        valido: formularioValido,
        sucios: function () {
            return Array.prototype.filter.call(document.forms, function (f) {
                return formularioValido(f) && serializar(valoresDe(f)) !== baseDe(f);
            });
        },
        guardado: function (form) {
            window.clearTimeout(temporizadores.get(form));
            quitar(claveDe(form));
            bases.set(form, serializar(valoresDe(form)));
        },
        descartarTodo: function () {
            congelado = true;
            window.setTimeout(function () { congelado = false; }, 3000);
            claves().forEach(function (clave) { if (clave.indexOf(MIS) === 0) { quitar(clave); } });
        },
    };

    window.setTimeout(inicializar, 0);
})();
