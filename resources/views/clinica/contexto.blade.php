@extends('clinica.layout')

@section('titulo', 'Elegir consultorio')

@section('contenido')
    <h1 class="h4 mb-3">¿Con qué consultorio vas a trabajar?</h1>

    @if (empty($disponibles))
        <div class="alert alert-warning">
            Tu cuenta todavía no tiene acceso a los datos de ningún médico.
            Pedile a un administrador que te asigne un <strong>reg_medico</strong> (panel → Usuarios → Registros de datos).
        </div>
    @endif

    <div class="row g-3">
        @foreach ($disponibles as $regMedico => $acceso)
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h6 mb-1">
                            {{ trim(($acceso['medico']->prefix ?? '') . ' ' . $acceso['medico']->name . ' ' . $acceso['medico']->lastname) }}
                        </h2>
                        <p class="text-muted small mb-3">
                            Registro <code>{{ $regMedico }}</code>
                            @if ($acceso['medico']->license_number)
                                · Licencia {{ $acceso['medico']->license_number }}
                            @endif
                        </p>

                        <form method="POST" action="{{ route('clinica.contexto.guardar') }}">
                            @csrf
                            <input type="hidden" name="reg_medico" value="{{ $regMedico }}">

                            <div class="mb-2">
                                <label class="form-label small mb-1">Especialidad</label>
                                <select name="specialty_id" class="form-select form-select-sm">
                                    @forelse ($acceso['especialidades'] as $especialidad)
                                        <option value="{{ $especialidad->id }}">{{ $especialidad->name }}</option>
                                    @empty
                                        <option value="">(el médico no tiene especialidad asignada)</option>
                                    @endforelse
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small mb-1">Sede</label>
                                <select name="office_id" class="form-select form-select-sm">
                                    <option value="">Todas / sin especificar</option>
                                    @foreach ($acceso['sedes'] as $sede)
                                        <option value="{{ $sede->id }}">
                                            {{ $sede->medicalCenter->name ?? 'Sede' }}@if ($sede->office_number) · {{ $sede->office_number }}@endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <button class="btn btn-sm btn-primary">Trabajar acá</button>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
