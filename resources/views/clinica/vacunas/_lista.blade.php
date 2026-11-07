{{-- **La lista de vacunas del paciente** (O-68/O-69): las filas de `vacuna_paciente` del niño —lo aplicado y lo programado—,
     como la solapa *Inmunizaciones* del escritorio (`d_vacunas_consulta_dos.srd`). Es la pantalla principal: el esquema del
     catálogo quedó abajo, plegado, y sólo propone.

     Una fila = una dosis. La columna «F. a aplicar» es `fecha_planeada` y la «F. aplicada» es `fecha_ejecutada`, pero ésta
     sólo se muestra cuando `ejecutada = 'S'`: el escritorio guarda en esa columna el día en que cargó la fila planeada. La
     segunda línea (vía, zona, vence, próxima) son datos de la fila, no cálculos. «Atrasada» no existe acá: eso es del panel.

     Recibe `$vm` (CalendarioDeVacunasVista), que trae las filas ya armadas y las URLs. --}}
<x-ui.tarjeta id="vacunas-del-paciente" titulo="Vacunas" icono="bi-shield-plus">
    <x-slot name="acciones">
        @if ($vm->puedeEscribir())
            {{-- «Agregar una vacuna» (el *Buscar* del escritorio): abre el catálogo del médico para elegir **vacuna + dosis** en
                 el diálogo compartido (`_dialogo-registrar`). Si el consultorio no tiene catálogo, no hay qué elegir: la
                 tarjeta de «sin catálogo» del panel lo explica. --}}
            @unless ($vm->sinCatalogoParaAgregar())
                <button type="button" class="btn btn-primary btn-sm" data-agregar-vacuna>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar una vacuna
                </button>
            @endunless
        @endif
    </x-slot>

    @if ($vm->resumenDeLaLista() !== [])
        <div class="d-flex flex-wrap gap-2 mb-3" id="vacunas-lista-resumen">
            @foreach ($vm->resumenDeLaLista() as $r)
                <x-ui.estado :tono="$r['tono']" :icono="$r['icono']">{{ $r['texto'] }}</x-ui.estado>
            @endforeach
        </div>
    @endif

    @unless ($vm->hayFilas())
        @php $vacio = $vm->vacioDeLaLista(); @endphp
        <x-ui.vacio icono="bi-capsule" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
    @else
        <x-ui.tabla class="table align-middle mb-0 vacunas-lista">
            <thead>
                <tr>
                    <th scope="col">Vacuna</th>
                    <th scope="col">Dosis</th>
                    <th scope="col">Lote</th>
                    <th scope="col">F. a aplicar</th>
                    <th scope="col">F. aplicada</th>
                    <th scope="col">Estado</th>
                    <th scope="col" class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($vm->filas() as $fila)
                    @php
                        $aplicada = $fila['estado'] === \App\Clinica\Vacunas\ListaDeVacunasDelPaciente::APLICADA;
                        // La segunda línea de la fila del escritorio (`d_vacunas_consulta_dos.srd:41-50`), con su rótulo
                        // porque acá no hay una columna para cada dato.
                        $detalle = array_values(array_filter([
                            $fila['via'] ? 'Vía ' . $fila['via'] : null,
                            $fila['zona'] ? 'Zona ' . $fila['zona'] : null,
                            $fila['vence'] ? 'Vence ' . $fila['vence'] : null,
                            $fila['proxima'] ? 'Próxima ' . $fila['proxima'] : null,
                        ]));
                    @endphp

                    <tr>
                        <td class="celda-principal">
                            <span class="d-block fw-semibold">{{ $fila['vacuna'] }}</span>
                            @if ($fila['tipo'])
                                <span class="d-block small texto-tenue">{{ $fila['tipo'] }}</span>
                            @endif
                            @unless ($fila['enCatalogo'])
                                {{-- Aplicada en otro lado o de un catálogo que cambió: no se pierde, se avisa. --}}
                                <x-ui.estado tono="neutro" icono="bi-box">Ya no está en tu catálogo</x-ui.estado>
                            @endunless

                            @if ($detalle !== [])
                                <span class="vacuna-detalle d-block small texto-tenue">{{ implode(' · ', $detalle) }}</span>
                            @endif
                            @if ($fila['notas'])
                                <span class="vacuna-nota d-block small texto-tenue">Notas: {{ $fila['notas'] }}</span>
                            @endif
                        </td>
                        <td data-etiqueta="Dosis">{{ $fila['dosis'] }}</td>
                        <td data-etiqueta="Lote">{{ $fila['lote'] ?? '—' }}</td>
                        <td data-etiqueta="F. a aplicar">{{ $fila['planeada'] ?? '—' }}</td>
                        <td data-etiqueta="F. aplicada">{{ $fila['aplicada'] ?? '—' }}</td>
                        <td data-etiqueta="Estado">
                            <x-ui.estado :tono="$aplicada ? 'exito' : 'aviso'" :icono="$aplicada ? 'bi-check-circle' : 'bi-calendar-event'">
                                {{ $fila['rotulo'] }}
                            </x-ui.estado>
                        </td>
                        <td class="text-end celda-acciones">
                            @if (! $aplicada && $vm->puedeEscribir())
                                <x-ui.acciones-fila :etiqueta="'Acciones de ' . $fila['vacuna'] . ' ' . $fila['dosis']">
                                    {{-- «Aplicar» abre el mismo diálogo que «Agregar», ya lleno con esta dosis: al guardar, la
                                         Action completa esta fila planeada en el lugar (no crea otra). --}}
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-registrar-dosis
                                            data-codigo="{{ $fila['codigo'] }}" data-numero="{{ $fila['numero'] }}"
                                            data-rotulo="{{ $fila['dosis'] }}" data-planeada="{{ $fila['planeadaIso'] }}"
                                            data-nombre="{{ $fila['vacuna'] }}"
                                            aria-label="Aplicar {{ $fila['dosis'] }} de {{ $fila['vacuna'] }}">
                                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Aplicar
                                    </button>

                                    @if ($fila['urlQuitar'])
                                        {{-- El «Quitar registro» del escritorio: sólo para lo programado. Una aplicada es
                                             historia clínica y la Action la rechaza. --}}
                                        <form method="POST" action="{{ $fila['urlQuitar'] }}"
                                              data-confirmar="¿Quitar «{{ $fila['vacuna'] }} · {{ $fila['dosis'] }}» de la lista? No se puede deshacer.">
                                            @csrf
                                            @method('DELETE')
                                            @if ($vm->consulta())
                                                <input type="hidden" name="consulta" value="{{ $vm->consulta() }}">
                                            @endif
                                            <button class="btn btn-sm btn-outline-danger" title="Quitar de la lista"
                                                    aria-label="Quitar {{ $fila['dosis'] }} de {{ $fila['vacuna'] }} de la lista">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    @endif
                                </x-ui.acciones-fila>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.tabla>
    @endunless
</x-ui.tarjeta>
