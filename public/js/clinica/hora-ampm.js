/*
 * Campo de hora con AM/PM (`<x-ui.hora>`).
 *
 * El campo visible (`input[data-hora-ampm]`) es de texto y tolerante; el `<input type="hidden">` hermano
 * (el id está en `data-hora-ampm`) lleva `HH:MM` de 24 h, que es lo que el servidor ya espera.
 *
 * Criterio cuando se escribe sin AM/PM:
 *   - 13 a 23 -> 24 h tal cual («13:00» = 1:00 PM) y 0 -> 12 AM
 *   - 12      -> 12 PM (mediodía)
 *   - 1 a 6   -> PM («1» = 1:00 PM: nadie atiende de madrugada)
 *   - 7 a 11  -> AM
 * Con «a», «am», «p» o «pm» manda lo escrito (de 1 a 12). «0800», «800», «1:30p», «1pm», «8» son válidos.
 *
 * Con un dispositivo de 24 h (WEB-13.5, `<html data-hora-ciclo="24">`) el campo se muestra «13:00» y lo escrito
 * sin AM/PM se toma **tal cual** («1» = 01:00): quien usa ese reloj escribe 13 para la una de la tarde. AM/PM se
 * sigue entendiendo si se escribe.
 */
(function (global) {
    'use strict';

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    /** 12 o 24: el reloj del dispositivo que dejó el servidor en el `<html>` (12 si no lo dijo). */
    function cicloDelDocumento() {
        var raiz = typeof document !== 'undefined' ? document.documentElement : null;
        return raiz && raiz.getAttribute('data-hora-ciclo') === '24' ? 24 : 12;
    }

    function formatear(hora, minuto, ciclo) {
        if ((ciclo || cicloDelDocumento()) === 24) { return pad(hora) + ':' + pad(minuto); }
        var h12 = hora % 12 === 0 ? 12 : hora % 12;
        return h12 + ':' + pad(minuto) + ' ' + (hora >= 12 ? 'PM' : 'AM');
    }

    /** Texto -> { hhmm: '13:00', texto: '1:00 PM' } o null si no es una hora. */
    function parsearHora(entrada, ciclo) {
        ciclo = ciclo || cicloDelDocumento();
        var t = String(entrada == null ? '' : entrada).toLowerCase().replace(/\./g, '').replace(/\s+/g, '');
        var m = /^(\d{1,2}):?(\d{2})?(am|pm|a|p)?$/.exec(t);
        if (!m) { return null; }

        var hora = parseInt(m[1], 10);
        var minuto = m[2] === undefined ? 0 : parseInt(m[2], 10);
        var sufijo = m[3] ? m[3].charAt(0) : '';

        if (minuto > 59) { return null; }

        if (sufijo) {
            if (hora < 1 || hora > 12) { return null; }
            if (sufijo === 'a') { hora = hora === 12 ? 0 : hora; }
            else { hora = hora === 12 ? 12 : hora + 12; }
        } else {
            if (hora > 23) { return null; }
            if (ciclo !== 24 && hora >= 1 && hora <= 6) { hora += 12; }
        }

        return { hhmm: pad(hora) + ':' + pad(minuto), texto: formatear(hora, minuto, ciclo) };
    }

    /** Cambia AM por PM (y al revés) en un `HH:MM`. */
    function alternar(hhmm, ciclo) {
        var p = /^(\d{2}):(\d{2})$/.exec(hhmm || '');
        if (!p) { return null; }
        var hora = (parseInt(p[1], 10) + 12) % 24;
        return { hhmm: pad(hora) + ':' + p[2], texto: formatear(hora, parseInt(p[2], 10), ciclo) };
    }

    function destino(campo) {
        return document.getElementById(campo.getAttribute('data-hora-ampm'));
    }

    /** Pasa lo escrito al hidden y marca el campo; no reescribe el texto (eso lo hace `normalizar`). */
    function sincronizar(campo) {
        var oculto = destino(campo);
        var texto = campo.value.trim();
        var hora = texto === '' ? null : parsearHora(texto);

        if (oculto) { oculto.value = hora ? hora.hhmm : ''; }

        var invalido = texto !== '' && !hora;
        campo.classList.toggle('is-invalid', invalido);
        campo.setCustomValidity(!invalido ? '' : (cicloDelDocumento() === 24
            ? 'Escribe una hora de 0 a 23, por ejemplo 13:00.'
            : 'Escribe una hora con AM o PM, por ejemplo 8:00 AM.'));
        return hora;
    }

    function normalizar(campo) {
        var hora = sincronizar(campo);
        if (hora) { campo.value = hora.texto; }
    }

    function esCampo(el) {
        return !!(el && el.matches && el.matches('input[data-hora-ampm]'));
    }

    if (typeof document !== 'undefined') {
        document.addEventListener('input', function (e) {
            if (esCampo(e.target)) { sincronizar(e.target); }
        });

        document.addEventListener('focusout', function (e) {
            if (esCampo(e.target)) { normalizar(e.target); }
        });

        document.addEventListener('keydown', function (e) {
            var campo = e.target;
            if (!esCampo(campo)) { return; }

            if (e.key === 'Enter') { normalizar(campo); return; }

            // Flechas: arriba/abajo alternan AM y PM sobre una hora ya válida.
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                var actual = sincronizar(campo);
                if (actual) {
                    e.preventDefault();
                    var otra = alternar(actual.hhmm);
                    campo.value = otra.texto;
                    sincronizar(campo);
                }
            }
        });

        // Antes de enviar un formulario, cada campo de hora deja su hidden al día (el autocompletado del
        // navegador no siempre dispara `input`).
        document.addEventListener('submit', function (e) {
            if (!e.target.querySelectorAll) { return; }
            e.target.querySelectorAll('input[data-hora-ampm]').forEach(normalizar);
        }, true);
    }

    var api = { parsearHora: parsearHora, alternar: alternar, formatear: formatear };
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
    global.HoraAmPm = api;
})(typeof window !== 'undefined' ? window : globalThis);
