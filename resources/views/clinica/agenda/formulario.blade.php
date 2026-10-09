@extends('clinica.layout')

@php
    use App\Models\Office;
    use Carbon\Carbon;

    $editando = $cita !== null;
    $valor = fn (string $campo, $defecto = null) => old($campo, $valores[$campo] ?? $defecto);

    // En una sede con hora de cita la hora es la cita; en una por orden de llegada la pone el
    // bloque, así que no se pregunta (el orden lo decide la cola).
    $esConHora = $sede !== null && $sede->modalidad === Office::MODALIDAD_HORA;

    $urlFiltro = $editando ? route('clinica.agenda.editar', $cita->id) : route('clinica.agenda.nueva');
    $volver = route('clinica.agenda', ['vista' => 'dia', 'fecha' => $fecha->toDateString(), 'sede' => $sede?->id ?? 'todas']);
    $elegido = $pacienteElegido;
@endphp

@section('titulo', $editando ? 'Editar cita' : 'Nueva cita')

@section('contenido')
    <div class="page-head">
        <div>
            <h1>{{ $editando ? 'Editar cita' : 'Nueva cita' }}</h1>
            <p class="page-head-sub">
                {{ $sede?->medicalCenter->name ?? 'Sin sede' }} · {{ $fecha->format('d/m/Y') }}
                @if ($jornada)
                    · próximo #{{ $jornada->siguienteNumero() }}
                @endif
            </p>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="{{ $volver }}">
            <i class="bi bi-arrow-left"></i> Volver a la agenda
        </a>
    </div>

    {{-- Paso 1: dónde y cuándo. Es un GET sobre esta misma pantalla porque la sede y el día son lo
         que define la jornada (modalidad, cupo y qué número le toca): con eso resuelto el servidor
         arma el resto del formulario. Cambiar cualquiera de los dos recarga y recalcula. --}}
    <form method="GET" action="{{ $urlFiltro }}" class="card mb-3">
        <div class="card-body">
            <h2 class="h6 mb-2">1. Dónde y cuándo</h2>

            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-0" for="sede-elegida">Sede</label>
                    <select name="sede" id="sede-elegida" class="form-select form-select-sm"
                            onchange="this.form.submit()" @disabled($sedes->isEmpty())>
                        {{-- Sin sede elegida no se preselecciona ninguna: la sede decide la jornada y el
                             lugar, y elegirla por el usuario sería agendar donde no corresponde. --}}
                        @if ($sede === null && $sedes->isNotEmpty())
                            <option value="" selected>— Elegí una sede —</option>
                        @endif
                        @foreach ($sedes as $opcion)
                            <option value="{{ $opcion->id }}" {{ $sede !== null && $sede->id === $opcion->id ? 'selected' : '' }}>
                                {{ $opcion->medicalCenter->name ?? 'Sede' }}@if ($opcion->office_number) — {{ $opcion->office_number }}@endif
                            </option>
                        @endforeach
                        @if ($sedes->isEmpty())
                            <option value="">Sin sedes configuradas</option>
                        @endif
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label small mb-0" for="fecha-elegida">Día</label>
                    <input type="date" name="fecha" id="fecha-elegida" class="form-control form-control-sm"
                           value="{{ $fecha->toDateString() }}" onchange="this.form.submit()">
                </div>

                <div class="col-md-3">
                    <button class="btn btn-sm btn-outline-secondary w-100">
                        <i class="bi bi-arrow-repeat"></i> Ver la jornada
                    </button>
                </div>
            </div>

            @if ($sede === null && $sedes->isNotEmpty())
                <p class="small text-muted mb-0 mt-2">
                    <i class="bi bi-geo-alt"></i>
                    Elegí la sede para ver su jornada y qué número le toca al próximo paciente.
                </p>
            @endif

            @if ($jornada)
                <p class="small text-muted mb-0 mt-2">
                    <span class="badge {{ $jornada->esPorOrdenDeLlegada() ? 'bg-info text-dark' : 'bg-secondary' }}">
                        {{ $jornada->esPorOrdenDeLlegada() ? 'Orden de llegada' : 'Hora de cita' }}
                    </span>
                    {{ $jornada->horarioTexto() }}
                    · {{ $jornada->cantidad() }} {{ $jornada->cantidad() === 1 ? 'paciente' : 'pacientes' }}
                    @if ($jornada->cupo() !== null)
                        · cupo {{ $jornada->cantidad() }}/{{ $jornada->cupo() }}
                        @if ($jornada->llena())
                            <span class="badge bg-warning text-dark">Cupo completo</span>
                        @endif
                    @endif
                    · le tocaría el #{{ $jornada->siguienteNumero() }}
                </p>

                @if ($bloques->isNotEmpty() && $jornada->horario === null)
                    <p class="small text-warning-emphasis mb-0 mt-1">
                        <i class="bi bi-exclamation-triangle"></i>
                        La hora elegida no cae en ningún bloque configurado de ese día.
                    </p>
                @endif
            @endif
        </div>
    </form>

    <form method="POST" action="{{ $accion }}" class="card">
        @csrf

        {{-- La sede y el día vienen del paso 1 (ahí se recargan y se recalculan). --}}
        <input type="hidden" name="sede" value="{{ $sede?->id }}">
        <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">

        {{-- La pregunta por la cita pendiente: no se escribió nada todavía, así que decide el usuario
             con los mismos datos que ya cargó en el formulario. --}}
        @if ($pendiente)
            @php
                $otra = $pendiente['cita'];
                $otraSede = $sedes->firstWhere('medical_center_id', $pendiente['centroId']);
            @endphp

            <div class="card-body bg-warning-subtle border-bottom">
                <h2 class="h6 mb-1">Ya tiene una cita pendiente</h2>
                <p class="small mb-2">
                    <strong>{{ $elegido['nombre'] ?? 'Este paciente' }}</strong> ya tiene cita el
                    {{ $otra->fecha->format('d/m/Y') }}
                    @if ($otra->hora_ini) a las {{ substr($otra->hora_ini, 0, 5) }} @endif
                    @if ($otraSede) en {{ $otraSede->medicalCenter->name ?? 'esa sede' }} @endif.
                    Normalmente un paciente tiene <strong>una sola</strong> cita pendiente por consultorio
                    (los tratamientos —masajistas, terapias— son la excepción: ahí se agendan varias).
                    ¿Qué querés hacer?
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-sm btn-primary" name="decision" value="mover">
                        Mover esa cita a la nueva fecha
                    </button>
                    <button class="btn btn-sm btn-outline-primary" name="decision" value="otra">
                        Agendar otra de todos modos
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" name="decision" value="cancelar">
                        Cancelar
                    </button>
                </div>
            </div>
        @endif

        <div class="card-body">
            <h2 class="h6 mb-2">2. Paciente</h2>

            @if ($editando)
                <p class="mb-0">
                    <strong>{{ $elegido['nombre'] ?? 'Paciente' }}</strong>
                    @if (! empty($elegido['numhistoria']))
                        <span class="text-muted small">· H. {{ $elegido['numhistoria'] }}</span>
                    @else
                        <span class="badge bg-secondary">Sin historia</span>
                    @endif
                    <span class="d-block small text-muted">
                        El paciente de una cita no se cambia: para otro paciente, agendá una cita nueva.
                    </span>
                </p>
                <input type="hidden" name="paciente_id" value="{{ $elegido['id'] ?? $valor('paciente_id') }}">
            @else
                <div class="position-relative">
                    <label class="form-label small mb-0" for="paciente-busqueda">
                        Buscar por apellido, cédula o n.º de historia
                    </label>
                    <input type="text" id="paciente-busqueda" class="form-control form-control-sm"
                           value="{{ $elegido['nombre'] ?? '' }}" autocomplete="off"
                           placeholder="Escribí al menos dos letras" data-url="{{ $urlBuscar }}">
                    <input type="hidden" name="paciente_id" id="paciente-id" value="{{ $valor('paciente_id') }}">

                    <div id="paciente-resultados" class="list-group position-absolute w-100 shadow"
                         style="z-index: 1000; max-height: 16rem; overflow-y: auto;" hidden></div>
                </div>

                @error('paciente_id')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror

                <p class="small text-muted mt-2 mb-0" id="paciente-elegido">
                    @if (! empty($elegido))
                        Elegido: <strong>{{ $elegido['nombre'] }}</strong>
                        @if (! empty($elegido['numhistoria']))
                            <span class="text-muted">· H. {{ $elegido['numhistoria'] }}</span>
                        @else
                            <span class="badge bg-secondary">Sin historia</span>
                        @endif
                    @else
                        Todavía no elegiste a nadie.
                    @endif
                </p>

                <details class="mt-3" {{ $valor('nuevo_cedula') ? 'open' : '' }}>
                    <summary class="small">No está en la lista: cargar un paciente nuevo</summary>

                    <div class="row g-2 mt-1">
                        <div class="col-md-3">
                            <label class="form-label small mb-0" for="nuevo_cedula">Cédula *</label>
                            <input type="text" name="nuevo_cedula" id="nuevo_cedula" class="form-control form-control-sm"
                                   value="{{ $valor('nuevo_cedula') }}">
                            @error('nuevo_cedula') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0" for="nuevo_nombres">Nombres *</label>
                            <input type="text" name="nuevo_nombres" id="nuevo_nombres" class="form-control form-control-sm"
                                   value="{{ $valor('nuevo_nombres') }}">
                            @error('nuevo_nombres') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0" for="nuevo_apellidos">Apellidos *</label>
                            <input type="text" name="nuevo_apellidos" id="nuevo_apellidos" class="form-control form-control-sm"
                                   value="{{ $valor('nuevo_apellidos') }}">
                            @error('nuevo_apellidos') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0" for="nuevo_telefono">Teléfono</label>
                            <input type="text" name="nuevo_telefono" id="nuevo_telefono" class="form-control form-control-sm"
                                   value="{{ $valor('nuevo_telefono') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-0" for="nuevo_email">Correo</label>
                            <input type="email" name="nuevo_email" id="nuevo_email" class="form-control form-control-sm"
                                   value="{{ $valor('nuevo_email') }}">
                            @error('nuevo_email') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0" for="nuevo_sexo">Sexo</label>
                            <select name="nuevo_sexo" id="nuevo_sexo" class="form-select form-select-sm">
                                <option value="">Sin dato</option>
                                <option value="F" {{ $valor('nuevo_sexo') === 'F' ? 'selected' : '' }}>Femenino</option>
                                <option value="M" {{ $valor('nuevo_sexo') === 'M' ? 'selected' : '' }}>Masculino</option>
                            </select>
                        </div>
                    </div>

                    <p class="small text-muted mb-0 mt-1">
                        Queda <strong>sin número de historia</strong>: lo asigna el sistema cuando se complete la
                        historia. Si la cédula ya existe, se enlaza a esa ficha en vez de duplicarla.
                    </p>
                </details>
            @endif
        </div>

        <div class="card-body border-top">
            <h2 class="h6 mb-2">3. La cita</h2>

            <div class="row g-2">
                @if ($esConHora)
                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="hora">Hora de la cita *</label>
                        <input type="time" name="hora" id="hora" class="form-control form-control-sm"
                               value="{{ $valor('hora') }}" required>
                        @error('hora') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                @else
                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="hora">Hora de llegada</label>
                        <input type="time" name="hora" id="hora" class="form-control form-control-sm"
                               value="{{ $valor('hora') }}">
                        <span class="form-text small">Vacía = la apertura del bloque.</span>
                    </div>

                    @if ($bloques->count() > 1)
                        <div class="col-md-3">
                            <label class="form-label small mb-0" for="bloque">Bloque del día</label>
                            <select name="bloque" id="bloque" class="form-select form-select-sm">
                                @foreach ($bloques as $indice => $bloque)
                                    <option value="{{ $indice }}" {{ (int) $valor('bloque', 0) === $indice ? 'selected' : '' }}>
                                        {{ substr($bloque->hora_inicio, 0, 5) }} a {{ substr($bloque->hora_fin, 0, 5) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                @endif

                <div class="col-md-3">
                    <label class="form-label small mb-0" for="motivo">Motivo</label>
                    <select name="motivo" id="motivo" class="form-select form-select-sm">
                        <option value="">Sin motivo</option>
                        @foreach ($motivos as $motivo)
                            <option value="{{ $motivo->codigo }}"
                                    {{ (string) $valor('motivo') === (string) $motivo->codigo ? 'selected' : '' }}>
                                {{ $motivo->tipo_atencion ?: $motivo->codigo }}
                            </option>
                        @endforeach
                        @if ($valor('motivo') && ! $motivos->contains(fn ($m) => (string) $m->codigo === (string) $valor('motivo')))
                            <option value="{{ $valor('motivo') }}" selected>
                                {{ $valor('motivo') }} (no está en el catálogo)
                            </option>
                        @endif
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small mb-0" for="monto">Monto</label>
                    <input type="number" step="0.01" min="0" name="monto" id="monto" class="form-control form-control-sm"
                           value="{{ $valor('monto') }}" placeholder="{{ $motivos->firstWhere('codigo', $valor('motivo'))?->precio }}">
                    <span class="form-text small">Vacío = el precio del motivo.</span>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-sm btn-outline-secondary" href="{{ $volver }}">Cancelar</a>
                <button class="btn btn-sm btn-primary">
                    {{ $editando ? 'Guardar cambios' : 'Agendar la cita' }}
                </button>
            </div>
        </div>
    </form>
@endsection

@section('scripts')
    <script>
        // Buscador de pacientes: pide al servidor los que coinciden y deja el elegido en el campo
        // oculto. Sin él, el formulario se puede completar igual con el alta de un paciente nuevo.
        (function () {
            const caja = document.getElementById('paciente-busqueda');
            if (!caja) return;

            const lista = document.getElementById('paciente-resultados');
            const elegido = document.getElementById('paciente-elegido');
            const url = caja.dataset.url;
            let temporizador = null;

            function limpiar() {
                lista.innerHTML = '';
                lista.hidden = true;
            }

            function elegir(paciente) {
                document.querySelectorAll('input[name="paciente_id"]').forEach(function (oculto) {
                    oculto.value = paciente.id;
                });
                caja.value = paciente.nombre;
                elegido.textContent = 'Elegido: ' + paciente.nombre
                    + (paciente.numhistoria ? ' · H. ' + paciente.numhistoria : ' · sin historia');
                limpiar();
            }

            function pintar(pacientes) {
                lista.innerHTML = '';

                if (!pacientes.length) {
                    limpiar();
                    return;
                }

                pacientes.forEach(function (paciente) {
                    const boton = document.createElement('button');
                    boton.type = 'button';
                    boton.className = 'list-group-item list-group-item-action py-1 small';
                    boton.textContent = paciente.nombre
                        + (paciente.cedula ? ' · ' + paciente.cedula : '')
                        + (paciente.numhistoria ? ' · H. ' + paciente.numhistoria : ' · sin historia');
                    boton.addEventListener('click', function () { elegir(paciente); });
                    lista.appendChild(boton);
                });

                lista.hidden = false;
            }

            caja.addEventListener('input', function () {
                document.querySelectorAll('input[name="paciente_id"]').forEach(function (oculto) {
                    oculto.value = '';
                });

                clearTimeout(temporizador);
                const texto = caja.value.trim();

                if (texto.length < 2) {
                    limpiar();
                    return;
                }

                temporizador = setTimeout(function () {
                    fetch(url + '?q=' + encodeURIComponent(texto), { headers: { 'Accept': 'application/json' } })
                        .then(function (respuesta) { return respuesta.json(); })
                        .then(pintar)
                        .catch(limpiar);
                }, 250);
            });

            document.addEventListener('click', function (evento) {
                if (evento.target !== caja && !lista.contains(evento.target)) {
                    limpiar();
                }
            });
        })();
    </script>
@endsection
