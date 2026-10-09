@extends('clinica.layout')

@section('titulo', 'Inicio')

@section('contenido')
    <h1 class="h4 mb-3">Consultorio</h1>

    @if ($sinEspecialidad)
        <div class="alert alert-warning">
            Este médico todavía no tiene una especialidad asignada, así que no hay módulos para mostrar.
            Se asigna desde el panel de administración.
        </div>
    @endif

    @if ($modulos->isEmpty())
        <div class="card">
            <div class="card-body text-muted">
                No hay módulos habilitados en este consultorio todavía.
                El menú se va llenando a medida que cada módulo se construye.
            </div>
        </div>
    @else
        <div class="row g-3">
            @foreach ($modulos as $modulo)
                @php $rutaModulo = 'clinica.' . $modulo->slug; @endphp
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6">{{ $modulo->nombre }}</h2>
                            @if (\Illuminate\Support\Facades\Route::has($rutaModulo))
                                <a class="stretched-link small" href="{{ route($rutaModulo) }}">Abrir</a>
                            @else
                                <span class="small text-muted">Pantalla en construcción</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
