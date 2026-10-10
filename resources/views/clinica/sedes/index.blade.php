@extends('clinica.layout')

@php
    $valor = fn (string $campo, $defecto = null) => old($campo, $defecto);
    $volver = route('clinica.inicio');
@endphp

@section('titulo', 'Sedes')

@section('contenido')
    <div class="page-head">
        <div>
            <h1>Sedes</h1>
            <p class="page-head-sub">
                Los lugares donde atienden los médicos del consultorio.
                Acá se elige la sede al agendar y al crear un consultorio.
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a class="btn btn-sm btn-outline-secondary {{ $verTodas ? '' : 'active' }}"
               href="{{ route('clinica.sedes') }}">
                Activas ({{ $totalActivas }})
            </a>
            <a class="btn btn-sm btn-outline-secondary {{ $verTodas ? 'active' : '' }}"
               href="{{ route('clinica.sedes', ['todas' => 1]) }}">
                Todas
            </a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ $volver }}">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-warning py-2">
            <i class="bi bi-exclamation-triangle-fill"></i> {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('clinica.sedes.guardar') }}" class="card mb-3">
        @csrf
        <input type="hidden" name="id" value="{{ $valor('id', $editando?->id) }}">
        <input type="hidden" name="ver_todas" value="{{ $verTodas ? 1 : 0 }}">

        <div class="card-body">
            <h2 class="h6 mb-2">{{ $editando ? 'Editar la sede' : 'Nueva sede' }}</h2>

            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small mb-0" for="name">Nombre *</label>
                    <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="200"
                           placeholder="Clínica Metropolitana" value="{{ $valor('name', $editando?->name) }}">
                </div>

                <div class="col-md-5">
                    <label class="form-label small mb-0" for="address">Dirección *</label>
                    <input type="text" name="address" id="address" class="form-control form-control-sm" maxlength="1000"
                           value="{{ $valor('address', $editando?->address) }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label small mb-0" for="phone">Teléfono</label>
                    <input type="text" name="phone" id="phone" class="form-control form-control-sm" maxlength="50"
                           value="{{ $valor('phone', $editando?->phone) }}">
                </div>

                <div class="col-md-5">
                    <label class="form-label small mb-0" for="city_id">Ciudad *</label>
                    <select name="city_id" id="city_id" class="form-select form-select-sm" required>
                        <option value="">— Elegí la ciudad —</option>
                        @foreach ($ciudades as $ciudad)
                            <option value="{{ $ciudad->id }}"
                                    {{ (int) $valor('city_id', $editando?->city_id) === $ciudad->id ? 'selected' : '' }}>
                                {{ $ciudad->name }} — {{ $ciudad->state->name ?? '' }}
                            </option>
                        @endforeach
                    </select>
                    {{-- El estado y el país no se preguntan: se derivan de la ciudad (los exige el
                         esquema migrado y una ciudad pertenece a un solo estado). --}}
                    <span class="form-text small">El estado y el país salen de la ciudad.</span>
                </div>

                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="activo" id="activo" value="1"
                               {{ $valor('activo', $editando?->activo ?? true) ? 'checked' : '' }}>
                        <label class="form-check-label small" for="activo">Se sigue usando</label>
                    </div>
                </div>

                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button class="btn btn-sm btn-primary flex-grow-1">
                        {{ $editando ? 'Guardar cambios' : 'Registrar la sede' }}
                    </button>
                    @if ($editando)
                        <a class="btn btn-sm btn-outline-secondary"
                           href="{{ route('clinica.sedes', $verTodas ? ['todas' => 1] : []) }}">Cancelar</a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div class="card">
        @if ($sedes->isEmpty())
            <div class="empty-state">
                <i class="bi bi-hospital"></i>
                <p>No hay sedes {{ $verTodas ? '' : 'activas' }}.</p>
                <p class="small">Cargá la primera en el formulario de arriba: sin sedes no se pueden crear consultorios.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th scope="col">Sede</th>
                            <th scope="col">Dirección</th>
                            <th scope="col">Ciudad</th>
                            <th scope="col">Teléfono</th>
                            <th scope="col" class="text-center">Consultorios</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sedes as $sede)
                            <tr>
                                <td><strong>{{ $sede->name }}</strong></td>
                                <td>{{ $sede->address }}</td>
                                <td>{{ $sede->city->name ?? '—' }}</td>
                                <td>{{ $sede->phone ?: '—' }}</td>
                                <td class="text-center">
                                    @if ($sede->consultorios_activos > 0)
                                        <span class="badge bg-secondary">{{ $sede->consultorios_activos }}</span>
                                    @else
                                        <span class="text-muted small">ninguno</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($sede->activo)
                                        <span class="badge bg-success">Activa</span>
                                    @else
                                        <span class="badge bg-secondary">Inactiva</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('clinica.sedes', array_filter(['editar' => $sede->id, 'todas' => $verTodas ? 1 : null])) }}">
                                        <i class="bi bi-pencil"></i> Editar
                                    </a>
                                    {{-- La baja es desactivar: la cita guarda `medical_center_id` y el
                                         historial tiene que poder seguir leyendo el lugar. --}}
                                    <form method="POST" action="{{ route('clinica.sedes.activar', $sede->id) }}"
                                          class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="ver_todas" value="{{ $verTodas ? 1 : 0 }}">
                                        <button class="btn btn-sm {{ $sede->activo ? 'btn-outline-danger' : 'btn-outline-primary' }}">
                                            @if ($sede->activo)
                                                <i class="bi bi-slash-circle"></i> Desactivar
                                            @else
                                                <i class="bi bi-check-circle"></i> Activar
                                            @endif
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
