<div>
    <x-ui.page-head titulo="Usuarios · Pacientes"
                    subtitulo="Cuentas que los pacientes crean por su cuenta para agendar. Sin datos clínicos; aquí se pueden bloquear en una emergencia." />

    <x-ui.avisos />

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-header bg-white py-3">
            <div class="row g-2">
                <div class="col-md-5">
                    <input type="text" wire:model.live.debounce.400ms="search" class="form-control"
                           placeholder="Buscar por nombre, cédula, correo o teléfono..." aria-label="Buscar pacientes">
                </div>
                <div class="col-md-3">
                    <select wire:model.live="estado" class="form-select" aria-label="Filtrar por estado">
                        <option value="todos">Todos</option>
                        <option value="activos">Activos</option>
                        <option value="bloqueados">Bloqueados</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Paciente</th>
                            <th>Cédula</th>
                            <th>Contacto</th>
                            <th>Identidad</th>
                            <th>Estado</th>
                            <th>Alta</th>
                            <th class="text-end">Citas</th>
                            <th>Última cita</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            @php
                                $ficha = $fila->paciente;
                                $resumen = $ficha ? ($citas[$ficha->id] ?? null) : null;
                                $bloqueado = $fila->is_active === false;
                            @endphp
                            <tr wire:key="paciente-{{ $fila->id }}">
                                <td class="fw-semibold">{{ $ficha ? trim($ficha->nombres . ' ' . $ficha->apellidos) : $fila->name }}</td>
                                <td>{{ $ficha?->cedula ?: '—' }}</td>
                                <td>
                                    <div>{{ $fila->email }}</div>
                                    @if ($ficha?->telefono)
                                        <small class="text-muted">{{ $ficha->telefono }}</small>
                                    @endif
                                </td>
                                <td>
                                    @php $nivel = $niveles[$fila->id] ?? 0; @endphp
                                    <span class="badge {{ $nivel === 2 ? 'bg-primary' : ($nivel === 1 ? 'bg-info text-dark' : 'bg-secondary') }}">
                                        {{ $nivel === 2 ? 'Nivel 2 · Cédula confirmada' : ($nivel === 1 ? 'Nivel 1 · Teléfono validado' : 'Nivel 0 · Sin validar') }}
                                    </span>
                                </td>
                                <td>
                                    @if ($bloqueado)
                                        <span class="badge bg-danger" @if ($fila->blocked_reason) title="{{ $fila->blocked_reason }}" @endif>Bloqueado</span>
                                    @else
                                        <span class="badge bg-success">Activo</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">{{ $fila->created_at?->format(\App\Support\Regionalizacion::formatoActual()) ?? '—' }}</td>
                                <td class="text-end">{{ $resumen['total'] ?? 0 }}</td>
                                <td class="text-nowrap">{{ ! empty($resumen['ultima']) ? \Illuminate\Support\Carbon::parse($resumen['ultima'])->format(\App\Support\Regionalizacion::formatoActual()) : '—' }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($bloqueado)
                                        <button type="button" wire:click="abrir('desbloquear', {{ $fila->id }})" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-unlock me-1" aria-hidden="true"></i> Desbloquear
                                        </button>
                                    @else
                                        <button type="button" wire:click="abrir('bloquear', {{ $fila->id }})" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Bloquear
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9">
                                    <x-ui.vacio icono="bi-person-heart" titulo="No hay pacientes con cuenta">
                                        Aparecen cuando se registran en «Mis citas» o al reservar con cuenta.
                                    </x-ui.vacio>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($filas->hasPages())
            <div class="card-footer bg-white">{{ $filas->links() }}</div>
        @endif
    </div>

    @if ($modal && $seleccionado)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form wire:submit.prevent="{{ $modal === 'bloquear' ? 'confirmarBloqueo' : 'confirmarDesbloqueo' }}">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $modal === 'bloquear' ? 'Bloquear paciente' : 'Desbloquear paciente' }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            @error('cuenta') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror
                            <p class="fw-semibold mb-2">{{ $seleccionado->paciente ? trim($seleccionado->paciente->nombres . ' ' . $seleccionado->paciente->apellidos) : $seleccionado->name }}</p>
                            @if ($modal === 'bloquear')
                                <p>No podrá iniciar sesión ni reservar citas por «Mis citas», la reserva pública ni el chatbot, y se cerrarán sus sesiones. La cuenta no se borra y puedes desbloquearla cuando quieras.</p>
                                @if ($alcances)
                                    <p class="mb-1 small fw-semibold">Identidad: nivel {{ $alcances['nivel'] }}. Se bloqueará:</p>
                                    <ul class="small mb-3" id="alcances-bloqueo">
                                        <li>La cuenta.</li>
                                        @if ($alcances['telefono'])
                                            <li>El teléfono +{{ $alcances['telefono'] }}, por {{ \App\Paciente\NivelesDeIdentidad::mesesDeBloqueoPorTelefono() }} meses (renovable).</li>
                                        @else
                                            <li class="text-muted">El teléfono no: no está validado o la validación venció.</li>
                                        @endif
                                        @if ($alcances['cedula'])
                                            <li>La cédula {{ $alcances['cedula'] }}, confirmada por el consultorio.</li>
                                        @else
                                            <li class="text-muted">La cédula no: no está confirmada por el personal del consultorio.</li>
                                        @endif
                                    </ul>
                                @endif
                                <label class="form-label" for="motivo-bloqueo">Motivo (opcional)</label>
                                <input id="motivo-bloqueo" type="text" class="form-control" wire:model="motivo" maxlength="255" placeholder="Ej.: reservas falsas">
                                @error('motivo') <span class="text-danger small">{{ $message }}</span> @enderror
                            @else
                                <p>El paciente volverá a poder iniciar sesión y reservar citas.</p>
                                @if ($seleccionado->blocked_reason)
                                    <p class="small text-muted mb-0">Motivo del bloqueo: {{ $seleccionado->blocked_reason }}</p>
                                @endif
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn {{ $modal === 'bloquear' ? 'btn-danger' : 'btn-success' }}">
                                {{ $modal === 'bloquear' ? 'Bloquear' : 'Desbloquear' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
