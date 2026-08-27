<div>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3">
            <div class="row align-items-center g-2">
                <div class="col-12 col-md-6">
                    <h4 class="mb-0 text-primary fw-bold">
                        <i class="bi bi-people-fill me-2"></i>Listado de Pacientes
                    </h4>
                </div>
                <div class="col-12 col-md-6 text-md-end">
                    <button class="btn btn-primary w-100 w-md-auto" wire:click="create">
                        <i class="bi bi-plus-lg me-1"></i> Nuevo Paciente
                    </button>
                </div>
            </div>
        </div>

        <div class="card-body">
            @if (session()->has('message'))
                <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-check-circle me-1"></i> {{ session('message') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <!-- Filtros y Búsqueda -->
            <div class="row mb-3">
                <div class="col-12 col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0 ps-0" 
                            placeholder="Buscar por cédula, nombre, apellido o N° historia..." 
                            wire:model.live="search">
                    </div>
                </div>
            </div>

            <!-- Tabla de Pacientes -->
            <div class="table-responsive">
                <table class="table table-hover align-middle border-top">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" style="width: 100px;">Historia</th>
                            <th scope="col" style="width: 130px;">Cédula</th>
                            <th scope="col">Paciente</th>
                            <th scope="col" class="d-none d-md-table-cell">Teléfono</th>
                            <th scope="col" class="d-none d-lg-table-cell">Email</th>
                            <th scope="col" class="text-center" style="width: 120px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pacientes as $paciente)
                            <tr>
                                <td>
                                    <span class="badge bg-secondary">
                                        {{ $paciente->pivot->numhistoria ?? 'S/H' }}
                                    </span>
                                </td>
                                <td class="fw-bold text-nowrap">
                                    {{ $paciente->nac }}-{{ $paciente->cedula }}
                                </td>
                                <td>
                                    <div class="fw-bold">{{ $paciente->nombres }} {{ $paciente->apellidos }}</div>
                                    <small class="text-muted d-block d-md-none">
                                        <i class="bi bi-telephone"></i> {{ $paciente->telefono ?? 'N/A' }}
                                    </small>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    {{ $paciente->telefono ?? 'N/A' }}
                                </td>
                                <td class="d-none d-lg-table-cell">
                                    {{ $paciente->email ?? 'N/A' }}
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-outline-warning" wire:click="edit({{ $paciente->id }})" title="Editar">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" wire:click="delete({{ $paciente->id }})" 
                                            wire:confirm="¿Está seguro de remover este paciente de su lista?" title="Eliminar">
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

            <div class="mt-3">
                {{ $pacientes->links() }}
            </div>
        </div>
    </div>

    <!-- Modal Formulario Paciente Completo -->
    <div class="modal fade" id="modalPaciente" tabindex="-1" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-person-lines-fill me-2"></i>
                        {{ $paciente_id ? 'Editar Paciente' : 'Nuevo Paciente' }}
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <form wire:submit.prevent="save">
                    <div class="modal-body">
                        
                        <!-- Sección 1: Datos de Historia y Documento -->
                        <h6 class="text-primary fw-bold mb-3"><i class="bi bi-card-heading me-1"></i> Identificación</h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">N° Historia Médica</label>
                                <input type="text" class="form-control" wire:model="numhistoria" placeholder="Ej: H-1002">
                                @error('numhistoria') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Nacionalidad</label>
                                <select class="form-select" wire:model="nac">
                                    <option value="V">V - Venezolano</option>
                                    <option value="E">E - Extranjero</option>
                                    <option value="P">P - Pasaporte</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Cédula / Documento <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model="cedula" placeholder="Número de documento">
                                @error('cedula') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <!-- Sección 2: Datos Personales -->
                        <h6 class="text-primary fw-bold mb-3"><i class="bi bi-person me-1"></i> Datos Personales</h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Nombres <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model="nombres">
                                @error('nombres') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Apellidos <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model="apellidos">
                                @error('apellidos') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Sexo</label>
                                <select class="form-select" wire:model="sexo">
                                    <option value="">Seleccione...</option>
                                    <option value="M">Masculino</option>
                                    <option value="F">Femenino</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Fecha Nacimiento</label>
                                <input type="date" class="form-control" wire:model="fnacimiento">
                                @error('fnacimiento') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Lugar Nacimiento</label>
                                <input type="text" class="form-control" wire:model="lnacimiento">
                            </div>
                        </div>

                        <!-- Sección 3: Datos de Contacto y Ubicación -->
                        <h6 class="text-primary fw-bold mb-3"><i class="bi bi-telephone me-1"></i> Contacto y Profesión</h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Teléfono</label>
                                <input type="text" class="form-control" wire:model="telefono" placeholder="0414-0000000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Correo Electrónico</label>
                                <input type="email" class="form-control" wire:model="email" placeholder="correo@dominio.com">
                                @error('email') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Escolaridad</label>
                                <input type="text" class="form-control" wire:model="escolaridad">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Ocupación</label>
                                <input type="text" class="form-control" wire:model="ocupacion">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Profesión</label>
                                <input type="text" class="form-control" wire:model="profesion">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Dirección</label>
                                <textarea class="form-control" rows="2" wire:model="direccion" placeholder="Dirección de habitación"></textarea>
                            </div>
                        </div>

                    </div>
                    
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i> Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i> Guardar Paciente
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('livewire:initialized', () => {
            const modalElement = document.getElementById('modalPaciente');
            const modal = new bootstrap.Modal(modalElement);
            
            Livewire.on('open-modal-paciente', () => modal.show());
            Livewire.on('close-modal-paciente', () => modal.hide());
        });
    </script>
</div>