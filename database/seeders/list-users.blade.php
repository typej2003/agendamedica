<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Gestión de Usuarios</h5>
                    <button wire:click="create" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Crear Usuario
                    </button>
                </div>
                <div class="card-body">
                    @if (session()->has('message'))
                        <div class="alert alert-success">{{ session('message') }}</div>
                    @endif
                    @if (session()->has('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <input type="text" wire:model="search" class="form-control" placeholder="Buscar por nombre o email...">
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">ID</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Nombre</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Email</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Rol</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($users as $user)
                                    <tr>
                                        <td>{{ $user->id }}</td>
                                        <td>{{ $user->name }}</td>
                                        <td>{{ $user->email }}</td>
                                        <td><span class="badge bg-primary">{{ $user->getRoleNames()->first() }}</span></td>
                                        <td class="text-center">
                                            <button wire:click="edit({{ $user->id }})" class="btn btn-sm btn-warning" title="Editar"><i class="bi bi-pencil-fill"></i></button>
                                            <button wire:click="confirmDelete({{ $user->id }})" class="btn btn-sm btn-danger" title="Eliminar"><i class="bi bi-trash-fill"></i></button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center">No se encontraron usuarios.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        {{ $users->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Crear/Editar Usuario -->
    <div wire:ignore.self class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalLabel">{{ $isEditMode ? 'Editar Usuario' : 'Crear Usuario' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form wire:submit.prevent="{{ $isEditMode ? 'update' : 'store' }}">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nombre</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" wire:model.defer="name">
                            @error('name') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" wire:model.defer="email">
                            @error('email') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Contraseña</label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" wire:model.defer="password" placeholder="{{ $isEditMode ? 'Dejar en blanco para no cambiar' : '' }}">
                            @error('password') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Rol</label>
                            <select class="form-select @error('role') is-invalid @enderror" id="role" wire:model.defer="role">
                                <option value="">Seleccionar Rol</option>
                                @foreach($roles as $role)
                                    <option value="{{ $role->name }}">{{ $role->name }}</option>
                                @endforeach
                            </select>
                            @error('role') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">{{ $isEditMode ? 'Actualizar' : 'Guardar' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmación de Eliminación -->
    <div wire:ignore.self class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Confirmar Eliminación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    ¿Estás seguro de que quieres eliminar este usuario? Esta acción no se puede deshacer.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" wire:click="delete" class="btn btn-danger">Sí, Eliminar</button>
                </div>
            </div>
        </div>
    </div>

    @push('js')
    <script>
        document.addEventListener('livewire:load', function () {
            const userModal = new bootstrap.Modal(document.getElementById('userModal'));
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));

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
    @endpush
</div>

```

### Pasos Finales

1.  **Ejecuta las migraciones y el seeder** para aplicar los nuevos permisos y roles en tu base de datos:
    ```bash
    php artisan migrate:fresh --seed
    ```
2.  **Inicia sesión** con el usuario `admin@admin.com` (contraseña: `password`).
3.  **Verás el enlace "Usuarios"** en el menú lateral. Al hacer clic, te llevará a la nueva página de gestión de usuarios, que es totalmente funcional.

¡Listo! Con esto, has integrado de forma segura y completa la administración de usuarios en tu proyecto.

<!--
[PROMPT_SUGGESTION]¿Cómo puedo hacer que los botones de editar y eliminar solo aparezcan si el usuario tiene los permisos 'editar-usuarios' y 'eliminar-usuarios'?[/PROMPT_SUGGESTION]
[PROMPT_SUGGESTION]Explícame cómo funciona el script de JavaScript para controlar los modales con Livewire.[/PROMPT_SUGGESTION]
-->