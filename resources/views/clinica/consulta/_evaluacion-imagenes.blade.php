{{-- **La galería de imágenes de los estudios** (O-22, 2026-10-17): la solapa *Imágenes* de *Evaluación física*.

     Es una solapa más, pero **no de texto**: el mismo parcial `_imagenes` que usan las solapas de la
     colposcopía y la histeroscopía, con «Esta consulta / Todas» y la subida. Era la mitad de la sección
     *Exámenes* (ítem 7, retirada del panel) y no cambia nada de la tabla: `imagen_consulta.observacion` sigue
     teniendo dos dueños (trampa 116) y las filas viejas del escritorio se leen como siempre.

     `$seccion` es el ViewModel `ImagenesDeLaConsultaVista` de la galería. La vista no lleva lógica, estilos
     ni JS. --}}
<div class="consulta-seccion" id="consulta-evaluacion-imagenes">
    @include('clinica.consulta._imagenes', [
        'imagenes' => $seccion,
        'titulo' => 'Imágenes de los estudios',
        'prefijo' => 'evaluacion-imagenes',
    ])
</div>
