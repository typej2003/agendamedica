@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()" />

    <x-ui.errores />

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header bg-white py-3"><strong>El plan que elegiste</strong></div>
                <div class="card-body">
                    <h2 class="h5 mb-1">{{ $vm->plan->nombre }}</h2>
                    @if ($vm->plan->descripcion)
                        <p class="text-muted small mb-3">{{ $vm->plan->descripcion }}</p>
                    @endif

                    <dl class="row mb-0">
                        <dt class="col-6 fw-normal text-muted">Frecuencia</dt>
                        <dd class="col-6 text-end">{{ $vm->frecuencia() }}</dd>

                        <dt class="col-6 fw-normal text-muted">Precio</dt>
                        <dd class="col-6 text-end fw-semibold">{{ $vm->precioUsdTexto() }}</dd>

                        <dt class="col-6 fw-normal text-muted">Monto a pagar</dt>
                        <dd class="col-6 text-end fw-semibold">{{ $vm->precioUsdTexto() }}</dd>
                    </dl>

                    <p class="text-muted small mt-3 mb-0">
                        El monto se cobra en la moneda del método que elijas. Si pagas en bolívares, se aplica la tasa del momento.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header bg-white py-3"><strong>¿Cómo quieres pagar?</strong></div>
                <div class="card-body">
                    @if ($vm->aviso())
                        <div class="alert alert-warning" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i> {{ $vm->aviso() }}
                        </div>
                    @endif

                    @if ($vm->metodos->isNotEmpty())
                        <form method="POST" action="{{ route('clinica.plan.comprar') }}">
                            @csrf
                            <input type="hidden" name="plan_id" value="{{ $vm->plan->id }}">

                            @foreach ($vm->metodos as $metodo)
                                <div class="form-check card mb-2">
                                    <div class="card-body d-flex align-items-center gap-3 py-3">
                                        <input class="form-check-input mt-0" type="radio" name="metodo_pago_id"
                                               id="metodo-{{ $metodo->id }}" value="{{ $metodo->id }}"
                                               {{ $loop->first ? 'checked' : '' }} required>
                                        <label class="form-check-label flex-grow-1 mb-0" for="metodo-{{ $metodo->id }}">
                                            <span class="fw-semibold d-block">{{ $metodo->etiqueta() }}</span>
                                            <span class="text-muted small">Pagarás con este método</span>
                                        </label>
                                        <span class="fs-5 fw-semibold text-nowrap">{{ $vm->montoTexto($metodo) }}</span>
                                    </div>
                                </div>
                            @endforeach

                            <div class="d-flex gap-2 mt-3">
                                <a href="{{ route('clinica.plan') }}" class="btn btn-outline-secondary">Volver a mi plan</a>
                                <button type="submit" class="btn btn-primary" {{ $vm->puedeContinuar() ? '' : 'disabled' }}>
                                    <i class="bi bi-arrow-right me-1" aria-hidden="true"></i> Continuar
                                </button>
                            </div>
                        </form>
                    @else
                        <p class="text-muted mb-3">No hay un método de pago disponible para cobrar este plan.</p>
                        <a href="{{ route('clinica.plan') }}" class="btn btn-outline-secondary">Volver a mi plan</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
