@extends('clinica.layout')

@section('titulo', 'Inicio')

@section('contenido')
    <div class="page-head">
        <div>
            <h1>Consultorio</h1>
            <p class="page-head-sub">
                {{ $contexto['medico']->prefix ?? '' }} {{ $contexto['medico']->name ?? '' }} {{ $contexto['medico']->lastname ?? '' }}
                @if (! empty($contexto['specialty']))
                    · {{ $contexto['specialty']->name }}
                @endif
            </p>
        </div>
    </div>

    @if ($sinEspecialidad)
        <div class="alert alert-warning d-flex gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>
                Este médico todavía no tiene una especialidad asignada, así que no hay módulos para mostrar.
                Se asigna desde el panel de administración.
            </div>
        </div>
    @endif

    @if ($modulos->isEmpty())
        <div class="card">
            <div class="empty-state">
                <i class="bi bi-grid"></i>
                <p>No hay módulos habilitados en este consultorio todavía.</p>
                <p class="small">El menú se va llenando a medida que cada módulo se construye.</p>
            </div>
        </div>
    @else
        <div class="row g-3">
            @foreach ($modulos as $modulo)
                @php
                    $rutaModulo = 'clinica.' . $modulo->slug;
                    $disponible = \Illuminate\Support\Facades\Route::has($rutaModulo);
                @endphp
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card h-100">
                        <div class="card-body d-flex gap-3">
                            <span class="stat-icono" style="background: var(--ds-violeta-100); color: var(--ds-violeta-700);">
                                <i class="bi {{ \App\Support\IconoModulo::para($modulo->slug) }}"></i>
                            </span>
                            <div class="flex-grow-1">
                                <h2 class="h6 mb-1">{{ $modulo->nombre }}</h2>
                                @if ($disponible)
                                    <a class="small fw-semibold stretched-link" href="{{ route($rutaModulo) }}">Abrir</a>
                                @else
                                    <span class="small text-muted">Pantalla en construcción</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
