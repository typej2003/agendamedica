/*
 * La regionalización del dispositivo (WEB-13.5): el orden de la fecha, su separador y el reloj de 12 o 24 h.
 *
 * Se lee con `Intl` del **primer idioma en español o portugués** del navegador (`navigator.languages`), no del
 * primero a secas: un Windows o un Chrome en inglés en Venezuela daría `en-US` (mes/día), y un orden de fecha
 * equivocado en una FUR es un dato clínico inventado. Sin un idioma así no se manda nada y el servidor usa el
 * del consultorio. Tampoco se acepta un orden que no sea día-mes-año o mes-día-año.
 *
 * Lo que sale es `dmy|/|12` en la cookie `ddr_region`; el servidor la valida (`App\Support\Regionalizacion`) y
 * con ella arma las fechas y las horas de la próxima página. Esta no se recarga: puede venir de un POST.
 *
 * Los scripts que **pintan o leen** una fecha en la página no usan lo detectado sino lo que el servidor dejó en
 * el `<html>` (`data-fecha-orden`, `data-fecha-separador`, `data-hora-ciclo`): así lo que escribe el JS y lo que
 * pinta el servidor coinciden siempre. Para eso está `window.Regionalizacion` (`fecha`, `hora`, `leerFecha`).
 */
(function (global) {
    'use strict';

    var COOKIE = 'ddr_region';

    function idiomaDeLaRegion(idiomas) {
        for (var i = 0; i < idiomas.length; i++) {
            if (/^(es|pt)(-|$)/i.test(String(idiomas[i] || ''))) { return idiomas[i]; }
        }
        return null;
    }

    /** Un locale → `dmy|/|12`, o null si no se puede decir con certeza. */
    function describir(locale) {
        var partes, ciclo, opciones;
        try {
            partes = new Intl.DateTimeFormat(locale, { year: 'numeric', month: '2-digit', day: '2-digit' })
                .formatToParts(new Date(2026, 4, 9));
            opciones = new Intl.DateTimeFormat(locale, { hour: 'numeric' }).resolvedOptions();
        } catch (e) {
            return null;
        }

        var orden = '';
        var separador = '';
        partes.forEach(function (p) {
            if (p.type === 'day' || p.type === 'month' || p.type === 'year') { orden += p.type.charAt(0); }
            else if (p.type === 'literal' && !separador) { separador = p.value.trim(); }
        });

        if ((orden !== 'dmy' && orden !== 'mdy') || ['/', '-', '.'].indexOf(separador) < 0) { return null; }

        if (opciones.hourCycle) {
            ciclo = (opciones.hourCycle === 'h23' || opciones.hourCycle === 'h24') ? 24 : 12;
        } else if (typeof opciones.hour12 === 'boolean') {
            ciclo = opciones.hour12 ? 12 : 24;
        } else {
            return null;
        }

        return orden + '|' + separador + '|' + ciclo;
    }

    function detectar(nav) {
        var idiomas = (nav && nav.languages && nav.languages.length) ? nav.languages : [nav && nav.language];
        var locale = idiomaDeLaRegion(idiomas);
        return locale ? describir(locale) : null;
    }

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    /** La regionalización con la que el servidor armó esta página. */
    function deLaPagina() {
        var raiz = typeof document !== 'undefined' ? document.documentElement : null;
        var leer = function (nombre) { return raiz ? raiz.getAttribute(nombre) : null; };
        var separador = leer('data-fecha-separador');
        return {
            orden: leer('data-fecha-orden') === 'mdy' ? 'mdy' : 'dmy',
            separador: ['/', '-', '.'].indexOf(separador) >= 0 ? separador : '/',
            ciclo: leer('data-hora-ciclo') === '24' ? 24 : 12,
        };
    }

    /** «09/05/2026» (o «05/09/2026», «09-05-2026»). */
    function fecha(dia, mes, anio, region) {
        var r = region || deLaPagina();
        var partes = r.orden === 'mdy' ? [pad(mes), pad(dia)] : [pad(dia), pad(mes)];
        return partes[0] + r.separador + partes[1] + r.separador + anio;
    }

    /** «1:05 PM» o «13:05». */
    function hora(h, m, region) {
        var r = region || deLaPagina();
        if (r.ciclo === 24) { return pad(h) + ':' + pad(m); }
        return (h % 12 === 0 ? 12 : h % 12) + ':' + pad(m) + ' ' + (h >= 12 ? 'PM' : 'AM');
    }

    /** `AAAA-MM-DD` o una fecha tecleada en el orden de la página → { anio, mes, dia }, o null. */
    function leerFecha(texto, region) {
        var r = region || deLaPagina();
        var t = String(texto == null ? '' : texto).trim();
        var p = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(t);
        var anio; var mes; var dia;

        if (p) {
            anio = +p[1]; mes = +p[2]; dia = +p[3];
        } else {
            p = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/.exec(t);
            if (!p) { return null; }
            anio = +p[3];
            if (r.orden === 'mdy') { mes = +p[1]; dia = +p[2]; } else { dia = +p[1]; mes = +p[2]; }
        }

        var f = new Date(Date.UTC(anio, mes - 1, dia));
        return f.getUTCFullYear() === anio && f.getUTCMonth() === mes - 1 && f.getUTCDate() === dia
            ? { anio: anio, mes: mes, dia: dia } : null;
    }

    if (typeof document !== 'undefined') {
        var valor = detectar(global.navigator);
        var actual = (document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)')) || [])[1];

        if (valor && decodeURIComponent(actual || '') !== valor) {
            document.cookie = COOKIE + '=' + encodeURIComponent(valor) + '; path=/; max-age=31536000; SameSite=Lax';
        } else if (!valor && actual) {
            document.cookie = COOKIE + '=; path=/; max-age=0; SameSite=Lax';
        }
    }

    var api = {
        describir: describir, detectar: detectar, idiomaDeLaRegion: idiomaDeLaRegion,
        deLaPagina: deLaPagina, fecha: fecha, hora: hora, leerFecha: leerFecha,
    };
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
    global.Regionalizacion = api;
})(typeof window !== 'undefined' ? window : globalThis);
