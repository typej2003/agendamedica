/*
 * Alta y edición de paciente (`/clinica/pacientes/nuevo` y `/clinica/pacientes/{paciente}/editar`,
 * WEB-3.2). La vista no lleva JS inline (Docs/Web/guia-arquitectura-web.md): lo que declara son los
 * controles `#sin_cedula` y `#cedula`, y esto es lo que los conecta.
 *
 * **"Sin cédula (S.C.)"**: con el tilde puesto, la cédula queda deshabilitada y vacía —no se escribe un
 * documento y se dice que no hay—, y al destildarlo vuelve a habilitarse. Escribir una cédula destilda
 * la opción: son excluyentes. El servidor no depende de esto (valida el campo con `sin_cedula`), es
 * solo para que la pantalla no pida un dato que el usuario ya dijo que no tiene.
 *
 * La vieja pregunta "¿Atenderá a este paciente hoy?" no está más (O-04/O-05): el alta numera la historia y
 * la pregunta «¿Atender ahora?» la hace la ficha, en `atender-paciente.js`.
 */
(function () {
    'use strict';

    // --- Sin cédula -----------------------------------------------------------------------------

    const sinCedula = document.getElementById('sin_cedula');
    const cedula = document.getElementById('cedula');

    if (sinCedula && cedula) {
        const aplicarEstado = function () {
            cedula.disabled = sinCedula.checked;

            if (sinCedula.checked) {
                cedula.value = '';
            }
        };

        sinCedula.addEventListener('change', aplicarEstado);
        cedula.addEventListener('input', function () {
            if (cedula.value.trim() !== '') {
                sinCedula.checked = false;
                cedula.disabled = false;
            }
        });

        aplicarEstado();
    }
})();
