@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()"
                    :volver="$vm->urlVolver()" :volver-texto="$vm->textoVolver()" />

    {{-- El primer error de validación, arriba: los que no caen en un campo del formulario —elegir la sede
         cuando el alta llega desde la agenda con `sede=todas`, por ejemplo— no se verían de otra forma y
         el formulario parecería no guardar. Mismo patrón que las otras pantallas migradas. --}}
    <x-ui.errores />

    {{-- Paso 1: dónde y cuándo. Es un GET sobre esta misma pantalla porque la sede y el día son lo que define
         la jornada (modalidad, cupo y qué número le toca): con eso resuelto el servidor arma el resto del
         formulario. Cambiar cualquiera de los dos recarga y recalcula. --}}
    <form method="GET" action="{{ $vm->urlFiltro() }}">
        {{-- El paciente ya elegido no se pierde al cambiar la sede o el día: este paso recarga la pantalla
             y el elegido viaja en la URL (si no, el buscador de la agenda mandaría a elegirlo dos veces). --}}
        @if ($vm->pacienteId())
            <input type="hidden" name="paciente_id" value="{{ $vm->pacienteId() }}">
        @endif
        @if ($vm->volverConsulta())
            <input type="hidden" name="volver_consulta" value="{{ $vm->volverConsulta() }}">
        @endif

        <x-ui.tarjeta class="mb-3">
            <h2 class="visually-hidden">Dónde y cuándo</h2>

            <div class="row g-3 align-items-end">
                <div class="col-md-5 col-xl-3">
                    <label class="form-label" for="sede-elegida">Sede</label>
                    {{-- `@disabled` es de Blade 9: en Laravel 8 se escribe el atributo. --}}
                    <select name="sede" id="sede-elegida" class="form-select" data-autoenviar
                            @if ($vm->sinSedes()) disabled @endif>
                        @foreach ($vm->opcionesDeSede() as $opcion)
                            <option value="{{ $opcion['valor'] }}" {{ $opcion['elegida'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4 col-xl-2">
                    <label class="form-label" for="fecha-elegida">Día</label>
                    <input type="date" name="fecha" id="fecha-elegida" class="form-control" value="{{ $vm->fecha() }}" data-autoenviar>
                </div>

                <div class="col-md-3 col-xl-2">
                    <button class="btn btn-outline-secondary w-100">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Ver la jornada
                    </button>
                </div>
                @if ($jornada = $vm->jornada())
                <div class="col-12 col-xl-5">
                @php $modalidad = $jornada->modalidad(); @endphp
                <div class="jornada-resumen jornada-resumen-inline">
                    <x-ui.estado :tono="$modalidad['tono']" :icono="$modalidad['icono']">{{ $modalidad['texto'] }}</x-ui.estado>
                    <span>{{ $jornada->horario() }}</span>
                    <span>{{ $jornada->cantidadTexto() }}</span>
                    @if ($jornada->tieneCupo())
                        <span>{{ $jornada->cupoTexto() }}</span>
                    @endif
                    @if ($jornada->llena())
                        <x-ui.estado tono="aviso" icono="bi-exclamation-triangle">Cupo completo</x-ui.estado>
                    @endif
                    <span>le tocaría el {{ $jornada->proximo() }}</span>
                </div>

                </div>
                @endif
            </div>

            @if ($vm->faltaElegirSede())
                <p class="form-text mb-0 mt-2">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                    Elige la sede para ver su jornada y qué número le toca al próximo paciente.
                </p>
            @endif

            {{-- Día no laborable (WEB-2.8): el escritorio **aborta** el agendamiento cuando el día tiene
                 motivo; acá se avisa con el mismo texto y se deja decidir. --}}
            @if ($vm->avisoNoLaborable())
                <div class="alert alert-warning py-2 mt-3 mb-0 d-flex flex-wrap align-items-center gap-2">
                    <i class="bi bi-calendar-x" aria-hidden="true"></i>
                    <span><strong>El {{ $vm->fechaTexto() }} el médico no atiende.</strong> {{ $vm->avisoNoLaborable() }}</span>
                    <a class="ms-auto small" href="{{ $vm->urlNoLaborables() }}">Ver los días no laborables</a>
                </div>
            @endif

            @if ($vm->jornada() && $vm->horaFueraDeBloque())
                <p class="form-text text-warning-emphasis mb-0 mt-2">
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                    La hora elegida no cae en ningún bloque configurado de ese día.
                </p>
            @endif
        </x-ui.tarjeta>
    </form>

    <form method="POST" action="{{ $vm->accion() }}">
        @csrf
        <x-ui.tarjeta sin-relleno>

            {{-- La sede y el día vienen del paso 1 (ahí se recargan y se recalculan). --}}
            <input type="hidden" name="sede" value="{{ $vm->sedeId() }}">
            <input type="hidden" name="fecha" value="{{ $vm->fecha() }}">
            @if ($vm->volverConsulta())
                {{-- "Hacer cita" desde los récipes de una consulta (WEB-5.2): al guardar vuelve a ellos. --}}
                <input type="hidden" name="volver_consulta" value="{{ $vm->volverConsulta() }}">
            @endif

            {{-- La pregunta por la cita pendiente: no se escribió nada todavía, así que decide el usuario con
                 los mismos datos que ya cargó en el formulario. --}}
            @if ($pendiente = $vm->pendiente())
                <div class="paso paso-aviso">
                    <h2 class="paso-titulo"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Ya tiene una cita pendiente</h2>
                    <p class="mb-3">
                        <strong>{{ $pendiente['paciente'] }}</strong> ya tiene cita el {{ $pendiente['fecha'] }}
                        @if ($pendiente['hora']) a las {{ $pendiente['hora'] }} @endif
                        @if ($pendiente['sede']) en {{ $pendiente['sede'] }}@endif.
                        Normalmente un paciente tiene <strong>una sola</strong> cita pendiente por consultorio (los
                        tratamientos —masajistas, terapias— son la excepción: ahí se agendan varias).
                        ¿Qué quieres hacer?
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" name="decision" value="mover">Mover esa cita a la nueva fecha</button>
                        <button class="btn btn-outline-primary" name="decision" value="otra">Agendar otra de todos modos</button>
                        <button class="btn btn-outline-secondary" name="decision" value="cancelar">Cancelar</button>
                    </div>

                    {{-- Si es un tratamiento, la excepción se marca una vez y la pregunta deja de aparecer
                         (WEB-2.17): acá es donde el usuario se entera de que existe. --}}
                    <p class="form-text mb-0 mt-3">
                        Si este motivo se agenda varias veces —masajistas, terapias—,
                        @if ($vm->puedeAdministrarMotivos())
                            <a href="{{ $vm->urlMotivos() }}">márcalo en los motivos de cita</a> y no se vuelve a preguntar.
                        @else
                            pídele al médico que lo marque en sus motivos de cita y no se vuelve a preguntar.
                        @endif
                    </p>
                </div>
            @endif

            {{-- Paciente y cita lado a lado (>= 992 px): todo el formulario cabe sin scroll. --}}
            <div class="pasos-columnas">
            <div class="paso">
                <h2 class="paso-titulo"><span class="paso-numero">2</span> Paciente @if ($vm->puestoDelPaciente() !== null)<span id="puesto-del-paciente">#{{ $vm->puestoDelPaciente() }}</span>@endif</h2>

                @if ($vm->editando())
                    @php $elegido = $vm->paciente(); @endphp
                    <x-ui.identidad :nombre="$elegido['nombre'] ?? 'Paciente'" :detalle="! empty($elegido['numhistoria']) ? 'H. ' . $elegido['numhistoria'] : null" />
                    @if (empty($elegido['numhistoria']))
                        <x-ui.estado tono="aviso" icono="bi-exclamation-triangle" class="mt-2">Sin historia</x-ui.estado>
                    @endif
                    <p class="form-text mb-0 mt-2">El paciente de una cita no se cambia: para otro paciente, agenda una cita nueva.</p>
                    <input type="hidden" name="paciente_id" value="{{ $vm->pacienteId() }}">
                @else
                    <div class="position-relative" id="paciente-busqueda-caja">
                        <label class="form-label" for="paciente-busqueda">Buscar por apellido, cédula o n.º de historia</label>
                        <x-ui.buscador-vivo id="paciente-busqueda" resultados="paciente-resultados"
                                            :valor="$vm->paciente()['nombre'] ?? ''" placeholder="Escribe al menos dos letras"
                                            etiqueta="Buscar paciente" :data-url="$vm->urlBuscarPacientes()" />
                        <input type="hidden" name="paciente_id" id="paciente-id" value="{{ $vm->valor('paciente_id') }}">
                    </div>

                    @error('paciente_id')
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror

                    <p class="form-text mt-2 mb-0" id="paciente-elegido">
                        @if ($elegido = $vm->paciente())
                            Elegido: <strong>{{ $elegido['nombre'] }}</strong>
                            {{ ! empty($elegido['numhistoria']) ? '· H. ' . $elegido['numhistoria'] : '· sin historia' }}
                        @else
                            Todavía no elegiste a nadie.
                        @endif
                    </p>

                    <details class="mt-3" {{ $vm->altaAbierta() ? 'open' : '' }}>
                        <summary class="small">No está en la lista: cargar un paciente nuevo</summary>

                        <div class="row g-3 mt-0">
                            <div class="col-sm-6">
                                <label class="form-label" for="nuevo_cedula">Cédula *</label>
                                <input type="text" name="nuevo_cedula" id="nuevo_cedula" class="form-control" value="{{ $vm->valor('nuevo_cedula') }}">
                                @error('nuevo_cedula') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="nuevo_nombres">Nombres *</label>
                                <input type="text" name="nuevo_nombres" id="nuevo_nombres" class="form-control" value="{{ $vm->valor('nuevo_nombres') }}">
                                @error('nuevo_nombres') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="nuevo_apellidos">Apellidos *</label>
                                <input type="text" name="nuevo_apellidos" id="nuevo_apellidos" class="form-control" value="{{ $vm->valor('nuevo_apellidos') }}">
                                @error('nuevo_apellidos') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="nuevo_telefono">Teléfono</label>
                                <input type="text" name="nuevo_telefono" id="nuevo_telefono" class="form-control" value="{{ $vm->valor('nuevo_telefono') }}">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="nuevo_email">Correo</label>
                                <input type="email" name="nuevo_email" id="nuevo_email" class="form-control" value="{{ $vm->valor('nuevo_email') }}">
                                @error('nuevo_email') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="nuevo_sexo">Sexo</label>
                                <select name="nuevo_sexo" id="nuevo_sexo" class="form-select">
                                    <option value="">Sin dato</option>
                                    <option value="F" {{ $vm->valor('nuevo_sexo', $vm->sexoPorDefectoDelNuevoPaciente()) === 'F' ? 'selected' : '' }}>Femenino</option>
                                    <option value="M" {{ $vm->valor('nuevo_sexo', $vm->sexoPorDefectoDelNuevoPaciente()) === 'M' ? 'selected' : '' }}>Masculino</option>
                                </select>
                            </div>
                        </div>

                        <p class="form-text mb-0 mt-2">
                            Queda <strong>sin número de historia</strong>: lo asigna el sistema cuando se complete la historia.
                            Si la cédula ya existe, se enlaza a esa ficha en vez de duplicarla.
                        </p>
                    </details>
                @endif
            </div>

            <div class="paso">
                <h2 class="paso-titulo"><span class="paso-numero">3</span> La cita</h2>

                <div class="row g-3">
                    @if ($vm->esConHora())
                        <div class="col-sm-6 col-xl">
                            <label class="form-label" for="hora">Hora de la cita *</label>
                            <x-ui.hora name="hora" :valor="$vm->valor('hora')" required />
                            @error('hora') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                    @else
                        <div class="col-sm-6 col-xl">
                            <label class="form-label" for="hora">Hora de llegada</label>
                            <x-ui.hora name="hora" :valor="$vm->valor('hora')" />
                        </div>

                        @if ($vm->mostrarBloques())
                            <div class="col-sm-6 col-xl">
                                <label class="form-label" for="bloque">Bloque del día</label>
                                <select name="bloque" id="bloque" class="form-select">
                                    @foreach ($vm->opcionesDeBloque() as $opcion)
                                        <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    @endif

                    <div class="col-sm-6 col-xl">
                        <label class="form-label" for="motivo">Motivo</label>
                        <select name="motivo" id="motivo" class="form-select" data-motivo-select>
                            <option value="" data-precio="">Sin motivo</option>
                            @foreach ($vm->opcionesDeMotivo() as $opcion)
                                <option value="{{ $opcion['valor'] }}" data-precio="{{ $opcion['precio'] }}"
                                        data-precio-texto="{{ $opcion['precioTexto'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                            @endforeach
                        </select>
                        {{-- Sin motivos para esta sede (WEB-8.1c): se dice y se ofrece crear el primero, que el médico carga en
                             su catálogo con el precio. La secretaría agenda con lo que él cargó. --}}
                        @if ($vm->sinMotivos())
                            <span class="form-text d-block">
                                Esta sede todavía no tiene motivos de cita.
                                @if ($vm->puedeAdministrarMotivos())
                                    <a href="{{ $vm->urlCrearMotivo() }}">Crea el primero</a> con su precio.
                                @else
                                    Pídele al médico que los cargue.
                                @endif
                            </span>
                        @elseif ($vm->puedeAdministrarMotivos())
                            <a class="form-text d-block" href="{{ $vm->urlMotivos() }}">Administrar motivos</a>
                        @endif
                        {{-- La excepción de citas múltiples (WEB-2.17): el motivo marcado no pregunta. Se
                             dice acá para que no parezca que la regla de la cita pendiente se rompió. --}}
                        @if ($vm->motivoAdmiteVarias())
                            <span class="form-text d-block">Admite varias citas pendientes del mismo paciente.</span>
                        @endif
                    </div>

                    <div class="col-sm-6 col-xl">
                        <label class="form-label" for="monto">Monto <span id="monto-obligatorio" @if (! $vm->montoObligatorio()) hidden @endif>*</span></label>
                        <input type="number" step="0.01" min="0" name="monto" id="monto" class="form-control"
                               value="{{ $vm->valor('monto') }}" placeholder="{{ $vm->montoObligatorio() ? 'Sin precio: escríbelo (0 si es gratis)' : $vm->precioDelMotivo() }}"
                               @if ($vm->montoObligatorio()) required @endif>
                        @error('monto') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            </div>{{-- /.pasos-columnas --}}

            <div class="tarjeta-pie justify-content-end">
                <a class="btn btn-outline-secondary" href="{{ $vm->urlVolver() }}">Cancelar</a>
                <button class="btn btn-primary">{{ $vm->botonGuardar() }}</button>
            </div>
        </x-ui.tarjeta>
    </form>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/cita-formulario.js') }}"></script>
@endsection
