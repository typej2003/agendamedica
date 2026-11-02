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
        @elseif ($vm->esPagoMovil())
            {{-- Pago móvil o transferencia: el paciente **envía** desde su banco y deja la referencia. --}}
            @php($cuenta = $vm->datosDeLaCuenta())
            @php($declarado = $vm->pagoMovilDeclarado())
            @php($ultima = $vm->ultimaTransaccion())

            @if ($declarado && $ultima && $ultima->estaPendiente())
                <div class="alert alert-info" role="status">
                    <h2 class="alert-heading h5">
                        <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i> Estamos verificando tu pago
                    </h2>
                    <p class="mb-0">
                        Nos dijiste que pagaste con la referencia <strong>{{ $ultima->referencia }}</strong>.
                        @if ($vm->cuentaDeCobro()?->conciliaSola())
                            Le estamos preguntando al banco; puede tardar unos minutos.
                        @else
                            El consultorio va a confirmar el pago y te avisamos.
                        @endif
                    </p>
                </div>

                <form method="POST" action="{{ route('pagar.consultar', $vm->token()) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i> Volver a consultar
                    </button>
                </form>
            @else
                @if ($declarado && $ultima && $ultima->mensaje)
                    <div class="alert alert-warning" role="alert">
                        <h2 class="alert-heading h5">
                            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i> No pudimos confirmarlo
                        </h2>
                        <p class="mb-0">{{ $ultima->mensaje }}</p>
                    </div>
                @endif

                <div class="reservas-panel">
                    <h2 class="reservas-paso-titulo">
                        <span class="reservas-paso-numero">1</span> Paga desde tu banco
                    </h2>
                    <p class="reservas-ayuda">
                        Envía el monto exacto a estos datos. Después vuelve acá y escribe el número de referencia
                        que te dio tu banco.
                    </p>

                    <dl class="reservas-detalle">
                        <div>
                            <dt>Banco</dt>
                            <dd>{{ $cuenta['banco'] }}</dd>
                        </div>
                        @if ($cuenta['aceptaPagoMovil'] && $cuenta['telefono'] !== '')
                            <div>
                                <dt>Pago móvil · teléfono</dt>
                                <dd>{{ $cuenta['telefono'] }}</dd>
                            </div>
                        @endif
                        @if ($cuenta['aceptaTransferencia'] && $cuenta['cuenta'] !== '')
                            <div>
                                <dt>Transferencia · cuenta</dt>
                                <dd>{{ $cuenta['cuenta'] }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt>Documento del titular</dt>
                            <dd>{{ $cuenta['documento'] }}</dd>
                        </div>
                        @if ($cuenta['titular'] !== '')
                            <div>
                                <dt>Titular</dt>
                                <dd>{{ $cuenta['titular'] }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt>Monto</dt>
                            <dd>{{ $vm->monto() }}</dd>
                        </div>
                    </dl>

                    <h2 class="reservas-paso-titulo mt-4">
                        <span class="reservas-paso-numero">2</span> Dinos que ya pagaste
                    </h2>

                    <form method="POST" action="{{ route('pagar.declarar', $vm->token()) }}" id="pago-movil-form">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label" for="tipo_pago">¿Cómo pagaste?</label>
                            <select name="tipo_pago" id="tipo_pago" class="form-select" required>
                                @if ($cuenta['aceptaPagoMovil'])
                                    <option value="PM" {{ old('tipo_pago', 'PM') === 'PM' ? 'selected' : '' }}>Pago móvil</option>
                                @endif
                                @if ($cuenta['aceptaTransferencia'])
                                    <option value="TRF" {{ old('tipo_pago') === 'TRF' ? 'selected' : '' }}>Transferencia</option>
                                @endif
                            </select>
                        </div>

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
                                <label class="form-label" for="documento_numero">Tu documento</label>
                                <input type="text" name="documento_numero" id="documento_numero"
                                       class="form-control @error('documento_numero') is-invalid @enderror"
                                       value="{{ old('documento_numero') }}" maxlength="20" autocomplete="off" required>
                                @error('documento_numero') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="telefono">Tu teléfono</label>
                                <input type="text" name="telefono" id="telefono"
                                       class="form-control @error('telefono') is-invalid @enderror"
                                       value="{{ old('telefono') }}" maxlength="15" inputmode="numeric"
                                       placeholder="04141234567" autocomplete="off">
                                <div class="form-text">El del pago móvil; en una transferencia no hace falta.</div>
                                @error('telefono') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="nombre">Nombre del titular que pagó</label>
                            <input type="text" name="nombre" id="nombre"
                                   class="form-control @error('nombre') is-invalid @enderror"
                                   value="{{ old('nombre') }}" maxlength="120" autocomplete="off" required>
                            @error('nombre') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="referencia">Número de referencia</label>
                            <input type="text" name="referencia" id="referencia"
                                   class="form-control @error('referencia') is-invalid @enderror"
                                   value="{{ old('referencia') }}" maxlength="40" inputmode="numeric"
                                   autocomplete="off" required>
                            <div class="form-text">Es el que aparece en el comprobante de tu banco.</div>
                            @error('referencia') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Ya pagué: verificar el pago
                        </button>
                    </form>
                </div>
            @endif
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
