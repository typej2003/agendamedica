/**
 * Reserva pública: «¿Quién reserva?» (guía de UI, §11).
 *
 * Mientras no se elige, no se muestran los campos que sólo sirven para una de las dos respuestas. Al
 * elegir «Soy su representante» aparecen —y se vuelven obligatorios—; al volver a «Soy el paciente» se
 * esconden y se vacían sus errores. Sin JavaScript la vista los deja visibles (su `noscript`).
 */
(() => {
    const grupo = document.querySelector('[data-quien-reserva]');
    const campos = document.querySelector('[data-representante-campos]');

    if (grupo === null || campos === null) {
        return;
    }

    const entradas = campos.querySelectorAll('input');

    const actualizar = () => {
        const elegido = grupo.querySelector('input[name="reservado_por"]:checked');
        const esRepresentante = elegido !== null && elegido.value === 'R';

        campos.hidden = !esRepresentante;
        entradas.forEach((entrada) => {
            entrada.required = esRepresentante;
        });
    };

    grupo.addEventListener('change', actualizar);
    actualizar();
})();
