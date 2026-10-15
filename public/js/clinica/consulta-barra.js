// La barra de la consulta (WEB-11.5): Guardar / Guardar y salir / Salir, el autoguardado y el aviso al cambiar de sección o de consulta.
//
// Decisión de Alexander (2026-10-15): los datos se van guardando solos cuando se pueda. Las secciones de texto (`form[data-autoguardar]`:
// enfermedad actual, evolución y las solapas de texto del examen) se guardan:
//   - 2 segundos después de dejar de escribir,
//   - al cambiar de sección (el panel), de solapa (los chips), de consulta (el navegador 1…N) o al salir,
// con el mismo `POST` del botón de la sección (la regla y el rastro del sync son los del servidor). El indicador dice «Guardado».
// Las secciones que cargan listas (motivos, diagnósticos…) guardan al instante con cada botón y no tienen formulario de texto:
// ahí los botones de la barra se deshabilitan.
(function () {
    'use strict';

    var barra = document.querySelector('[data-consulta-barra]');
    if (!barra) { return; }

    var estado = barra.querySelector('[data-barra-estado]');
    var botonGuardar = barra.querySelector('[data-barra-guardar]');
    var botonGuardarSalir = barra.querySelector('[data-barra-guardar-salir]');
    var form = document.querySelector('form[data-autoguardar]');
    var urlSalir = barra.dataset.urlSalir;
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
        [botonGuardar, botonGuardarSalir].forEach(function (boton) {
            boton.disabled = true;
            boton.title = 'Esta sección guarda al instante con cada botón.';
        });
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

    botonGuardarSalir.addEventListener('click', function () {
        guardar().then(function (ok) { if (ok) { window.location.href = urlSalir; } });
    });

    // Cambiar de sección (el panel), de solapa (los chips), de consulta (el navegador) o salir: primero se guarda lo escrito.
    document.addEventListener('click', function (evento) {
        var enlace = evento.target.closest('a[href]');
        if (!enlace || !enlace.closest('.consulta-panel, [data-chips], [data-barra-salir]')) { return; }
        if (evento.defaultPrevented || evento.ctrlKey || evento.metaKey || evento.shiftKey || !sucio()) { return; }

        evento.preventDefault();
        guardar().then(function (ok) { if (ok) { window.location.href = enlace.href; } });
    });
})();
