@extends('clinica.layout')

@section('titulo', 'Cobrar la cita')

@section('contenido')
    <x-ui.page-head titulo="Cobrar la cita" :subtitulo="$vm->subtitulo()"
                    :volver="$vm->urlVolver()" volver-texto="Volver a la agenda">
    </x-ui.page-head>

    @php $pagos = $vm->pagos(); @endphp

    <x-ui.tarjeta titulo="Qué se cobra" icono="bi-cash-coin" class="mb-3">
        <dl class="row mb-0">
            <dt class="col-sm-3">Precio de la cita</dt>
            <dd class="col-sm-9">{{ $vm->monto() }}</dd>

            <dt class="col-sm-3">Ya cobrado</dt>
            <dd class="col-sm-9">{{ $vm->pagado() }}</dd>

            <dt class="col-sm-3">Falta cobrar</dt>
            <dd class="col-sm-9"><strong>{{ $vm->pendiente() }}</strong></dd>
        </dl>
    </x-ui.tarjeta>

    @if ($vm->estaPagada())
        <div class="alert alert-success d-flex align-items-start gap-2" role="status" data-cobro-pagada>
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <span>Esta cita ya está cobrada. Si hay que devolver algo, es una tarea de reembolsos.</span>
        </div>
    @elseif ($vm->sinPrecio())
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>La cita todavía no tiene precio: ponlo en la agenda antes de cobrar, o escribe el monto en el
                cobro manual.</span>
        </div>
    @endif

    @unless ($vm->estaPagada())
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <x-ui.tarjeta titulo="Con la pasarela" icono="bi-credit-card">
                    @if ($vm->pagoEnProceso())
                        <p class="texto-tenue">
                            Se pidió el código para el cobro. Escríbelo para confirmar el débito.
                        </p>

                        <form method="POST" action="{{ $vm->urlConfirmar($vm->pagoEnProceso()) }}" class="row g-3">
                            @csrf
                            <div class="col-12 col-sm-6">
                                <label class="form-label" for="otp">Código que llegó al teléfono</label>
                                <input type="text" class="form-control @error('otp') is-invalid @enderror" id="otp"
                                       name="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
                                @error('otp')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary">
                                    <i class="bi bi-shield-check" aria-hidden="true"></i> Confirmar el cobro
                                </button>
                            </div>
                        </form>
                    @elseif ($vm->puedeUsarLaPasarela())
                        <p class="texto-tenue">
                            Se cobra el saldo ({{ $vm->pendiente() }}) con {{ $vm->nombreDelMetodo() }} y el código
                            le llega al teléfono del paciente.
                        </p>

                        <form method="POST" action="{{ $vm->urlPasarela() }}" class="row g-3">
                            @csrf
                            <div class="col-4">
                                <label class="form-label" for="documento_tipo">Documento</label>
                                <select class="form-select" id="documento_tipo" name="documento_tipo" required>
                                    @foreach (\App\Models\CuentaBancaria::DOCUMENTOS as $tipo)
                                        <option value="{{ $tipo }}" @selected(old('documento_tipo') === $tipo)>{{ $tipo }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-8">
                                <label class="form-label" for="documento_numero">Número</label>
                                <input type="text" class="form-control" id="documento_numero" name="documento_numero"
                                       value="{{ old('documento_numero') }}" maxlength="20" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="nombre">Nombre del pagador</label>
                                <input type="text" class="form-control" id="nombre" name="nombre"
                                       value="{{ old('nombre') }}" maxlength="120" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label" for="banco_codigo">Banco</label>
                                <input type="text" class="form-control" id="banco_codigo" name="banco_codigo"
                                       value="{{ old('banco_codigo') }}" inputmode="numeric" maxlength="4"
                                       placeholder="0172" required>
                            </div>
                            <div class="col-8">
                                <label class="form-label" for="cuenta">Cuenta o teléfono</label>
                                <input type="text" class="form-control" id="cuenta" name="cuenta"
                                       value="{{ old('cuenta') }}" maxlength="30" required>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary">
                                    <i class="bi bi-phone" aria-hidden="true"></i> Pedir el código
                                </button>
                            </div>
                        </form>
                    @else
                        <p class="texto-tenue mb-0">
                            No hay un método de pago configurado para cobrar con la pasarela. Mientras tanto, se
                            puede registrar el cobro a mano.
                        </p>
                    @endif
                </x-ui.tarjeta>
            </div>

            <div class="col-12 col-lg-6">
                <x-ui.tarjeta titulo="Cobro manual" icono="bi-wallet2">
                    <p class="texto-tenue">
                        Para lo que se cobra en el consultorio sin pasar por la pasarela.
                    </p>

                    <form method="POST" action="{{ $vm->urlManual() }}" class="row g-3">
                        @csrf
                        <div class="col-6">
                            <label class="form-label" for="recibido">Recibido (USD)</label>
                            <input type="text" class="form-control @error('recibido') is-invalid @enderror" id="recibido"
                                   name="recibido" inputmode="decimal" value="{{ old('recibido', $vm->pendienteParaElFormulario()) }}" required>
                            @error('recibido')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="monto">Precio de la cita (si no tenía)</label>
                            <input type="text" class="form-control" id="monto" name="monto" inputmode="decimal"
                                   value="{{ old('monto') }}" placeholder="Opcional">
                        </div>

                        <div class="col-12">
                            <span class="form-label d-block">Con qué se cobró</span>
                            @foreach ($vm->medios() as $valor => $texto)
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="medio" id="medio-{{ $valor }}"
                                           value="{{ $valor }}" @checked(old('medio', 'efectivo') === $valor)>
                                    <label class="form-check-label" for="medio-{{ $valor }}">{{ $texto }}</label>
                                </div>
                            @endforeach
                            @error('medio')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="nota">Nota (opcional)</label>
                            <input type="text" class="form-control" id="nota" name="nota" maxlength="200"
                                   value="{{ old('nota') }}">
                        </div>

                        <div class="col-12">
                            <button class="btn btn-success">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i> Registrar el cobro
                            </button>
                        </div>
                    </form>
                </x-ui.tarjeta>
            </div>
        </div>
    @endunless

    <x-ui.tarjeta titulo="Los pagos de esta cita" icono="bi-receipt" class="mt-3">
        @if ($vm->hayPagos())
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Fecha</th>
                            <th scope="col">Monto</th>
                            <th scope="col">Medio</th>
                            <th scope="col">Canal</th>
                            <th scope="col">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pagos as $pago)
                            <tr>
                                <td>{{ $pago['fecha'] }}</td>
                                <td>{{ $pago['monto'] }}</td>
                                <td>{{ $pago['medio'] }}</td>
                                <td>{{ $pago['canal'] }}</td>
                                <td><x-ui.estado :tono="$pago['tono']">{{ $pago['estado'] }}</x-ui.estado></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-ui.vacio icono="bi-receipt" titulo="Todavía no hay pagos de esta cita.">Cuando se cobre, aparecerá acá con su medio y su estado.</x-ui.vacio>
        @endif
    </x-ui.tarjeta>
@endsection
