/**
 * Cobros: resolver un pago que espera confirmación (guía de UI, §11).
 *
 * Mientras no se elige qué hacer, no se muestran ni el monto ni el motivo, y cada rama trae su propio
 * botón. Sin JavaScript la vista deja las dos ramas a la vista (su `noscript`), como estaban antes.
 */
(() => {
    document.querySelectorAll('[data-cobro-resolver]').forEach((formulario) => {
        const eleccion = formulario.querySelector('[data-cobro-accion]');
        const bloques = formulario.querySelectorAll('[data-cobro-campos]');

        if (eleccion === null || bloques.length === 0) {
            return;
        }

        const actualizar = () => {
            bloques.forEach((bloque) => {
                bloque.hidden = bloque.dataset.cobroCampos !== eleccion.value;
            });
        };

        eleccion.addEventListener('change', actualizar);
        actualizar();
    });
})();
