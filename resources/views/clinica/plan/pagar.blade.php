@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" subtitulo="Revisa el monto y completa el pago. El servicio se renueva solo cuando SyPago confirma." />

    <x-ui.errores />

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card" data-pago-refresco
                 data-url="{{ route('clinica.plan.refrescar', $vm->pago) }}"
                 data-segundos="{{ $vm->segundosRestantes() }}"
                 data-csrf="{{ csrf_token() }}">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <strong>Resumen</strong>
                    <span class="badge bg-{{ $vm->estadoTono() }}">{{ $vm->estadoLegible() }}</span>
                </div>
                <div class="card-body">
                    <p class="mb-1 fw-semibold">{{ $vm->pago->concepto }}</p>
                    <p class="text-muted small mb-3">Pago #{{ $vm->pago->id }}</p>

                    <dl class="row mb-0">
                        <dt class="col-6 fw-normal text-muted">Total del plan</dt>
                        <dd class="col-6 text-end fw-semibold">{{ $vm->montoUsd() }}</dd>

                        @if ($vm->pago->usaLatidos())
                            <dt class="col-6 fw-normal text-muted">Descuento con Latidos</dt>
                            <dd class="col-6 text-end text-success fw-semibold">
                                {{ $vm->pago->latidosTexto() }}
                                <small class="text-muted fw-normal">({{ $vm->pago->descuentoUsdTexto() }})</small>
                            </dd>
                        @endif

                        <dt class="col-6 fw-normal text-muted">
                            {{ $vm->pago->cubiertoConLatidos() ? 'A pagar con SyPago' : 'Monto a pagar' }}
                        </dt>
                        <dd class="col-6 text-end fw-semibold" data-pago-monto>
                            @if ($vm->pago->cubiertoConLatidos())
                                Sin cobro
                            @elseif ($vm->pago->usaLatidos())
                                {{ $vm->pago->montoPasarelaUsdTexto() }}
                                <small class="text-muted fw-normal">({{ $vm->monto() }})</small>
                            @else
                                {{ $vm->monto() }}
                            @endif
                        </dd>

                        <dt class="col-6 fw-normal text-muted">Método</dt>
                        <dd class="col-6 text-end">
                            {{ $vm->pago->cubiertoConLatidos() ? 'Latidos' : $vm->metodoEtiqueta() }}
                        </dd>
                    </dl>

                    {{-- Aparece sólo si al vencer el pago la tasa cambió: el formulario queda bloqueado
                         hasta que el médico acepte el monto nuevo. --}}
                    <div id="pago-reintentar-caja" class="mt-3 d-none">
                        <p id="pago-reintentar-aviso" class="text-warning small mb-2"></p>
                        <button type="button" id="pago-reintentar" class="btn btn-warning btn-sm">
                            <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i> Reintentar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            @if ($vm->aprobado())
                <div class="alert alert-success" role="status">
                    <h5 class="alert-heading"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i> ¡Pago aprobado!</h5>
                    <p class="mb-0">
                        El servicio de este consultorio ya quedó renovado
                        {{ $vm->pago->cubiertoConLatidos() ? 'con tus Latidos.' : 'con el plan que pagaste.' }}
                    </p>
                </div>
                <a href="{{ route('clinica.plan') }}" class="btn btn-primary">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a mi plan
                </a>
            @elseif ($vm->fallado())
                <div class="alert alert-danger" role="alert">
                    <h5 class="alert-heading"><i class="bi bi-x-circle-fill me-1" aria-hidden="true"></i> El pago no se completó</h5>
                    <p class="mb-0">
                        SyPago rechazó la operación. No se te cobró nada.
                        Puedes empezar de nuevo con la tasa vigente.
                    </p>
                </div>
                <a href="{{ route('clinica.plan') }}" class="btn btn-primary">
                    <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Volver a intentar
                </a>
            @elseif ($vm->enDatos())
                <div class="card">
                    <div class="card-header bg-white py-3"><strong>Tus datos para el cobro</strong></div>
                    <div class="card-body">
                        @if ($vm->recordado)
                            <p class="text-muted small">
                                Usamos los datos de tu último pago aprobado. Puedes cambiarlos si hace falta.
                            </p>
                        @endif
                        <form method="POST" action="{{ route('clinica.plan.iniciar', $vm->pago) }}" id="pago-form">
                            @csrf
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="documento_tipo">Tipo</label>
                                    <select name="documento_tipo" id="documento_tipo" class="form-select" required>
                                        @foreach (\App\Models\CuentaBancaria::DOCUMENTOS as $tipo)
                                            <option value="{{ $tipo }}" {{ old('documento_tipo', $vm->recordado('documento_tipo', 'V')) === $tipo ? 'selected' : '' }}>{{ $tipo }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-5 mb-3">
                                    <label class="form-label" for="documento_numero">Documento</label>
                                    <input type="text" name="documento_numero" id="documento_numero" class="form-control"
                                           value="{{ old('documento_numero', $vm->recordado('documento_numero')) }}" maxlength="20" autocomplete="off" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="banco_codigo">Banco (4 dígitos)</label>
                                    <input type="text" name="banco_codigo" id="banco_codigo" class="form-control"
                                           value="{{ old('banco_codigo', $vm->recordado('banco_codigo')) }}" maxlength="4" inputmode="numeric"
                                           placeholder="0172" autocomplete="off" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="nombre">Nombre del titular</label>
                                <input type="text" name="nombre" id="nombre" class="form-control"
                                       value="{{ old('nombre', $vm->recordado('nombre', auth()->user()->name)) }}" maxlength="120" autocomplete="off" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="cuenta">Número de cuenta o teléfono</label>
                                <input type="text" name="cuenta" id="cuenta" class="form-control"
                                       value="{{ old('cuenta', $vm->cuentaRecordada()) }}" maxlength="30" inputmode="numeric"
                                       placeholder="Número de cuenta o teléfono" autocomplete="off" required>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-shield-lock me-1" aria-hidden="true"></i> Enviar código de confirmación
                            </button>
                        </form>
                    </div>
                </div>
            @elseif ($vm->enOtp())
                <div class="card">
                    <div class="card-header bg-white py-3"><strong>Confirma con el código</strong></div>
                    <div class="card-body">
                        <p class="text-muted small">
                            Te enviamos un código al teléfono asociado a la cuenta. Escríbelo aquí para autorizar el débito.
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
                    Este pago no se puede continuar desde aquí. Vuelve a tu plan y empieza uno nuevo.
                </div>
                <a href="{{ route('clinica.plan') }}" class="btn btn-primary">Volver a mi plan</a>
            @endif
        </div>
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/plan-pago.js') }}"></script>
@endsection
