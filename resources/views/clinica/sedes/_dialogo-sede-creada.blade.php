{{-- Después de registrar una sede: lo normal es haberla creado para atender ahí, así que el panel lo ofrece de una vez.
     «Agregar consultorio» abre el panel del consultorio con esta sede elegida (sin recargar; el enlace es el respaldo). --}}
<x-ui.dialogo id="dialogo-sede-creada" titulo="Sede creada" lateral>
    <div class="dialogo-cuerpo sede-creada">
        <i class="bi bi-check-circle sede-creada-icono" aria-hidden="true"></i>
        <p class="sede-creada-pregunta">¿Agregar un consultorio en <strong>{{ $creada['nombre'] }}</strong>?</p>
    </div>

    <div class="dialogo-pie">
        <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Ahora no</button>
        <a class="btn btn-primary" href="{{ $creada['url'] }}" data-panel="dialogo-consultorio" data-sede="{{ $creada['id'] }}" autofocus>
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar consultorio
        </a>
    </div>
</x-ui.dialogo>
