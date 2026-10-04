<div>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h4 class="fw-bold mb-0">Asignar Permisos Directos a Usuarios</h4>
        </div>
        <div class="card-body">

            @if (session()->has('message'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('message') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            <!-- Selector de Usuario -->
            <div class="mb-4">
                <label for="userSelect" class="form-label fw-bold">Selecciona un Usuario</label>
                <select wire:model="selectedUserId" id="userSelect" class="form-select">
                    <option value="">-- Elige un usuario --</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                    @endforeach
                </select>
                @error('selectedUserId') <span class="text-danger small mt-1">{{ $message }}</span> @enderror
            </div>

            <!-- Lista de Permisos (solo se muestra si se ha seleccionado un usuario) -->
            @if($selectedUserId)
                <hr>
                <h5 class="fw-bold mt-4">Listado de Permisos</h5>
                <p class="text-muted">Marca los permisos que deseas asignar directamente a este usuario. Los permisos heredados por el rol no se muestran aquí.</p>

                <form wire:submit.prevent="syncPermissions">
                    <div class="row">
                        @forelse($permissions as $permission)
                            <div class="col-md-4 col-sm-6 mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           role="switch"
                                           id="perm-{{ $permission->id }}"
                                           value="{{ $permission->name }}"
                                           wire:model.defer="userPermissions">
                                    <label class="form-check-label" for="perm-{{ $permission->id }}">
                                        {{ $permission->name }}
                                    </label>
                                </div>
                            </div>
                        @empty
                            <div class="col-12">
                                <p class="text-muted">No hay permisos definidos en el sistema.</p>
                            </div>
                        @endforelse
                    </div>

                    <div class="mt-4 text-end">
                        <button type="submit" class="btn btn-primary">Guardar Permisos</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
