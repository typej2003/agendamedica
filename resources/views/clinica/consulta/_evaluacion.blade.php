{{-- El examen de la consulta (WEB-6.5, ítem 4 del panel del legado): el contenedor de sus solapas, que en
     el escritorio son las de `tab_7` de `tabpage_examen` (`w_consulta_principal.srw`).

     Cada solapa es un enlace de la misma sección (`?seccion=evaluacion&pestana=colposcopia`), no JavaScript,
     y tiene su propio parcial `_evaluacion-<clave>`: el JS del aviso de cambios sin guardar y de las
     plantillas de texto vive en `consulta.js`. Las solapas que todavía no existen no se muestran —el panel
     de la consulta ya dijo qué llegaba con cada tarea—. --}}
<div class="consulta-seccion" id="consulta-evaluacion">
    <x-ui.chips class="mb-3" :opciones="$seccion->pestanas()"
                     etiqueta="Examen" />

    @include($seccion->parcial(), ['seccion' => $seccion->vista()])
</div>
