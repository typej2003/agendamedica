{{-- El estudio ecográfico de la consulta (WEB-6.1, ítem 5 del panel del legado): el contenedor de los
     siete estudios del ítem, que ya existen todos (el eco pélvico, los dos obstétricos, el Doppler
     perinatal, el ecocardiograma fetal, el mamario y el abdominal).

     Cada tipo es un enlace de la misma sección (`?seccion=estudio&tipo=ginecologico`), no JavaScript, y
     tiene su propio parcial `_estudio-<clave>`. Los tipos que todavía no existen no se muestran —el panel
     de la consulta ya dijo qué llegaba con cada tarea— y el JS del aviso de cambios sin guardar vive en
     `consulta.js`: la vista no lleva JS ni estilos. --}}
<div class="consulta-seccion" id="consulta-estudio">
    <x-ui.segmentado class="recipe-pestanas pestanas-en-fila mb-3" :opciones="$seccion->tipos()"
                     etiqueta="Estudios ecográficos" />

    @include($seccion->parcial(), ['vm' => $seccion->estudio()])
</div>
