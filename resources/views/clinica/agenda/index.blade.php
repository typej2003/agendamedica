@extends('clinica.layout')

@section('titulo', 'Agenda')

@php
    use App\Support\FechaClinica;

    $titulo = match ($vista) {
        'semana' => 'Semana del ' . $fecha->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->format('d/m/Y'),
        'mes'    => FechaClinica::tituloMes($fecha),
        default  => FechaClinica::tituloDia($fecha),
    };
    $anterior = match ($vista) {
        'semana' => $fecha->copy()->subWeek(),
        'mes'    => $fecha->copy()->subMonth(),
        default  => $fecha->copy()->subDay(),
    };
    $siguiente = match ($vista) {
        'semana' => $fecha->copy()->addWeek(),
        'mes'    => $fecha->copy()->addMonth(),
        default  => $fecha->copy()->addDay(),
    };
    // Todo link de la agenda conserva la vista y la sede elegidas (solo cambia lo que toca).
    $url = fn (array $extra) => route('clinica.agenda', array_merge(request()->query(), $extra));
@endphp

@section('contenido')
    <div class="page-head">
        <div>
            <h1>Agenda</h1>
            <p class="page-head-sub">
                {{ ucfirst($titulo) }} · {{ $totalRango }} {{ $totalRango === 1 ? 'cita' : 'citas' }}
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="btn-group btn-group-sm" role="group" aria-label="Vista">
                @foreach (['dia' => 'Día', 'semana' => 'Semana', 'mes' => 'Mes'] as $clave => $etiqueta)
                    <a class="btn btn-outline-primary {{ $vista === $clave ? 'active' : '' }}"
                       href="{{ $url(['vista' => $clave, 'fecha' => $fecha->toDateString()]) }}"
                       @if ($vista === $clave) aria-current="true" @endif>{{ $etiqueta }}</a>
                @endforeach
            </div>

            <form method="GET" action="{{ route('clinica.agenda') }}" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="vista" value="{{ $vista }}">
                <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">
                <select name="sede" class="form-select form-select-sm" onchange="this.form.submit()"
                        style="min-width: 240px;" aria-label="Sede">
                    <option value="todas" {{ $sedeId === null ? 'selected' : '' }}>Todas las sedes</option>
                    @foreach ($sedes as $sede)
                        <option value="{{ $sede->id }}" {{ $sedeId === $sede->id ? 'selected' : '' }}>
                            {{ $sede->medicalCenter->name ?? 'Sede' }}@if ($sede->office_number) — {{ $sede->office_number }}@endif
                        </option>
                    @endforeach
                </select>
            </form>

            {{-- Acciones globales del día (WEB-2.7): la ventana de envío y el listado que se imprime.
                 Solo en la vista de día: son "las del día", no las del rango. --}}
            @if ($vista === 'dia')
                <a class="btn btn-sm btn-outline-secondary"
                   href="{{ route('clinica.agenda.envio', ['fecha' => $fecha->toDateString(), 'sede' => $sedeId ?? 'todas']) }}">
                    <i class="bi bi-send"></i> Enviar a todos
                </a>
                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                   href="{{ route('clinica.agenda.imprimir', ['fecha' => $fecha->toDateString(), 'sede' => $sedeId ?? 'todas']) }}">
                    <i class="bi bi-printer"></i> Imprimir listado
                </a>
            @endif

            <a class="btn btn-sm btn-primary"
               href="{{ route('clinica.agenda.nueva', ['fecha' => $fecha->toDateString(), 'sede' => $sedeId]) }}">
                <i class="bi bi-plus-lg"></i> Nueva cita
            </a>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 mb-3">
        <a class="btn btn-sm btn-outline-secondary" title="Anterior" aria-label="Día anterior"
           href="{{ $url(['fecha' => $anterior->toDateString()]) }}"><i class="bi bi-chevron-left"></i></a>
        <a class="btn btn-sm btn-outline-secondary" href="{{ $url(['fecha' => now()->toDateString()]) }}">Hoy</a>
        <a class="btn btn-sm btn-outline-secondary" title="Siguiente" aria-label="Día siguiente"
           href="{{ $url(['fecha' => $siguiente->toDateString()]) }}"><i class="bi bi-chevron-right"></i></a>
        <strong class="ms-2">{{ ucfirst($titulo) }}</strong>
    </div>

    @include('clinica.agenda.' . $vista)

    {{-- Un solo diálogo de cobro para toda la página: el botón de cada cita lo llena con sus datos. --}}
    <div id="cobro-fondo" class="cobro-fondo" hidden>
        <div class="cobro-caja">
            <form method="POST" id="cobro-form">
                @csrf
                <h2 class="h6">Cobrar a <span id="cobro-paciente"></span></h2>

                <div class="mb-2" id="cobro-monto-fila" hidden>
                    <label class="form-label small mb-0" for="cobro-monto">Monto de la consulta</label>
                    <input type="number" step="0.01" min="0" name="monto" id="cobro-monto" class="form-control form-control-sm">
                </div>

                <div class="mb-2">
                    <label class="form-label small mb-0" for="cobro-recibido">Recibido ahora</label>
                    <input type="number" step="0.01" min="0" name="recibido" id="cobro-recibido"
                           class="form-control form-control-sm" required>
                </div>

                <p class="small text-muted mb-3" id="cobro-detalle"></p>

                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cerrarCobro()">Cancelar</button>
                    <button class="btn btn-sm btn-primary">Registrar cobro</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Recordatorio de una cita (WEB-2.6): mismo diálogo para todas las filas, que lo llena con sus
         datos. Los tres canales se muestran siempre; el que no tiene dato queda deshabilitado y el
         aviso dice cuál falta (regla de la wiki). --}}
    <div id="mensaje-fondo" class="cobro-fondo" hidden>
        <div class="cobro-caja">
            <form method="POST" id="mensaje-form">
                @csrf
                <h2 class="h6">Recordar a <span id="mensaje-paciente"></span></h2>

                <div class="mb-2">
                    <span class="form-label small mb-0 d-block">Por dónde</span>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach ($canales as $clave => $etiqueta)
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="canal" id="canal-{{ $clave }}"
                                       value="{{ $clave }}" data-canal="{{ $clave }}">
                                <label class="form-check-label small" for="canal-{{ $clave }}">{{ $etiqueta }}</label>
                            </div>
                        @endforeach
                    </div>
                    <p class="small text-muted mb-0" id="mensaje-falta"></p>
                </div>

                <div class="mb-2">
                    <label class="form-label small mb-0" for="mensaje-texto">Mensaje</label>
                    <textarea name="mensaje" id="mensaje-texto" rows="4" maxlength="500"
                              class="form-control form-control-sm"></textarea>
                    <p class="small text-muted mb-0" id="mensaje-contador"></p>
                </div>

                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cerrarMensaje()">Cancelar</button>
                    <button class="btn btn-sm btn-primary" id="mensaje-enviar">Enviar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Cobro: el diálogo se llena desde los datos de la cita; el monto solo se pide si la cita
        // todavía no tiene precio (lo común en los datos del legado).
        function abrirCobro(boton) {
            const datos = boton.dataset;
            document.getElementById('cobro-form').action = datos.url;
            document.getElementById('cobro-paciente').textContent = datos.paciente;
            document.getElementById('cobro-monto-fila').hidden = datos.requiereMonto !== '1';
            document.getElementById('cobro-monto').value = '';
            document.getElementById('cobro-recibido').value = datos.pendiente;
            document.getElementById('cobro-detalle').textContent = datos.detalle;
            document.getElementById('cobro-fondo').hidden = false;
            document.getElementById('cobro-recibido').focus();
        }

        function cerrarCobro() {
            document.getElementById('cobro-fondo').hidden = true;
        }

        // Recordatorio de una cita (WEB-2.6). Los tres canales se muestran siempre: el que el
        // paciente no puede recibir queda deshabilitado y el aviso dice cuál falta. Un SMS de más de
        // 160 caracteres se avisa (antes se recortaba en silencio).
        function abrirMensaje(boton) {
            const datos = boton.dataset;
            document.getElementById('mensaje-form').action = datos.url;
            document.getElementById('mensaje-paciente').textContent = datos.paciente;
            document.getElementById('mensaje-texto').value = datos.mensaje;

            const disponible = {
                sms: datos.tieneTelefono === '1',
                whatsapp: datos.tieneTelefono === '1',
                correo: datos.tieneCorreo === '1',
            };

            let elegido = false;
            document.querySelectorAll('#mensaje-fondo [data-canal]').forEach(function (radio) {
                const puede = disponible[radio.dataset.canal] === true;
                radio.disabled = !puede;
                radio.closest('.form-check').classList.toggle('text-muted', !puede);
                radio.checked = puede && !elegido;
                if (radio.checked) {
                    elegido = true;
                }
            });

            const faltantes = [];
            if (!disponible.sms) {
                faltantes.push('teléfono');
            }
            if (!disponible.correo) {
                faltantes.push('correo');
            }
            document.getElementById('mensaje-falta').textContent = faltantes.length === 0
                ? ''
                : 'Al paciente le falta ' + faltantes.join(' y ') + ' en la ficha.';

            document.getElementById('mensaje-enviar').disabled = !elegido;
            actualizarContadorMensaje();
            document.getElementById('mensaje-fondo').hidden = false;
            document.getElementById('mensaje-texto').focus();
        }

        function cerrarMensaje() {
            document.getElementById('mensaje-fondo').hidden = true;
        }

        function actualizarContadorMensaje() {
            const texto = document.getElementById('mensaje-texto').value;
            const canal = document.querySelector('#mensaje-fondo [data-canal]:checked');
            const aviso = canal && canal.value === 'sms' && texto.length > 160
                ? ' — pasa los 160 caracteres de un SMS'
                : '';
            document.getElementById('mensaje-contador').textContent = texto.length + ' caracteres' + aviso;
        }

        document.getElementById('mensaje-texto').addEventListener('input', actualizarContadorMensaje);
        document.querySelectorAll('#mensaje-fondo [data-canal]').forEach(function (radio) {
            radio.addEventListener('change', actualizarContadorMensaje);
        });

        // Reordenar: el arrastre manda **el movimiento** (desde -> hasta), no el orden nuevo de cada
        // fila; el servidor corre las demás en una transacción. Es la misma operación de sync que el
        // móvil (`ReordenarCola`), no N updates.
        (function () {
            let arrastrada = null;

            document.querySelectorAll('.lista-cola').forEach(function (lista) {
                lista.querySelectorAll('tr[draggable="true"]').forEach(function (fila) {
                    fila.addEventListener('dragstart', function () {
                        arrastrada = fila;
                        fila.classList.add('arrastrando');
                    });
                    fila.addEventListener('dragend', function () {
                        fila.classList.remove('arrastrando');
                    });
                });

                lista.addEventListener('dragover', function (evento) {
                    evento.preventDefault();
                });

                lista.addEventListener('drop', function (evento) {
                    evento.preventDefault();
                    const destino = evento.target.closest('tr[draggable="true"]');
                    if (!arrastrada || !destino || destino === arrastrada || arrastrada.parentElement !== lista) {
                        return;
                    }

                    const desde = parseInt(arrastrada.dataset.numorden, 10);
                    const hasta = parseInt(destino.dataset.numorden, 10);
                    if (!Number.isInteger(desde) || !Number.isInteger(hasta) || desde === hasta) {
                        return;
                    }

                    fetch(lista.dataset.reordenar, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': lista.dataset.csrf,
                        },
                        body: JSON.stringify({
                            record_id: parseInt(arrastrada.dataset.id, 10),
                            from: desde,
                            to: hasta,
                            fecha: lista.dataset.fecha,
                        }),
                    }).then(function () {
                        window.location.reload();
                    });
                });
            });
        })();
    </script>
@endsection
