<div class="mt-4">
    <div class="row mb-3 align-items-center">
        <div class="col-md-6">
            <h4 class="fw-bold mb-0">Gestión de Usuarios</h4>
        </div>
        <div class="col-md-6 text-md-end">
            <button wire:click="create" class="btn btn-primary">
                + Nuevo Usuario
            </button>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <div class="row">
                <div class="col-md-4">
                    <input type="text" wire:model="search" class="form-control" placeholder="Buscar por nombre o correo...">
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Correo Electrónico</th>
                            <th>Rol</th>
                            <th>Permisos</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                            <tr>
                                <td>{{ $user->id }}</td>
                                <td>{{ $user->name }}</td>
                                <td>{{ $user->email }}</td>
                                <td>
                                    @foreach($user->roles as $r)
                                        <span class="badge bg-info text-dark">{{ $r->name }}</span>
                                    @endforeach
                                </td>
                                <td>
                                    {{-- getAllPermissions() obtiene permisos directos y via roles --}}
                                    @forelse($user->getAllPermissions() as $permission)
                                        <span class="badge bg-secondary">{{ $permission->name }}</span>
                                    @empty
                                        <span class="text-muted small">Sin permisos</span>
                                    @endforelse
                                 </td>
                                <td class="text-end">
                                    <button wire:click="edit({{ $user->id }})" class="btn btn-sm btn-outline-warning me-1">
                                        Editar
                                    </button>
                                    <button wire:click="confirmDelete({{ $user->id }})" class="btn btn-sm btn-outline-danger">
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">No se encontraron usuarios registrados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white py-3">
            {{ $users->links() }}
        </div>
    </div>

    <!-- MODAL CREAR / EDITAR USUARIO -->
    <div wire:ignore.self class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="userModalLabel">
                        {{ $isEditMode ? 'Editar Usuario' : 'Nuevo Usuario' }}
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form wire:submit.prevent="{{ $isEditMode ? 'update' : 'store' }}">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre Completo</label>
                            <input type="text" wire:model.defer="name" class="form-control @error('name') is-invalid @enderror">
                            @error('name') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Correo Electrónico</label>
                            <input type="email" wire:model.defer="email" class="form-control @error('email') is-invalid @enderror">
                            @error('email') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Contraseña {{ $isEditMode ? '(Dejar en blanco para conservar)' : '' }}</label>
                            <input type="password" wire:model.defer="password" class="form-control @error('password') is-invalid @enderror">
                            @error('password') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Rol del Usuario</label>
                            <select wire:model.defer="role" class="form-select @error('role') is-invalid @enderror">
                                <option value="">-- Seleccionar Rol --</option>
                                @foreach($roles as $r)
                                    <option value="{{ $r->name }}">{{ $r->name }}</option>
                                @endforeach
                            </select>
                            @error('role') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            {{ $isEditMode ? 'Guardar Cambios' : 'Crear Usuario' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL CONFIRMAR ELIMINACIÓN -->
    <div wire:ignore.self class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel">Confirmar Eliminación</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    ¿Estás seguro de que deseas eliminar este usuario? Esta acción no se puede deshacer.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" wire:click="delete" class="btn btn-danger">Sí, Eliminar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPT DE NAVEGADOR PARA MANEJAR MODALES -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var userModal = new bootstrap.Modal(document.getElementById('userModal'));
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));

            window.addEventListener('open-user-modal', event => {
                userModal.show();
            });

            window.addEventListener('close-user-modal', event => {
                userModal.hide();
            });

            window.addEventListener('open-delete-modal', event => {
                deleteModal.show();
            });

            window.addEventListener('close-delete-modal', event => {
                deleteModal.hide();
            });
        });
    </script>
</div>