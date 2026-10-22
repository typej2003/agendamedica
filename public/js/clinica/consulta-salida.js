/*
 * El diálogo de salida de la consulta: «Guardar todo» / «Descartar» / «Seguir editando».
 *
 * Al abandonar la consulta (un enlace del menú o de la barra, la flecha a la agenda o «Terminar la consulta») con
 * formularios sin guardar, en vez de varios avisos se muestra UN diálogo propio:
 * - «Guardar todo»: envía cada formulario pendiente a su `action` de siempre (POST con su token, por `fetch`, uno tras otro);
 *   la regla de negocio es la del servidor. Si uno falla (validación, sesión, red) se detiene ahí, lo marca y se queda en la
 *   consulta; los ya guardados quedan guardados y su borrador local se limpia. Sin errores, continúa a donde iba.
 * - «Descartar»: borra los borradores locales y sigue sin más avisos.
 * - «Seguir editando»: cancela la salida.
 * Los editores con estado propio (récipes, órdenes, solicitudes, los de archivos e imágenes, los que «crean» o «agregan»)
 * NO se envían aquí: si están sucios se listan como «requieren guardado manual» y no se puede continuar sin Descartar.
 *
 * Cerrar la pestaña, recargar o «atrás» no se pueden personalizar: ahí queda el aviso nativo de cada pantalla, que sólo
 * salta con cambios de verdad. Tras elegir en el diálogo se levanta `window.ddrSalidaConfirmada` para que `shell.js` no
 * deje pasar ese aviso nativo. Guardar un formulario con su botón no pasa por aquí (no es un enlace).
 */
