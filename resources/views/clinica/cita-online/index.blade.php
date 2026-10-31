@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()" />

    <x-ui.errores />

    @php
        // Después de un error de validación se conserva lo que el médico había marcado; en una visita
        // normal manda lo guardado (o el valor por defecto, que es «publicada»).
        $publicada = old('porcentaje') !== null ? old('publicar_agenda') !== null : $vm->publicaAgenda();
    @endphp

    <form method="POST" action="{{ $vm->urlGuardar() }}">
        @csrf

        <x-ui.tarjeta titulo="Cita Online" icono="bi-globe2" class="mb-3">
            <div class="row g-3">
                <x-ui.campo etiqueta="Porcentaje que se cobra al reservar" for="porcentaje" class="col-md-4"
                            requerido
                            ayuda="El resto se cobra en el consultorio. El monto en bolívares se calcula con la tasa del día.">
                    <div class="input-group">
                        {{-- El campo es 0–100 y se guarda en puntos base: 30 % = 3000. --}}
                        <input type="number" class="form-control" id="porcentaje" name="porcentaje"
                               min="0" max="100" step="0.01" inputmode="decimal" required
                               value="{{ old('porcentaje', $vm->porcentaje()) }}">
                        <span class="input-group-text">%</span>
                    </div>
                </x-ui.campo>

                <div class="col-md-8">
                    <div class="form-check form-switch mt-md-4">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="publicar_agenda" name="publicar_agenda" value="1"
                               {{ $publicada ? 'checked' : '' }}>
                        <label class="form-check-label" for="publicar_agenda">
                            Mostrar mi agenda en el portal de reservas y en el bot de WhatsApp
                        </label>
                    </div>
                    <p class="form-text mb-0">
                        Apagada, tu agenda deja de aparecer en el catálogo público; las citas que ya tienes no cambian.
                    </p>
                </div>
            </div>

            <hr class="my-3">

            @if ($referencia = $vm->referencia())
                {{-- Referencia con un motivo real del médico: no inventa un precio de ejemplo. --}}
                <div class="border rounded p-3 bg-body-tertiary" id="referencia-de-precio">
                    <div class="fw-semibold mb-1">
                        <i class="bi bi-calculator" aria-hidden="true"></i> Así se ve con tu precio de hoy
                    </div>
                    <p class="mb-1">
                        Cita de <strong>{{ $referencia['motivo'] }}</strong> · {{ $referencia['precio'] }}
                    </p>
                    <p class="mb-0 small">
                        Al reservar se cobran <strong>{{ $referencia['alReservar'] }}</strong>.
                        @if ($referencia['resto_e2'] > 0)
                            Los <strong>{{ $referencia['resto'] }}</strong> restantes se cobran en el consultorio.
                        @endif
                    </p>
                </div>
            @else
                <p class="text-muted small mb-0" id="referencia-de-precio">
                    Todavía no tienes un motivo de cita con precio, así que no hay un ejemplo que mostrarte.
                </p>
            @endif

            <div class="d-flex justify-content-end mt-3">
                <button class="btn btn-primary">
                    <i class="bi bi-check2" aria-hidden="true"></i> Guardar
                </button>
            </div>
        </x-ui.tarjeta>
    </form>
@endsection
