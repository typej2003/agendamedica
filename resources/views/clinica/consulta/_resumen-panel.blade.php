{{-- **El cajón con la historia y el resumen del paciente** (O-30), en las pantallas donde se redacta un documento.

     Es el patrón de cajón de la guía de UI §9.3 (`<x-ui.dialogo lateral>`): el mismo `<dialog>` nativo de las
     plantillas, que entra por la derecha a alto completo, cierra con `Esc` y devuelve el foco al botón que lo
     abrió. El contenido **no** se arma acá: lo trae `public/js/clinica/resumen-panel.js` de la pantalla de la
     historia (`GET /clinica/pacientes/{paciente}/historia?panel=1`), que es la misma lectura y la misma vista de
     siempre. No hay un segundo resumen.

     El enlace «Abrir en otra pestaña» es la salida de siempre —la historia completa, con su encabezado y su
     impresión— y sirve de respaldo si el cajón no puede cargar. Los enlaces del resumen también abren aparte:
     la consulta no se abandona y no se pierde lo escrito.

     Variables: `$url`, la dirección de la historia del paciente (la resuelve el encabezado con `$vm->urlHistoria()`). --}}
<x-ui.dialogo id="dialogo-resumen" titulo="Historia y resumen del paciente" lateral>
    <div class="dialogo-cuerpo resumen-cajon" data-resumen-cuerpo>
        <p class="texto-tenue small mb-0">Cargando la historia…</p>
    </div>

    <div class="dialogo-pie">
        <a class="btn btn-sm btn-outline-secondary me-auto" href="{{ $url }}" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Abrir en otra pestaña
        </a>
        <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cerrar</button>
    </div>
</x-ui.dialogo>
