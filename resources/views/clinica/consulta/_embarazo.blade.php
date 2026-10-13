{{-- El control de embarazo de la consulta (WEB-6.6, ítem 6 del panel del legado): el contenedor de sus
     cuatro pestañas del escritorio (Prenatal, Laboratorio, Controles y Reporte), de las que hoy existe la
     primera.

     Cada pestaña es un enlace de la misma sección (`?seccion=embarazo&pestana=prenatal`), no JavaScript, y
     tiene su propio parcial `_embarazo-<clave>`. Las que todavía no existen no se muestran —el panel de la
     consulta ya dijo qué llegaba con cada tarea— y el JS del aviso de cambios sin guardar vive en
     `consulta.js`: la vista no lleva JS ni estilos. --}}
<div class="consulta-seccion" id="consulta-embarazo">
    <x-ui.segmentado class="recipe-pestanas pestanas-en-fila mb-3" :opciones="$seccion->pestanas()"
                     etiqueta="Control de embarazo" />

    @include($seccion->parcial(), ['vm' => $seccion->pestana()])
</div>
