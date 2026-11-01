/**
 * Copiar y compartir el link de referido (fase 4). Sin dependencias.
 *
 * `navigator.clipboard` exige contexto seguro; en local se cae al `execCommand` con el input
 * seleccionado. `navigator.share` sólo existe en algunos teléfonos: si no está, comparte copiando.
 */
(function () {
    'use strict';

    const botonCopiar = document.getElementById('copiar-link-referido');
    const botonCompartir = document.getElementById('compartir-link-referido');
    const estado = document.getElementById('estado-link-referido');
    const entrada = document.getElementById('url-referido');

    if (!botonCopiar || !entrada) return;

    const url = botonCopiar.dataset.url || entrada.value;
    let temporizador = null;

    function avisar(texto) {
        if (!estado) return;
        estado.textContent = texto;
        window.clearTimeout(temporizador);
        temporizador = window.setTimeout(function () {
            estado.textContent = '';
        }, 4000);
    }

    async function copiar() {
        try {
            await navigator.clipboard.writeText(url);
        } catch (error) {
            entrada.removeAttribute('readonly');
            entrada.select();
            entrada.setSelectionRange(0, entrada.value.length);
            document.execCommand('copy');
            entrada.setAttribute('readonly', 'readonly');
        }

        avisar('Link copiado.');
    }

    botonCopiar.addEventListener('click', copiar);

    if (botonCompartir) {
        botonCompartir.addEventListener('click', async function () {
            if (!navigator.share) {
                await copiar();
                return;
            }

            try {
                await navigator.share({
                    title: 'Doctorísimo',
                    text: 'Te invito a Doctorísimo. Al registrarte y aprobar tu cuenta, los dos ganamos Latidos.',
                    url: url,
                });
            } catch (error) {
                // El usuario canceló el diálogo del sistema: no hay nada que avisar.
                if (error && error.name !== 'AbortError') await copiar();
            }
        });
    }
})();
