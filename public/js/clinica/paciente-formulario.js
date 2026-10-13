/*
 * Alta y edición de paciente (`/clinica/pacientes/nuevo` y `/clinica/pacientes/{paciente}/editar`,
 * WEB-3.2). La vista no lleva JS inline (Docs/Web/guia-arquitectura-web.md): lo que declara son los
 * controles `#sin_cedula`, `#cedula` y `#atender_hoy`, y esto es lo que los conecta.
 *
 * Dos cosas:
 *
 *  - **"Sin cédula (S.C.)"**: con el tilde puesto, la cédula queda deshabilitada y vacía —no se escribe un
 *    documento y se dice que no hay—, y al destildarlo vuelve a habilitarse. Escribir una cédula destilda
 *    la opción: son excluyentes. El servidor no depende de esto (valida el campo con `sin_cedula`), es
 *    solo para que la pantalla no pida un dato que el usuario ya dijo que no tiene.
 *  - **"¿Atenderá a este paciente hoy?"**: el texto de ayuda cambia con la respuesta, para que se lea qué
 *    va a pasar al guardar sin tener que probarlo.
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

    // --- ¿Atenderá a este paciente hoy? ----------------------------------------------------------

    const atenderHoy = document.getElementById('atender_hoy');
    const ayuda = document.getElementById('atender-hoy-ayuda');

    if (atenderHoy && ayuda) {
        const textoNormal = ayuda.textContent;
        const textoSi = 'Al guardar te llevo al alta de la cita de hoy con el paciente ya elegido: ahí se pide la '
            + 'razón de la consulta y se arma la cola del día.';

        const aplicarTexto = function () {
            ayuda.textContent = atenderHoy.checked ? textoSi : textoNormal;
        };

        atenderHoy.addEventListener('change', aplicarTexto);
        aplicarTexto();
    }
})();
