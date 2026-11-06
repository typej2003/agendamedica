@extends('clinica.layout')

@section('titulo', 'Elegir consultorio')

@section('contenido')
    @php
        // Si no hay nada que elegir, el encabezado no pregunta: lo dice (2026-10-19).
        $hayQueElegir = $vm->hayAlgoQueElegir();
        $titulo = $hayQueElegir ? '¿Con qué consultorio vas a trabajar?' : 'Tu consultorio';
        $subtitulo = $hayQueElegir
            ? 'Elige de a uno: cuando llegues al consultorio, un click te deja dentro.'
            : 'Ya está configurado: se entra con lo que ya tienes.';
        $pasos = $vm->pasos();
        $medicos = $vm->medicos();
        $variosMedicos = $vm->hayVariosMedicos();
        $medicoElegido = collect($medicos)->firstWhere('id', $vm->medicoActual());
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

    {{-- Los pasos (2026-11-06): cada elección colapsa en una línea con el dato y «Cambiar», y recién entonces
         aparece el paso siguiente. Sin JavaScript se ven todos los pasos a la vez y el consultorio se envía
         igual, con su formulario. --}}
    <div class="contexto-pasos" data-contexto data-medico-actual="{{ $vm->medicoActual() ?? '' }}">

        @if ($variosMedicos)
            <section class="contexto-paso contexto-paso-medico" data-paso-medico
                     @if ($vm->medicoActual() !== null) data-elegido="1" @endif>
                <h2 class="contexto-paso-titulo">
                    <span class="contexto-paso-numero">{{ $pasos['medico'] }}</span>
                    ¿Con quién trabajas?
                </h2>

                <div class="contexto-opciones contexto-opciones-medicos">
                    @foreach ($medicos as $medico)
                        <button type="button" class="contexto-opcion contexto-opcion-medico"
                                data-elegir-medico="{{ $medico['id'] }}"
                                data-nombre="{{ $medico['nombre'] }}"
                                aria-pressed="{{ $medico['actual'] ? 'true' : 'false' }}">
                            <span class="contexto-opcion-cuerpo">
                                <span class="contexto-opcion-texto">
                                    <span class="contexto-opcion-titulo">{{ $medico['nombre'] }}</span>
                                    <span class="contexto-opcion-detalle">
                                        {{ $medico['esCentro'] ? 'Centro médico · ' : '' }}Registro {{ $medico['regMedico'] }}
                                    </span>
                                </span>
                                <span class="contexto-opcion-marca" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></span>
                            </span>
                        </button>
                    @endforeach
                </div>

                <p class="contexto-elegido">
                    <span class="contexto-elegido-texto" data-texto-elegido>{{ $medicoElegido['nombre'] ?? '' }}</span>
                    <button type="button" class="btn btn-link btn-sm contexto-cambiar" data-cambiar>Cambiar</button>
                </p>
            </section>
        @endif

        @foreach ($medicos as $medico)
            <div class="contexto-panel" data-panel-medico="{{ $medico['id'] }}">
                {{-- Con varios médicos cada panel dice de quién es: sin JavaScript se ven todos. --}}
                @if ($variosMedicos)
                    <h2 class="contexto-panel-titulo">{{ $medico['nombre'] }}</h2>
                @endif

                <form method="POST" action="{{ $vm->urlGuardar() }}" class="contexto-form"
                      data-medico="{{ $medico['id'] }}" data-reg-medico="{{ $medico['regMedico'] }}">
                    @csrf
                    {{-- El registro del contexto es **el del consultorio elegido**: el del médico o el del
                         centro donde está ese consultorio. Viaja el del médico y el script lo corrige con el
                         del botón clickeado; el servidor, además, lo resuelve del `office_id`. --}}
                    <input type="hidden" name="reg_medico" value="{{ $medico['regMedico'] }}">

                    @if ($medico['elegirEspecialidad'])
                        <section class="contexto-paso" data-paso="especialidad"
                                 @if ($medico['elegida']) data-elegido="1" @endif>
                            <h2 class="contexto-paso-titulo">
                                <span class="contexto-paso-numero">{{ $pasos['especialidad'] }}</span> Especialidad
                            </h2>

                            <div class="contexto-opciones">
                                @forelse ($medico['especialidades'] as $especialidad)
                                    <label class="contexto-opcion">
                                        <input type="radio" name="specialty_id" value="{{ $especialidad['valor'] }}"
                                               @checked($medico['elegida'] && $medico['elegida']['valor'] === $especialidad['valor'])
                                               required>
                                        <span class="contexto-opcion-cuerpo">
                                            <span class="contexto-opcion-texto">
                                                <span class="contexto-opcion-titulo">{{ $especialidad['texto'] }}</span>
                                            </span>
                                            <span class="contexto-opcion-marca" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></span>
                                        </span>
                                    </label>
                                @empty
                                    <p class="contexto-sin-especialidad">
                                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                        {{ $medico['esCentro'] ? 'El centro todavía no tiene doctores con especialidad.' : 'El médico no tiene especialidad asignada.' }}
                                    </p>
                                @endforelse
                            </div>

                            @if (count($medico['especialidades']) > 0)
                                <p class="contexto-elegido">
                                    <span class="contexto-elegido-texto">{{ $medico['elegida']['texto'] ?? '' }}</span>
                                    <button type="button" class="btn btn-link btn-sm contexto-cambiar" data-cambiar>Cambiar</button>
                                </p>
                            @endif
                        </section>
                    @elseif ($medico['especialidadFija'])
                        <input type="hidden" name="specialty_id" value="{{ $medico['especialidadFija']['valor'] }}">
                        <p class="contexto-fija">
                            <i class="bi bi-mortarboard" aria-hidden="true"></i>
                            <span class="contexto-fija-texto">{{ $medico['especialidadFija']['texto'] }}</span>
                            <span class="contexto-fija-nota">Única especialidad</span>
                        </p>
                    @endif

                    <section class="contexto-paso" data-paso="consultorio">
                        <h2 class="contexto-paso-titulo">
                            <span class="contexto-paso-numero">{{ $pasos['consultorio'] }}</span> Consultorio
                        </h2>

                        @if ($medico['sinConsultorios'])
                            <div class="alert alert-warning d-flex gap-2 mb-0" role="alert">
                                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                <div>
                                    Este médico todavía no tiene consultorios cargados.
                                    <a class="alert-link" href="{{ $vm->urlConsultorios() }}">Cárgalos en «Sedes y consultorios»</a>
                                    para que la agenda pueda armar la jornada.
                                </div>
                            </div>
                        @else
                            @foreach ($medico['grupos'] as $grupo)
                                <div class="contexto-grupo">
                                    @if (count($medico['grupos']) > 1)
                                        <h3 class="contexto-grupo-titulo">{{ $grupo['etiqueta'] }}</h3>
                                    @endif

                                    <div class="contexto-consultorios">
                                        @if ($grupo['todas'])
                                            <button type="submit" name="office_id" value=""
                                                    data-reg-medico="{{ $grupo['todasReg'] }}"
                                                    class="contexto-consultorio contexto-consultorio-todas">
                                                <span class="contexto-consultorio-icono" aria-hidden="true"><i class="bi bi-grid-3x3-gap"></i></span>
                                                <span class="contexto-consultorio-sede">{{ $grupo['todas'] }}</span>
                                            </button>
                                        @endif

                                        @foreach ($grupo['sedes'] as $sede)
                                            <button type="submit" name="office_id" value="{{ $sede['valor'] }}"
                                                    data-reg-medico="{{ $sede['regMedico'] }}"
                                                    class="contexto-consultorio"
                                                    @if ($sede['actual']) aria-current="true" @endif>
                                                <span class="contexto-consultorio-icono" aria-hidden="true"><i class="bi bi-door-closed"></i></span>
                                                <span class="contexto-consultorio-sede">{{ $sede['sede'] }}</span>
                                                <span class="contexto-consultorio-pie">
                                                    @if ($sede['numero'])
                                                        <span class="contexto-consultorio-numero">Consultorio {{ $sede['numero'] }}</span>
                                                    @endif
                                                    @if ($sede['actual'])
                                                        <span class="contexto-consultorio-actual">Actual</span>
                                                    @endif
                                                </span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            <p class="form-text mb-0 mt-3">
                                El consultorio es dónde atiende el médico dentro de una sede. ¿Falta uno o quieres cambiarlo?
                                <a href="{{ $vm->urlConsultorios() }}">Sedes y consultorios</a>.
                            </p>
                        @endif
                    </section>
                </form>
            </div>
        @endforeach
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/contexto.js') }}?v={{ @filemtime(public_path('js/clinica/contexto.js')) }}"></script>
@endsection
