<div>
    <div class="card shadow-sm border-0 mb-4">
        <!-- Cabecera Limpia -->
        <div class="card-header bg-white py-3 border-bottom-0">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div>
                    <h5 class="mb-0 text-primary fw-bold">
                        <i class="bi bi-people-fill me-2"></i>Pacientes
                    </h5>
                    <small class="text-muted">Gestión de historias y datos de contacto</small>
                </div>
                <div>
                    <button class="btn btn-primary btn-sm w-100 w-sm-auto px-3" wire:click="create">
                        <i class="bi bi-plus-lg me-1"></i> Nuevo Paciente
                    </button>
                </div>
            </div>
        </div>

        <div class="card-body pt-0">
            @if (session()->has('message'))
                <div class="alert alert-success alert-dismissible fade show mb-3 py-2" role="alert">
                    <small><i class="bi bi-check-circle me-1"></i> {{ session('message') }}</small>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <!-- Buscador -->
            <div class="row mb-3">
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0 ps-0" 
                            placeholder="Buscar por cédula, nombre o N° historia..." 
                            wire:model="search">
                    </div>
                </div>
            </div>

            <!-- 1. VISTA MÓVIL (Tarjetas compactas) -->
            <div class="d-block d-md-none">
                @forelse($pacientes as $paciente)
                    <div class="card mb-2 border shadow-none bg-light">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="badge bg-primary me-1">
                                        {{ $paciente->nac }}-{{ $paciente->cedula }}
                                    </span>
                                    <span class="badge bg-secondary">
                                        {{ $paciente->numhistoria ?? ($paciente->pivot->numhistoria ?? 'S/H') }}
                                    </span>
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-sm btn-outline-warning py-0 px-2" wire:click="edit({{ $paciente->id }})">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="confirm('¿Desea eliminar este paciente?') || event.stopImmediatePropagation()" wire:click="delete({{ $paciente->id }})">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <h6 class="fw-bold mb-1 text-dark">{{ $paciente->nombres }} {{ $paciente->apellidos }}</h6>
                            <div class="text-muted small">
                                @if($paciente->telefono)
                                    <div><i class="bi bi-telephone me-1"></i>{{ $paciente->telefono }}</div>
                                @endif
                                @if($paciente->email)
                                    <div><i class="bi bi-envelope me-1"></i>{{ $paciente->email }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-4 text-muted border rounded bg-light">
                        <i class="bi bi-inbox fs-4 d-block mb-1"></i>
                        <small>No se encontraron pacientes.</small>
                    </div>
                @endforelse
            </div>

            <!-- 2. VISTA ESCRITORIO -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle border-top mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" style="width: 110px;">N° Historia</th>
                            <th scope="col" style="width: 130px;">Cédula</th>
                            <th scope="col">Paciente</th>
                            <th scope="col">Teléfono</th>
                            <th scope="col">Email</th>
                            <th scope="col" class="text-center" style="width: 100px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pacientes as $paciente)
                            <tr>
                                <td>
                                    <span class="badge bg-secondary font-monospace">
                                        {{ $paciente->numhistoria ?? ($paciente->pivot->numhistoria ?? 'S/H') }}
                                    </span>
                                </td>
                                <td class="fw-bold text-nowrap">
                                    {{ $paciente->nac }}-{{ $paciente->cedula }}
                                </td>
                                <td class="fw-semibold">
                                    {{ $paciente->nombres }} {{ $paciente->apellidos }}
                                </td>
                                <td class="small">
                                    {{ $paciente->telefono ?? 'N/A' }}
                                </td>
                                <td class="small">
                                    {{ $paciente->email ?? 'N/A' }}
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-outline-warning" wire:click="edit({{ $paciente->id }})" title="Editar">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" onclick="confirm('¿Está seguro de remover este paciente?') || event.stopImmediatePropagation()" wire:click="delete({{ $paciente->id }})" title="Eliminar">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    No se encontraron pacientes registrados.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Paginación con estilo Bootstrap Responsive -->
            <div class="mt-3 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
                <div class="d-flex justify-content-between w-100 d-md-none">
                    <button class="btn btn-outline-primary btn-sm px-3" wire:click="previousPage" @if($pacientes->onFirstPage()) disabled @endif>
                        <i class="bi bi-chevron-left me-1"></i> Anterior
                    </button>
                    <span class="small text-muted align-self-center">
                        Página {{ $pacientes->currentPage() }} de {{ $pacientes->lastPage() }}
                    </span>
                    <button class="btn btn-outline-primary btn-sm px-3" wire:click="nextPage" @if(!$pacientes->hasMorePages()) disabled @endif>
                        Siguiente <i class="bi bi-chevron-right ms-1"></i>
                    </button>
                </div>

                <div class="d-none d-md-block ms-auto">
                    {{ $pacientes->links() }}
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Formulario Paciente -->
    <div class="modal fade" id="modalPaciente" tabindex="-1" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold fs-6">
                        <i class="bi bi-person-lines-fill me-2"></i>
                        {{ $paciente_id ? 'Editar Paciente' : 'Nuevo Paciente' }}
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <form wire:submit.prevent="save">
                    <div class="modal-body p-3">
                        
                        <!-- Identificación -->
                        <div class="bg-light p-2 rounded mb-3">
                            <span class="text-primary fw-bold small text-uppercase"><i class="bi bi-card-heading me-1"></i> Identificación</span>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-semibold">N° Historia</label>
                                <input type="text" class="form-control form-control-sm" wire:model="numhistoria" placeholder="Ej: H-1002">
                                @error('numhistoria') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-semibold">Nac.</label>
                                <select class="form-select form-select-sm" wire:model="nac">
                                    <option value="V">V</option>
                                    <option value="E">E</option>
                                    <option value="P">P</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-semibold">Cédula / Documento <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" wire:model="cedula">
                                @error('cedula') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <!-- Datos Personales -->
                        <div class="bg-light p-2 rounded mb-3">
                            <span class="text-primary fw-bold small text-uppercase"><i class="bi bi-person me-1"></i> Datos Personales</span>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-semibold">Nombres <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" wire:model="nombres">
                                @error('nombres') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-semibold">Apellidos <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" wire:model="apellidos">
                                @error('apellidos') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label small fw-semibold">Sexo</label>
                                <select class="form-select form-select-sm" wire:model="sexo">
                                    <option value="">Seleccione...</option>
                                    <option value="M">Masculino</option>
                                    <option value="F">Femenino</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label small fw-semibold">Fecha Nacimiento</label>
                                <input type="date" class="form-control form-control-sm" wire:model="fnacimiento">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-semibold">Lugar Nacimiento</label>
                                <input type="text" class="form-control form-control-sm" wire:model="lnacimiento">
                            </div>
                        </div>

                        <!-- Contacto y Detalles -->
                        <div class="bg-light p-2 rounded mb-3">
                            <span class="text-primary fw-bold small text-uppercase"><i class="bi bi-telephone me-1"></i> Contacto y Perfil</span>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-semibold">Teléfono</label>
                                <input type="text" class="form-control form-control-sm" wire:model="telefono" placeholder="0414-0000000">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-semibold">Email</label>
                                <input type="email" class="form-control form-control-sm" wire:model="email">
                                @error('email') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-4 col-md-4">
                                <label class="form-label small fw-semibold">Escolaridad</label>
                                <input type="text" class="form-control form-control-sm" wire:model="escolaridad">
                            </div>
                            <div class="col-4 col-md-4">
                                <label class="form-label small fw-semibold">Ocupación</label>
                                <input type="text" class="form-control form-control-sm" wire:model="ocupacion">
                            </div>
                            <div class="col-4 col-md-4">
                                <label class="form-label small fw-semibold">Profesión</label>
                                <input type="text" class="form-control form-control-sm" wire:model="profesion">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Dirección</label>
                                <textarea class="form-control form-control-sm" rows="2" wire:model="direccion"></textarea>
                            </div>
                        </div>

                    </div>
                    
                    <div class="modal-footer bg-light py-2">
                        <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm px-3">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modalElement = document.getElementById('modalPaciente');
            const modal = new bootstrap.Modal(modalElement);
            
            window.addEventListener('open-modal-paciente', event => {
                modal.show();
            });

            window.addEventListener('close-modal-paciente', event => {
                modal.hide();
            });
        });
    </script>
</div>