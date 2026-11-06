<div>
    <x-ui.page-head titulo="Solicitudes de médicos"
                    subtitulo="Los médicos que se postulan desde la web. Verifica sus datos y aprueba o rechaza." />

    <x-ui.avisos />

    {{-- La política de verificación (2026-11-03): apagada, el médico entra solo al confirmar su correo. --}}
    <div class="card mb-3 {{ $exigir_carnet ? 'border-warning' : '' }}">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <h2 class="h6 mb-1">Verificación de médicos</h2>
                    <p class="text-muted small mb-0">
                        @if ($exigir_carnet)
                            <strong>Encendida.</strong> El registro espera la aprobación y un médico sin el carnet
                            verificado no puede usar la app. Antes de encenderla, verifica a los médicos que deban
                            seguir entrando.
                        @else
                            <strong>Apagada.</strong> El médico entra solo al confirmar su correo; su carnet queda
                            guardado como sin verificar (o pendiente si adjuntó la foto) y lo puedes validar después.
                        @endif
                    </p>
                </div>
                <div class="form-check form-switch text-start">
                    <input class="form-check-input" type="checkbox" role="switch" id="exigir_carnet"
                           wire:model="exigir_carnet">
                    <label class="form-check-label" for="exigir_carnet">Exigir carnet verificado</label>
                    <button type="button" class="btn btn-sm btn-primary d-block mt-2 w-100"
                            wire:click="guardarPolitica" wire:loading.attr="disabled">
                        Guardar
                    </button>
                </div>
            </div>
        </div>
    </div>

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
        {{-- El detalle en un diálogo lateral (2026-11-04): la tabla queda a la vista para comparar el número de
             esta solicitud con el de otra que parezca duplicada. --}}
        <div class="offcanvas offcanvas-end show" tabindex="-1" id="solicitud-detalle"
             role="dialog" aria-modal="true" aria-labelledby="solicitud-detalle-titulo"
             wire:keydown.escape.window="cerrar"
             style="visibility: visible; width: min(40rem, 100vw);">
            <div class="offcanvas-header border-bottom align-items-start">
                <div class="me-2">
                    <h2 class="h5 mb-1" id="solicitud-detalle-titulo">Solicitud #{{ $seleccionada->id }}</h2>
                    <div class="fw-semibold">{{ $seleccionada->nombreCompleto() }}</div>
                    <small class="text-muted d-block">
                        Recibida el {{ \App\Support\HoraLocal::fechaHora($seleccionada->created_at) }}
                        · Cédula {{ $seleccionada->cedula }} · MPPS {{ $seleccionada->mpps }}
                    </small>
                    @if ($seleccionada->aprobada_automaticamente)
                        <x-ui.estado tono="info">Aprobada automáticamente</x-ui.estado>
                    @endif
                </div>
                <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar la solicitud"></button>
            </div>

            <div class="offcanvas-body">
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

                @if ($verificaciones)
                    {{-- Las tres verificaciones del perfil (2026-11-03): carnet, correo y teléfono. --}}
                    <div class="mt-3 rounded border bg-light p-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                            <div class="d-flex flex-wrap gap-4">
                                @foreach ($verificaciones as $tipo => $verificacion)
                                    <div wire:key="verificacion-{{ $tipo }}">
                                        <small class="text-muted d-block">{{ \App\Models\VerificacionDeMedico::nombre($tipo) }}</small>
                                        <x-ui.estado :tono="\App\Models\VerificacionDeMedico::tono($verificacion->estado)">
                                            {{ str_replace('_', ' ', $verificacion->estado) }}
                                        </x-ui.estado>
                                        @if ($verificacion->nota)
                                            <small class="text-muted d-block">{{ $verificacion->nota }}</small>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-sm btn-outline-success"
                                        wire:click="marcarCarnet('verificado')" wire:loading.attr="disabled">
                                    Carnet verificado
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-warning"
                                        wire:click="marcarCarnet('pendiente')" wire:loading.attr="disabled">
                                    Pendiente
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        wire:click="marcarCarnet('rechazado')" wire:loading.attr="disabled">
                                    Rechazar carnet
                                </button>
                            </div>
                        </div>
                    </div>
                @endif

                <hr>

                @if ($seleccionada->estaPendiente())
                    {{-- La decisión se pregunta **antes** y sin opción marcada (guía de UI, §11): aprobar y
                         rechazar piden datos distintos, así que sólo se muestra la rama elegida y el botón
                         de abajo cambia con ella. --}}
                    <div class="mb-3">
                        <span class="form-label d-block" id="decision-titulo">¿Qué haces con la solicitud?</span>
                        <div class="d-flex flex-wrap gap-4" role="radiogroup" aria-labelledby="decision-titulo">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" id="decision-aprobar"
                                       value="aprobar" wire:model.live="decision">
                                <label class="form-check-label" for="decision-aprobar">Aprobar</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" id="decision-rechazar"
                                       value="rechazar" wire:model.live="decision">
                                <label class="form-check-label" for="decision-rechazar">Rechazar</label>
                            </div>
                        </div>
                    </div>

                    @if ($decision === 'aprobar')
                        <div class="row g-3">
                            <div class="col-md-6">
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
                                        con ese reg_medico:
                                        @if ($fichaExistente->user_id)
                                            ya tiene cuenta, así que la solicitud se vincula a su ficha y no se crea otra.
                                        @else
                                            se le da acceso a esa ficha en vez de crear otra.
                                        @endif
                                    </div>
                                @else
                                    <div class="form-text">Se crea una ficha nueva con este reg_medico.</div>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <label for="specialty_id" class="form-label">Especialidad</label>
                                <select id="specialty_id" class="form-select" wire:model="specialty_id">
                                    <option value="">Sin especialidad</option>
                                    @foreach ($especialidades as $especialidad)
                                        <option value="{{ $especialidad->id }}">{{ $especialidad->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="carnet_verificado"
                                   wire:model="carnet_verificado">
                            <label class="form-check-label" for="carnet_verificado">
                                Revisé el carnet y lo doy por verificado
                            </label>
                            @if (! $seleccionada->carnet_ruta)
                                <div class="form-text text-warning-emphasis">
                                    No adjuntó foto: su carnet quedará sin verificar.
                                </div>
                            @endif
                        </div>
                    @elseif ($decision === 'rechazar')
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
                    @endif

                    @if ($decision === 'aprobar' || $decision === 'rechazar')
                        <button type="button"
                                class="btn {{ $decision === 'aprobar' ? 'btn-success' : 'btn-outline-danger' }}"
                                wire:click="{{ $decision === 'aprobar' ? 'aprobar' : 'rechazar' }}"
                                wire:loading.attr="disabled">
                            {{ $decision === 'aprobar' ? 'Aprobar y crear la cuenta' : 'Rechazar solicitud' }}
                        </button>
                    @endif

                    @error('solicitud')
                        <div class="alert alert-warning mt-3 mb-0">{{ $message }}</div>
                    @enderror
                @else
                    @if ($seleccionada->aprobada_automaticamente)
                        <p class="mb-1">
                            Aprobada sola el {{ \App\Support\HoraLocal::fechaHora($seleccionada->revisada_el) }}:
                            la verificación del carnet estaba apagada cuando se registró.
                        </p>
                    @else
                        <p class="mb-1">
                            {{ $seleccionada->estado === 'aprobada' ? 'Aprobada' : 'Rechazada' }}
                            por {{ $seleccionada->revisor?->name ?? '—' }}
                            el {{ \App\Support\HoraLocal::fechaHora($seleccionada->revisada_el) }}.
                        </p>
                    @endif
                    @if ($seleccionada->estado === 'aprobada')
                        <p class="mb-0 text-muted">reg_medico <code>{{ $seleccionada->reg_medico }}</code></p>
                    @elseif ($seleccionada->motivo_rechazo)
                        <p class="mb-0" style="white-space: pre-line">{{ $seleccionada->motivo_rechazo }}</p>
                    @endif
                @endif
            </div>
        </div>

        <div class="offcanvas-backdrop fade show" wire:click="cerrar" aria-hidden="true"></div>
    @endif

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />

        <div class="card-body p-0">
            <x-ui.tabla class="table-sm mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th title="Número de solicitud"><span class="visually-hidden">Solicitud </span>#</th>
                            <th>Médico</th>
                            <th>Cédula</th>
                            <th class="columna-secundaria">MPPS</th>
                            <th>Especialidad</th>
                            <th>Correo</th>
                            <th class="columna-secundaria">Referido</th>
                            <th>Recibida</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($solicitudes as $solicitud)
                            <tr wire:key="solicitud-{{ $solicitud->id }}"
                                @class(['table-active' => $seleccionada && $seleccionada->id === $solicitud->id])>
                                <td class="text-muted" data-etiqueta="#">#{{ $solicitud->id }}</td>
                                <td class="fw-semibold celda-principal" data-etiqueta="Médico">{{ $solicitud->nombreCompleto() }}</td>
                                <td data-etiqueta="Cédula">{{ $solicitud->cedula }}</td>
                                <td class="columna-secundaria" data-etiqueta="MPPS">{{ $solicitud->mpps }}</td>
                                <td data-etiqueta="Especialidad">{{ $solicitud->especialidad?->name ?? '—' }}</td>
                                <td data-etiqueta="Correo">{{ $solicitud->email }}</td>
                                <td class="columna-secundaria" data-etiqueta="Referido">
                                    @if ($solicitud->referente_reg_medico)
                                        @php($referente = $solicitud->referente)
                                        <div class="fw-semibold">
                                            {{ $referente ? trim($referente->name . ' ' . $referente->lastname) : $solicitud->referente_reg_medico }}
                                        </div>
                                        <small class="text-muted">
                                            {{ $solicitud->codigo_referido ?: 'Sin código' }}
                                            · {{ $solicitud->referente_reg_medico }}
                                        </small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td data-etiqueta="Recibida">
                                    <div>{{ \App\Support\HoraLocal::instante($solicitud->created_at)?->format(\App\Support\Regionalizacion::formatoActual()) }}</div>
                                    <small class="text-muted">{{ $solicitud->created_at?->diffForHumans() }}</small>
                                </td>
                                <td data-etiqueta="Estado">
                                    @if ($solicitud->estado === 'aprobada')
                                        <x-ui.estado tono="exito">Aprobada</x-ui.estado>
                                        @if ($solicitud->aprobada_automaticamente)
                                            <x-ui.estado tono="info">Automática</x-ui.estado>
                                        @endif
                                    @elseif ($solicitud->estado === 'rechazada')
                                        <x-ui.estado tono="neutro">Rechazada</x-ui.estado>
                                    @else
                                        <x-ui.estado tono="aviso">Pendiente</x-ui.estado>
                                    @endif
                                </td>
                                <td class="text-end celda-acciones">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            wire:click="abrir({{ $solicitud->id }})">
                                        {{ $solicitud->estaPendiente() ? 'Revisar' : 'Ver' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    {{ $estado === 'pendiente' ? 'No hay solicitudes por revisar.' : 'No hay solicitudes en esta lista.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
            </x-ui.tabla>
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
                            @if (! $claveGenerada['clave'])
                                @if ($claveGenerada['yaTeniaCuenta'])
                                    Ese médico ya tenía su cuenta y su ficha: la solicitud quedó vinculada a la ficha y
                                    <strong>no se creó otra</strong>. Entra con su contraseña de siempre.
                                @else
                                    Ya tenía cuenta: le habilitamos el área médica y entra con <strong>su contraseña de siempre</strong>, no le cambiamos ninguna clave.
                                @endif
                                @if (! $claveGenerada['correoEnviado'])
                                    <strong>El correo no salió:</strong> avísale por otro medio.
                                @endif
                            @elseif ($claveGenerada['correoEnviado'])
                                Le mandamos estos datos por correo. Por si no le llega, la contraseña se muestra solo esta vez.
                            @else
                                <strong>El correo no salió.</strong> Copia estos datos ahora y pásaselos al médico: la contraseña no se vuelve a mostrar.
                            @endif
                        </div>

                        <dl class="row mb-0">
                            <dt class="col-sm-4">Correo</dt>
                            <dd class="col-sm-8"><code>{{ $claveGenerada['correo'] }}</code></dd>
                            @if ($claveGenerada['clave'])
                                <dt class="col-sm-4">Contraseña temporal</dt>
                                <dd class="col-sm-8"><code class="fs-5">{{ $claveGenerada['clave'] }}</code></dd>
                            @endif
                        </dl>
                    </div>
                    <div class="modal-footer">
                        @if ($claveGenerada['clave'])
                            <button type="button" class="btn btn-outline-primary"
                                    onclick="navigator.clipboard.writeText({{ json_encode($claveGenerada['clave']) }}); this.textContent = 'Copiada';">
                                <i class="bi bi-clipboard me-1"></i> Copiar contraseña
                            </button>
                        @endif
                        <button type="button" class="btn btn-primary" wire:click="cerrarClave">Listo</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
