/*
 * Usuarios del consultorio (`/clinica/usuarios`, WEB-8.5). La vista no lleva JS inline (Docs/Web/guia-arquitectura-web.md):
 * declara los botones con `data-*` y esto los resuelve.
 *
 *  - `data-dialogo="dialogo-usuario"`: abre el diálogo de la secretaria nueva. Si la validación falló, la página lo abre sola
 *    (`data-abrir-al-cargar="1"`): el error está debajo del campo, que vive dentro del diálogo.
 *  - `data-bloquear` (+ `data-accion`, `data-nombre`): llena el diálogo de bloqueo con la cuenta de esa fila y lo abre.
 *  - `data-copiar="id"`: copia al portapapeles el texto del elemento (la clave temporal).
 */
(function () {
    'use strict';

    const nueva = document.getElementById('dialogo-usuario');

    if (nueva) {
        const abrir = function () {
            nueva.showModal();
            const primero = nueva.querySelector('[data-foco-inicial]');
            if (primero) primero.focus();
        };

        document.querySelectorAll('[data-dialogo="dialogo-usuario"]').forEach(function (boton) {
            boton.addEventListener('click', abrir);
        });

        if (nueva.dataset.abrirAlCargar === '1') abrir();
    }

    const bloqueo = document.getElementById('dialogo-bloquear');

    if (bloqueo) {
        document.querySelectorAll('[data-bloquear]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                document.getElementById('bloquear-form').setAttribute('action', boton.dataset.accion);
                document.getElementById('bloquear-nombre').textContent = boton.dataset.nombre;
                document.getElementById('bloquear-motivo').value = '';
                bloqueo.showModal();
                document.getElementById('bloquear-motivo').focus();
            });
        });
    }

    document.querySelectorAll('[data-copiar]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            const origen = document.getElementById(boton.dataset.copiar);
            if (!origen || !navigator.clipboard) return;

            navigator.clipboard.writeText(origen.textContent.trim()).then(function () {
                boton.textContent = 'Copiada';
            }, function () {
                boton.textContent = 'No se pudo copiar';
            });
        });
    });
})();
