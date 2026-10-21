{{-- «Nueva sede» desde el panel del consultorio: se abre **encima** de él (el consultorio queda abierto debajo, con lo
     escrito) y crea la sede por `fetch` contra el mismo `POST /clinica/sedes`, que responde JSON. Al guardar, `sedes.js`
     suma la sede al selector, la deja elegida y vuelve al consultorio. --}}
<x-ui.dialogo id="dialogo-sede-rapida" titulo="Nueva sede" lateral>
    <form method="POST" action="{{ $vm->urlGuardarSede() }}" data-sede-rapida novalidate>
        @csrf

        <div class="dialogo-cuerpo">
            <div class="alert alert-warning py-2" role="alert" data-error-general hidden></div>
            @include('clinica.sedes._campos-sede', ['prefijo' => 'rapida-', 'conValores' => false])
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al consultorio
            </button>
            <button class="btn btn-primary">Registrar la sede</button>
        </div>
    </form>
</x-ui.dialogo>
