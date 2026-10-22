/*
 * El candado suave de la consulta: «abierta en otro dispositivo».
 *
 * Al abrir la consulta se registra este dispositivo (`POST` a `data-candado-url`, `ConsultaSesionController`) y mientras la
 * pantalla está a la vista se renueva cada ~60 s y al volver a la pestaña. El candado vence a los ~3 minutos sin latidos.
 *
 * - Otro dispositivo con el candado vigente: un diálogo avisa «La consulta está activa en otro dispositivo…» con «Abrir aquí»
 *   (toma el control) y «Salir» (vuelve a la agenda). Mismo dispositivo o candado vencido: entra sin preguntar.
 * - Es un aviso, no un bloqueo. Si otro dispositivo toma el control, el latido siguiente lo descubre y la pantalla muestra
 *   «Esta consulta se abrió en otro dispositivo» (con «Retomar aquí»); no se pierde nada de lo escrito.
 * - Offline-first: si el servidor no responde (sin conexión, sesión vencida) el latido falla en silencio y no bloquea nada.
 * - El dispositivo se identifica con un UUID guardado en localStorage (`ddr:dispositivo`), con respaldo en memoria.
 */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-candado-url]');
    if (!raiz) { return; }

    var url = raiz.getAttribute('data-candado-url');
    if (!url) { return; }

    var LATIDO_MS = 60 * 1000;
    var adelanto = Number(window.DDR_CANDADO_MS);
    if (adelanto > 0) { LATIDO_MS = adelanto; }

    // ------------------------------------------------------------------ el id del dispositivo
    var enMemoria = null;

    function nuevoId() {
        try {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') { return window.crypto.randomUUID(); }
        } catch (e) { /* sigue */ }
        var h = '';
        for (var i = 0; i < 32; i += 1) { h += Math.floor(Math.random() * 16).toString(16); }
        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }

    function dispositivo() {
        try {
            var guardado = window.localStorage.getItem('ddr:dispositivo');
            if (guardado && /^[A-Za-z0-9\-]{8,64}$/.test(guardado)) { return guardado; }
            var id = nuevoId();
            window.localStorage.setItem('ddr:dispositivo', id);
            return id;
        } catch (e) {
            if (!enMemoria) { enMemoria = nuevoId(); }
            return enMemoria;
        }
    }

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // ------------------------------------------------------------------ estado
    var tengoControl = false;
    var temporizador = null;
    var enVuelo = false;
    var dialogo = null;
    var aviso = null;

    function registrar(tomar) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ dispositivo_id: dispositivo(), tomar: !!tomar }),
        }).then(function (respuesta) {
            if (!respuesta.ok || respuesta.redirected) { return null; }
            return respuesta.json();
        }).catch(function () {
            // Sin conexión: en silencio, nada se bloquea.
            return null;
        });
    }

    function quitarAviso() {
        if (aviso) { aviso.remove(); aviso = null; }
    }

    function perderControl() {
        tengoControl = false;
        if (aviso) { return; }

        aviso = document.createElement('div');
        aviso.className = 'alert alert-warning py-2 d-flex flex-wrap align-items-center gap-2';
        aviso.setAttribute('role', 'alert');
        aviso.setAttribute('data-candado-aviso', '');
        aviso.innerHTML = '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>';

        var texto = document.createElement('span');
        texto.textContent = 'Esta consulta se abrió en otro dispositivo. Si sigues editando aquí podrías perder datos.';
        aviso.appendChild(texto);

        var retomar = document.createElement('button');
        retomar.type = 'button';
        retomar.className = 'btn btn-sm btn-outline-secondary ms-auto';
        retomar.textContent = 'Retomar aquí';
        retomar.addEventListener('click', function () {
            registrar(true).then(function (r) { if (r && r.estado === 'ok') { quitarAviso(); ganarControl(); } });
        });
        aviso.appendChild(retomar);

        var principal = document.getElementById('contenido-principal') || document.body;
        principal.insertBefore(aviso, principal.firstChild);
    }

    function latir() {
        if (!tengoControl || enVuelo || document.visibilityState !== 'visible') { return; }
        enVuelo = true;

        registrar(false).then(function (r) {
            enVuelo = false;
            if (r && r.estado === 'conflicto') { perderControl(); }
        });
    }

    function ganarControl() {
        tengoControl = true;
        if (temporizador === null) { temporizador = window.setInterval(latir, LATIDO_MS); }
    }

    // ------------------------------------------------------------------ el diálogo de conflicto
    function pedirDecision() {
        dialogo = document.getElementById('dialogo-candado');
        if (!dialogo || typeof dialogo.showModal !== 'function') { perderControl(); return; }

        var abrir = dialogo.querySelector('[data-candado-abrir]');
        // Sin Escape: la decisión es «Abrir aquí» o «Salir».
        dialogo.addEventListener('cancel', function (e) { e.preventDefault(); });

        abrir.addEventListener('click', function () {
            abrir.disabled = true;
            registrar(true).then(function (r) {
                abrir.disabled = false;
                if (r && r.estado === 'ok') {
                    dialogo.close();
                    ganarControl();
                } else if (r === null) {
                    // Sin conexión: sin servidor no hay a quién avisar; se deja entrar (offline-first).
                    dialogo.close();
                }
            });
        });

        dialogo.showModal();
    }

    // ------------------------------------------------------------------ arranque
    registrar(false).then(function (r) {
        if (r === null) { return; }
        if (r.estado === 'ok') { ganarControl(); } else { pedirDecision(); }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') { latir(); }
    });

    // Un guardado (autoguardado de `consulta-barra.js`) también comprueba que el candado siga siendo de este dispositivo.
    document.addEventListener('consulta:guardado', latir, true);
})();
