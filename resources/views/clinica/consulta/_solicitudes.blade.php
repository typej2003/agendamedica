{{-- Las solicitudes de la consulta (WEB-5.3a, WEB-5.3b y WEB-5.4, ítem 11 del panel del legado): laboratorio, estudios, constancia, reposo, referencia, hospitalización, informe médico y dieta / recomendaciones.

     Cada pestaña es un enlace de la misma sección (`?seccion=solicitudes&solicitud=reposo`), no JavaScript, y tiene su propio
     parcial `_solicitudes-<clave>` (laboratorio y estudios comparten el catálogo a la vista `_orden-catalogo`, con el agregado y
     la baja al instante desde O-29; su orden de la **pareja** sigue siendo el editor `_orden-editor` con `consulta-ordenes.js`).
     Los documentos se guardan con un botón y se imprimen en media hoja desde su pestaña.
     El JS (`consulta-solicitudes.js`) pone el aviso de cambios sin guardar, copia las plantillas al campo y calcula las fechas
     del reposo; la vista no lleva JS ni estilos. --}}
<div class="consulta-seccion" id="consulta-solicitudes">
    <x-ui.chips class="mb-3" :opciones="$seccion->pestanas()" etiqueta="Solicitudes de la consulta" />

    @include($seccion->parcial(), ['vm' => $seccion->pestana()])
</div>
