<div class="container-fluid pt-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="fw-bold text-dark">Centros Médicos</h2>
        <button class="btn btn-primary" wire:click="create">
            <i class="bi bi-plus-lg"></i> Nuevo Centro Médico
        </button>
    </div>

    @if (session()->has('message'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="mb-3">
        <input type="text" class="form-control" placeholder="Buscar por nombre del centro..." wire:model="search">
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Ubicación</th>
                    <th>Teléfono</th>
                    <th>Email</th>
                    <th>Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($centros as $centro)
                    <tr>
                        <td>{{ $centro->id }}</td>
                        <td class="fw-bold">{{ $centro->name }}</td>
                        <td>
                            <small class="d-block">
                                <strong>País:</strong> {{ $centro->country->name ?? 'N/A' }}
                            </small>
                            <small class="d-block">
                                <strong>Estado/Ciudad:</strong> {{ $centro->estado->name ?? '' }} {{ $centro->city ? ' - ' . $centro->city->name : '' }}
                            </small>
                        </td>
                        <td>{{ $centro->phone ?? 'N/A' }}</td>
                        <td>{{ $centro->email ?? 'N/A' }}</td>
                        <td>
                            <span class="badge {{ $centro->is_active ? 'bg-success' : 'bg-danger' }}">
                                {{ $centro->is_active ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-warning me-1" wire:click="edit({{ $centro->id }})">
                                Editar
                            </button>
                            <button class="btn btn-sm btn-danger" wire:click="triggerDeleteConfirm({{ $centro->id }})">
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
        {{ $centros->links() }}
    </div>

    <!-- Modal Formulario Centro Médico -->
    <div class="modal fade" id="modalCentro" tabindex="-1" aria-labelledby="modalCentroLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCentroLabel">{{ $centro_id ? 'Editar Centro Médico' : 'Nuevo Centro Médico' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form wire:submit.prevent="save">
                        <div class="row">
                            <!-- Nombre -->
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Nombre del Centro Médico <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model.defer="name">
                                @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <!-- Selects Dependientes: Ubicación -->
                            <div class="col-md-4 mb-3">
                                <label class="form-label">País <span class="text-danger">*</span></label>
                                <select class="form-select" wire:model="country_id">
                                    <option value="">Seleccione País...</option>
                                    @foreach($paises as $pais)
                                        <option value="{{ $pais->id }}">{{ $pais->name }}</option>
                                    @endforeach
                                </select>
                                @error('country_id') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Estado</label>
                                <select class="form-select" wire:model="state_id" {{ empty($estados) ? 'disabled' : '' }}>
                                    <option value="">Seleccione Estado...</option>
                                    @foreach($estados as $estado)
                                        <option value="{{ $estado->id }}">{{ $estado->name }}</option>
                                    @endforeach
                                </select>
                                @error('state_id') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Ciudad</label>
                                <select class="form-select" wire:model="city_id" {{ empty($ciudades) ? 'disabled' : '' }}>
                                    <option value="">Seleccione Ciudad...</option>
                                    @foreach($ciudades as $ciudad)
                                        <option value="{{ $ciudad->id }}">{{ $ciudad->name }}</option>
                                    @endforeach
                                </select>
                                @error('city_id') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <!-- Datos de Contacto -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="text" class="form-control" wire:model.defer="phone">
                                @error('phone') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" wire:model.defer="email">
                                @error('email') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <div class="col-md-12 mb-3">
                                <label class="form-label">Dirección</label>
                                <textarea class="form-control" rows="2" wire:model.defer="address"></textarea>
                                @error('address') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <div class="col-md-12 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_active_centro" wire:model.defer="is_active">
                                    <label class="form-check-label" for="is_active_centro">Estado Activo</label>
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

    <!-- Scripts de integración JS con Livewire 2 y Bootstrap 5 -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modalElement = document.getElementById('modalCentro');
            let bsModal = bootstrap.Modal.getInstance(modalElement);
            
            if (!bsModal) {
                bsModal = new bootstrap.Modal(modalElement);
            }

            // Control del Modal
            window.addEventListener('open-modal-centro', () => {
                bsModal.show();
            });

            window.addEventListener('close-modal-centro', () => {
                bsModal.hide();
            });

            // Confirmación de Eliminación
            window.addEventListener('show-delete-confirmation', event => {
                const id = event.detail.id;

                if (typeof Swal !== 'undefined') {
                    // Si SweetAlert2 está disponible
                    Swal.fire({
                        title: '¿Está seguro?',
                        text: "¡Esta acción eliminará el centro médico permanentemente!",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Sí, eliminar',
                        cancelButtonText: 'Cancelar'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            Livewire.emit('confirmDelete', id);
                        }
                    });
                } else {
                    // Fallback con confirmación JS nativa
                    if (confirm('¿Está seguro de eliminar este centro médico?')) {
                        Livewire.emit('confirmDelete', id);
                    }
                }
            });
        });
    </script>
</div>