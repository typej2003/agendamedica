@extends('clinica.layout')

@section('titulo', 'Pacientes')

@section('contenido')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h4 mb-0">Pacientes</h1>
        <form class="d-flex gap-2" method="GET" action="{{ route('clinica.pacientes') }}">
            <input type="search" name="buscar" value="{{ $buscar }}" class="form-control form-control-sm"
                   placeholder="Nombre, cédula o N.º de historia" style="min-width: 260px;">
            <button class="btn btn-sm btn-primary">Buscar</button>
            @if ($buscar !== '')
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('clinica.pacientes') }}">Limpiar</a>
            @endif
        </form>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Paciente</th>
                    <th>Cédula</th>
                    <th>N.º historia</th>
                    <th>Nacimiento</th>
                    <th>Edad</th>
                    <th>Teléfono</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($pacientes as $paciente)
                    <tr>
                        <td>{{ trim($paciente->apellidos . ', ' . $paciente->nombres) }}</td>
                        <td>{{ $paciente->cedula ?: '—' }}</td>
                        <td>
                            @if ($paciente->numhistoria)
                                {{ $paciente->numhistoria }}
                            @else
                                <span class="badge bg-secondary">Sin historia</span>
                            @endif
                        </td>
                        <td>{{ \App\Support\FechaClinica::formato($paciente->fnacimiento) }}</td>
                        <td>{{ \App\Support\FechaClinica::edad($paciente->fnacimiento) ?? '—' }}</td>
                        <td>{{ $paciente->telefono ?: '—' }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary"
                               href="{{ route('clinica.pacientes.ver', $paciente->id) }}">Ver ficha</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            {{ $buscar !== '' ? 'Ningún paciente coincide con la búsqueda.' : 'Este consultorio todavía no tiene pacientes.' }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $pacientes->onEachSide(1)->links() }}
    </div>
@endsection
