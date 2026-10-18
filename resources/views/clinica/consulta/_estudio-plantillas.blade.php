{{-- El botón «Plantillas» de un bloque del estudio ecográfico (revisión de Alexander, 2026-10-17).

     Va **arriba del campo al que escribe** (guía de UI §3): lo pinta el bloque que contiene la columna del
     informe de ese estudio, no el principio del formulario. `$plantillas` lo arma el contenedor de la sección
     (`SeccionDeEstudioVista::plantillas()`) con `PlantillasDelEstudio`: el tipo de `formato_print` que el
     escritorio usa en esa ventana y la columna destino. Es `null` en el ecocardiograma fetal, que no tiene
     plantillas en el escritorio, así que ahí no se pinta nada.

     La vista no lleva JS: el cajón lo resuelven `shell.js` y `plantillas-panel.js`. --}}
@if (($plantillas ?? null) !== null && in_array($plantillas['columna'], array_column($grupo['campos'], 'columna'), true))
    <div class="mb-2">
        <x-ui.plantillas :plantillas="$plantillas['lista']" destino="#{{ $plantillas['columna'] }}"
                         :tipos="$plantillas['etiquetas']" :nombre="$plantillas['nombre']" />
    </div>
@endif
