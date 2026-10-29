{{-- La franja de la próxima cita de la sección de récipes (WEB-5.2): lo que saldrá al pie de la hoja y las dos
     formas de agendarla. Recibe `$seccion` (SeccionDeRecipesVista o SeccionDeRecipeDeLaParejaVista). Sin la
     impresión activa no se muestra.

     2026-10-28: además de «Hacer cita» —el formulario completo de la agenda, que vuelve acá al guardar— la franja
     ofrece **agendar en el lugar** con los tres datos que de verdad hacen falta (día, hora si la sede agenda por
     hora, y motivo). No usa JavaScript: es un `<details>` con un `<form>` normal que postea al alta de siempre
     (`clinica.agenda.crear`), así que la cita pendiente, la jornada y el número se resuelven donde siempre. --}}
@if ($seccion->puedeImprimir())
    @php $agendar = $seccion->agendar(); @endphp

    <div class="recipe-proxima-cita-franja" id="recipe-proxima-cita">
        <p class="recipe-proxima-cita-texto mb-0">
            <i class="bi {{ $seccion->tieneProximaCita() ? 'bi-calendar-check' : 'bi-calendar-x' }} texto-tenue" aria-hidden="true"></i>
            <span>{{ $seccion->textoDeLaProximaCita() }}</span>
        </p>

        <div class="recipe-proxima-cita-acciones">
            @if ($agendar !== null && $agendar->puedeAgendar())
                <details class="recipe-agendar">
                    <summary class="btn btn-sm btn-primary">
                        <i class="bi bi-calendar-plus" aria-hidden="true"></i> Agendar aquí
                    </summary>

                    <form method="POST" action="{{ $agendar->url() }}" class="recipe-agendar-form">
                        @csrf
                        {{-- La marca que le dice al request que esta cita es del récipe: de mañana en adelante. --}}
                        <input type="hidden" name="origen" value="proxima-cita">
                        <input type="hidden" name="paciente_id" value="{{ $agendar->pacienteId() }}">
                        <input type="hidden" name="volver_consulta" value="{{ $agendar->consultaId() }}">

                        {{-- Si la validación falló, el primer aviso se muestra acá: la consulta no pinta `$errors`
                             (los errores de sus secciones viajan por el `estado` del armazón). --}}
                        @if (old('origen') === 'proxima-cita' && $errors->any())
                            <p class="alert alert-danger py-2 mb-2">{{ $errors->first() }}</p>
                        @endif

                        <div class="row g-2 align-items-start">
                            <div class="col-sm-4 col-lg-3">
                                <x-ui.campo etiqueta="Día" for="proxima-cita-fecha" requerido>
                                    <input type="date" id="proxima-cita-fecha" name="fecha" class="form-control"
                                           min="{{ $agendar->fechaMinima() }}"
                                           value="{{ old('fecha', $agendar->fechaMinima()) }}" required>
                                </x-ui.campo>
                            </div>

                            @if ($agendar->mostrarSede())
                                <div class="col-sm-4 col-lg-3">
                                    <x-ui.campo etiqueta="Sede" for="proxima-cita-sede">
                                        <select id="proxima-cita-sede" name="sede" class="form-select">
                                            @foreach ($agendar->opcionesDeSede() as $opcion)
                                                <option value="{{ $opcion['valor'] }}" {{ (string) $agendar->sedeElegida() === (string) $opcion['valor'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                                            @endforeach
                                        </select>
                                    </x-ui.campo>
                                </div>
                            @elseif ($agendar->sedeElegida() !== null)
                                {{-- Con una sola sede no se pregunta: viaja oculta (la cita siempre es en una sede). --}}
                                <input type="hidden" name="sede" value="{{ $agendar->sedeElegida() }}">
                            @endif

                            @if ($agendar->pideHora())
                                <div class="col-sm-4 col-lg-2">
                                    <x-ui.campo etiqueta="Hora" for="proxima-cita-hora" :requerido="$agendar->horaObligatoria()"
                                                :ayuda="$agendar->ayudaDeLaHora()">
                                        <x-ui.hora name="hora" id="proxima-cita-hora" :required="$agendar->horaObligatoria()" />
                                    </x-ui.campo>
                                </div>
                            @endif

                            <div class="col-sm-4 col-lg-3">
                                <x-ui.campo etiqueta="Motivo" for="proxima-cita-motivo">
                                    <select id="proxima-cita-motivo" name="motivo" class="form-select">
                                        <option value="">Sin motivo</option>
                                        @foreach ($agendar->opcionesDeMotivo() as $opcion)
                                            <option value="{{ $opcion['valor'] }}" {{ old('motivo') === $opcion['valor'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.campo>
                            </div>

                            <div class="col-sm-auto pt-4">
                                <button class="btn btn-primary">
                                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Agendar
                                </button>
                            </div>
                        </div>

                        <p class="form-text mb-0 mt-2">
                            La cita sale al pie de la hoja como
                            <strong>«Próxima cita: {{ \App\Support\Regionalizacion::actual()->patronDeTecleo() }}»</strong>.
                            Si el paciente ya tiene una cita pendiente, se te va a preguntar si moverla.
                        </p>
                    </form>
                </details>
            @endif

            <a class="btn btn-sm btn-outline-secondary" href="{{ $seccion->urlHacerCita() }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Hacer cita
            </a>
        </div>
    </div>
@endif
