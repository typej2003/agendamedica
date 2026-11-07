{{-- **El esquema del catálogo**, plegado (O-68/O-69): las dosis que el catálogo del médico le propone a este niño, con su «le
     toca» y su estado (aplicada · atrasada · próxima · pendiente · sin fecha), y un botón para **programar** cada una en la lista
     del paciente. Alexander: «quizás se pueda reciclar en vez de quitar» — el calendario no se tiró, se recicló como panel.

     **Nada se inserta solo**: el esquema propone, el médico aprieta «Programar» y recién ahí aparece la fila. El estado
     «atrasada» vive **sólo acá adentro**: nunca es el estado de una fila del paciente.

     Es **el mismo** en la pantalla del módulo (`/clinica/pacientes/{paciente}/vacunas`) y en la sección *Inmunizaciones* de la
     consulta (`?seccion=inmunizaciones`, O-63). Lo único que cambia son las URLs, y las pone el ViewModel. Recibe `$vm`. --}}
@if ($vm->sinCatalogo())
    @php $vacio = $vm->vacioSinCatalogo(); @endphp
    <x-ui.tarjeta id="vacunas-sin-catalogo">
        <x-ui.vacio icono="bi-capsule" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
    </x-ui.tarjeta>
@else
    <details class="vacunas-esquema" id="vacunas-calendario">
        <summary class="vacunas-esquema-titulo">
            <span class="card-titulo"><i class="bi bi-list-check" aria-hidden="true"></i> Esquema del catálogo</span>
            <span class="vacunas-esquema-ayuda texto-tenue small">
                Lo que propone tu catálogo · nada se guarda si no lo programas
            </span>
            <i class="bi bi-chevron-right vacunas-esquema-flecha" aria-hidden="true"></i>
        </summary>

        <div class="vacunas-esquema-cuerpo">
            @if ($vm->sinFechaDeNacimiento())
                <x-ui.estado tono="aviso" icono="bi-info-circle" class="mb-3" id="vacunas-sin-nacimiento">
                    Falta la fecha de nacimiento en la ficha: sin ella no se puede calcular cuándo le toca cada dosis.
                </x-ui.estado>
            @endif

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div class="d-flex flex-wrap gap-2" id="vacunas-resumen">
                    @foreach ($vm->resumen() as $r)
                        <x-ui.estado :tono="$r['tono']" :icono="$r['icono']">{{ $r['cantidad'] }} {{ mb_strtolower($r['texto']) }}{{ $r['cantidad'] === 1 ? '' : 's' }}</x-ui.estado>
                    @endforeach
                </div>
                <x-ui.segmentado :opciones="$vm->filtros()" etiqueta="Dosis" />
            </div>

            @if (! $vm->hayAlgoQueMostrar())
                @php $vacio = $vm->vacioDelFiltro(); @endphp
                <x-ui.tarjeta id="vacunas-vacio">
                    <x-ui.vacio icono="bi-capsule" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
                </x-ui.tarjeta>
            @else
                <x-ui.tabla class="table align-middle mb-0 vacunas-tabla">
                    <thead>
                        <tr>
                            <th scope="col">Vacuna</th>
                            <th scope="col">Dosis</th>
                            <th scope="col">Le toca</th>
                            <th scope="col">Aplicada</th>
                            <th scope="col">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($vm->vacunas() as $vacuna)
                            @if ($vacuna['dosis'] === [])
                                <tr>
                                    <th scope="row" class="fw-semibold">{{ $vacuna['vacuna'] }}</th>
                                    <td colspan="4" class="texto-tenue">Sin edad definida en el catálogo.</td>
                                </tr>
                            @endif
                            @foreach ($vacuna['dosis'] as $i => $d)
                                <tr>
                                    @if ($i === 0)
                                        <th scope="row" rowspan="{{ count($vacuna['dosis']) }}" class="fw-semibold">
                                            {{ $vacuna['vacuna'] }}
                                            <span class="d-block small texto-tenue fw-normal">{{ $vacuna['tipo'] }}@if ($vacuna['cobertura']) · {{ $vacuna['cobertura'] }}@endif</span>
                                        </th>
                                    @endif
                                    <td data-etiqueta="Dosis">{{ $d['rotulo'] }}</td>
                                    <td data-etiqueta="Le toca">
                                        {{ $d['planeada'] ?? '—' }}
                                        @if ($d['vence'])
                                            {{-- La ventana de la vacuna (`vacunas.edad_final_meses`): pasada esa fecha la dosis ya
                                                 no se recuerda y no se ofrece para programar. --}}
                                            <span class="d-block small texto-tenue">{{ $d['clave'] === \App\Clinica\Vacunas\CalendarioDeVacunas::VENCIDA ? 'Vencía el' : 'Hasta el' }} {{ $d['vence'] }}</span>
                                        @endif
                                    </td>
                                    <td data-etiqueta="Aplicada">
                                        {{ $d['aplicada'] ?? '—' }}
                                        @if ($d['lote'])
                                            <span class="d-block small texto-tenue">Lote {{ $d['lote'] }}</span>
                                        @endif
                                    </td>
                                    <td data-etiqueta="Estado">
                                        <x-ui.estado :tono="$d['tono']" :icono="$d['icono']">{{ $d['estado'] }}</x-ui.estado>
                                        @if ($d['registrable'])
                                            <button type="button" class="btn btn-sm btn-outline-primary ms-1" data-registrar-dosis
                                                    data-codigo="{{ $d['codigo'] }}" data-numero="{{ $d['numero'] }}" data-rotulo="{{ $d['rotulo'] }}"
                                                    data-planeada="{{ $d['planeadaIso'] }}" data-nombre="{{ $d['nombre'] }}"
                                                    aria-label="Registrar {{ $d['rotulo'] }} de {{ $d['nombre'] }}">
                                                <i class="bi bi-plus-lg" aria-hidden="true"></i> Registrar
                                            </button>
                                        @endif

                                        @if ($d['programable'])
                                            {{-- **Programar** (O-68/O-69): la fila planeada con la fecha que le toca. Un click y
                                                 aparece en la lista del paciente; sin esto el esquema no escribe nada. --}}
                                            <form method="POST" action="{{ $vm->urlProgramar() }}" class="d-inline">
                                                @csrf
                                                <input type="hidden" name="codi_vacuna" value="{{ $d['codigo'] }}">
                                                <input type="hidden" name="dosis_numero" value="{{ $d['numero'] }}">
                                                <input type="hidden" name="rotulo" value="{{ $d['rotulo'] }}">
                                                <input type="hidden" name="planeada" value="{{ $d['planeadaIso'] }}">
                                                @if ($vm->consulta())
                                                    <input type="hidden" name="consulta" value="{{ $vm->consulta() }}">
                                                @endif
                                                <button class="btn btn-sm btn-outline-secondary ms-1"
                                                        aria-label="Programar {{ $d['rotulo'] }} de {{ $d['nombre'] }} para el {{ $d['planeada'] }}">
                                                    <i class="bi bi-calendar-plus" aria-hidden="true"></i> Programar
                                                </button>
                                            </form>
                                        @elseif ($d['enLista'] && $d['estado'] !== \App\Clinica\Vacunas\CalendarioDeVacunas::APLICADA)
                                            <span class="small texto-tenue">Ya está en la lista</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </x-ui.tabla>
            @endif

            @if ($vm->otras() !== [])
                <div id="vacunas-otras" class="mt-3">
                    <h3 class="card-titulo"><i class="bi bi-clipboard2-check" aria-hidden="true"></i> Otras vacunas aplicadas</h3>
                    <p class="texto-tenue small mb-1">Se le aplicaron, pero ya no están en el catálogo de este consultorio.</p>
                    <ul class="list-unstyled mb-0">
                        @foreach ($vm->otras() as $otra)
                            <li>{{ $otra['vacuna'] }}@if ($otra['rotulo']) · {{ $otra['rotulo'] }}@endif · {{ $otra['aplicada'] ?? '—' }}@if ($otra['lote']) · lote {{ $otra['lote'] }}@endif</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </details>
@endif
