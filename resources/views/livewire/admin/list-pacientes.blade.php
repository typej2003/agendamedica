<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Listado de Pacientes</h2>
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
        <input type="text" class="form-control" placeholder="Buscar por cédula, nombre o apellido..." wire:model.live="search">
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
                        <td>{{ $paciente->nac }}-{{ $paciente->cedula }}</td>
                        <td>{{ $paciente->nombres }} {{ $paciente->apellidos }}</td>
                        <td>{{ $paciente->telefono ?? 'N/A' }}</td>
                        <td>{{ $paciente->email ?? 'N/A' }}</td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-warning me-1" wire:click="edit({{ $paciente->id }})">
                                Editar
                            </button>
                            <button class="btn btn-sm btn-danger" wire:click="delete({{ $paciente->id }})" wire:confirm="¿Está seguro de eliminar este paciente?">
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
    <div class="modal fade" id="modalPaciente" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $paciente_id ? 'Editar Paciente' : 'Nuevo Paciente' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form wire:submit.prevent="save">
                        <div class="row">
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Nac.</label>
                                <select class="form-select" wire:model="nac">
                                    <option value="V">V</option>
                                    <option value="E">E</option>
                                    <option value="P">P</option>
                                </select>
                            </div>
                            <div class="col-md-10 mb-3">
                                <label class="form-label">Cédula</label>
                                <input type="text" class="form-control" wire:model="cedula">
                                @error('cedula') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nombres</label>
                                <input type="text" class="form-control" wire:model="nombres">
                                @error('nombres') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Apellidos</label>
                                <input type="text" class="form-control" wire:model="apellidos">
                                @error('apellidos') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Sexo</label>
                                <select class="form-select" wire:model="sexo">
                                    <option value="">Seleccione...</option>
                                    <option value="M">Masculino</option>
                                    <option value="F">Femenino</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="text" class="form-control" wire:model="telefono">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" wire:model="email">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Dirección</label>
                                <textarea class="form-control" rows="2" wire:model="direccion"></textarea>
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
            const modal = new bootstrap.Modal(document.getElementById('modalPaciente'));
            
            Livewire.on('open-modal-paciente', () => modal.show());
            Livewire.on('close-modal-paciente', () => modal.hide());
        });
    </script>
</div>