{{-- Una cita en la lista de la jornada. Recibe `$fila` (FilaDeCitaVista) y `$reordenable`.
     La posición (#7) sale del mapa que se calcula **sobre todo el rango**, no de la lista filtrada: al
     filtrar por sede o por razón el paciente 7 sigue siendo el 7 (regla del móvil). --}}
@php
    $estado = $fila->estado();
    $pago = $fila->pago();
    // El médico de la cita (Fase 3 del cambio de `reg_medico`): `null` cuando el consultorio tiene un solo
    // doctor (ahí el nombre se repetiría en todas las filas) y «Sin asignar» cuando todavía no lo tiene.
    $doctor = $fila->doctor();
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
            <span class="turno turno-vacio" title="La cita se movió a otro día">—</span>
        @endif
    </td>

    <td class="celda-hora">{{ $fila->hora() }}</td>

    <td class="celda-principal">
        {{-- Una línea: la identidad y el aviso de «sin historia» —que es estado y se queda— en la misma fila
             (pauta de Alexander). El médico de la cita va en la misma línea, como pastilla discreta: sólo
             aparece cuando el consultorio tiene más de un doctor. --}}
        <span class="d-flex align-items-center gap-2 min-w-0">
            <x-ui.identidad :nombre="$fila->paciente()" :detalle="$fila->detalle()" :href="$fila->urlPaciente()" tamano="sm" />
            @unless ($fila->historia())
                <x-ui.estado tono="aviso" icono="bi-exclamation-triangle">Sin historia</x-ui.estado>
            @endunless
            @if ($doctor)
                <x-ui.estado :tono="$doctor['tono']" :icono="$doctor['icono']" :title="$doctor['titulo']">{{ $doctor['texto'] }}</x-ui.estado>
            @endif
        </span>
    </td>

    <td class="celda-estado">
        <div class="estados-fila">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
            <x-ui.estado :tono="$pago['tono']" :icono="$pago['icono']">{{ $pago['texto'] }}</x-ui.estado>
        </div>
    </td>

    <td class="celda-acciones text-end">
        @if ($fila->movida())
            <span class="texto-tenue small">Sin acciones: la cita se movió</span>
        @else
            @php
                $confirmar = $fila->confirmar();
                $atender = $fila->atender();
                $cobro = $fila->cobro();
                $linkDePago = $fila->linkDePago();
                $recordatorio = $fila->recordatorio();
                $imprimirDocumentos = $fila->imprimirDocumentos();
                $quitar = $fila->quitarConfirmacion();
                // «Asignar médico» (Fase 3 del cambio de `reg_medico`): el menú con los doctores del
                // consultorio, sólo donde la fila muestra el médico (más de un doctor) y mientras la cita no
                // esté atendida. Lo ven la secretaría y el médico (la ruta está en el grupo de la agenda).
                $asignarMedico = $fila->asignarMedico();

                // El menú «…» es para **dos o más** acciones secundarias (pauta de Alexander: un menú de una sola
                // opción está prohibido). Si queda una sola —hoy pasa con «Citas del paciente» en una cita atendida
                // que ya no tiene nada más que hacer— va como botón directo, al lado de las demás.
                $secundarias = array_filter([
                    'recordatorio' => (bool) $recordatorio,
                    'link' => (bool) $linkDePago,
                    'atender' => (bool) ($confirmar && $atender),
                    'quitar' => (bool) $quitar,
                    'citas' => (bool) $fila->pacienteId(),
                    'editar' => (bool) $fila->urlEditar(),
                    'eliminar' => (bool) $fila->urlEliminar(),
                ]);
                $soloCitasDelPaciente = count($secundarias) === 1 && isset($secundarias['citas']);
            @endphp

            <div class="acciones-fila">
                {{-- La acción que sigue: confirmar (el paciente llegó) y, ya confirmada, atender.
                     **"Atender" abre la consulta** (WEB-4.1): el botón no envía el formulario, abre el
                     diálogo de la página (`data-atender`) que ofrece abrir la consulta de hoy o crear una
                     nueva. Con la cita ya atendida el botón dice "Editar consulta" y entra a la misma
                     consulta del día sin marcar nada. **"Atender" es sólo del médico** (WEB-4.9): a la
                     secretaría no se le pinta —ni acá ni en el menú—, porque atender es abrir la consulta
                     (PLAN-WEB.md §3.14) y el POST a mano responde 403. --}}
                @if ($confirmar)
                    <form method="POST" action="{{ $confirmar['url'] }}">
                        @csrf
                        <input type="hidden" name="estado" value="{{ $confirmar['estado'] }}">
                        <button class="btn btn-sm btn-outline-success" title="{{ $confirmar['titulo'] }}"
                                aria-label="Confirmar la cita de {{ $fila->paciente() }}"
                                @if (! $confirmar['habilitada']) disabled @endif>
                            <i class="bi bi-check2-circle" aria-hidden="true"></i> <span class="accion-texto">Confirmar</span>
                        </button>
                    </form>
                @elseif ($atender)
                    <form method="POST" action="{{ $atender['url'] }}"
                          data-atender
                          data-paciente="{{ $fila->paciente() }}"
                          data-editar="{{ $atender['editar'] ? '1' : '0' }}">
                        @csrf
                        <button class="btn btn-sm {{ $atender['editar'] ? 'btn-outline-primary' : 'btn-primary' }}"
                                title="{{ $atender['titulo'] }}"
                                aria-label="{{ $atender['editar'] ? 'Editar la consulta de' : 'Atender a' }} {{ $fila->paciente() }}"
                                @if (! $atender['habilitada']) disabled @endif>
                            <i class="bi {{ $atender['editar'] ? 'bi-pencil' : 'bi-play-circle' }}" aria-hidden="true"></i>
                            <span class="accion-texto">{{ $atender['editar'] ? 'Editar consulta' : 'Atender' }}</span>
                        </button>
                    </form>
                @endif

                {{-- Imprimir los documentos de la cita ya atendida (WEB-5.2, ampliado en WEB-5.3a): la secretaría entrega
                     los papeles sin entrar a la consulta, que es sólo del médico. Sólo aparece si la consulta tiene
                     algo que imprimir (récipes, constancia, reposo, referencias o informe). --}}
                @if ($imprimirDocumentos)
                    <a class="btn btn-sm btn-outline-secondary" href="{{ $imprimirDocumentos['url'] }}"
                       title="Imprimir los documentos de la cita" aria-label="Imprimir los documentos de la cita de {{ $fila->paciente() }}">
                        <i class="bi bi-printer" aria-hidden="true"></i> <span class="accion-texto">Imprimir documentos</span>
                    </a>
                @endif

                @if ($cobro)
                    <button type="button" class="btn btn-sm btn-outline-dark" data-dialogo="cobro"
                            title="Cobrar la cita" aria-label="Cobrar la cita de {{ $fila->paciente() }}"
                            data-url="{{ $cobro['url'] }}"
                            data-paciente="{{ $cobro['paciente'] }}"
                            data-pendiente="{{ $cobro['pendiente'] }}"
                            data-requiere-monto="{{ $cobro['requiereMonto'] ? '1' : '0' }}"
                            data-detalle="{{ $cobro['detalle'] }}">
                        <i class="bi bi-cash-coin" aria-hidden="true"></i> <span class="accion-texto">Cobrar</span>
                    </button>
                @endif

                {{-- **El médico de la cita** (Fase 3 del cambio de `reg_medico`): la cita del centro nace a
                     nombre del centro y sin doctor, y la secretaría (o el médico) elige quién la atiende. Un
                     ítem por doctor del consultorio; con la cita ya asignada el mismo menú la reasigna. --}}
                @if ($asignarMedico)
                    <x-ui.menu :etiqueta="$asignarMedico['reasignar'] ? 'Cambiar el médico de la cita' : 'Asignar médico'"
                               icono="bi-person-badge">
                        @foreach ($asignarMedico['medicos'] as $medicoId => $nombreMedico)
                            <form method="POST" action="{{ $asignarMedico['url'] }}">
                                @csrf
                                <input type="hidden" name="medico_id" value="{{ $medicoId }}">
                                <button class="menu-item"><i class="bi bi-person-check" aria-hidden="true"></i> {{ $nombreMedico }}</button>
                            </form>
                        @endforeach
                    </x-ui.menu>
                @endif

                {{-- Si lo único que queda es «Citas del paciente», va como botón directo (un menú de una sola opción
                     está prohibido): es el otro eje del listado, las citas de **este** paciente ("Cita x Paciente",
                     `pb_2` de `w_hacer_cita.srw:1039` → `w_listado_pacientes_a_citas`, WEB-2.15). --}}
                @if ($soloCitasDelPaciente)
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('clinica.pacientes.citas', $fila->pacienteId()) }}"
                       title="Citas del paciente" aria-label="Citas del paciente {{ $fila->paciente() }}">
                        <i class="bi bi-calendar3" aria-hidden="true"></i> <span class="accion-texto">Citas del paciente</span>
                    </a>
                @endif

                {{-- El resto de las acciones, en el menú. Una cita atendida ya ocurrió (y tiene la consulta
                     colgando) y una con pago registrado no se borra: la regla la aplica `EliminarCita`;
                     acá solo no se ofrece. El menú se muestra aunque no haya ninguna de esas acciones
                     cuando el paciente tiene ficha: "Citas del paciente" siempre está. --}}
                @if (! $soloCitasDelPaciente && ($recordatorio || $linkDePago || $quitar || ($confirmar && $atender) || $fila->urlEditar() || $fila->urlEliminar() || $fila->pacienteId()))
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

                        {{-- El link público que el paciente abre para pagar la cita (punto 3 del cobro). La
                             pantalla lo genera al abrirse y lo comparte; no cobra nada. --}}
                        @if ($linkDePago)
                            <a class="menu-item" href="{{ $linkDePago['url'] }}">
                                <i class="bi bi-link-45deg" aria-hidden="true"></i> Link de pago
                            </a>
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

                        {{-- **Lo destructivo, al final y en rojo** (pauta de Alexander). --}}
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
