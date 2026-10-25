/**
 * **La foto del carnet del registro de médicos**: avisar antes de mandar el formulario cuando la imagen pesa
 * más de lo que el servidor acepta.
 *
 * El tope lo pone el servidor (`data-carnet-maximo`, en bytes: el menor entre la política de archivos y lo que
 * permiten `upload_max_filesize` / `post_max_size`). Cuando PHP descarta la foto por tamaño, el único error que
 * llega es «No se pudo subir el campo carnet», que no dice ni cuánto pesa ni cuánto se acepta; esto lo dice en
 * el momento de elegirla, sin subir nada, y también cuando la foto entra.
 *
 * Es sólo un aviso: la validación de verdad la sigue haciendo el controlador. Si el JS no corre, el campo
 * funciona igual que antes.
 */
(function () {
    'use strict';

    var campo = document.getElementById('carnet');

    if (!campo || !campo.form) {
        return;
    }

    var maximo = parseInt(campo.getAttribute('data-carnet-maximo') || '', 10);
    var aviso = document.getElementById('carnet-aviso');

    if (!maximo || !aviso) {
        return;
    }

    /** "4,3 MB" o "68 KB": el tamaño como lo lee una persona. */
    function enTamano(bytes) {
        if (bytes < 1048576) {
            return Math.max(1, Math.round(bytes / 1024)) + ' KB';
        }

        var megas = Math.round((bytes / 1048576) * 10) / 10;

        return String(megas).replace('.', ',') + ' MB';
    }

    /** Muestra u oculta el aviso. Devuelve si la foto elegida entra en el tope. */
    function revisar() {
        var archivo = campo.files && campo.files[0];
        var tamano = archivo ? archivo.size : 0;

        if (!archivo) {
            aviso.textContent = '';
            aviso.hidden = true;
            aviso.classList.remove('text-danger');
            campo.classList.remove('is-invalid');

            return true;
        }

        if (tamano > maximo) {
            aviso.textContent = 'La foto pesa ' + enTamano(tamano) + ' y el máximo es ' + enTamano(maximo)
                + '. Elige una más liviana o tómala con menos resolución.';
            aviso.hidden = false;
            aviso.classList.add('text-danger');
            campo.classList.add('is-invalid');

            return false;
        }

        // Entra: se dice igual cuánto pesa, para que el médico lo vea antes de enviar.
        aviso.textContent = 'Foto elegida: ' + (archivo.name || 'la imagen') + ' (' + enTamano(tamano) + ').';
        aviso.hidden = false;
        aviso.classList.remove('text-danger');
        campo.classList.remove('is-invalid');

        return true;
    }

    campo.addEventListener('change', revisar);

    // Con una foto que no entra no se manda: el servidor la descartaría y el trámite se perdería.
    campo.form.addEventListener('submit', function (evento) {
        if (!revisar()) {
            evento.preventDefault();
            campo.focus();
        }
    });
})();
