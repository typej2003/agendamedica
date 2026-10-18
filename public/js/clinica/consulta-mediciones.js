// Las mediciones de la consulta (WEB-7.4.3a): recalcula el IMC y el «AC» al escribir el peso o la talla.
// Son las fórmulas del escritorio (`gffimc.srf` y `gffac.srf`), las mismas de `App\Clinica\Crecimiento\MedidasAntropometricas`:
// el servidor es el que manda (guarda el IMC y vuelve a calcularlo al mostrar), esto sólo adelanta el número en pantalla.
(function () {
    'use strict';

    var formulario = document.querySelector('[data-consulta-mediciones]');
    if (!formulario) { return; }

    var peso = formulario.querySelector('[data-medicion="peso"]');
    var talla = formulario.querySelector('[data-medicion="talla"]');
    var imc = formulario.querySelector('[data-medicion-imc]');
    var ac = formulario.querySelector('[data-medicion-ac]');

    function numero(campo) {
        var valor = parseFloat(String(campo.value).replace(',', '.'));
        return isFinite(valor) && valor > 0 ? valor : 0;
    }

    function redondear(valor) { return Math.round(valor * 100) / 100; }

    function mostrar(salida, valor) {
        salida.textContent = valor > 0 ? redondear(valor).toFixed(2).replace('.', ',') : '—';
    }

    function calcular() {
        var kg = numero(peso);
        var cm = numero(talla);

        if (kg <= 0 || cm <= 0) {
            mostrar(imc, 0);
            mostrar(ac, 0);
            return;
        }

        mostrar(imc, kg / Math.pow(cm / 100, 2));

        var superficie = Math.pow(cm, 0.725) * Math.pow(kg, 0.425) * 71.84 / 10000;
        if (superficie < 0.6) {
            var gramos = kg * 1000;
            superficie = Math.pow(cm, 0.3) * Math.pow(gramos, 0.7285 - (0.0188 * Math.log(gramos))) * 3.207 / 10000;
        }
        mostrar(ac, superficie);
    }

    peso.addEventListener('input', calcular);
    talla.addEventListener('input', calcular);
})();
