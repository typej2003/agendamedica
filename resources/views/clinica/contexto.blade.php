@extends('clinica.layout')

@section('titulo', 'Elegir consultorio')

@section('contenido')
    @php
        // Si no hay nada que elegir, el encabezado no pregunta: lo dice (2026-10-19).
        $hayQueElegir = $vm->hayAlgoQueElegir();
        $titulo = $hayQueElegir ? '¿Con qué consultorio vas a trabajar?' : 'Tu consultorio';
        $subtitulo = $hayQueElegir
            ? 'Se elige una vez y se puede cambiar cuando quieras.'
            : 'Ya está configurado: se entra con lo que ya tienes.';
    @endphp

    <x-ui.page-head :titulo="$titulo" :subtitulo="$subtitulo" />

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

                        {{-- Con una sola especialidad no hay nada que elegir: se entra con esa y se muestra
                             cuál es. El valor viaja igual, en un campo oculto, para que el contexto quede
                             fijado explícitamente (decisión del usuario, 2026-10-19). --}}
                        @if ($medico['elegirEspecialidad'])
                            <x-ui.campo etiqueta="Especialidad" for="especialidad-{{ $medico['regMedico'] }}" class="mb-3">
                                <select name="specialty_id" id="especialidad-{{ $medico['regMedico'] }}" class="form-select">
                                    @forelse ($medico['especialidades'] as $especialidad)
                                        <option value="{{ $especialidad['valor'] }}">{{ $especialidad['texto'] }}</option>
                                    @empty
                                        <option value="">(el médico no tiene especialidad asignada)</option>
                                    @endforelse
                                </select>
                            </x-ui.campo>
                        @else
                            <input type="hidden" name="specialty_id" value="{{ $medico['especialidadFija']['valor'] }}">
                            <x-ui.campo etiqueta="Especialidad" for="especialidad-{{ $medico['regMedico'] }}" class="mb-3">
                                <output class="form-control-plaintext fw-semibold"
                                        id="especialidad-{{ $medico['regMedico'] }}">{{ $medico['especialidadFija']['texto'] }}</output>
                                <span class="form-text d-block">Es su única especialidad, así que no hay que elegirla.</span>
                            </x-ui.campo>
                        @endif

                        {{-- El campo guarda el **consultorio** del médico (`offices`), no la sede
                             (`medical_centers`): se etiqueta por lo que es, porque el rótulo "Sede" hacía
                             buscar una pantalla para asignarle una sede al médico (2026-10-11). Vacío =
                             todos sus consultorios: la agenda muestra las sedes juntas. Con **un solo**
                             consultorio tampoco se pregunta: se trabaja en él (2026-10-19). --}}
                        @if ($medico['elegirConsultorio'])
                            <x-ui.campo etiqueta="Consultorio" for="sede-{{ $medico['regMedico'] }}" class="mb-3">
                                <select name="office_id" id="sede-{{ $medico['regMedico'] }}" class="form-select">
                                    <option value="">Todos mis consultorios (sin especificar)</option>
                                    @foreach ($medico['sedes'] as $sede)
                                        <option value="{{ $sede['valor'] }}">{{ $sede['texto'] }}</option>
                                    @endforeach
                                </select>
                                <span class="form-text d-block">
                                    El consultorio es dónde atiende el médico dentro de una sede. ¿Falta uno o quieres
                                    cambiarlo? <a href="{{ $vm->urlConsultorios() }}">Consultorios</a>.
                                </span>
                            </x-ui.campo>
                        @elseif ($medico['sinConsultorios'])
                            {{-- Sin consultorios no hay nada que elegir, pero sí un camino: darlos de alta. --}}
                            <x-ui.campo etiqueta="Consultorio" for="sede-{{ $medico['regMedico'] }}" class="mb-3">
                                <output class="form-control-plaintext fw-semibold"
                                        id="sede-{{ $medico['regMedico'] }}">Sin consultorios</output>
                                <span class="form-text d-block">
                                    Este médico todavía no tiene consultorios cargados: cárgalos en
                                    <a href="{{ $vm->urlConsultorios() }}">Consultorios</a> (si el lugar todavía no
                                    existe, primero la <a href="{{ $vm->urlSedes() }}">sede</a>).
                                </span>
                            </x-ui.campo>
                        @else
                            <input type="hidden" name="office_id" value="{{ $medico['consultorioFijo']['valor'] }}">
                            <x-ui.campo etiqueta="Consultorio" for="sede-{{ $medico['regMedico'] }}" class="mb-3">
                                <output class="form-control-plaintext fw-semibold"
                                        id="sede-{{ $medico['regMedico'] }}">{{ $medico['consultorioFijo']['texto'] }}</output>
                                <span class="form-text d-block">
                                    Es su único consultorio, así que no hay que elegirlo. ¿Quieres cambiarlo?
                                    <a href="{{ $vm->urlConsultorios() }}">Consultorios</a>.
                                </span>
                            </x-ui.campo>
                        @endif

                        <button class="btn btn-primary">Trabajar aquí</button>
                    </form>
                </x-ui.tarjeta>
            </div>
        @endforeach
    </div>
@endsection
