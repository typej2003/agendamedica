// La fila del encabezado de la consulta (A-11): Guardar, el autoguardado y el aviso al cambiar de sección o de consulta.
//
// Decisión de Alexander (2026-10-15): los datos se van guardando solos cuando se pueda. Las secciones de texto (`form[data-autoguardar]`:
// enfermedad actual, evolución y las solapas de texto del examen) se guardan:
//   - 2 segundos después de dejar de escribir,
//   - al cambiar de sección (el menú lateral), de solapa (los chips), de consulta (el navegador 1…N), al volver a la
//     agenda con la flecha o al terminar la consulta,
// con el mismo `POST` del botón de la sección (la regla y el rastro del sync son los del servidor). El indicador dice «Guardado».
// Las secciones que cargan listas (motivos, diagnósticos…) guardan al instante con cada botón y no tienen formulario de texto:
// ahí el botón Guardar se deshabilita.
//
// «Guardar y salir» y «Salir» se fueron con la fila nueva: la salida es la flecha «Volver a la agenda» y «Terminar la
// consulta»; las dos guardan antes lo escrito (la flecha por `data-barra-salir`, el formulario por `data-barra-terminar`).
(function () {
    'use strict';

    var barra = document.querySelector('[data-consulta-barra]');
    if (!barra) { return; }

    var estado = barra.querySelector('[data-barra-estado]');
    var botonGuardar = barra.querySelector('[data-barra-guardar]');
    var form = document.querySelector('form[data-autoguardar]');
    var formularioTerminar = barra.querySelector('[data-barra-terminar]');
    var pausa = null;
    var guardando = null;

    function decir(texto, error) {
        estado.textContent = texto;
        estado.classList.toggle('es-error', !!error);
    }

    function hora() {
        var ahora = new Date();
        return ('0' + ahora.getHours()).slice(-2) + ':' + ('0' + ahora.getMinutes()).slice(-2);
    }

    if (!form) {
        if (botonGuardar) {
            botonGuardar.disabled = true;
            botonGuardar.title = 'Esta sección guarda al instante con cada botón.';
        }
        return;
    }

    function instantanea() {
        var partes = [];
        new FormData(form).forEach(function (valor, nombre) {
            if (nombre !== '_token' && typeof valor === 'string') { partes.push(nombre + '=' + valor); }
        });
        return partes.join('&');
    }

    var guardado = instantanea();

    function sucio() { return instantanea() !== guardado; }

    // Guarda con el POST de la sección. Resuelve true si quedó guardado (o no había nada que guardar).
    function guardar() {
        clearTimeout(pausa);
        if (guardando) { return guardando; }
        if (!sucio()) { decir('Guardado ' + hora(), false); return Promise.resolve(true); }

        var enviado = instantanea();
        decir('Guardando…', false);

        guardando = fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (respuesta) {
            return respuesta.text().then(function (html) { return { ok: respuesta.ok, html: html }; });
        }).then(function (r) {
            var documento = new DOMParser().parseFromString(r.html, 'text/html');
            var problema = documento.querySelector('.alert[role="alert"], .alert-danger');
            if (!r.ok || problema) {
                decir(problema ? problema.textContent.trim().slice(0, 120) : 'No se pudo guardar.', true);
                return false;
            }
            guardado = enviado;
            form.dispatchEvent(new CustomEvent('consulta:guardado'));
            decir('Guardado ' + hora(), false);
            return true;
        }).catch(function () {
            decir('No se pudo guardar: revisa la conexión.', true);
            return false;
        }).then(function (ok) {
            guardando = null;
            return ok;
        });

        return guardando;
    }

    form.addEventListener('input', function () {
        decir('Sin guardar…', false);
        clearTimeout(pausa);
        pausa = setTimeout(guardar, 2000);
    });

    botonGuardar.addEventListener('click', function () { guardar(); });

    // «Terminar la consulta» (A-11): el formulario pide confirmación en `shell.js` (`data-confirmar`) y, si hay algo
    // sin guardar, esto guarda primero y recién después lo envía. El envío de verdad se hace con `form.submit()`, que
    // no vuelve a disparar los `submit` (una sola confirmación). Si el médico canceló la confirmación, `shell.js` ya
    // dejó `defaultPrevented` y acá no se guarda ni se envía nada.
    //
    // El listener va en `document` y no en el formulario a propósito: `shell.js` (que es quien pide la confirmación)
    // escucha también en `document` y se carga antes, así que el orden es confirmar → guardar → enviar. Escuchando en
    // el formulario, este handler correría **antes** de la confirmación y, al enviar con `form.submit()`, la saltearía.
    if (formularioTerminar) {
        document.addEventListener('submit', function (evento) {
            if (evento.target !== formularioTerminar) { return; }
            if (evento.defaultPrevented || formularioTerminar.dataset.enviando === '1' || !sucio()) { return; }

            evento.preventDefault();
            guardar().then(function (ok) {
                if (!ok) { return; }
                formularioTerminar.dataset.enviando = '1';
                formularioTerminar.submit();
            });
        });
    }

    // Cambiar de sección (el grupo «Consulta» del menú lateral), de solapa (los chips), de consulta (el navegador) o
    // volver a la agenda con la flecha: primero se guarda lo escrito. El grupo se reconoce por su `data-menu`.
    document.addEventListener('click', function (evento) {
        var enlace = evento.target.closest('a[href]');
        if (!enlace || !enlace.closest('[data-menu="consulta"], [data-chips], [data-barra-salir]')) { return; }
        if (evento.defaultPrevented || evento.ctrlKey || evento.metaKey || evento.shiftKey || !sucio()) { return; }

        evento.preventDefault();
        guardar().then(function (ok) { if (ok) { window.location.href = enlace.href; } });
    });
})();
