@extends('clinica.layout')

@section('titulo', 'Cobros')

@section('estilos')
    {{-- Sin JavaScript no se puede reaccionar al «¿Qué haces?»: las dos ramas quedan a la vista, como
         estaban antes, para que la pantalla siga funcionando. --}}
    <noscript>
        <style>[data-cobro-campos][hidden] { display: flex !important; }</style>
    </noscript>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/cobros.js') }}?v={{ @filemtime(public_path('js/clinica/cobros.js')) }}"></script>
@endsection

@section('contenido')
    <x-ui.page-head titulo="Cobros"
                    subtitulo="Tus cuentas de pago móvil y los pagos que esperan confirmación."></x-ui.page-head>

    @if ($administra)
        <x-ui.tarjeta titulo="¿Quién cobra tus citas?" icono="bi-cash-stack">
            <p class="texto-tenue">
                Por defecto las citas se cobran con la cuenta de la plataforma y se te liquidan. Si prefieres,
                tus pacientes pueden pagar directo a tu cuenta: la plata entra a tu banco y no hay liquidación.
            </p>

            <form method="POST" action="{{ route('clinica.cobros.preferencia') }}" class="row g-2 align-items-end">
                @csrf

                <div class="col-12 col-sm-6">
                    <label class="form-label" for="recaudador">Cobrar mis citas con</label>
                    <select name="recaudador" id="recaudador" class="form-select">
                        <option value="plataforma" @selected($preferencia === 'plataforma')>La plataforma (por defecto)</option>
                        <option value="medico" @selected($preferencia === 'medico')>Mis cuentas</option>
                    </select>
                </div>

                <div class="col-12 col-sm-3">
                    <button class="btn btn-primary w-100">Guardar</button>
                </div>
            </form>
        </x-ui.tarjeta>
    @endif

    <x-ui.tarjeta titulo="Por confirmar" icono="bi-hourglass-split">
        @if ($porConfirmar->isEmpty())
            <p class="texto-tenue mb-0">No hay pagos esperando confirmación.</p>
        @else
            <p class="texto-tenue">
                Cuando veas la plata en tu banco, confirma el pago. Si el paciente se equivocó de monto o de
                referencia, recházalo y pídele que lo haga de nuevo.
            </p>

            <x-ui.tabla class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Cita</th>
                            <th scope="col">Monto</th>
                            <th scope="col">Pagador</th>
                            <th scope="col">Referencia</th>
                            <th scope="col">Resolver</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($porConfirmar as $pago)
                            @php($transaccion = $pago->transacciones->last())
                            <tr>
                                <td class="celda-principal" data-etiqueta="Cita">{{ $pago->concepto }}</td>
                                <td data-etiqueta="Monto">{{ $pago->montoTexto() }}</td>
                                <td data-etiqueta="Pagador">
                                    {{ $pago->datos_pagador['nombre'] ?? '—' }}<br>
                                    <span class="texto-tenue small">
                                        {{ $pago->datos_pagador['documento_tipo'] ?? '' }}{{ $pago->datos_pagador['documento_numero'] ?? '' }}
                                        @if (! empty($pago->datos_pagador['telefono']))
                                            · {{ $pago->datos_pagador['telefono'] }}
                                        @endif
                                    </span>
                                </td>
                                <td data-etiqueta="Referencia">
                                    {{ $transaccion?->referencia ?? '—' }}
                                    @if ($transaccion?->mensaje)
                                        <br><span class="texto-tenue small">{{ $transaccion->mensaje }}</span>
                                    @endif
                                </td>
                                <td class="celda-acciones" data-etiqueta="Resolver">
                                    {{-- La decisión primero (guía de UI, §11): se elige confirmar o rechazar y
                                         recién ahí aparece el dato de esa acción y su botón. Sin JavaScript
                                         quedan las dos ramas a la vista, como antes. --}}
                                    <form method="POST" action="{{ route('clinica.cobros.confirmar', $pago) }}"
                                          data-cobro-resolver>
                                        @csrf

                                        <label class="visually-hidden" for="accion-{{ $pago->id }}">Qué haces con el pago</label>
                                        <select name="accion" id="accion-{{ $pago->id }}"
                                                class="form-select form-select-sm mb-1" data-cobro-accion required>
                                            <option value="">¿Qué haces?</option>
                                            <option value="confirmar">Confirmar el pago</option>
                                            <option value="rechazar">Rechazar el pago</option>
                                        </select>

                                        <div class="d-flex flex-wrap gap-1 align-items-end"
                                             data-cobro-campos="confirmar" hidden>
                                            <div>
                                                <label class="visually-hidden" for="monto-{{ $pago->id }}">Monto</label>
                                                <input type="text" name="monto" id="monto-{{ $pago->id }}"
                                                       class="form-control form-control-sm" style="max-width: 8rem"
                                                       value="{{ \App\Pagos\Centavos::aTexto((int) $pago->monto_e2) }}">
                                            </div>
                                            <button class="btn btn-sm btn-primary"
                                                    formaction="{{ route('clinica.cobros.confirmar', $pago) }}">
                                                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Confirmar
                                            </button>
                                        </div>

                                        <div class="d-flex flex-wrap gap-1 align-items-end"
                                             data-cobro-campos="rechazar" hidden>
                                            <div>
                                                <label class="visually-hidden" for="motivo-{{ $pago->id }}">Motivo</label>
                                                <input type="text" name="motivo" id="motivo-{{ $pago->id }}"
                                                       class="form-control form-control-sm" style="max-width: 12rem"
                                                       placeholder="Motivo (opcional)">
                                            </div>
                                            <button class="btn btn-sm btn-outline-danger"
                                                    formaction="{{ route('clinica.cobros.rechazar', $pago) }}">
                                                Rechazar
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
            </x-ui.tabla>
        @endif
    </x-ui.tarjeta>

    @if ($administra)
        <x-ui.tarjeta titulo="Mis cuentas de pago móvil" icono="bi-bank">
            @if ($cuentas->isEmpty())
                <p class="texto-tenue">Todavía no tienes cuentas cargadas. Carga la primera abajo.</p>
            @else
                <div class="mb-3">
                    <x-ui.tabla class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Banco</th>
                                <th scope="col">Pago móvil</th>
                                <th scope="col">Cuenta</th>
                                <th scope="col">Confirmación</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cuentas as $cuenta)
                                <tr>
                                    <td class="celda-principal" data-etiqueta="Banco">
                                        {{ $cuenta->bancoNombre() }}
                                        @if ($cuenta->predeterminada)
                                            <span class="badge text-bg-secondary">Predeterminada</span>
                                        @endif
                                    </td>
                                    <td data-etiqueta="Pago móvil">{{ $cuenta->telefono ?: '—' }}</td>
                                    <td data-etiqueta="Cuenta">{{ $cuenta->numero ?: '—' }}</td>
                                    <td data-etiqueta="Confirmación">
                                        @if ($cuenta->conciliaSola())
                                            Automática
                                        @elseif ($cuenta->conciliacionAutomatica())
                                            Automática sin verificar
                                        @else
                                            Manual
                                        @endif
                                        @if ($cuenta->conciliacion_error)
                                            <br><span class="texto-tenue small">{{ $cuenta->conciliacion_error }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end celda-acciones">
                                        @if (! $cuenta->predeterminada)
                                            <form method="POST" action="{{ route('clinica.cobros.cuentas.predeterminada', $cuenta) }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-secondary">Predeterminar</button>
                                            </form>
                                        @endif

                                        @if ($cuenta->soportaConciliacionAutomatica())
                                            <form method="POST" action="{{ route('clinica.cobros.cuentas.probar', $cuenta) }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-secondary">Probar conexión</button>
                                            </form>
                                        @endif

                                        <details class="d-inline-block text-start">
                                            <summary class="btn btn-sm btn-outline-secondary">Editar</summary>
                                            <div class="mt-2">
                                                @include('clinica.cobros._cuenta', ['cuenta' => $cuenta])
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.tabla>
                </div>
            @endif

            <details @if ($cuentas->isEmpty()) open @endif>
                <summary class="btn btn-primary">+ Agregar una cuenta</summary>
                <div class="mt-3">
                    @include('clinica.cobros._cuenta', ['cuenta' => null])
                </div>
            </details>
        </x-ui.tarjeta>
    @endif
@endsection
