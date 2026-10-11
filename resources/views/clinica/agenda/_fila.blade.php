{{-- Una cita en la lista de la jornada. Recibe `$fila` (FilaDeCitaVista) y `$reordenable`.
     La posición (#7) sale del mapa que se calcula **sobre todo el rango**, no de la lista filtrada: al
     filtrar por sede o por razón el paciente 7 sigue siendo el 7 (regla del móvil). --}}
@php
    $estado = $fila->estado();
    $pago = $fila->pago();
@endphp

<tr class="{{ $fila->clase() }}"
    @if ($fila->arrastrable()) draggable="true" data-id="{{ $fila->id() }}" data-numorden="{{ $fila->numOrden() }}" @endif>
    @if ($reordenable)
        <td class="celda-arrastre" title="Arrastrar para reordenar">
            @if ($fila->arrastrable())
                <i class="bi bi-grip-vertical" aria-hidden="true"></i>
            @endif
        </td>
    @endif

    <td class="celda-turno">
        @if ($fila->tieneTurno())
            <span class="turno">{{ $fila->turno() }}</span>
        @else
            <span class="turno turno-vacio" title="El escritorio la movió a otro día">—</span>
        @endif
    </td>

    <td class="celda-hora">{{ $fila->hora() }}</td>

    <td class="celda-principal">
        <x-ui.identidad :nombre="$fila->paciente()" :detalle="$fila->detalle()" :href="$fila->urlPaciente()" tamano="sm" />
        @unless ($fila->historia())
            <x-ui.estado tono="aviso" icono="bi-exclamation-triangle" class="mt-1">Sin historia</x-ui.estado>
        @endunless
    </td>

    <td class="celda-estado">
        <div class="estados-fila">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
            <x-ui.estado :tono="$pago['tono']" :icono="$pago['icono']">{{ $pago['texto'] }}</x-ui.estado>
        </div>
    </td>

    <td class="celda-acciones text-end">
        @if ($fila->movida())
            <span class="texto-tenue small">Sin acciones: el escritorio la movió</span>
        @else
            @php
                $confirmar = $fila->confirmar();
                $atender = $fila->atender();
                $cobro = $fila->cobro();
                $recordatorio = $fila->recordatorio();
                $quitar = $fila->quitarConfirmacion();
            @endphp

            <div class="acciones-fila">
                {{-- La acción que sigue: confirmar (el paciente llegó) y, ya confirmada, atender.
                     **"Atender" abre la consulta** (WEB-4.1): el botón no envía el formulario, abre el
                     diálogo de la página (`data-atender`) que ofrece abrir la consulta de hoy o crear una
                     nueva. Con la cita ya atendida el botón dice "Editar consulta" y entra a la misma
                     consulta del día sin marcar nada; a la secretaría no se le ofrece (la consulta es del
                     médico, PLAN-WEB.md §3.14). --}}
                @if ($confirmar)
                    <form method="POST" action="{{ $confirmar['url'] }}">
                        @csrf
                        <input type="hidden" name="estado" value="{{ $confirmar['estado'] }}">
                        <button class="btn btn-sm btn-outline-success" title="{{ $confirmar['titulo'] }}"
                                @if (! $confirmar['habilitada']) disabled @endif>
                            <i class="bi bi-check2-circle" aria-hidden="true"></i> Confirmar
                        </button>
                    </form>
                @elseif ($atender)
                    <form method="POST" action="{{ $atender['url'] }}"
                          data-atender
                          data-paciente="{{ $fila->paciente() }}"
                          data-editar="{{ $atender['editar'] ? '1' : '0' }}">
                        @csrf
                        <button class="btn btn-sm {{ $atender['editar'] ? 'btn-outline-primary' : 'btn-primary' }}"
                                title="{{ $atender['titulo'] }}" @if (! $atender['habilitada']) disabled @endif>
                            <i class="bi {{ $atender['editar'] ? 'bi-pencil' : 'bi-play-circle' }}" aria-hidden="true"></i>
                            {{ $atender['editar'] ? 'Editar consulta' : 'Atender' }}
                        </button>
                    </form>
                @endif

                @if ($cobro)
                    <button type="button" class="btn btn-sm btn-outline-dark" data-dialogo="cobro"
                            data-url="{{ $cobro['url'] }}"
                            data-paciente="{{ $cobro['paciente'] }}"
                            data-pendiente="{{ $cobro['pendiente'] }}"
                            data-requiere-monto="{{ $cobro['requiereMonto'] ? '1' : '0' }}"
                            data-detalle="{{ $cobro['detalle'] }}">
                        <i class="bi bi-cash-coin" aria-hidden="true"></i> Cobrar
                    </button>
                @endif

                {{-- El resto de las acciones, en el menú. Una cita atendida ya ocurrió (y tiene la consulta
                     colgando) y una con pago registrado no se borra: la regla la aplica `EliminarCita`;
                     acá solo no se ofrece. El menú se muestra aunque no haya ninguna de esas acciones
                     cuando el paciente tiene ficha: "Citas del paciente" siempre está. --}}
                @if ($recordatorio || $quitar || ($confirmar && $atender) || $fila->urlEditar() || $fila->urlEliminar() || $fila->pacienteId())
                    <x-ui.menu>
                        @if ($recordatorio)
                            <button type="button" class="menu-item" data-dialogo="recordatorio"
                                    data-url="{{ $recordatorio['url'] }}"
                                    data-paciente="{{ $recordatorio['paciente'] }}"
                                    data-telefono="{{ $recordatorio['telefono'] }}"
                                    data-email="{{ $recordatorio['email'] }}"
                                    data-mensaje="{{ $recordatorio['mensaje'] }}"
                                    data-tiene-telefono="{{ $recordatorio['tieneTelefono'] ? '1' : '0' }}"
                                    data-tiene-correo="{{ $recordatorio['tieneCorreo'] ? '1' : '0' }}">
                                <i class="bi bi-send" aria-hidden="true"></i> Recordar al paciente
                            </button>
                        @endif

                        {{-- Con la cita sin confirmar, "Atender" va en el menú: implica confirmada y además
                             abre la consulta del día (WEB-4.1). Es el mismo diálogo de la página. --}}
                        @if ($confirmar && $atender)
                            <form method="POST" action="{{ $atender['url'] }}"
                                  data-atender data-paciente="{{ $fila->paciente() }}" data-editar="0">
                                @csrf
                                <button class="menu-item" title="{{ $atender['titulo'] }}" @if (! $atender['habilitada']) disabled @endif>
                                    <i class="bi bi-play-circle" aria-hidden="true"></i> Atender (implica confirmada)
                                </button>
                            </form>
                        @endif

                        @if ($quitar)
                            <form method="POST" action="{{ $quitar['url'] }}">
                                @csrf
                                <input type="hidden" name="estado" value="{{ $quitar['estado'] }}">
                                <button class="menu-item"><i class="bi bi-x-circle" aria-hidden="true"></i> Quitar confirmación</button>
                            </form>
                        @endif

                        {{-- El otro eje del listado: las citas de **este** paciente ("Cita x Paciente",
                             `pb_2` de `w_hacer_cita.srw:1039` → `w_listado_pacientes_a_citas`, WEB-2.15). --}}
                        @if ($fila->pacienteId())
                            <a class="menu-item" href="{{ route('clinica.pacientes.citas', $fila->pacienteId()) }}">
                                <i class="bi bi-calendar3" aria-hidden="true"></i> Citas del paciente
                            </a>
                        @endif

                        @if ($fila->urlEditar())
                            <a class="menu-item" href="{{ $fila->urlEditar() }}">
                                <i class="bi bi-pencil" aria-hidden="true"></i> Editar o reagendar
                            </a>
                        @endif

                        @if ($fila->urlEliminar())
                            <form method="POST" action="{{ $fila->urlEliminar() }}"
                                  data-confirmar="¿Eliminar esta cita? No se puede deshacer.">
                                @csrf
                                <button class="menu-item menu-item-peligro">
                                    <i class="bi bi-trash" aria-hidden="true"></i> Eliminar la cita
                                </button>
                            </form>
                        @endif
                    </x-ui.menu>
                @endif
            </div>
        @endif
    </td>
</tr>
