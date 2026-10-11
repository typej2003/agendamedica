@extends('clinica.layout')

@section('titulo', 'Elegir consultorio')

@section('contenido')
    <x-ui.page-head titulo="¿Con qué consultorio vas a trabajar?" subtitulo="Se elige una vez y se puede cambiar cuando quieras." />

    @if ($vm->sinAcceso())
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <div>
                Tu cuenta todavía no tiene acceso a los datos de ningún médico.
                Pídele a un administrador que te asigne un <strong>reg_medico</strong> (panel → Usuarios → Registros de datos).
            </div>
        </div>
    @endif

    <div class="row g-3">
        @foreach ($vm->medicos() as $medico)
            <div class="col-12 col-lg-6">
                <x-ui.tarjeta class="h-100">
                    <x-ui.identidad :nombre="$medico['nombre']"
                                    :detalle="'Registro ' . $medico['regMedico'] . ($medico['licencia'] ? ' · Licencia ' . $medico['licencia'] : '')"
                                    class="mb-3" />

                    <form method="POST" action="{{ $vm->urlGuardar() }}">
                        @csrf
                        <input type="hidden" name="reg_medico" value="{{ $medico['regMedico'] }}">

                        <x-ui.campo etiqueta="Especialidad" for="especialidad-{{ $medico['regMedico'] }}" class="mb-3">
                            <select name="specialty_id" id="especialidad-{{ $medico['regMedico'] }}" class="form-select">
                                @forelse ($medico['especialidades'] as $especialidad)
                                    <option value="{{ $especialidad['valor'] }}">{{ $especialidad['texto'] }}</option>
                                @empty
                                    <option value="">(el médico no tiene especialidad asignada)</option>
                                @endforelse
                            </select>
                        </x-ui.campo>

                        {{-- El campo guarda el **consultorio** del médico (`offices`), no la sede
                             (`medical_centers`): se etiqueta por lo que es, porque el rótulo "Sede" hacía
                             buscar una pantalla para asignarle una sede al médico (2026-10-11). Vacío =
                             todos sus consultorios: la agenda muestra las sedes juntas. --}}
                        <x-ui.campo etiqueta="Consultorio" for="sede-{{ $medico['regMedico'] }}" class="mb-3">
                            <select name="office_id" id="sede-{{ $medico['regMedico'] }}" class="form-select">
                                <option value="">Todos mis consultorios (sin especificar)</option>
                                @foreach ($medico['sedes'] as $sede)
                                    <option value="{{ $sede['valor'] }}">{{ $sede['texto'] }}</option>
                                @endforeach
                            </select>
                            @if ($medico['sinConsultorios'])
                                <span class="form-text d-block">
                                    Este médico todavía no tiene consultorios cargados: cárgalos en
                                    <a href="{{ $vm->urlConsultorios() }}">Consultorios</a> (si el lugar todavía no
                                    existe, primero la <a href="{{ $vm->urlSedes() }}">sede</a>).
                                </span>
                            @else
                                <span class="form-text d-block">
                                    El consultorio es dónde atiende el médico dentro de una sede. ¿Falta uno o quieres
                                    cambiarlo? <a href="{{ $vm->urlConsultorios() }}">Consultorios</a>.
                                </span>
                            @endif
                        </x-ui.campo>

                        <button class="btn btn-primary">Trabajar aquí</button>
                    </form>
                </x-ui.tarjeta>
            </div>
        @endforeach
    </div>
@endsection
