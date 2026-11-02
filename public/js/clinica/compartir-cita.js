/*
 * «Copiar» en la pantalla de compartir una cita (`/clinica/agenda/{cita}/link-de-cita`, O-52).
 *
 * Es el mismo gesto que el «Copiar» del link de pago y el de las claves temporales, y cada pantalla carga el
 * suyo (guia-arquitectura-web.md: la vista no lleva JS inline y no se comparte el archivo de otra pantalla).
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-copiar]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            const origen = document.getElementById(boton.dataset.copiar);
            const texto = origen ? (origen.value !== undefined ? origen.value : origen.textContent) : '';
            if (!texto || !navigator.clipboard) return;

            navigator.clipboard.writeText(texto.trim()).then(function () {
                boton.textContent = 'Copiado';
            }, function () {
                boton.textContent = 'No se pudo copiar';
            });
        });
    });
})();
