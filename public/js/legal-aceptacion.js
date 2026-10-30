/**
 * Aceptación de términos y condiciones en los registros (`<x-legal.aceptacion>`).
 *
 * La casilla no se marca al tocarla: abre el diálogo con los dos documentos. «Aceptar» queda
 * deshabilitado hasta que el contenedor llega al final; al aceptar, se marca la casilla y se cierra.
 * Sin este archivo, la casilla funciona como cualquier checkbox y los enlaces abren las páginas
 * legales en otra pestaña (mejora progresiva).
 */
(function () {
    'use strict';

    var MARGEN_FINAL = 12;

    function iniciar(raiz) {
        var casilla = raiz.querySelector('[data-aceptacion-casilla]');
        var dialogo = raiz.querySelector('[data-aceptacion-dialogo]');
        var cuerpo = raiz.querySelector('[data-aceptacion-scroll]');
        var aceptar = raiz.querySelector('[data-aceptacion-aceptar]');
        var aviso = raiz.querySelector('[data-aceptacion-aviso]');

        if (!casilla || !dialogo || !cuerpo || !aceptar) return;

        var cargado = false;

        function cargar() {
            if (cargado) return;

            raiz.querySelectorAll('template[data-aceptacion-documento]').forEach(function (plantilla) {
                cuerpo.appendChild(plantilla.content.cloneNode(true));
            });

            // Los enlaces internos del documento no pueden sacar al médico del registro (perdería lo
            // escrito): se abren en otra pestaña. La cabecera del diálogo ya enlaza a cada sección.
            cuerpo.querySelectorAll('a[href]').forEach(function (enlace) {
                enlace.target = '_blank';
                enlace.rel = 'noopener';
            });

            cargado = true;
        }

        function estaAlFinal() {
            return cuerpo.scrollHeight <= cuerpo.clientHeight + MARGEN_FINAL
                || cuerpo.scrollTop + cuerpo.clientHeight >= cuerpo.scrollHeight - MARGEN_FINAL;
        }

        function revisar() {
            var habilitado = estaAlFinal();
            aceptar.disabled = !habilitado;

            if (aviso) {
                aviso.textContent = habilitado
                    ? 'Ya puedes aceptar los términos y condiciones.'
                    : 'Desplázate hasta el final para habilitar «Aceptar».';
            }
        }

        function irASeccion(seccion) {
            var destino = cuerpo.querySelector('[data-legal-documento="' + seccion + '"]');

            if (!destino) {
                cuerpo.scrollTop = 0;
                return;
            }

            // Las posiciones se miden contra el contenedor que scrollea, no contra el documento.
            var caja = cuerpo.getBoundingClientRect();
            var documento = destino.getBoundingClientRect();
            cuerpo.scrollTop += documento.top - caja.top;
        }

        function abrir(seccion) {
            cargar();

            if (!dialogo.open) dialogo.showModal();

            // El alto del contenido recién está calculado después de pintar.
            window.requestAnimationFrame(function () {
                if (seccion) {
                    irASeccion(seccion);
                } else {
                    cuerpo.scrollTop = 0;
                }

                revisar();
            });
        }

        casilla.addEventListener('click', function (evento) {
            // El navegador cambia `checked` antes de disparar el click: si quedó marcada, la persona
            // acaba de marcar la casilla y hay que deshacerlo hasta que acepte en el diálogo.
            if (!casilla.checked) return;

            evento.preventDefault();
            casilla.checked = false;
            abrir(null);
        });

        raiz.querySelectorAll('[data-aceptacion-abrir]').forEach(function (enlace) {
            enlace.addEventListener('click', function (evento) {
                // Los atajos del navegador (nueva pestaña, nueva ventana) siguen funcionando.
                if (evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) return;

                evento.preventDefault();
                abrir(enlace.getAttribute('data-aceptacion-abrir'));
            });
        });

        cuerpo.addEventListener('scroll', revisar, { passive: true });

        window.addEventListener('resize', function () {
            if (dialogo.open) revisar();
        });

        aceptar.addEventListener('click', function () {
            if (aceptar.disabled) return;

            casilla.checked = true;
            casilla.dispatchEvent(new Event('input', { bubbles: true }));
            casilla.dispatchEvent(new Event('change', { bubbles: true }));
            dialogo.close('aceptado');
        });

        raiz.querySelectorAll('[data-aceptacion-cancelar]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                dialogo.close('cancelado');
            });
        });
    }

    document.querySelectorAll('[data-aceptacion-legal]').forEach(iniciar);
})();
