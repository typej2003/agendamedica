<div>
    <x-ui.page-head titulo="Médicos" subtitulo="Los médicos registrados en el sistema.">
        <x-slot name="acciones">
            <button class="btn btn-primary" wire:click="create">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo médico
            </button>
        </x-slot>
    </x-ui.page-head>

    <x-ui.avisos />

    <div class="mb-3">
        <input type="text" class="form-control" placeholder="Buscar por nombre, apellido o registro médico..." wire:model.live="search">
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Nombre Completo</th>
                    <th>Reg. Médico</th>
                    <th>Nº Licencia</th>
                    <th>Teléfono / Email</th>
                    <th>Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($medicos as $medico)
                    <tr>
                        <td>{{ $medico->id }}</td>
                        <td class="fw-bold">{{ $medico->name }} {{ $medico->lastname }}</td>
                        <td><span class="badge bg-info text-dark">{{ $medico->{'reg-medico'} }}</span></td>
                        <td>{{ $medico->license_number ?? 'N/A' }}</td>
                        <td>
                            <small class="d-block"><strong>Tel:</strong> {{ $medico->phone ?? 'N/A' }}</small>
                            <small class="d-block"><strong>Email:</strong> {{ $medico->email ?? 'N/A' }}</small>
                        </td>
                        <td>
                            <span class="badge {{ $medico->is_active ? 'bg-success' : 'bg-danger' }}">
                                {{ $medico->is_active ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary me-1" wire:click="edit({{ $medico->id }})">
                                Editar
                            </button>
                            <button class="btn btn-sm btn-outline-danger" wire:click="triggerDeleteConfirm({{ $medico->id }})">
                                Eliminar
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center">No se encontraron registros.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $medicos->links() }}
    </div>

    <!-- Modal Formulario Médico -->
    <div class="modal fade" id="modalMedico" tabindex="-1" aria-labelledby="modalMedicoLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lateral">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalMedicoLabel">{{ $medico_id ? 'Editar Médico' : 'Nuevo Médico' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form wire:submit.prevent="save">
                        <div class="row">
                            <!-- Prefijo -->
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Prefijo</label>
                                <input type="text" class="form-control" maxlength="20" placeholder="Dr" wire:model="prefix">
                                <small class="text-muted">Texto libre (Dr, Dra, Ing, Lic…); el punto se agrega solo. Vacío: sin prefijo.</small>
                                @error('prefix') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>

                            <!-- Nombre -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nombres <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model="name">
                                @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <!-- Apellidos -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Apellidos <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model="lastname">
                                @error('lastname') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <!-- Reg Médico -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Registro Médico <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model="reg_medico">
                                @error('reg_medico') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <!-- Nº Licencia -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Número de Licencia</label>
                                <input type="text" class="form-control" wire:model="license_number">
                                @error('license_number') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <!-- Teléfono -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="text" class="form-control" wire:model="phone">
                                @error('phone') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <!-- Email -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" wire:model="email">
                                @error('email') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <!-- Estado Activo -->
                            <div class="col-md-12 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_active_medico" wire:model="is_active">
                                    <label class="form-check-label" for="is_active_medico">Estado Activo</label>
                                </div>
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

    <!-- Scripts JS para Livewire 2 y Bootstrap 5 -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modalElement = document.getElementById('modalMedico');
            let bsModal = bootstrap.Modal.getInstance(modalElement);
            
            if (!bsModal) {
                bsModal = new bootstrap.Modal(modalElement);
            }

            // Abrir y Cerrar Modal
            window.addEventListener('open-modal-medico', () => {
                bsModal.show();
            });

            window.addEventListener('close-modal-medico', () => {
                bsModal.hide();
            });

            // Confirmación de Eliminación
            window.addEventListener('show-delete-confirmation-medico', event => {
                const id = event.detail.id;

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: '¿Está seguro?',
                        text: "¡Esta acción eliminará el médico permanentemente!",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Sí, eliminar',
                        cancelButtonText: 'Cancelar'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            Livewire.dispatch('confirmDeleteMedico', { id: id });
                        }
                    });
                } else {
                    if (confirm('¿Está seguro de eliminar este médico?')) {
                        Livewire.dispatch('confirmDeleteMedico', { id: id });
                    }
                }
            });
        });
    </script>
</div>