(function () {
    'use strict';

    var dialogo = document.getElementById('dialogo-salida');
    var API = window.ConsultaBorradores;
    if (!dialogo || !API) { return; }

    var lista = dialogo.querySelector('[data-salida-lista]');
    var intro = dialogo.querySelector('[data-salida-intro]');
    var textoManual = dialogo.querySelector('[data-salida-manual]');
    var estado = dialogo.querySelector('[data-salida-estado]');
    var error = dialogo.querySelector('[data-salida-error]');
    var bSeguir = dialogo.querySelector('[data-salida-seguir]');
    var bDescartar = dialogo.querySelector('[data-salida-descartar]');
    var bGuardar = dialogo.querySelector('[data-salida-guardar]');
    var terminar = document.querySelector('form[data-barra-terminar]');

    // Los formularios que NO entran en «Guardar todo».
    var MANUALES = '[data-consulta-recipe], [data-orden-form], [data-orden-resumen], [data-solicitud-form], [data-confirmar], [data-salida-manual]';
    var ACCIONES_ALTA = /\/(crear|agregar|nuevo|nueva|medico-nuevo|subir|gesta)(\/|$)/i;

    var destino = null;      // función que continúa la navegación
    var ocupado = false;
    var fallidos = [];

    function esManual(form) {
        if (form.matches(MANUALES)) { return true; }
        if (!API.valido(form)) { return true; }
        try { return ACCIONES_ALTA.test(new URL(form.action, window.location.href).pathname); } catch (e) { return false; }
    }

    function consultable(form) {
        return (form.getAttribute('method') || 'get').toLowerCase() === 'post'
            && form !== terminar && !form.closest('#clinica-sidebar, header, dialog');
    }

    // ------------------------------------------------------------------ cambios de los formularios «manuales»
    function huella(form) {
        var partes = [];
        new FormData(form).forEach(function (valor, nombre) {
            if (nombre === '_token') { return; }
            partes.push(nombre + '=' + (typeof valor === 'string' ? valor : 'archivo:' + valor.name + ':' + valor.size));
        });
        return partes.join('&');
    }

    var bases = new WeakMap();
    function tomarBases() {
        Array.prototype.forEach.call(document.forms, function (f) {
            if (consultable(f) && esManual(f)) { bases.set(f, huella(f)); }
        });
    }
    window.setTimeout(tomarBases, 0);

    function etiqueta(form) {
        var propia = form.getAttribute('data-salida-etiqueta');
        if (propia) { return propia; }
        var cabeza = form.closest('.card, section, fieldset');
        var titulo = cabeza && cabeza.querySelector('.card-titulo, h1, h2, h3, legend');
        var texto = titulo ? titulo.textContent.replace(/\s+/g, ' ').trim() : '';
        return texto ? texto.slice(0, 80) : (document.title.split('·')[0].trim() || 'Formulario');
    }

    function pendientes() {
        var guardables = API.sucios().filter(function (f) { return consultable(f) && !esManual(f); });
        var manuales = Array.prototype.filter.call(document.forms, function (f) {
            return consultable(f) && esManual(f) && bases.has(f) && huella(f) !== bases.get(f);
        });
        return { guardables: guardables, manuales: manuales, otros: false };
    }

    // Lo que ninguna lista conoce: los avisos propios de cada pantalla (`beforeunload`). Se les pregunta con un evento
    // sin efecto real: si alguno pide el aviso nativo, hay algo sin guardar.
    function algunaPantallaPideAviso() {
        var sonda = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(sonda);
        return sonda.defaultPrevented;
    }

    function hayPendientes() {
        var p = pendientes();
        if (p.guardables.length || p.manuales.length) { return p; }
        if (algunaPantallaPideAviso()) { p.otros = true; return p; }
        return null;
    }

    // ------------------------------------------------------------------ el diálogo
    function pintar(p) {
        lista.textContent = '';
        p.guardables.forEach(function (f) {
            var li = document.createElement('li');
            li.textContent = etiqueta(f);
            if (fallidos.indexOf(f) !== -1) { li.className = 'text-danger'; }
            lista.appendChild(li);
        });

        var hayManual = p.manuales.length > 0 || p.otros;
        textoManual.hidden = !hayManual;
        if (hayManual) {
            var nombres = p.manuales.map(etiqueta);
            textoManual.textContent = 'Requieren guardado manual (con su propio botón): '
                + (nombres.length ? nombres.join(', ') : 'otros cambios de esta pantalla')
                + '. Para salir elige «Descartar» o vuelve con «Seguir editando».';
        }
        intro.textContent = p.guardables.length
            ? 'Estos formularios tienen cambios sin guardar:'
            : 'Esta pantalla tiene cambios sin guardar.';
        bGuardar.hidden = !p.guardables.length;
    }

    function activar(si) {
        bGuardar.disabled = bDescartar.disabled = bSeguir.disabled = !si;
    }

    function abrir(p, continuar) {
        destino = continuar;
        fallidos = [];
        estado.textContent = '';
        error.hidden = true;
        error.textContent = '';
        pintar(p);
        activar(true);
        if (!dialogo.open) { dialogo.showModal(); }
        (p.guardables.length ? bGuardar : bSeguir).focus();
    }

    function avanzar() {
        window.ddrSalidaConfirmada = true;
        dialogo.close();
        var f = destino;
        destino = null;
        if (f) { f(); }
    }

    function fallo(form, mensaje) {
        fallidos = [form];
        error.textContent = mensaje;
        error.hidden = false;
        estado.textContent = '';
        form.classList.add('border', 'border-danger');
        form.setAttribute('data-salida-error', '');
    }

    function mensajeDe(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var alerta = doc.querySelector('.alert[role="alert"], .alert-danger');
        if (alerta) { return alerta.textContent.replace(/\s+/g, ' ').trim().slice(0, 200); }
        var campo = doc.querySelector('.invalid-feedback');
        if (campo && campo.textContent.trim()) { return campo.textContent.replace(/\s+/g, ' ').trim().slice(0, 200); }
        return doc.querySelector('.is-invalid') ? 'Revisa los campos marcados.' : '';
    }

    // Un formulario por `fetch`: true si quedó guardado; si no, ya dejó el error a la vista.
    function enviar(form) {
        return fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (r) {
            return r.text().then(function (html) { return { ok: r.ok, status: r.status, html: html }; });
        }).then(function (r) {
            if (r.status === 419) { fallo(form, 'Tu sesión venció: lo escrito no se guardó. Cópialo y vuelve a entrar.'); return false; }
            var problema = mensajeDe(r.html);
            if (!r.ok || problema) { fallo(form, etiqueta(form) + ': ' + (problema || 'No se pudo guardar.')); return false; }
            form.classList.remove('border', 'border-danger');
            form.removeAttribute('data-salida-error');
            form.dispatchEvent(new CustomEvent('consulta:guardado'));
            API.guardado(form);
            return true;
        }, function () {
            fallo(form, 'Sin conexión: no se guardó ' + etiqueta(form) + '. Tu borrador sigue protegido en este navegador.');
            return false;
        });
    }

    function guardarTodo() {
        var cola = pendientes().guardables.slice();
        var total = cola.length;
        if (!total || ocupado) { return; }
        ocupado = true;
        activar(false);
        error.hidden = true;

        function siguiente(n) {
            if (!cola.length) { return Promise.resolve(true); }
            var form = cola.shift();
            estado.textContent = 'Guardando ' + n + ' de ' + total + '…';
            if (window.navigator.onLine === false) {
                fallo(form, 'Sin conexión: no se guardó ' + etiqueta(form) + '. Tu borrador sigue protegido en este navegador.');
                return Promise.resolve(false);
            }
            return enviar(form).then(function (ok) { return ok ? siguiente(n + 1) : false; });
        }

        siguiente(1).then(function (ok) {
            ocupado = false;
            var resto = pendientes();
            if (!ok) {
                activar(true);
                pintar(resto);
                var malo = document.querySelector('form[data-salida-error]');
                if (malo && malo.scrollIntoView) { malo.scrollIntoView({ block: 'center' }); }
                return;
            }
            if (resto.manuales.length) {
                activar(true);
                estado.textContent = 'Se guardó lo demás.';
                pintar(resto);
                return;
            }
            estado.textContent = 'Guardado.';
            avanzar();
        });
    }

    bGuardar.addEventListener('click', guardarTodo);
    bSeguir.addEventListener('click', function () { destino = null; dialogo.close(); });
    bDescartar.addEventListener('click', function () {
        API.descartarTodo();
        // Sin guardados silenciosos pendientes (`consulta-barra.js`) mientras se va; si la salida se frena, se levanta.
        window.ddrDescartando = true;
        window.setTimeout(function () { window.ddrDescartando = false; }, 3000);
        avanzar();
    });
    dialogo.addEventListener('cancel', function (e) { if (ocupado) { e.preventDefault(); } else { destino = null; } });

    // ------------------------------------------------------------------ los puntos de salida
    // En captura, para correr antes que el guardado silencioso de `consulta-barra.js`.
    document.addEventListener('click', function (evento) {
        var enlace = evento.target.closest ? evento.target.closest('a[href]') : null;
        if (!enlace || dialogo.contains(enlace)) { return; }
        if (evento.defaultPrevented || evento.button !== 0 || evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey) { return; }
        if (enlace.target && enlace.target !== '_self') { return; }
        if (enlace.hasAttribute('download') || enlace.hasAttribute('data-abrir-dialogo') || enlace.hasAttribute('data-cerrar-dialogo')) { return; }

        var url;
        try { url = new URL(enlace.href, window.location.href); } catch (e) { return; }
        if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) { return; }
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) { return; }

        var p = hayPendientes();
        if (!p) { return; }

        evento.preventDefault();
        evento.stopImmediatePropagation();
        abrir(p, function () { window.location.href = enlace.href; });
    }, true);

    if (terminar) {
        document.addEventListener('submit', function (evento) {
            if (evento.target !== terminar || terminar.dataset.salidaOk === '1') { return; }
            var p = hayPendientes();
            if (!p) { return; }

            evento.preventDefault();
            evento.stopImmediatePropagation();
            abrir(p, function () {
                // Ya se guardó o se descartó: `consulta-barra.js` no debe volver a guardar y `shell.js` pide su confirmación.
                terminar.dataset.salidaOk = '1';
                terminar.dataset.enviando = '1';
                try { terminar.requestSubmit(); } finally {
                    delete terminar.dataset.salidaOk;
                    delete terminar.dataset.enviando;
                    window.ddrSalidaConfirmada = false;
                }
            });
        }, true);
    }

    window.addEventListener('pageshow', function () { window.ddrSalidaConfirmada = false; });
})();
