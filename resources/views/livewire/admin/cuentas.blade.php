<div class="mt-4">
    <div class="row mb-3 align-items-center">
        <div class="col-md-5">
            <h4 class="fw-bold mb-0">Usuarios</h4>
            <small class="text-muted">Médicos y administradores: altas, claves y bloqueos.</small>
        </div>
        <div class="col-md-7 text-md-end mt-2 mt-md-0">
            <button wire:click="abrirAlta('medico')" class="btn btn-primary">
                <i class="bi bi-person-plus-fill me-1"></i> Nuevo médico
            </button>
            <button wire:click="abrirAlta('administrador')" class="btn btn-outline-primary">
                <i class="bi bi-shield-plus me-1"></i> Nuevo administrador
            </button>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    @endif

    {{-- Errores de reglas de negocio de acciones sin ventana (p. ej. "último administrador") --}}
    @if (! $modal && ($errors->has('cuenta') || $errors->has('medico')))
        <div class="alert alert-danger" role="alert">
            {{ $errors->first('cuenta') ?: $errors->first('medico') }}
        </div>
    @endif

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button type="button" class="nav-link {{ $pestana === 'medicos' ? 'active' : '' }}" wire:click="$set('pestana', 'medicos')">
                <i class="bi bi-person-badge me-1"></i> Médicos
            </button>
        </li>
        <li class="nav-item">
            <button type="button" class="nav-link {{ $pestana === 'administradores' ? 'active' : '' }}" wire:click="$set('pestana', 'administradores')">
                <i class="bi bi-shield-lock me-1"></i> Administradores
            </button>
        </li>
    </ul>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <div class="row">
                <div class="col-md-5">
                    <input type="text" wire:model.debounce.400ms="search" class="form-control"
                           placeholder="{{ $pestana === 'medicos' ? 'Buscar por nombre, correo o registro...' : 'Buscar por nombre o correo...' }}">
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
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
                                <td>
                                    @if ($pestana === 'medicos')
                                        <div class="fw-semibold">{{ trim($fila->name . ' ' . $fila->lastname) }}</div>
                                        <small class="text-muted">{{ $fila->reg_medico }}</small>
                                    @else
                                        <div class="fw-semibold">{{ $fila->name }}</div>
                                        @if ($m)
                                            <small class="text-muted">También médico · {{ $m->reg_medico }}</small>
                                        @endif
                                    @endif
                                </td>
                                <td>{{ $u?->email ?? $fila->email }}</td>
                                <td>
                                    @if ($u)
                                        @foreach ($u->roles as $rol)
                                            <span class="badge bg-info text-dark">{{ $rol->name }}</span>
                                        @endforeach
                                    @endif
                                </td>
                                <td>
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
                                <td class="text-end text-nowrap">
                                    @if (! $u)
                                        <button wire:click="abrirAcceso({{ $fila->id }})" class="btn btn-sm btn-primary">
                                            <i class="bi bi-key me-1"></i> Crear acceso
                                        </button>
                                    @else
                                        <button wire:click="abrirReset({{ $u->id }})" class="btn btn-sm btn-outline-secondary" title="Poner una clave temporal nueva">
                                            <i class="bi bi-arrow-repeat me-1"></i> Resetear clave
                                        </button>

                                        @if ($u->is_active === false)
                                            <button wire:click="desbloquear({{ $u->id }})" class="btn btn-sm btn-outline-success">
                                                <i class="bi bi-unlock me-1"></i> Desbloquear
                                            </button>
                                        @else
                                            <button wire:click="abrirBloqueo({{ $u->id }})" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-lock me-1"></i> Bloquear
                                            </button>
                                        @endif

                                        @if ($u->esAdministrador())
                                            <button wire:click="abrirQuitarAdmin({{ $u->id }})" class="btn btn-sm btn-outline-dark" title="Quitar el rol de administrador">
                                                <i class="bi bi-shield-x me-1"></i> Quitar admin
                                            </button>
                                        @else
                                            <button wire:click="hacerAdministrador({{ $u->id }})" class="btn btn-sm btn-outline-dark" title="Sumar el rol de administrador (conserva los demás)">
                                                <i class="bi bi-shield-check me-1"></i> Hacer admin
                                            </button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No se encontraron registros.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
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
            <div class="modal-dialog">
                <div class="modal-content">
                    <form wire:submit.prevent="guardarAlta">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $tipoAlta === 'administrador' ? 'Nuevo administrador' : 'Nuevo médico' }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="{{ $tipoAlta === 'administrador' ? 'col-12' : 'col-md-6' }} mb-3">
                                    <label class="form-label">{{ $tipoAlta === 'administrador' ? 'Nombre' : 'Nombres' }} <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" wire:model.defer="name">
                                    @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                @if ($tipoAlta === 'medico')
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Apellidos <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" wire:model.defer="lastname">
                                        @error('lastname') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                @endif

                                <div class="col-12 mb-3">
                                    <label class="form-label">Correo <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" wire:model.defer="email" autocomplete="off">
                                    @error('email') <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>

                                @if ($tipoAlta === 'medico')
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Registro médico (reg_medico) <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" wire:model.defer="reg_medico" autocomplete="off">
                                        <div class="form-text">Debe ser el mismo que tiene el médico en su GinecoReport (tabla <code>evolucion</code>).</div>
                                        @error('reg_medico') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                @endif

                                <div class="col-12 mb-1">
                                    <label class="form-label">Contraseña temporal</label>
                                    <input type="text" class="form-control" wire:model.defer="clave" autocomplete="off" placeholder="Vacío = se genera una">
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
            <div class="modal-dialog">
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
                            <input type="text" class="form-control" wire:model.defer="clave" autocomplete="off" placeholder="Vacío = se genera una">
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
                            <input type="text" class="form-control" wire:model.defer="clave" autocomplete="off" placeholder="Vacío = se genera una">
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
                            <input type="text" class="form-control" wire:model.defer="motivo" maxlength="255" placeholder="Ej.: falta de pago">
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

    {{-- Quitar el rol de administrador --}}
    @if ($modal === 'quitar-admin')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Quitar rol de administrador</h5>
                        <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        @error('cuenta') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror
                        <p class="mb-0">Dejará de ver esta sección. Conserva sus otros roles (por ejemplo, médico).</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                        <button type="button" class="btn btn-dark" wire:click="confirmarQuitarAdmin">Quitar rol</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

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
</div>
