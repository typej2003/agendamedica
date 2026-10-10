@extends('clinica.layout')

@section('titulo', 'Inicio')

@section('contenido')
    <x-ui.page-head titulo="Consultorio" :subtitulo="$vm->subtitulo()" />

    @if ($vm->sinEspecialidad())
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <div>
                Este médico todavía no tiene una especialidad asignada, así que no hay módulos para mostrar.
                Se asigna desde el panel de administración.
            </div>
        </div>
    @endif

    @if ($vm->sinModulos())
        <x-ui.tarjeta>
            <x-ui.vacio icono="bi-grid" titulo="No hay módulos habilitados en este consultorio todavía.">
                El menú se va llenando a medida que cada módulo se construye.
            </x-ui.vacio>
        </x-ui.tarjeta>
    @else
        <div class="row g-3">
            @foreach ($vm->modulos() as $modulo)
                <div class="col-12 col-md-6 col-xl-4">
                    <x-ui.tarjeta class="h-100 modulo-acceso">
                        <div class="d-flex gap-3 align-items-center">
                            <span class="stat-icono modulo-icono"><i class="bi {{ $modulo['icono'] }}" aria-hidden="true"></i></span>
                            <div class="flex-grow-1">
                                <h2 class="modulo-nombre">{{ $modulo['nombre'] }}</h2>
                                @if ($modulo['url'])
                                    <a class="small fw-semibold stretched-link" href="{{ $modulo['url'] }}">Abrir</a>
                                @else
                                    <span class="small texto-tenue">Pantalla en construcción</span>
                                @endif
                            </div>
                        </div>
                    </x-ui.tarjeta>
                </div>
            @endforeach
        </div>
    @endif
@endsection
