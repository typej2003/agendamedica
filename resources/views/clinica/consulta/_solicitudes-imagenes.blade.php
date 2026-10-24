{{-- **Las imágenes de los estudios**, pestaña de *Solicitudes* (2026-10-23, Alexander): en el escritorio la galería siempre estuvo en
     *Solicitudes → Imágenes*. Es el mismo parcial `_imagenes` que usan las solapas de la colposcopía y la histeroscopía, con
     «Esta consulta / Todas» y la subida; no cambia nada de la tabla (`imagen_consulta.observacion` sigue teniendo dos dueños,
     trampa 116, y las filas viejas del escritorio se leen como siempre).

     `$vm` es el ViewModel `ImagenesDeLaConsultaVista` de la galería. La vista no lleva lógica, estilos ni JS. --}}
<div class="consulta-seccion" id="consulta-solicitudes-imagenes">
    @include('clinica.consulta._imagenes', [
        'imagenes' => $vm,
        'titulo' => 'Imágenes de los estudios',
        'prefijo' => 'solicitudes-imagenes',
    ])
</div>
