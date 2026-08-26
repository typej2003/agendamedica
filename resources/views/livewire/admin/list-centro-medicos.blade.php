<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Centros Médicos</h2>
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
        <input type="text" class="form-control" placeholder="Buscar por nombre del centro..." wire:model.live="search">
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
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
                        <td>{{ $centro->name }}</td>
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
                            <button class="btn btn-sm btn-danger" wire:click="delete({{ $centro->id }})" wire:confirm="¿Está seguro de eliminar este centro médico?">
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
        {{ $centros->links() }}
    </div>

    <!-- Modal Formulario Centro Médico -->
    <div class="modal fade" id="modalCentro" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $centro_id ? 'Editar Centro Médico' : 'Nuevo Centro Médico' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form wire:submit.prevent="save">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Nombre del Centro Médico</label>
                                <input type="text" class="form-control" wire:model="name">
                                @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="text" class="form-control" wire:model="phone">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" wire:model="email">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Dirección</label>
                                <textarea class="form-control" rows="2" wire:model="address"></textarea>
                            </div>
                            <div class="col-md-12 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_active_centro" wire:model="is_active">
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

    <script>
        document.addEventListener('livewire:initialized', () => {
            const modal = new bootstrap.Modal(document.getElementById('modalCentro'));
            
            Livewire.on('open-modal-centro', () => modal.show());
            Livewire.on('close-modal-centro', () => modal.hide());
        });
    </script>
</div>