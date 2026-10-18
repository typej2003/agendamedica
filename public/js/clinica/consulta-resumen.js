// «Actualizar» del examen (WEB-11.8): arma el resumen físico o ginecológico con los datos que hay en el formulario (el servidor hace la
// cuenta, `ArmarResumenDelExamen`) y lo pone en su cuadro, que sigue siendo editable. Si el cuadro ya tiene un texto distinto del que
// se armaría, pregunta antes de reemplazarlo. Dispara `input` para que el aviso de cambios y el autoguardado se enteren.
(function () {
    'use strict';

    var formulario = document.querySelector('form[data-url-resumen]');
    if (!formulario) { return; }

    formulario.querySelectorAll('[data-resumen-actualizar]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            var clave = boton.dataset.resumenActualizar;
            var campo = formulario.querySelector('[data-resumen="' + clave + '"] textarea');
            var token = document.querySelector('meta[name="csrf-token"]');
            boton.disabled = true;

            fetch(formulario.dataset.urlResumen, {
                method: 'POST',
                body: new FormData(formulario),
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' },
            }).then(function (respuesta) {
                if (!respuesta.ok) { throw new Error('http'); }
                return respuesta.json();
            }).then(function (datos) {
                var nuevo = datos[clave] || '';
                var actual = campo.value.trim();

                if (nuevo === '') {
                    window.alert('Todavía no hay datos cargados en el examen para armar el resumen.');
                    return;
                }
                if (actual !== '' && actual !== nuevo
                    && !window.confirm('El resumen ya tiene texto (quizá editado). ¿Reemplazarlo con el que se arma de los datos del examen?')) {
                    return;
                }

                campo.value = nuevo;
                campo.dispatchEvent(new Event('input', { bubbles: true }));
            }).catch(function () {
                window.alert('No se pudo armar el resumen. Revisa los datos del examen e inténtalo de nuevo.');
            }).then(function () { boton.disabled = false; });
        });
    });
})();
