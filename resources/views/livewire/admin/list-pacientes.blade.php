<div class="container-fluid pt-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="fw-bold text-dark">Listado de Pacientes</h2>
        <button class="btn btn-primary" wire:click="create">
            <i class="bi bi-plus-lg"></i> Nuevo Paciente
        </button>
    </div>

    @if (session()->has('message'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="mb-3">
        <input type="text" class="form-control" placeholder="Buscar por cédula, nombre o apellido..." wire:model="search">
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Cédula</th>
                    <th>Nombre Completo</th>
                    <th>Teléfono</th>
                    <th>Email</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($pacientes as $paciente)
                    <tr>
                        <td>{{ $paciente->id }}</td>
                        <td><span class="badge bg-secondary">{{ $paciente->nac }}-{{ $paciente->cedula }}</span></td>
                        <td class="fw-bold">{{ $paciente->nombres }} {{ $paciente->apellidos }}</td>
                        <td>{{ $paciente->telefono ?? 'N/A' }}</td>
                        <td>{{ $paciente->email ?? 'N/A' }}</td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-warning me-1" wire:click="edit({{ $paciente->id }})">
                                Editar
                            </button>
                            <button class="btn btn-sm btn-danger" wire:click="triggerDeleteConfirm({{ $paciente->id }})">
                                Eliminar
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center">No se encontraron registros.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $pacientes->links() }}
    </div>

    <!-- Modal Formulario Paciente -->
    <div class="modal fade" id="modalPaciente" tabindex="-1" aria-labelledby="modalPacienteLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPacienteLabel">{{ $paciente_id ? 'Editar Paciente' : 'Nuevo Paciente' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form wire:submit.prevent="save">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Nacionalidad <span class="text-danger">*</span></label>
                                <select class="form-select" wire:model.defer="nac">
                                    <option value="V">V - Venezolano</option>
                                    <option value="E">E - Extranjero</option>
                                    <option value="P">P - Pasaporte</option>
                                </select>
                                @error('nac') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-9 mb-3">
                                <label class="form-label">Cédula / Documento <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model.defer="cedula">
                                @error('cedula') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nombres <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model.defer="nombres">
                                @error('nombres') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Apellidos <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model.defer="apellidos">
                                @error('apellidos') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Sexo</label>
                                <select class="form-select" wire:model.defer="sexo">
                                    <option value="">Seleccione...</option>
                                    <option value="M">Masculino</option>
                                    <option value="F">Femenino</option>
                                </select>
                                @error('sexo') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="text" class="form-control" wire:model.defer="telefono">
                                @error('telefono') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" wire:model.defer="email">
                                @error('email') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <div class="col-md-12 mb-3">
                                <label class="form-label">Dirección</label>
                                <textarea class="form-control" rows="2" wire:model.defer="direccion"></textarea>
                                @error('direccion') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="modal-footer px-0 pb-0">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts de integración JS con Livewire 2 y Bootstrap 5 -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modalElement = document.getElementById('modalPaciente');
            let bsModal = bootstrap.Modal.getInstance(modalElement);
            
            if (!bsModal) {
                bsModal = new bootstrap.Modal(modalElement);
            }

            // Abrir y Cerrar Modal
            window.addEventListener('open-modal-paciente', () => {
                bsModal.show();
            });

            window.addEventListener('close-modal-paciente', () => {
                bsModal.hide();
            });

            // Confirmación de Eliminación
            window.addEventListener('show-delete-confirmation-paciente', event => {
                const id = event.detail.id;

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: '¿Está seguro?',
                        text: "¡Esta acción eliminará el paciente permanentemente!",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Sí, eliminar',
                        cancelButtonText: 'Cancelar'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            Livewire.emit('confirmDeletePaciente', id);
                        }
                    });
                } else {
                    if (confirm('¿Está seguro de eliminar este paciente?')) {
                        Livewire.emit('confirmDeletePaciente', id);
                    }
                }
            });
        });
    </script>
</div>