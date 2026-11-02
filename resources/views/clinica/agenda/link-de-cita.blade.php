@extends('clinica.layout')

@section('titulo', 'Compartir la cita')

@section('contenido')
    <x-ui.page-head titulo="Compartir la cita" :subtitulo="$vm->paciente()"
                    :volver="$vm->urlVolver()" volver-texto="Volver a la agenda">
    </x-ui.page-head>

    @if ($vm->hayLink())
        <x-ui.tarjeta titulo="El link del paciente" icono="bi-link-45deg" class="mb-3">
            <p class="texto-tenue">
                Abre la ficha pública con la fecha y el motivo ya elegidos: el paciente sólo confirma. Cópialo o
                muéstrale el código para que lo escanee.
            </p>

            <div class="input-group mb-3">
                <label class="visually-hidden" for="link-de-cita">Link para compartir la cita</label>
                <input type="text" class="form-control" id="link-de-cita" readonly
                       value="{{ $vm->link() }}" data-link>
                <button class="btn btn-outline-secondary" type="button" data-copiar="link-de-cita">
                    <i class="bi bi-clipboard" aria-hidden="true"></i> Copiar
                </button>
            </div>

            <div class="d-flex flex-wrap align-items-start gap-4">
                @if ($vm->qr() !== null)
                    <div class="text-center">
                        <div class="compartir-cita-qr" style="width: 160px; height: 160px">
                            {!! $vm->qr() !!}
                        </div>
                        <p class="texto-tenue small mb-0 mt-1">Que lo escanee con la cámara</p>
                    </div>
                @endif

                <dl class="row mb-0 flex-grow-1">
                    <dt class="col-sm-3">Paciente</dt>
                    <dd class="col-sm-9">{{ $vm->paciente() }}</dd>

                    <dt class="col-sm-3">Cita</dt>
                    <dd class="col-sm-9">
                        @if ($vm->fecha() !== null)
                            {{ $vm->fecha() }}@if ($vm->hora() !== null) a las {{ $vm->hora() }}@endif
                        @else
                            Sin fecha
                        @endif
                        @if ($vm->motivo() !== null)
                            · {{ $vm->motivo() }}
                        @endif
                    </dd>
                </dl>
            </div>
        </x-ui.tarjeta>

        <p class="texto-tenue">{{ $vm->vigencia() }}</p>
    @else
        <x-ui.tarjeta>
            <div class="alert alert-warning d-flex align-items-start gap-2" role="alert" data-aviso>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>{{ $vm->mensaje() }}</span>
            </div>

            <a class="btn btn-outline-secondary" href="{{ $vm->urlVolver() }}">Volver a la agenda</a>
        </x-ui.tarjeta>
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/compartir-cita.js') }}?v={{ @filemtime(public_path('js/clinica/compartir-cita.js')) }}"></script>
@endsection
