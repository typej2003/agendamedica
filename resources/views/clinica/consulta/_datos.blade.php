{{-- Los datos personales del paciente dentro de la consulta (WEB-11.25, ítem 13 del panel): la ficha editable sin salir de la atención.
     Los campos son los de la ficha (`clinica.pacientes._campos`) y se guardan con la misma ruta y la misma validación; `volver_a_consulta`
     hace que se vuelva a esta sección. Arriba, lo de sólo lectura y el aviso de lo que falta para terminar la consulta. --}}
@php($formulario = $seccion->formulario())

<div class="consulta-seccion" id="consulta-datos">
    <x-ui.tarjeta titulo="Datos personales" icono="bi-person-vcard" class="mb-3">
        <x-slot name="acciones">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-imprimir-ficha>
                <i class="bi bi-printer" aria-hidden="true"></i> Imprimir la ficha
            </button>
        </x-slot>

        <dl class="datos-rejilla mb-0">
            <x-ui.dato etiqueta="Historia" :valor="$seccion->historia()" />
            <x-ui.dato etiqueta="Edad" :valor="$seccion->edad()" />
            <x-ui.dato etiqueta="Fecha de ingreso" :valor="$seccion->ingreso()" />
        </dl>
    </x-ui.tarjeta>

    @if ($seccion->hayFaltantes())
        <p class="alert alert-warning py-2 d-flex align-items-center gap-2" role="status" id="datos-faltantes">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>{{ $seccion->avisoDeFaltantes() }}</span>
        </p>
    @endif

    <form method="POST" action="{{ $seccion->urlGuardar() }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="volver_a_consulta" value="{{ $seccion->consultaId() }}">

        @include('clinica.pacientes._campos', ['vm' => $formulario])

        <div class="d-flex justify-content-end gap-2 mb-3">
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar los datos
            </button>
        </div>
    </form>
</div>
