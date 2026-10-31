/*
 * Link de pago de una cita (`/clinica/agenda/{cita}/link-de-pago`). La vista no lleva JS inline
 * (Docs/Web/guia-arquitectura-web.md): declara el botón con `data-copiar="id"` y esto lo resuelve.
 *
 * Es el mismo gesto que el «Copiar» de las claves temporales en Usuarios (`usuarios.js`), pero esa
 * pantalla no se carga acá; no se comparte un archivo de otra tarea por un botón.
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
