@php
    use App\Models\Cola;

    // Se arrastra solo donde el orden lo decide la cola (sede por orden de llegada) y de hoy en
    // adelante: en una sede con hora de cita la posición la fija la hora, y reordenar un día pasado
    // no significa nada. Es la misma restricción que el móvil.
    $puedeReordenar = $conDrag && $jornada->esPorOrdenDeLlegada() && $jornada->fecha >= $ahora->toDateString();
    $cupo = $jornada->cupo();
@endphp

<div class="card mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <strong>{{ $jornada->nombreSede() }}</strong>
            <span class="badge {{ $jornada->esPorOrdenDeLlegada() ? 'bg-info text-dark' : 'bg-secondary' }} ms-1">
                {{ $jornada->esPorOrdenDeLlegada() ? 'Orden de llegada' : 'Hora de cita' }}
            </span>
            <span class="text-muted small ms-2">
                <i class="bi bi-clock"></i> {{ $jornada->horarioTexto() }} · turno {{ $jornada->turno() }}
            </span>
        </div>
        <div class="text-muted small">
            {{ $jornada->cantidad() }} {{ $jornada->cantidad() === 1 ? 'paciente' : 'pacientes' }}
            @if ($cupo !== null)
                · cupo {{ $jornada->cantidad() }}/{{ $cupo }}
                @if ($jornada->llena())
                    <span class="badge bg-warning text-dark">Cupo completo</span>
                @endif
            @else
                · sin cupo configurado
            @endif
            · próximo #{{ $jornada->siguienteNumero() }}
            @if ($jornada->movidas()->isNotEmpty())
                @php $movidas = $jornada->movidas()->count(); @endphp
                · <span class="text-muted">{{ $movidas }} {{ $movidas === 1 ? 'movida' : 'movidas' }} por el escritorio</span>
            @endif

            <a class="btn btn-sm btn-outline-primary ms-2"
               href="{{ route('clinica.agenda.nueva', ['fecha' => $jornada->fecha, 'sede' => $jornada->sede?->id]) }}">
                <i class="bi bi-plus-lg"></i> Agendar
            </a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                @if ($puedeReordenar)
                    <th style="width: 2rem;"></th>
                @endif
                <th style="width: 3rem;">#</th>
                <th style="width: 4.5rem;">Hora</th>
                <th>Paciente</th>
                <th>Motivo</th>
                <th>Estado</th>
                <th>Pago</th>
                <th class="text-end">Acciones</th>
            </tr>
            </thead>
            <tbody class="{{ $puedeReordenar ? 'lista-cola' : '' }}"
                   @if ($puedeReordenar)
                   data-fecha="{{ $jornada->fecha }}"
                   data-reordenar="{{ route('clinica.agenda.reordenar') }}"
                   data-csrf="{{ csrf_token() }}"
                   @endif>
            @foreach ($jornada->ordenadas() as $fila)
                @php $cita = $fila['cita']; @endphp
                <tr class="{{ $cita->movida() ? 'text-muted' : ($cita->atendida() ? 'table-success' : '') }}"
                    @if ($puedeReordenar && ! $cita->movida())
                        draggable="true" data-id="{{ $cita->id }}" data-numorden="{{ $cita->numOrden }}"
                    @endif>
                    @if ($puedeReordenar)
                        <td class="text-muted" title="Arrastrar para reordenar" style="cursor: grab;">
                            @unless ($cita->movida())
                                <i class="bi bi-grip-vertical"></i>
                            @endunless
                        </td>
                    @endif
                    <td>
                        @if ($fila['posicion'] !== null)
                            <span class="badge bg-dark">#{{ $fila['posicion'] }}</span>
                        @else
                            <span class="badge bg-secondary" title="El escritorio la movió a otro día">—</span>
                        @endif
                    </td>
                    <td>{{ $cita->horaCorta() }}</td>
                    <td>
                        @if ($cita->pacienteId)
                            <a href="{{ route('clinica.pacientes.ver', $cita->pacienteId) }}">{{ $cita->paciente }}</a>
                        @else
                            {{ $cita->paciente }}
                        @endif
                        @if ($cita->numHistoria)
                            <span class="text-muted small">· H. {{ $cita->numHistoria }}</span>
                        @else
                            <span class="badge bg-secondary">Sin historia</span>
                        @endif
                    </td>
                    <td class="small">{{ $cita->razon() }}</td>
                    <td>
                        @if ($cita->movida())
                            <span class="badge bg-secondary"
                                  title="El escritorio la postergó: el paciente ya está en la fecha nueva">Movida</span>
                        @elseif ($cita->atendida())
                            <span class="badge bg-success">Atendida</span>
                        @elseif ($cita->confirmada())
                            <span class="badge bg-primary">Confirmada</span>
                        @else
                            <span class="badge bg-light text-dark">Sin confirmar</span>
                        @endif
                    </td>
                    <td>
                        @php $pago = $cita->estadoPago(); @endphp
                        <span class="badge {{ $pago === Cola::PAGO_PAGADA ? 'bg-success' : ($pago === Cola::PAGO_SIN_MONTO ? 'bg-light text-dark' : 'bg-warning text-dark') }}">
                            {{ $cita->etiquetaPago() }}
                        </span>
                    </td>
                    <td class="text-end text-nowrap">
                        @if ($cita->movida())
                            <span class="small text-muted">Sin acciones: el escritorio la movió</span>
                        @else
                        @if (! $cita->confirmada())
                            <form method="POST" action="{{ route('clinica.agenda.confirmar', $cita->id) }}" class="d-inline">
                                @csrf
                                <input type="hidden" name="estado" value="{{ Cola::ESTADO_CONFIRMADA }}">
                                <button class="btn btn-sm btn-outline-success"
                                        {{ $cita->puedeConfirmar($ahora) ? '' : 'disabled' }}
                                        title="{{ $cita->puedeConfirmar($ahora) ? 'El paciente llegó' : 'Se confirma el día de la cita, desde una hora antes' }}">
                                    Confirmar
                                </button>
                            </form>
                        @elseif (! $cita->atendida())
                            <form method="POST" action="{{ route('clinica.agenda.confirmar', $cita->id) }}" class="d-inline">
                                @csrf
                                <input type="hidden" name="estado" value="{{ Cola::ESTADO_NO_CONFIRMADA }}">
                                <button class="btn btn-sm btn-outline-secondary">Quitar confirmación</button>
                            </form>
                        @endif

                        @if (! $cita->atendida())
                            <form method="POST" action="{{ route('clinica.agenda.atender', $cita->id) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-primary"
                                        {{ $cita->puedeAtender($ahora) ? '' : 'disabled' }}
                                        title="{{ $cita->puedeAtender($ahora) ? 'Marcar como atendida (implica confirmada)' : 'Solo el día de la cita' }}">
                                    Atender
                                </button>
                            </form>
                        @endif

                        @if ($pago !== Cola::PAGO_PAGADA)
                            <button type="button" class="btn btn-sm btn-outline-dark"
                                    data-url="{{ route('clinica.agenda.cobrar', $cita->id) }}"
                                    data-paciente="{{ $cita->paciente }}"
                                    data-pendiente="{{ number_format($cita->montoPendiente(), 2, '.', '') }}"
                                    data-requiere-monto="{{ $pago === Cola::PAGO_SIN_MONTO ? '1' : '0' }}"
                                    data-detalle="{{ $cita->etiquetaPago() }}"
                                    onclick="abrirCobro(this)">
                                Cobrar
                            </button>
                        @endif

                        {{-- Editar y eliminar: una cita atendida ya ocurrió (y tiene la consulta
                             colgando) y una con pago registrado no se borra — la regla la aplica
                             `App\Actions\Agenda\EliminarCita`; acá solo no se ofrece el botón. --}}
                        @unless ($cita->atendida())
                            <a class="btn btn-sm btn-outline-secondary" title="Editar o reagendar"
                               href="{{ route('clinica.agenda.editar', $cita->id) }}">
                                <i class="bi bi-pencil"></i>
                            </a>

                            @unless (in_array($pago, [Cola::PAGO_PAGADA, Cola::PAGO_ABONADA], true))
                                <form method="POST" action="{{ route('clinica.agenda.eliminar', $cita->id) }}"
                                      class="d-inline"
                                      onsubmit="return confirm('¿Eliminar esta cita? No se puede deshacer.');">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-danger" title="Eliminar la cita">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @endunless
                        @endunless
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
