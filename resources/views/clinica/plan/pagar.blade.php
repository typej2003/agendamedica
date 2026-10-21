@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" subtitulo="Revisá el monto y completá el pago. El servicio se renueva solo cuando SyPago confirma." />

    <x-ui.errores />

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <strong>Resumen</strong>
                    <span class="badge bg-{{ $vm->estadoTono() }}">{{ $vm->estadoLegible() }}</span>
                </div>
                <div class="card-body">
                    <p class="mb-1 fw-semibold">{{ $vm->pago->concepto }}</p>
                    <p class="text-muted small mb-3">Pago #{{ $vm->pago->id }}</p>

                    <dl class="row mb-0">
                        <dt class="col-6 fw-normal text-muted">Monto en USD</dt>
                        <dd class="col-6 text-end fw-semibold">{{ $vm->montoUsd() }}</dd>

                        <dt class="col-6 fw-normal text-muted">Monto a pagar</dt>
                        <dd class="col-6 text-end fw-semibold">{{ $vm->montoVes() }}</dd>

                        <dt class="col-6 fw-normal text-muted">Tasa</dt>
                        <dd class="col-6 text-end">1 USD = {{ $vm->tasaTexto() }} VES</dd>

                        <dt class="col-6 fw-normal text-muted">Método</dt>
                        <dd class="col-6 text-end">{{ $vm->metodoEtiqueta() }}</dd>
                    </dl>

                    @if ($vm->pendiente() && $vm->venceEl())
                        <p class="text-muted small mt-3 mb-0">
                            <i class="bi bi-clock me-1" aria-hidden="true"></i>
                            Este pago vence el {{ $vm->venceEl() }}.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            @if ($vm->aprobado())
                <div class="alert alert-success" role="status">
                    <h5 class="alert-heading"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i> ¡Pago aprobado!</h5>
                    <p class="mb-0">El servicio de este consultorio ya quedó renovado con el plan que pagaste.</p>
                </div>
                <a href="{{ route('clinica.plan') }}" class="btn btn-primary">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a mi plan
                </a>
            @elseif ($vm->fallado())
                <div class="alert alert-danger" role="alert">
                    <h5 class="alert-heading"><i class="bi bi-x-circle-fill me-1" aria-hidden="true"></i> El pago no se completó</h5>
                    <p class="mb-0">
                        @if ($vm->pago->estado === \App\Models\Pago::EXPIRADO)
                            Pasó el tiempo para pagar con la tasa que se te mostró.
                        @else
                            SyPago rechazó la operación. No se te cobró nada.
                        @endif
                        Podés empezar de nuevo con la tasa vigente.
                    </p>
                </div>
                <a href="{{ route('clinica.plan') }}" class="btn btn-primary">
                    <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Volver a intentar
                </a>
            @elseif ($vm->enDatos())
                <div class="card">
                    <div class="card-header bg-white py-3"><strong>Tus datos para el cobro</strong></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('clinica.plan.iniciar', $vm->pago) }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="documento_tipo">Tipo</label>
                                    <select name="documento_tipo" id="documento_tipo" class="form-select" required>
                                        @foreach (\App\Models\CuentaBancaria::DOCUMENTOS as $tipo)
                                            <option value="{{ $tipo }}" @selected(old('documento_tipo', 'V') === $tipo)>{{ $tipo }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-5 mb-3">
                                    <label class="form-label" for="documento_numero">Documento</label>
                                    <input type="text" name="documento_numero" id="documento_numero" class="form-control"
                                           value="{{ old('documento_numero') }}" maxlength="20" autocomplete="off" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="banco_codigo">Banco (4 dígitos)</label>
                                    <input type="text" name="banco_codigo" id="banco_codigo" class="form-control"
                                           value="{{ old('banco_codigo') }}" maxlength="4" inputmode="numeric"
                                           placeholder="0172" autocomplete="off" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="nombre">Nombre del titular</label>
                                <input type="text" name="nombre" id="nombre" class="form-control"
                                       value="{{ old('nombre', auth()->user()->name) }}" maxlength="120" autocomplete="off" required>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="tipo_cuenta">¿Desde dónde pagás?</label>
                                    <select name="tipo_cuenta" id="tipo_cuenta" class="form-select" required>
                                        <option value="cuenta" @selected(old('tipo_cuenta', $vm->tipoCuentaPorDefecto()) === 'cuenta')>Cuenta bancaria</option>
                                        <option value="pago_movil" @selected(old('tipo_cuenta', $vm->tipoCuentaPorDefecto()) === 'pago_movil')>Pago móvil</option>
                                    </select>
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label class="form-label" for="cuenta">Cuenta bancaria o teléfono</label>
                                    <input type="text" name="cuenta" id="cuenta" class="form-control"
                                           value="{{ old('cuenta') }}" maxlength="30" inputmode="numeric" autocomplete="off" required>
                                    <div class="form-text">
                                        Cuenta: 20 dígitos y empieza con el código del banco. Pago móvil: teléfono 0412…
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-shield-lock me-1" aria-hidden="true"></i> Enviar código de confirmación
                            </button>
                        </form>
                    </div>
                </div>
            @elseif ($vm->enOtp())
                <div class="card">
                    <div class="card-header bg-white py-3"><strong>Confirmá con el código</strong></div>
                    <div class="card-body">
                        <p class="text-muted small">
                            Te enviamos un código al teléfono asociado a la cuenta. Escribilo acá para autorizar el débito.
                        </p>
                        <form method="POST" action="{{ route('clinica.plan.confirmar', $vm->pago) }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label" for="otp">Código</label>
                                <input type="text" name="otp" id="otp" class="form-control form-control-lg"
                                       inputmode="numeric" autocomplete="one-time-code" maxlength="10" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Confirmar pago
                            </button>
                        </form>

                        <form method="POST" action="{{ route('clinica.plan.consultar', $vm->pago) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i> No me llegó: consultar el estado
                            </button>
                        </form>
                    </div>
                </div>
            @elseif ($vm->enConfirmacion())
                <div class="alert alert-info" role="status">
                    <h5 class="alert-heading"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i> Estamos confirmando el pago</h5>
                    <p class="mb-0">SyPago recibió la operación. En unos minutos se confirma sola y el plan se renueva.</p>
                </div>
                <form method="POST" action="{{ route('clinica.plan.consultar', $vm->pago) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i> Consultar ahora
                    </button>
                </form>
            @else
                <div class="alert alert-warning" role="alert">
                    Este pago no se puede continuar desde acá. Volvé a tu plan y empezá uno nuevo.
                </div>
                <a href="{{ route('clinica.plan') }}" class="btn btn-primary">Volver a mi plan</a>
            @endif
        </div>
    </div>
@endsection
