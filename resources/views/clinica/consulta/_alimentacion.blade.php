{{-- **Alimentación** de la consulta pediátrica (ítem 9 de su árbol, 2026-10-19): la dieta y las recomendaciones, con el
     catálogo de `dietas` del consultorio y las plantillas `DI` del médico.

     Es la **misma pantalla** que la pestaña *Dieta / Recomendaciones* de las *Solicitudes* —un título, un texto, las
     plantillas y el aviso de cambios sin guardar—, así que reusa el parcial de esa pestaña: lo propio de esta sección
     es ser **otra** sección (`?seccion=alimentacion`), con su ítem en el menú (el 9) y su encabezado.

     Antes el ítem 9 **redirigía** a `?seccion=solicitudes&solicitud=dieta`: el médico terminaba en *Solicitudes* y,
     como esa pestaña también se llama *Alimentación* en pediatría, la veía dos veces (Alexander, O-40 y O-41). --}}
<div class="consulta-seccion" id="consulta-alimentacion">
    @include('clinica.consulta._solicitudes-dieta', ['vm' => $seccion])
</div>
