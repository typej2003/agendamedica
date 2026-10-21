/*
 * El checkout del plan (2026-10-21): renueva el pago cuando se acaba el tiempo de la tasa.
 *
 * No muestra un contador ni bloquea nada mientras consulta: al vencer, pide al servidor que renueve el
 * mismo pago. Si la tasa no cambió, el pago se extiende y el formulario sigue igual. Si cambió, el
 * servidor devuelve `cambio` con el monto nuevo: se bloquea el formulario y aparece «Reintentar» en el
 * resumen; al aceptarlo se actualiza el pago con la tasa nueva y se vuelve a habilitar el formulario.
 */
(function () {
    'use strict';

    const raiz = document.querySelector('[data-pago-refresco]');
    const form = document.getElementById('pago-form');

    if (!raiz || !form) {
        return;
    }

    const url = raiz.dataset.url;
    const csrf = raiz.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '';
    const caja = document.getElementById('pago-reintentar-caja');
    const boton = document.getElementById('pago-reintentar');
    const aviso = document.getElementById('pago-reintentar-aviso');
    const monto = document.querySelector('[data-pago-monto]');
    const enviar = form.querySelector('button[type="submit"]');

    let segundos = parseInt(raiz.dataset.segundos || '0', 10);
    let temporizador = null;
    let pidiendo = false;

    if (Number.isNaN(segundos)) {
        segundos = 0;
    }

    function bloquear(bloquear) {
        form.querySelectorAll('input, select, button').forEach(function (campo) {
            campo.disabled = bloquear;
        });
    }

    function mostrarReintentar(mensaje) {
        if (aviso) {
            aviso.textContent = mensaje || 'No se pudo renovar el pago.';
        }
        if (caja) {
            caja.classList.remove('d-none');
        }
        if (boton) {
            boton.disabled = false;
        }
    }

    function ocultarReintentar() {
        if (caja) {
            caja.classList.add('d-none');
        }
        if (boton) {
            boton.disabled = false;
        }
    }

    function programar() {
        if (temporizador) {
            clearTimeout(temporizador);
            temporizador = null;
        }

        if (segundos <= 0) {
            // Ya venció: se consulta enseguida, sin bloquear el formulario.
            temporizador = setTimeout(function () {
                refrescar(false);
            }, 0);

            return;
        }

        temporizador = setTimeout(function () {
            segundos = 0;
            refrescar(false);
        }, segundos * 1000);
    }

    async function refrescar(aplicar) {
        if (pidiendo) {
            return;
        }

        pidiendo = true;
        if (boton) {
            boton.disabled = true;
        }

        try {
            const respuesta = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ aplicar: aplicar }),
            });

            const datos = await respuesta.json().catch(function () {
                return { estado: 'error', mensaje: 'No se pudo renovar el pago.' };
            });

            if (!respuesta.ok || datos.estado === 'error') {
                bloquear(true);
                mostrarReintentar(datos.mensaje || 'No se pudo renovar el pago. Reintenta.');
                return;
            }

            if (datos.estado === 'cambio') {
                bloquear(true);
                mostrarReintentar(datos.mensaje);
                return;
            }

            // `renovado` o `actualizado`: el pago sigue pendiente y el formulario queda disponible.
            if (monto && datos.monto_texto) {
                monto.textContent = datos.monto_texto;
            }
            segundos = parseInt(datos.segundos_restantes || '0', 10);
            bloquear(false);
            ocultarReintentar();
            programar();
        } catch (e) {
            bloquear(true);
            mostrarReintentar('No se pudo renovar el pago. Reintenta.');
        } finally {
            pidiendo = false;
            if (boton) {
                boton.disabled = false;
            }
        }
    }

    if (boton) {
        boton.addEventListener('click', function () {
            refrescar(true);
        });
    }

    programar();
})();
