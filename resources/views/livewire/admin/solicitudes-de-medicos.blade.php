<div>
    <x-ui.page-head titulo="Solicitudes de médicos"
                    subtitulo="Los médicos que se postulan desde la web. Verifica sus datos y aprueba o rechaza." />

    <x-ui.avisos />

    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="btn-group" role="group" aria-label="Filtrar por estado">
                @foreach (['pendiente' => 'Pendientes', 'aprobada' => 'Aprobadas', 'rechazada' => 'Rechazadas', 'todas' => 'Todas'] as $clave => $texto)
                    <button type="button"
                            class="btn btn-sm {{ $estado === $clave ? 'btn-primary' : 'btn-outline-secondary' }}"
                            wire:click="$set('estado', '{{ $clave }}')">
                        {{ $texto }}
                        <span class="badge text-bg-light">
                            {{ $clave === 'todas' ? array_sum($conteos) : ($conteos[$clave] ?? 0) }}
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    @if ($seleccionada)
        <div class="card mb-3 border-primary">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="fw-semibold">{{ $seleccionada->nombreCompleto() }}</div>
                    <small class="text-muted">Recibida el {{ \App\Support\HoraLocal::fechaHora($seleccionada->created_at) }}</small>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cerrar">Cerrar</button>
            </div>

            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-7">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Cédula</dt>
                            <dd class="col-sm-8">{{ $seleccionada->cedula }}</dd>
                            <dt class="col-sm-4">Registro MPPS</dt>
                            <dd class="col-sm-8">{{ $seleccionada->mpps }}</dd>
                            <dt class="col-sm-4">Colegio de Médicos</dt>
                            <dd class="col-sm-8">{{ $seleccionada->colegio ?: '—' }}</dd>
                            <dt class="col-sm-4">Especialidad</dt>
                            <dd class="col-sm-8">{{ $seleccionada->especialidad?->name ?? 'Medicina general / otra' }}</dd>
                            <dt class="col-sm-4">Correo</dt>
                            <dd class="col-sm-8">{{ $seleccionada->email }} <x-ui.estado tono="exito">Verificado</x-ui.estado></dd>
                            <dt class="col-sm-4">Teléfono</dt>
                            <dd class="col-sm-8">{{ \App\Notificaciones\Telefono::mostrar($seleccionada->telefono) }}</dd>
                            <dt class="col-sm-4">Ciudad</dt>
                            <dd class="col-sm-8">{{ $seleccionada->ciudad ?: '—' }}</dd>
                            @if ($seleccionada->mensaje)
                                <dt class="col-sm-4">Mensaje</dt>
                                <dd class="col-sm-8" style="white-space: pre-line">{{ $seleccionada->mensaje }}</dd>
                            @endif
                        </dl>
                    </div>

                    <div class="col-lg-5">
                        @if ($seleccionada->carnet_ruta)
                            @php($urlCarnet = route('admin.solicitudes-medicos.carnet', $seleccionada->id))
                            <a href="{{ $urlCarnet }}" target="_blank" rel="noopener" aria-label="Abrir la foto del carnet">
                                <img src="{{ $urlCarnet }}" alt="Foto del carnet de {{ $seleccionada->nombreCompleto() }}"
                                     class="img-fluid rounded border" style="max-height: 16rem">
                            </a>
                        @else
                            <p class="text-muted small mb-0">No adjuntó foto del carnet.</p>
                        @endif
                    </div>
                </div>

                <hr>

                @if ($seleccionada->estaPendiente())
                    <div class="row g-3">
                        <div class="col-md-6">
                            <h3 class="h6">Aprobar</h3>
                            <div class="mb-2">
                                <label for="reg_medico" class="form-label">reg_medico</label>
                                <input type="text" id="reg_medico" maxlength="100"
                                       class="form-control @error('reg_medico') is-invalid @enderror"
                                       wire:model.blur="reg_medico">
                                @error('reg_medico')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                @if ($fichaExistente)
                                    <div class="form-text text-warning-emphasis">
                                        Ya existe la ficha de {{ trim($fichaExistente->name . ' ' . $fichaExistente->lastname) }}
                                        con ese reg_medico: se le da acceso a esa ficha en vez de crear otra.
                                    </div>
                                @else
                                    <div class="form-text">Se crea una ficha nueva con este reg_medico.</div>
                                @endif
                            </div>
                            <div class="mb-3">
                                <label for="specialty_id" class="form-label">Especialidad</label>
                                <select id="specialty_id" class="form-select" wire:model="specialty_id">
                                    <option value="">Sin especialidad</option>
                                    @foreach ($especialidades as $especialidad)
                                        <option value="{{ $especialidad->id }}">{{ $especialidad->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="button" class="btn btn-success" wire:click="aprobar" wire:loading.attr="disabled">
                                Aprobar y crear la cuenta
                            </button>
                        </div>

                        <div class="col-md-6">
                            <h3 class="h6">Rechazar</h3>
                            <div class="mb-3">
                                <label for="motivo" class="form-label">Motivo (le llega al médico por correo)</label>
                                <textarea id="motivo" rows="4" maxlength="2000"
                                          class="form-control @error('motivo') is-invalid @enderror"
                                          wire:model="motivo"
                                          placeholder="Por ejemplo: el número del MPPS no coincide con la cédula."></textarea>
                                @error('motivo')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="button" class="btn btn-outline-danger" wire:click="rechazar" wire:loading.attr="disabled">
                                Rechazar
                            </button>
                        </div>
                    </div>

                    @error('solicitud')
                        <div class="alert alert-warning mt-3 mb-0">{{ $message }}</div>
                    @enderror
                @else
                    <p class="mb-1">
                        {{ $seleccionada->estado === 'aprobada' ? 'Aprobada' : 'Rechazada' }}
                        por {{ $seleccionada->revisor?->name ?? '—' }}
                        el {{ \App\Support\HoraLocal::fechaHora($seleccionada->revisada_el) }}.
                    </p>
                    @if ($seleccionada->estado === 'aprobada')
                        <p class="mb-0 text-muted">reg_medico <code>{{ $seleccionada->reg_medico }}</code></p>
                    @elseif ($seleccionada->motivo_rechazo)
                        <p class="mb-0" style="white-space: pre-line">{{ $seleccionada->motivo_rechazo }}</p>
                    @endif
                @endif
            </div>
        </div>
    @endif

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Médico</th>
                            <th>Cédula</th>
                            <th>MPPS</th>
                            <th>Especialidad</th>
                            <th>Correo</th>
                            <th>Recibida</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($solicitudes as $solicitud)
                            <tr wire:key="solicitud-{{ $solicitud->id }}">
                                <td class="fw-semibold">{{ $solicitud->nombreCompleto() }}</td>
                                <td>{{ $solicitud->cedula }}</td>
                                <td>{{ $solicitud->mpps }}</td>
                                <td>{{ $solicitud->especialidad?->name ?? '—' }}</td>
                                <td>{{ $solicitud->email }}</td>
                                <td>
                                    <div>{{ \App\Support\HoraLocal::instante($solicitud->created_at)?->format('d/m/Y') }}</div>
                                    <small class="text-muted">{{ $solicitud->created_at?->diffForHumans() }}</small>
                                </td>
                                <td>
                                    @if ($solicitud->estado === 'aprobada')
                                        <x-ui.estado tono="exito">Aprobada</x-ui.estado>
                                    @elseif ($solicitud->estado === 'rechazada')
                                        <x-ui.estado tono="neutro">Rechazada</x-ui.estado>
                                    @else
                                        <x-ui.estado tono="aviso">Pendiente</x-ui.estado>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            wire:click="abrir({{ $solicitud->id }})">
                                        {{ $solicitud->estaPendiente() ? 'Revisar' : 'Ver' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    {{ $estado === 'pendiente' ? 'No hay solicitudes por revisar.' : 'No hay solicitudes en esta lista.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($solicitudes->hasPages())
            <div class="card-footer bg-white">
                {{ $solicitudes->links() }}
            </div>
        @endif
    </div>

    {{-- La clave temporal se muestra una vez, como en «Cuentas»: si el correo no llega, se le pasa a mano. --}}
    @if ($claveGenerada)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="bi bi-check-circle me-1"></i> {{ $claveGenerada['titulo'] }}</h5>
                    </div>
                    <div class="modal-body">
                        <div class="alert {{ $claveGenerada['correoEnviado'] ? 'alert-info' : 'alert-warning' }} small">
                            @if ($claveGenerada['correoEnviado'])
                                Le mandamos estos datos por correo. Por si no le llega, la contraseña se muestra solo esta vez.
                            @else
                                <strong>El correo no salió.</strong> Copia estos datos ahora y pásaselos al médico: la contraseña no se vuelve a mostrar.
                            @endif
                        </div>

                        <dl class="row mb-0">
                            <dt class="col-sm-4">Correo</dt>
                            <dd class="col-sm-8"><code>{{ $claveGenerada['correo'] }}</code></dd>
                            <dt class="col-sm-4">Contraseña temporal</dt>
                            <dd class="col-sm-8"><code class="fs-5">{{ $claveGenerada['clave'] }}</code></dd>
                        </dl>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-primary"
                                onclick="navigator.clipboard.writeText({{ json_encode($claveGenerada['clave']) }}); this.textContent = 'Copiada';">
                            <i class="bi bi-clipboard me-1"></i> Copiar contraseña
                        </button>
                        <button type="button" class="btn btn-primary" wire:click="cerrarClave">Listo</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
