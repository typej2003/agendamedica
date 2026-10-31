@extends('clinica.layout')

@section('titulo', 'Link de pago')

@section('contenido')
    <x-ui.page-head titulo="Link de pago" :subtitulo="$vm->subtitulo()"
                    :volver="$vm->urlVolver()" volver-texto="Volver a la agenda">
    </x-ui.page-head>

    @if ($vm->hayLink())
        <div data-link-de-pago>
            <x-ui.tarjeta titulo="El link del paciente" icono="bi-link-45deg" class="mb-3">
                <p class="texto-tenue">
                    Copia el link y mándaselo al paciente, o ábrelo en tu WhatsApp con el mensaje ya escrito.
                </p>

                <div class="input-group mb-3">
                    <label class="visually-hidden" for="link-de-pago">Link de pago</label>
                    <input type="text" class="form-control" id="link-de-pago" readonly
                           value="{{ $vm->link() }}" data-link>
                    <button class="btn btn-outline-secondary" type="button" data-copiar="link-de-pago">
                        <i class="bi bi-clipboard" aria-hidden="true"></i> Copiar
                    </button>
                </div>

                <a class="btn btn-success" href="{{ $vm->whatsappUrl() }}" target="_blank" rel="noopener">
                    <i class="bi bi-whatsapp" aria-hidden="true"></i> Enviar por WhatsApp
                </a>
            </x-ui.tarjeta>

            <x-ui.tarjeta titulo="Qué se cobra" icono="bi-cash-coin">
                <dl class="row mb-3">
                    <dt class="col-sm-3">Monto</dt>
                    <dd class="col-sm-9" data-monto>
                        <strong>{{ $vm->montoUsd() }}</strong>
                        @if ($vm->montoVes())
                            <span class="texto-tenue"> · hoy equivale a {{ $vm->montoVes() }}</span>
                        @endif
                    </dd>

                    <dt class="col-sm-3">Cita</dt>
                    <dd class="col-sm-9">
                        {{ $vm->fecha() }}@if ($vm->hora() !== '') a las {{ $vm->hora() }}@endif
                        @if ($vm->medico() !== '') · {{ $vm->medico() }}@endif
                    </dd>
                </dl>

                <p class="mb-1">{{ $vm->avisoDeLaTasa() }}</p>
                @if ($vm->vigencia() !== '')
                    <p class="texto-tenue mb-0">{{ $vm->vigencia() }}</p>
                @endif
            </x-ui.tarjeta>
        </div>
    @else
        <x-ui.tarjeta>
            <div class="alert alert-warning d-flex align-items-start gap-2" role="alert" data-aviso>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>{{ $vm->mensaje() }}</span>
            </div>

            <div class="d-flex flex-wrap gap-2">
                @if ($vm->esSinPrecio())
                    <a class="btn btn-primary" href="{{ $vm->urlEditar() }}">
                        <i class="bi bi-pencil" aria-hidden="true"></i> Editar la cita
                    </a>
                @endif
                <a class="btn btn-outline-secondary" href="{{ $vm->urlVolver() }}">Volver a la agenda</a>
            </div>
        </x-ui.tarjeta>
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/link-de-pago.js') }}?v={{ @filemtime(public_path('js/clinica/link-de-pago.js')) }}"></script>
@endsection
