// El latido de la sesión (2026-10-19): toca la sesión cada pocos minutos mientras la pantalla está a la
// vista, para que no se venza con la consulta abierta.
//
// El problema que resuelve: la sesión vence a los `SESSION_LIFETIME` minutos **sin peticiones** (120 por
// defecto). La consulta se queda abierta horas y sus secciones se guardan solas, así que puede haber ratos
// largos sin una sola petición; cuando la sesión ya había vencido, «Terminar la consulta» contestaba 419
// («tu sesión venció») y la cita no se marcaba como atendida. El servidor no puede saber si la pestaña
// sigue abierta: lo dice este archivo con un GET vacío a `clinica.sesion` (`LatidoDeSesionController`), que
// contesta 204 y lo único que hace es refrescar la marca de actividad de la sesión.
//
// Se late **sólo con la pestaña a la vista** (`visibilityState`): una pestaña abandonada —la que puede
// quedar abierta en una computadora compartida— no se mantiene viva, y a los `SESSION_LIFETIME` minutos
// vence como siempre. Los navegadores además frenan los temporizadores de una pestaña oculta, así que
// tampoco habría que confiar en ellos.
//
// `window.DDR_LATIDO_MS` adelanta el intervalo y lo usan los verificadores de Chrome para medir el latido
// en segundos en vez de esperar cinco minutos. En la aplicación no se define nunca.
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="latido-sesion"]');
    var url = meta ? meta.getAttribute('content') : '';
    if (!url) { return; }

    // Cinco minutos contra los 120 de la sesión: sobra, y aun con un servidor configurado a 10 minutos el
    // latido llega a tiempo.
    var intervalo = 5 * 60 * 1000;
    var adelanto = Number(window.DDR_LATIDO_MS);
    if (adelanto > 0) { intervalo = adelanto; }

    var ultimo = Date.now();
    var latiendo = false;
    var temporizador = null;

    function latir() {
        if (latiendo || document.visibilityState !== 'visible') { return; }
        latiendo = true;

        fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (respuesta) {
            latiendo = false;

            // La sesión ya no existe (el servidor manda al login) o la cuenta está bloqueada: no hay nada
            // que seguir latiendo. El próximo clic lo resuelve la navegación normal.
            if (respuesta.redirected || respuesta.status === 401 || respuesta.status === 403 || respuesta.status === 419) {
                if (temporizador !== null) { window.clearInterval(temporizador); }
                temporizador = null;
                return;
            }

            ultimo = Date.now();
        }).catch(function () {
            // Sin conexión: se reintenta en el próximo latido.
            latiendo = false;
        });
    }

    temporizador = window.setInterval(latir, intervalo);

    // Al volver a la pestaña después de un rato, los temporizadores estuvieron frenados: si el último latido
    // quedó viejo, se late de una vez (no en el próximo intervalo, que puede ser tarde).
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && Date.now() - ultimo >= intervalo) { latir(); }
    });
})();
