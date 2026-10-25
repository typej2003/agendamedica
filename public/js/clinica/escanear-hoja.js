/*
 * **Escanear la hoja de resultados** (WEB-13.15). La vista declara `[data-escanear-hoja]` con la URL de lectura
 * (`data-url`), el campo de destino (`data-destino`) y el renglón de estado (`data-estado`); el `<input type=file>`
 * es `[data-escanear-archivo]`.
 *
 * Al elegir el archivo (en el teléfono, el selector ofrece la cámara) lo manda con `fetch`, muestra «Leyendo la
 * hoja…» y pone el texto leído en el campo: con el campo vacío lo escribe; con texto, lo **agrega al final** —nunca
 * reemplaza lo escrito—. Dispara `input`, así que el aviso de cambios sin guardar y el autoguardado de la sección
 * (`consulta-barra.js`) lo tratan como cualquier edición. Nada se guarda desde acá.
 */
(function () {
    'use strict';

    var token = document.querySelector('meta[name="csrf-token"]');

    document.querySelectorAll('[data-escanear-hoja]').forEach(function (caja) {
        var archivo = caja.querySelector('[data-escanear-archivo]');
        var campo = document.querySelector(caja.dataset.destino || '');
        var estado = document.querySelector(caja.dataset.estado || '');
        var boton = caja.querySelector('label');
        if (!archivo || !campo || !caja.dataset.url) { return; }

        function mostrar(tono, icono, texto) {
            if (!estado) { return; }
            estado.className = 'alert alert-' + tono + ' py-2 d-flex align-items-center gap-2 mb-3';
            estado.textContent = '';
            var i = document.createElement('i');
            i.className = 'bi ' + icono;
            i.setAttribute('aria-hidden', 'true');
            var span = document.createElement('span');
            span.textContent = texto;
            estado.appendChild(i);
            estado.appendChild(span);
            estado.hidden = false;
        }

        function ocupado(si) {
            archivo.disabled = si;
            if (boton) { boton.classList.toggle('disabled', si); boton.setAttribute('aria-disabled', si ? 'true' : 'false'); }
        }

        function poner(texto) {
            var valor = campo.value.replace(/\s+$/, '');
            campo.value = valor === '' ? texto : valor + '\n\n' + texto;
            campo.dispatchEvent(new Event('input', { bubbles: true }));
            campo.focus();
        }

        archivo.addEventListener('change', function () {
            var elegido = archivo.files && archivo.files[0];
            if (!elegido) { return; }

            var datos = new FormData();
            datos.append('hoja', elegido);
            ocupado(true);
            mostrar('info', 'bi-hourglass-split', 'Leyendo la hoja… puede tardar unos segundos.');

            fetch(caja.dataset.url, {
                method: 'POST',
                body: datos,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '', 'X-Requested-With': 'XMLHttpRequest' },
            }).then(function (r) {
                if (r.status === 419) { throw new Error('Tu sesión venció. Vuelve a entrar y escanea de nuevo.'); }
                if (r.status === 429) { throw new Error('Demasiadas lecturas seguidas. Espera un minuto e inténtalo de nuevo.'); }
                return r.json().catch(function () { return {}; }).then(function (cuerpo) {
                    if (!r.ok || typeof cuerpo.texto !== 'string') {
                        throw new Error(cuerpo.message || 'No se pudo leer la hoja. Inténtalo de nuevo o escribe el resultado a mano.');
                    }
                    return cuerpo;
                });
            }).then(function (cuerpo) {
                poner(cuerpo.texto);
                mostrar('warning', 'bi-exclamation-triangle-fill', cuerpo.aviso || 'Revisa el texto leído contra la hoja antes de guardar.');
            }).catch(function (error) {
                mostrar('danger', 'bi-x-octagon-fill', error.message);
            }).finally(function () {
                ocupado(false);
                archivo.value = '';
            });
        });
    });
})();
