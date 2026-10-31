@extends('layouts.app')

@section('title', 'Pagar tu cita · Doctorísimo')
@section('robots', 'noindex, nofollow')
{{-- El link lo puede abrir el paciente con su sesión abierta: es una página pública, sin el panel del
     consultorio (ver `layouts/app.blade.php`). --}}
@section('sin-panel', true)

@push('meta')
    {{-- Además de la cabecera `Referrer-Policy` del middleware: el token del link no sale de esta página. --}}
    <meta name="referrer" content="no-referrer">
@endpush

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/reservas.css') }}">
@endpush

@section('content')
    <div class="container reservas-cuerpo">
        <header class="reservas-ficha">
            <div class="reservas-avatar reservas-avatar-grande" aria-hidden="true">
                {{ mb_strtoupper(mb_substr($vm->medicoNombre() !== '' ? $vm->medicoNombre() : 'M', 0, 1)) }}
            </div>

            <div>
                <p class="reservas-etiqueta reservas-etiqueta-suave">Pago de cita</p>
                <h1 class="reservas-titulo reservas-titulo-chico">{{ $vm->titulo() }}</h1>
                <p class="reservas-medico-especialidad">
                    {{ $vm->medicoNombre() !== '' ? $vm->medicoNombre() : 'Consulta médica' }}
                </p>
            </div>
        </header>

        @if (session('estado'))
            <div class="alert alert-success" role="status">{{ session('estado') }}</div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <strong>Revisa lo que marcamos:</strong>
                <ul class="mb-0 mt-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- El resumen común: médico, fecha, hora, concepto y monto. Nunca datos del paciente. --}}
        <div class="reservas-panel">
            <dl class="reservas-detalle">
                @if ($vm->medicoNombre() !== '')
                    <div>
                        <dt>Médico</dt>
                        <dd>{{ $vm->medicoNombre() }}</dd>
                    </div>
                @endif

                @if ($vm->fechaCita() !== '')
                    <div>
                        <dt>Fecha de la cita</dt>
                        <dd>{{ $vm->fechaCita() }}</dd>
                    </div>
                @endif

                @if ($vm->horaCita() !== '')
                    <div>
                        <dt>Hora de la cita</dt>
                        <dd>{{ $vm->horaCita() }}</dd>
                    </div>
                @endif

                <div>
                    <dt>Concepto</dt>
                    <dd>{{ $vm->concepto() }}</dd>
                </div>
            </dl>

            <div class="reservas-resumen">
                <p class="reservas-resumen-medico">Monto a pagar</p>
                <p class="fs-4 fw-bold mb-1">{{ $vm->monto() }}</p>
                <p>Equivalente en dólares: {{ $vm->montoUsd() }}</p>
                @if ($vm->tasa())
                    <p>{{ $vm->montoEsDelDia() ? 'Tasa del día' : 'Tasa aplicada' }}: {{ $vm->tasa() }}</p>
                @endif
                @if ($vm->montoEsDelDia())
                    <p class="small mb-0">El monto en bolívares se confirma al momento de pagar.</p>
                @endif
            </div>
        </div>

        @if ($vm->aprobado())
            {{-- El pago ya está aprobado: comprobante, sin ningún formulario. --}}
            <div class="alert alert-success" role="status">
                <h2 class="alert-heading h5">
                    <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i> Pago aprobado
                </h2>
                <p class="mb-0">Ya recibimos tu pago. Guarda estos datos como comprobante.</p>
            </div>

            <dl class="reservas-detalle">
                <div>
                    <dt>Monto pagado</dt>
                    <dd>{{ $vm->monto() }}</dd>
                </div>
                <div>
                    <dt>Fecha del pago</dt>
                    <dd>{{ $vm->fechaPago() }}</dd>
                </div>
                <div>
                    <dt>Referencia</dt>
                    <dd>{{ $vm->referencia() }}</dd>
                </div>
            </dl>
        @elseif ($vm->vencido())
            <div class="alert alert-warning" role="alert">
                <h2 class="alert-heading h5">
                    <i class="bi bi-clock-history me-1" aria-hidden="true"></i> Este link venció
                </h2>
                <p class="mb-0">
                    Por seguridad, el monto y la tasa de este link ya no están vigentes y no se puede pagar.
                    Pídele al consultorio que te envíe un link nuevo.
                </p>
            </div>
        @elseif ($vm->fallado())
            <div class="alert alert-danger" role="alert">
                <h2 class="alert-heading h5">
                    <i class="bi bi-x-circle-fill me-1" aria-hidden="true"></i> El pago no se completó
                </h2>
                <p class="mb-0">
                    Este intento no terminó y no se te cobró nada por este link.
                    Pídele al consultorio que te envíe uno nuevo para volver a intentarlo.
                </p>
            </div>
        @elseif ($vm->citaYaPagada())
            <div class="alert alert-info" role="status">
                <h2 class="alert-heading h5">
                    <i class="bi bi-info-circle-fill me-1" aria-hidden="true"></i> Esta cita ya está pagada
                </h2>
                <p class="mb-0">
                    No tienes que pagar otra vez. Si crees que es un error, comunícate con el consultorio.
                </p>
            </div>
        @elseif ($vm->citaSinSaldo())
            <div class="alert alert-info" role="status">
                <h2 class="alert-heading h5">
                    <i class="bi bi-info-circle-fill me-1" aria-hidden="true"></i> Esta cita ya está pagada
                </h2>
                <p class="mb-0">
                    El consultorio ya registró el pago, así que no tienes que pagar otra vez.
                    Si crees que es un error, comunícate con el consultorio.
                </p>
            </div>
        @elseif ($vm->enOtp())
            {{-- Ya se pidió el código: el pagador sólo lo escribe. --}}
            <div class="reservas-panel">
                <h2 class="reservas-paso-titulo">
                    <span class="reservas-paso-numero">2</span> Confirma con el código
                </h2>
                <p class="reservas-ayuda">
                    Te enviamos un código al teléfono asociado a la cuenta o al pago móvil.
                    Escríbelo aquí para autorizar el débito.
                </p>

                <form method="POST" action="{{ route('pagar.confirmar', $vm->token()) }}">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label" for="otp">Código</label>
                        <input type="text" name="otp" id="otp"
                               class="form-control form-control-lg @error('otp') is-invalid @enderror"
                               inputmode="numeric" autocomplete="one-time-code" maxlength="10" required autofocus>
                        @error('otp') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Confirmar pago
                    </button>
                </form>

                <form method="POST" action="{{ route('pagar.consultar', $vm->token()) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i> No me llegó: consultar el estado
                    </button>
                </form>
            </div>
        @elseif ($vm->enConfirmacion())
            <div class="alert alert-info" role="status">
                <h2 class="alert-heading h5">
                    <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i> Estamos confirmando el pago
                </h2>
                <p class="mb-0">
                    SyPago recibió la operación. En unos minutos se confirma sola; puedes consultar el estado
                    ahora mismo.
                </p>
            </div>

            <form method="POST" action="{{ route('pagar.consultar', $vm->token()) }}">
                @csrf
                <button type="submit" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i> Consultar ahora
                </button>
            </form>
        @elseif ($vm->enDatos())
            {{-- El formulario del pagador: los mismos datos y los mismos nombres que el checkout del plan. --}}
            <div class="reservas-panel">
                <h2 class="reservas-paso-titulo">
                    <span class="reservas-paso-numero">1</span> Tus datos para el cobro
                </h2>
                <p class="reservas-ayuda">
                    El pago se debita de tu cuenta o de tu teléfono con un código que te llega al móvil.
                    No creamos ninguna cuenta.
                </p>

                <form method="POST" action="{{ route('pagar.iniciar', $vm->token()) }}" id="pago-publico-form">
                    @csrf

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="documento_tipo">Tipo</label>
                            <select name="documento_tipo" id="documento_tipo" class="form-select" required>
                                @foreach (\App\Models\CuentaBancaria::DOCUMENTOS as $tipo)
                                    <option value="{{ $tipo }}" {{ old('documento_tipo', 'V') === $tipo ? 'selected' : '' }}>{{ $tipo }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-5 mb-3">
                            <label class="form-label" for="documento_numero">Documento</label>
                            <input type="text" name="documento_numero" id="documento_numero"
                                   class="form-control @error('documento_numero') is-invalid @enderror"
                                   value="{{ old('documento_numero') }}" maxlength="20" autocomplete="off" required>
                            @error('documento_numero') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="banco_codigo">Banco</label>
                            @if ($vm->bancos !== [])
                                <select name="banco_codigo" id="banco_codigo"
                                        class="form-select @error('banco_codigo') is-invalid @enderror" required>
                                    <option value="">Elige el banco</option>
                                    @foreach ($vm->bancos as $banco)
                                        <option value="{{ $banco['codigo'] }}" {{ old('banco_codigo') === $banco['codigo'] ? 'selected' : '' }}>
                                            {{ $banco['codigo'] }} — {{ $banco['nombre'] }}
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <input type="text" name="banco_codigo" id="banco_codigo"
                                       class="form-control @error('banco_codigo') is-invalid @enderror"
                                       value="{{ old('banco_codigo') }}" maxlength="4" inputmode="numeric"
                                       placeholder="0172" autocomplete="off" required>
                            @endif
                            @error('banco_codigo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="nombre">Nombre del titular</label>
                        <input type="text" name="nombre" id="nombre"
                               class="form-control @error('nombre') is-invalid @enderror"
                               value="{{ old('nombre') }}" maxlength="120" autocomplete="off" required>
                        @error('nombre') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="cuenta">Número de cuenta o teléfono</label>
                        <input type="text" name="cuenta" id="cuenta"
                               class="form-control @error('cuenta') is-invalid @enderror"
                               value="{{ old('cuenta') }}" maxlength="30" inputmode="numeric"
                               placeholder="Número de cuenta o teléfono" autocomplete="off" required>
                        <div class="form-text">
                            La cuenta tiene 20 dígitos y empieza con el código del banco.
                            El pago móvil son 11 dígitos y empieza con 04.
                        </div>
                        @error('cuenta') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-shield-lock me-1" aria-hidden="true"></i> Enviar código de confirmación
                    </button>
                </form>
            </div>
        @else
            <div class="alert alert-warning" role="alert">
                Este link ya no se puede usar. Pídele al consultorio que te envíe uno nuevo.
            </div>
        @endif
    </div>
@endsection
