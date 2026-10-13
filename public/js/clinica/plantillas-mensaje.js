/*
 * Mensajes a pacientes (WEB-8.4): los editores de la plantilla del recordatorio de cita y del saludo de cumpleaños. Las vistas no
 * llevan JS: declaran lo que quieren con `data-*` y este archivo lo resuelve. Hace cuatro cosas, por cada `[data-plantilla]`:
 *
 * 1. **Botones de etiquetas** (`[data-etiqueta]`): ponen la etiqueta (`{paciente}`…) donde está el cursor, o reemplazan lo
 *    seleccionado, y dejan el cursor después de ella.
 * 2. **Vista previa en vivo**: al escribir (con *debounce*) pide al servidor el análisis del texto —`POST …/vista-previa`, un JSON
 *    que no guarda nada— y pinta el mensaje con datos de ejemplo, el contador de SMS y los avisos. La lógica de las etiquetas y la
 *    cuenta de los SMS viven **solo en el servidor** (las mismas clases que arman el mensaje real): acá no se repiten. El contador de
 *    caracteres sí se cuenta al instante, porque es trivial.
 * 3. **Cambios sin guardar**: el aviso de la pantalla (`[data-plantilla-aviso]`) y el del navegador al salir.
 * 4. **«Partir del mensaje por defecto»** (`[data-usar-defecto]`): copia el mensaje por defecto al campo para editarlo.
 *
 * Sin JavaScript la pantalla sigue sirviendo: cada editor es un formulario común, y la vista previa y los avisos son los del
 * servidor al cargar.
 */
(function () {
    'use strict';

    var ESPERA = 250;
    var sucios = new Set();
    var guardando = false;

    function contarCaracteres(texto) {
        // Puntos de código y no unidades UTF-16: es lo que cuenta el servidor (`mb_strlen`).
        return Array.from(texto).length;
    }

    function pintarAvisos(lista, avisos) {
        lista.textContent = '';
        avisos.forEach(function (aviso) {
            var fila = document.createElement('li');
            fila.className = 'plantilla-aviso plantilla-aviso-' + aviso.tono;

            var icono = document.createElement('i');
            icono.className = 'bi ' + (aviso.tono === 'aviso' ? 'bi-exclamation-triangle' : 'bi-info-circle');
            icono.setAttribute('aria-hidden', 'true');

            var texto = document.createElement('span');
            texto.textContent = aviso.texto;

            fila.appendChild(icono);
            fila.appendChild(texto);
            lista.appendChild(fila);
        });
    }

    function iniciar(editor) {
        var formulario = editor.querySelector('[data-plantilla-form]');
        var campo = editor.querySelector('[data-plantilla-texto]');
        if (!formulario || !campo) return;

        var maximo = parseInt(editor.dataset.maximo, 10) || 0;
        var url = editor.dataset.urlVistaPrevia;
        var previa = editor.querySelector('[data-vista-previa]');
        var contador = editor.querySelector('[data-contador]');
        var sms = editor.querySelector('[data-sms]');
        var avisos = editor.querySelector('[data-avisos]');
        var aviso = editor.querySelector('[data-plantilla-aviso]');
        var token = formulario.querySelector('input[name="_token"]');
        var espera = null;
        var peticion = null;

        function actualizarContador() {
            if (contador) contador.textContent = contarCaracteres(campo.value) + ' de ' + maximo + ' caracteres';
        }

        function pedirVistaPrevia() {
            if (!url) return;

            if (peticion) peticion.abort();
            peticion = typeof AbortController === 'function' ? new AbortController() : null;

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                signal: peticion ? peticion.signal : undefined,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': token ? token.value : ''
                },
                body: JSON.stringify({ texto: campo.value })
            }).then(function (respuesta) {
                if (!respuesta.ok) throw new Error('vista previa ' + respuesta.status);
                return respuesta.json();
            }).then(function (datos) {
                if (previa) previa.textContent = datos.vista_previa;
                if (sms) sms.textContent = datos.sms_frase;
                if (avisos) pintarAvisos(avisos, datos.avisos || []);
            }).catch(function (error) {
                // Una petición cancelada por otra más nueva no es un fallo; sin conexión se queda la última vista previa.
                if (error && error.name === 'AbortError') return;
            });
        }

        function alEscribir() {
            actualizarContador();
            sucios.add(formulario);
            if (aviso) aviso.hidden = false;

            window.clearTimeout(espera);
            espera = window.setTimeout(pedirVistaPrevia, ESPERA);
        }

        campo.addEventListener('input', alEscribir);

        editor.querySelectorAll('[data-etiqueta]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                var etiqueta = boton.dataset.etiqueta;
                var inicio = typeof campo.selectionStart === 'number' ? campo.selectionStart : campo.value.length;
                var fin = typeof campo.selectionEnd === 'number' ? campo.selectionEnd : inicio;

                campo.value = campo.value.slice(0, inicio) + etiqueta + campo.value.slice(fin);

                var posicion = inicio + etiqueta.length;
                campo.focus();
                campo.setSelectionRange(posicion, posicion);
                alEscribir();
            });
        });

        var defecto = editor.querySelector('[data-usar-defecto]');
        if (defecto) {
            defecto.addEventListener('click', function () {
                campo.value = defecto.dataset.usarDefecto;
                campo.focus();
                alEscribir();
            });
        }

        formulario.addEventListener('submit', function () {
            // Guardar es la salida correcta: ni el aviso del navegador ni el de la pantalla tienen nada que decir.
            guardando = true;
            sucios.delete(formulario);
            // Una vista previa pendiente no debe cruzarse con la redirección: una petición de la misma sesión entre el guardado y la
            // pantalla siguiente consumiría el aviso (o los errores) que el servidor dejó para ella.
            window.clearTimeout(espera);
            if (peticion) peticion.abort();
        });
    }

    document.querySelectorAll('[data-plantilla]').forEach(iniciar);

    // «Restablecer» es otro formulario (no se anidan): una vez confirmado, también es una salida correcta.
    document.addEventListener('submit', function (evento) {
        if (evento.target.matches('form:not([data-plantilla-form])') && !evento.defaultPrevented) {
            guardando = true;
        }
    });

    window.addEventListener('beforeunload', function (evento) {
        if (guardando || sucios.size === 0) return;
        evento.preventDefault();
        evento.returnValue = '';
    });
}());
