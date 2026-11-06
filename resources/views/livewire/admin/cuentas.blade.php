<div>
    <x-ui.page-head :titulo="$pestana === 'medicos' ? 'Usuarios · Médicos' : 'Usuarios · Administradores'" subtitulo="Altas, claves y bloqueos.">
        <x-slot name="acciones">
            <button wire:click="abrirAlta('medico')" class="btn btn-primary">
                <i class="bi bi-person-plus-fill" aria-hidden="true"></i> Nuevo médico
            </button>
            <button wire:click="abrirAlta('administrador')" class="btn btn-outline-primary">
                <i class="bi bi-shield-plus" aria-hidden="true"></i> Nuevo administrador
            </button>
        </x-slot>
    </x-ui.page-head>

    <x-ui.avisos />

    {{-- Errores de reglas de negocio de acciones sin ventana (p. ej. "último administrador") --}}
    @if (! $modal && ($errors->has('cuenta') || $errors->has('medico')))
        <div class="alert alert-danger" role="alert">
            {{ $errors->first('cuenta') ?: $errors->first('medico') }}
        </div>
    @endif

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-header bg-white py-3">
            <div class="row">
                <div class="col-md-5">
                    <input type="text" wire:model.live.debounce.400ms="search" class="form-control"
                           placeholder="{{ $pestana === 'medicos' ? 'Buscar por nombre, correo o registro...' : 'Buscar por nombre o correo...' }}">
                </div>
            </div>
        </div>

        <div class="card-body p-0 tabla-cuentas">
            <x-ui.tabla class="mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>{{ $pestana === 'medicos' ? 'Médico' : 'Nombre' }}</th>
                            <th>Correo</th>
                            <th>Roles</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            @php
                                $u = $pestana === 'medicos' ? $fila->user : $fila;
                                $m = $pestana === 'medicos' ? $fila : $fila->medico;
                            @endphp
                            <tr wire:key="{{ $pestana }}-{{ $fila->id }}">
                                <td class="celda-principal">
                                    @if ($pestana === 'medicos')
                                        <div class="fw-semibold">{{ trim($fila->name . ' ' . $fila->lastname) }}</div>
                                        <small class="text-muted">{{ $fila->reg_medico }}</small>
                                        @if ($fila->es_centro)
                                            @if ($fila->esConsultorioHijo())
                                                <span class="badge bg-primary" title="Un consultorio más del mismo médico: usa su plan, no tiene cuenta y no le suma un asiento">Consultorio</span>
                                            @else
                                                <span class="badge bg-primary" title="Es un centro médico: no inicia sesión ni atiende, es el tenant de sus doctores">Centro médico</span>
                                            @endif
                                        @endif
                                        @php $extras = $fila->registros->where('reg_medico', '!=', $fila->reg_medico)->count(); @endphp
                                        @if ($extras > 0)
                                            <span class="badge bg-light text-dark border" title="Además ve los datos de otros {{ $extras }} reg_medico">+{{ $extras }} registro{{ $extras > 1 ? 's' : '' }}</span>
                                        @endif
                                    @else
                                        <div class="fw-semibold">{{ $fila->name }}</div>
                                        @if ($m)
                                            <small class="text-muted">También médico · {{ $m->reg_medico }}</small>
                                        @endif
                                    @endif
                                </td>
                                <td data-etiqueta="Correo">{{ $u?->email ?? $fila->email }}</td>
                                <td data-etiqueta="Roles">
                                    @if ($u)
                                        @foreach ($u->roles as $rol)
                                            <span class="badge bg-info text-dark">{{ $rol->name }}</span>
                                        @endforeach
                                    @endif
                                </td>
                                <td data-etiqueta="Estado">
                                    @if (! $u)
                                        <span class="badge bg-secondary">Sin acceso</span>
                                    @elseif ($u->is_active === false)
                                        <span class="badge bg-danger" @if ($u->blocked_reason) title="{{ $u->blocked_reason }}" @endif>Bloqueado</span>
                                    @else
                                        <span class="badge bg-success">Activo</span>
                                    @endif
                                    @if ($u && $u->must_change_password)
                                        <span class="badge bg-warning text-dark" title="Debe cambiar su clave temporal en el próximo inicio de sesión">Clave temporal</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap celda-acciones">
                                    @if (! $u && ! $fila->es_centro)
                                        <button wire:click="abrirAcceso({{ $fila->id }})" class="btn btn-sm btn-primary">
                                            <i class="bi bi-key me-1"></i> Crear acceso
                                        </button>
                                    @endif

                                    <button wire:click="abrirEditar('{{ $pestana === 'medicos' ? 'medico' : 'administrador' }}', {{ $fila->id }})"
                                            class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square me-1"></i> Editar
                                    </button>

                                    {{-- «Ver» y «Ver sedes» van **en el menú «…»**, no como botones directos: con
                                         «Crear acceso» + «Ver» + «Editar» + «Ver sedes» + el menú la fila medía 1004 px y
                                         a 1280 px se salía de su tarjeta. La pauta es hasta dos acciones directas y el
                                         resto en el menú (guía de UI §9.1). --}}
                                    @if ($u || $m || $pestana === 'medicos')
                                        <div class="dropdown d-inline-block">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown"
                                                    aria-expanded="false" title="Más acciones" aria-label="Más acciones">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                @if ($pestana === 'medicos')
                                                    <li>
                                                        <a class="dropdown-item" href="{{ route('admin.cuentas.medico', $fila) }}">
                                                            <i class="bi bi-eye me-2" aria-hidden="true"></i> Ver
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="{{ route('admin.medicos.sedes', $fila) }}">
                                                            <i class="bi bi-geo-alt me-2" aria-hidden="true"></i> Ver sedes
                                                        </a>
                                                    </li>
                                                    @if ($u || $m)
                                                        <li><hr class="dropdown-divider"></li>
                                                    @endif
                                                @endif
                                                @if ($u)
                                                    <li>
                                                        <button type="button" class="dropdown-item" wire:click="abrirRoles({{ $u->id }})">
                                                            <i class="bi bi-shield-lock me-2"></i> Roles
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button type="button" class="dropdown-item" wire:click="abrirReset({{ $u->id }})">
                                                            <i class="bi bi-arrow-repeat me-2"></i> Resetear clave
                                                        </button>
                                                    </li>
                                                @endif

                                                @if ($m)
                                                    <li>
                                                        <button type="button" class="dropdown-item" wire:click="abrirRegistros({{ $m->id }})">
                                                            <i class="bi bi-database-lock me-2"></i>
                                                            {{ $m->esConsultorioHijo() ? 'Doctores del consultorio' : ($m->es_centro ? 'Doctores del centro' : 'Registros de datos') }}
                                                        </button>
                                                    </li>
                                                    {{-- **El formato de impresión del centro** (Fase 5 del cambio de `reg_medico`): el
                                                         membrete, el logo y el pie con los que el centro imprime los reportes de sus
                                                         doctores viven bajo su `reg_medico`. Un centro no inicia sesión, así que el
                                                         formato se abre desde acá, con el parámetro que resuelve la pantalla de
                                                         `/clinica/formato` (`ResuelveElRegistroDelFormato`, sólo `Root`). La firma y el
                                                         sello de cada reporte son los del doctor que atendió. --}}
                                                    @if ($m->es_centro && auth()->user()?->hasRole('Root'))
                                                        <li>
                                                            <a class="dropdown-item" href="{{ route('clinica.formato', ['reg_medico' => $m->reg_medico]) }}">
                                                                <i class="bi bi-printer me-2"></i> Formato de impresión del centro
                                                            </a>
                                                        </li>
                                                    @endif
                                                    @if (! $m->es_centro)
                                                        <li>
                                                            <button type="button" class="dropdown-item" wire:click="abrirEmitir({{ $m->id }})">
                                                                <i class="bi bi-key-fill me-2"></i> Generar API key
                                                            </button>
                                                        </li>
                                                    @endif
                                                @endif

                                                @if ($u)
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        @if ($u->is_active === false)
                                                            <button type="button" class="dropdown-item text-success" wire:click="desbloquear({{ $u->id }})">
                                                                <i class="bi bi-unlock me-2"></i> Desbloquear
                                                            </button>
                                                        @else
                                                            <button type="button" class="dropdown-item text-danger" wire:click="abrirBloqueo({{ $u->id }})">
                                                                <i class="bi bi-lock me-2"></i> Bloquear
                                                            </button>
                                                        @endif
                                                    </li>
                                                @endif
                                            </ul>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No se encontraron registros.</td>
                            </tr>
                        @endforelse
                    </tbody>
            </x-ui.tabla>
        </div>

        @if ($filas->hasPages())
            <div class="card-footer bg-white">
                {{ $filas->links() }}
            </div>
        @endif
    </div>

    {{-- ============================ Ventanas ============================ --}}

    {{-- Alta de médico / administrador --}}
    @if ($modal === 'alta')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lateral">
                <div class="modal-content">
                    <form wire:submit.prevent="guardarAlta">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $tipoAlta === 'administrador' ? 'Nuevo administrador' : 'Nuevo médico' }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                @if ($tipoAlta === 'medico')
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Prefijo</label>
                                        <input type="text" class="form-control" maxlength="20" placeholder="Dr" wire:model="prefix">
                                        @error('prefix') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    </div>
                                @endif

                                <div class="{{ $tipoAlta === 'administrador' ? 'col-12' : 'col-md-4' }} mb-3">
                                    <label class="form-label">{{ $tipoAlta === 'administrador' ? 'Nombre' : 'Nombres' }} <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" wire:model="name">
                                    @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                @if ($tipoAlta === 'medico')
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Apellidos <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" wire:model="lastname">
                                        @error('lastname') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                @endif

                                <div class="col-12 mb-3">
                                    <label class="form-label">Correo @if (! $es_centro) <span class="text-danger">*</span> @endif</label>
                                    <input type="email" class="form-control" wire:model="email" autocomplete="off">
                                    @if ($es_centro)
                                        <div class="form-text">Opcional: un centro médico no inicia sesión.</div>
                                    @endif
                                    @error('email') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                @if ($tipoAlta === 'medico')
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Registro médico (reg_medico)</label>
                                        <input type="text" class="form-control" wire:model="reg_medico" autocomplete="off" placeholder="Vacío = se genera uno">
                                        <div class="form-text">Vacío = se genera uno (med_00001, med_00002…).</div>
                                        @error('reg_medico') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>

                                    {{-- **Centro médico** (Fase 2 del cambio de `reg_medico`): una fila de `medicos` con
                                         `es_centro` y su `reg_medico`, SIN cuenta de acceso. Es el tenant donde trabajan
                                         los doctores; el centro no atiende. --}}
                                    <div class="col-12 mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="alta-es-centro" wire:model.live="es_centro">
                                            <label class="form-check-label" for="alta-es-centro">
                                                <strong>Es centro médico</strong>
                                                <span class="d-block form-text mb-0">
                                                    No tendrá cuenta de acceso (no inicia sesión ni atiende): sólo la ficha del centro, con
                                                    sus consultorios, su agenda y sus pacientes. Los doctores se asocian después desde
                                                    «Registros de datos».
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                @endif

                                <div class="col-12 mb-1">
                                    <label class="form-label">Contraseña temporal</label>
                                    <input type="text" class="form-control" wire:model="clave" autocomplete="off"
                                           placeholder="{{ $es_centro ? 'No se usa: un centro no inicia sesión' : 'Vacío = se genera una' }}"
                                           {{ $es_centro ? 'disabled' : '' }}>
                                    <div class="form-text">Mínimo 8 caracteres. En su primer inicio de sesión se le obliga a cambiarla.</div>
                                    @error('clave') <span class="text-danger small">{{ $message }}</span> @enderror
                                    @error('password') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Crear cuenta</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- Crear acceso a un médico que no tenía cuenta --}}
    @if ($modal === 'acceso')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lateral">
                <div class="modal-content">
                    <form wire:submit.prevent="guardarAcceso">
                        <div class="modal-header">
                            <h5 class="modal-title">Crear acceso</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3">Este médico existe pero todavía no puede iniciar sesión. Entrará con el correo <strong>{{ $email ?: '—' }}</strong>.</p>
                            @error('email') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror
                            @error('medico') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror
                            <label class="form-label">Contraseña temporal</label>
                            <input type="text" class="form-control" wire:model="clave" autocomplete="off" placeholder="Vacío = se genera una">
                            <div class="form-text">Mínimo 8 caracteres. Se le obliga a cambiarla en el primer inicio de sesión.</div>
                            @error('password') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Crear acceso</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- Resetear clave --}}
    @if ($modal === 'reset')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form wire:submit.prevent="confirmarReset">
                        <div class="modal-header">
                            <h5 class="modal-title">Resetear clave</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <p>Se le pondrá una clave temporal y se <strong>cerrarán todas sus sesiones</strong>. En su próximo inicio de sesión tendrá que elegir una clave nueva.</p>
                            <label class="form-label">Contraseña temporal</label>
                            <input type="text" class="form-control" wire:model="clave" autocomplete="off" placeholder="Vacío = se genera una">
                            @error('password') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Resetear clave</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- Bloquear --}}
    @if ($modal === 'bloquear')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form wire:submit.prevent="confirmarBloqueo">
                        <div class="modal-header">
                            <h5 class="modal-title">Bloquear cuenta</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            @error('cuenta') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror
                            <p>No podrá usar el app ni el panel y se cerrarán sus sesiones. <strong>La sincronización del escritorio no se corta</strong>: sus datos siguen respaldándose.</p>
                            <label class="form-label">Motivo (opcional)</label>
                            <input type="text" class="form-control" wire:model="motivo" maxlength="255" placeholder="Ej.: falta de pago">
                            @error('motivo') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-danger">Bloquear</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- Editar los datos. El formulario del médico es el mismo componente que usa el detalle del médico. --}}
    @if ($modal === 'editar')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lateral">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $tipoEdicion === 'administrador' ? 'Editar administrador' : 'Editar médico' }}</h5>
                        <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                    </div>

                    @if ($tipoEdicion === 'administrador')
                        <form wire:submit.prevent="guardarEdicion">
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Nombre <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" wire:model="name">
                                        @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>

                                    <div class="col-12 mb-3">
                                        <label class="form-label">Correo <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" wire:model="email" autocomplete="off">
                                        <div class="form-text">Es el correo con el que inicia sesión.</div>
                                        @error('email') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                                <button type="submit" class="btn btn-primary">Guardar cambios</button>
                            </div>
                        </form>
                    @else
                        <x-admin.medico-datos-form :formatos="$formatosDeReporte" :es-centro="$es_centro" :reg-medico="$reg_medico"
                                                   accion-guardar="guardarEdicion" accion-cancelar="cerrarModal" />
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Roles (el mismo formulario que usa el detalle del médico) --}}
    @if ($modal === 'roles')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lateral">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Roles</h5>
                        <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                    </div>
                    <x-admin.roles-form :roles="$rolesDisponibles" accion-guardar="guardarRoles" accion-cancelar="cerrarModal" />
                </div>
            </div>
        </div>
    @endif

    {{-- Registros de datos (reg_medico) de un médico --}}
    @if ($modal === 'registros' && $medicoRegistros)
        @php
            $regsDelMedico = app(\App\Services\CuentaService::class)->registrosDe($medicoRegistros);
            $asignables = app(\App\Services\CuentaService::class)->registrosAsignables($medicoRegistros);
        @endphp
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            {{ $medicoRegistros->esConsultorioHijo() ? 'Doctores del consultorio' : ($medicoRegistros->es_centro ? 'Doctores del centro' : 'Registros de datos') }}
                            · {{ trim($medicoRegistros->name . ' ' . $medicoRegistros->lastname) }}
                        </h5>
                        <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        @if ($avisoRegistros) <div class="alert alert-success py-2">{{ $avisoRegistros }}</div> @endif
                        @error('regNuevo') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror
                        @error('doctorNuevo') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                        @if ($medicoRegistros->es_centro)
                            {{-- **Los doctores del centro** (Fase 2 del cambio de `reg_medico`): el centro asocia
                                 doctores ya registrados y acá se ven los que trabajan en él hoy. Desvincular sólo
                                 quita la fila de `medico_registros`: no borra datos ni la cuenta del doctor. --}}
                            <p class="text-muted small">
                                Los doctores que trabajan en el centro. Cada uno lo ve en su contexto —con sus
                                pacientes y su agenda— y el centro no gana ninguna cuenta: el centro no atiende.
                                Desvincular no borra ningún dato, sólo el acceso del doctor a este centro.
                            </p>

                            <h6 class="mt-3">Doctores del centro</h6>
                            @if ($doctoresDelCentro->isEmpty())
                                <p class="text-muted small mb-2">Todavía no tiene doctores vinculados.</p>
                            @else
                                <ul class="list-group mb-3">
                                    @foreach ($doctoresDelCentro as $doctor)
                                        <li class="list-group-item d-flex justify-content-between align-items-center" wire:key="doctor-{{ $doctor->id }}">
                                            <span>
                                                {{ trim($doctor->name . ' ' . $doctor->lastname) }}
                                                <small class="text-muted">· {{ $doctor->reg_medico }}</small>
                                            </span>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    wire:click="quitarDoctorDelCentro({{ $doctor->id }})" wire:loading.attr="disabled">
                                                <i class="bi bi-x-circle me-1"></i> Desvincular
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <h6 class="mt-3">Vincular un doctor que ya está registrado</h6>
                            @if ($doctoresVinculables->isEmpty())
                                <p class="text-muted small mb-0">
                                    No hay doctores sin vincular. Se dan de alta desde «Nuevo médico», con su propio registro profesional.
                                </p>
                            @else
                                <form wire:submit.prevent="vincularDoctorAlCentro" class="d-flex gap-2">
                                    <select class="form-select" wire:model="doctorNuevo" aria-label="doctor a vincular al centro">
                                        <option value="">Elegir un doctor…</option>
                                        @foreach ($doctoresVinculables as $doctor)
                                            <option value="{{ $doctor->id }}">{{ trim($doctor->name . ' ' . $doctor->lastname) }} ({{ $doctor->reg_medico }})</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-primary text-nowrap" wire:loading.attr="disabled">
                                        <span wire:loading.remove wire:target="vincularDoctorAlCentro">Vincular</span>
                                        <span wire:loading wire:target="vincularDoctorAlCentro">Vinculando…</span>
                                    </button>
                                </form>
                            @endif
                        @else
                            <p class="text-muted small">
                                Los <code>reg_medico</code> cuyos datos (agenda, pacientes, consultas, récipes) ve este médico. Sirve para que un
                                colega o una asistente compartan un consultorio, o para depurar con otra cuenta. <strong>Mirar es seguro</strong>;
                                si edita algo que ya existe (confirmar o cobrar una cita), el cambio queda en los datos de ese registro.
                                Quitar un registro no borra ningún dato: solo el acceso de este médico.
                            </p>

                            <ul class="list-group mb-3">
                                @foreach ($regsDelMedico as $reg)
                                    <li class="list-group-item d-flex justify-content-between align-items-center" wire:key="reg-{{ $reg }}">
                                        <span>
                                            <code>{{ $reg }}</code>
                                            @if ($reg === $medicoRegistros->reg_medico) <span class="badge bg-secondary ms-1">propio</span> @endif
                                        </span>
                                        @if ($reg !== $medicoRegistros->reg_medico)
                                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="quitarRegistro({{ json_encode($reg) }})"
                                                    wire:loading.attr="disabled">
                                                <i class="bi bi-x-circle me-1"></i> Quitar
                                            </button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            <form wire:submit.prevent="asignarRegistro" class="d-flex gap-2">
                                <select class="form-select" wire:model="regNuevo" aria-label="reg_medico a asignar">
                                    <option value="">Asignar un reg_medico…</option>
                                    @foreach ($asignables as $reg => $etiqueta)
                                        <option value="{{ $reg }}">{{ $etiqueta }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-primary text-nowrap" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="asignarRegistro">Asignar</span>
                                    <span wire:loading wire:target="asignarRegistro">Asignando…</span>
                                </button>
                            </form>

                            {{-- **El segundo consultorio del médico** (2026-11-06): no es otra cuenta, es una ficha
                                 que cuelga de la suya y usa su plan. La credencial de esa PC sale de API Keys. --}}
                            <hr class="my-4">
                            <h6>Agregar un consultorio</h6>
                            <p class="text-muted small mb-2">
                                El otro sitio donde atiende este médico, con su propio <code>reg_medico</code> (el de
                                esa instalación; si lo dejas vacío se genera uno). No crea una cuenta: el consultorio
                                <strong>usa el plan de este médico</strong> y no le suma un asiento al equipo. Después se
                                emite la credencial de esa PC en <strong>API Keys</strong>.
                            </p>
                            <form wire:submit.prevent="agregarConsultorio" class="d-flex gap-2">
                                <input type="text" class="form-control" wire:model="regConsultorio" maxlength="20"
                                       autocomplete="off" placeholder="reg_medico (vacío = se genera)"
                                       aria-label="reg_medico del consultorio nuevo">
                                <button type="submit" class="btn btn-primary text-nowrap" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="agregarConsultorio">Agregar consultorio</span>
                                    <span wire:loading wire:target="agregarConsultorio">Agregando…</span>
                                </button>
                            </form>
                            @error('reg_consultorio') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Generar API key (la misma ventana que en la sección "API Keys") --}}
    @include('livewire.admin.partials.api-key-emitir')

    {{-- Clave generada: se muestra UNA sola vez --}}
    @if ($claveGenerada)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="bi bi-check-circle me-1"></i> {{ $claveGenerada['titulo'] }}</h5>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning small">
                            Copia estos datos <strong>ahora</strong>: la contraseña no se vuelve a mostrar. Es temporal: la persona tendrá que cambiarla en su primer inicio de sesión.
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

    @include('livewire.admin.partials.api-key-generada')
</div>
