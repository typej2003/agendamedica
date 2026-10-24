{{-- El botón «Plantillas» del estudio ecográfico, **en la cabecera de la tarjeta** (2026-10-23, Alexander): la plantilla es del
     informe completo, no de un campo; en el escritorio es la lista a un lado de toda la ventana. Al elegirla, su texto va al
     área libre del informe de ese estudio (`$plantillas['columna']`, la que imprime la hoja).

     `$plantillas` lo arma el contenedor de la sección (`SeccionDeEstudioVista::plantillas()`) con `PlantillasDelEstudio`; es
     `null` en el ecocardiograma fetal, que no tiene plantillas en el escritorio, y entonces no se pinta nada. La vista no lleva JS. --}}
@if (($plantillas ?? null) !== null)
    <x-ui.plantillas :plantillas="$plantillas['lista']" destino="#{{ $plantillas['columna'] }}"
                     :tipos="$plantillas['etiquetas']" :nombre="$plantillas['nombre']" />
@endif
