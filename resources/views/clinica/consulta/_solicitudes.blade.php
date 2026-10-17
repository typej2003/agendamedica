{{-- Las solicitudes de la consulta (WEB-5.3a, WEB-5.3b y WEB-5.4, ítem 11 del panel del legado): laboratorio, estudios, constancia, reposo, referencia, hospitalización, informe médico y dieta / recomendaciones.

     Cada pestaña es un enlace de la misma sección (`?seccion=solicitudes&solicitud=reposo`), no JavaScript, y tiene su propio
     parcial `_solicitudes-<clave>` (laboratorio y estudios comparten el editor `_orden-editor` y su JS, `consulta-ordenes.js`).
     Todo se guarda con un botón y se imprime en media hoja desde su pestaña.
     El JS (`consulta-solicitudes.js`) pone el aviso de cambios sin guardar, copia las plantillas al campo y calcula las fechas
     del reposo; la vista no lleva JS ni estilos. --}}
<div class="consulta-seccion" id="consulta-solicitudes">
    <x-ui.chips class="mb-3" :opciones="$seccion->pestanas()" etiqueta="Solicitudes de la consulta" />

    @include($seccion->parcial(), ['vm' => $seccion->pestana()])
</div>
