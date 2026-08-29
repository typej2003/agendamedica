<div class="mt-4">
    <div class="card shadow-sm border-0 mb-4">
        <!-- Cabecera -->
        <div class="card-header bg-white py-3 border-bottom-0">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div>
                    <h5 class="mb-0 text-primary fw-bold">
                        <i class="bi bi-people-fill me-2"></i>Pacientes
                    </h5>
                    <small class="text-muted">Gestión de historias clínicas y datos de contacto</small>
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

            <!-- Filtros: Buscador + Selección de Centro de Salud -->
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0 ps-0" 
                            placeholder="Buscar por cédula o nombre..." 
                            wire:model="search">
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="bi bi-hospital text-muted"></i>
                        </span>
                        <select class="form-select border-start-0" wire:model="medical_center_id_filtro">
                            <option value="">Seleccione un Centro / Consultorio</option>
                            @foreach($centrosSalud as $centro)
                                <option value="{{ $centro->id }}">Centro: {{ $centro->name }}</option>
                            @endforeach
                        </select>
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
                                        {{ $paciente->nac ?? 'V' }}-{{ $paciente->cedula }}
                                    </span>
                                    <span class="badge bg-secondary font-monospace">
                                        {{ $paciente->numhistoria ?? ($paciente->pivot->numhistoria ?? 'N/A') }}
                                    </span>
                                    @if(isset($paciente->historia->medicalCenter) || isset($paciente->medicalCenter))
                                        <span class="badge bg-info text-dark ms-1">
                                            {{ $paciente->historia->medicalCenter->name ?? ($paciente->medicalCenter->name ?? 'N/A') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-sm btn-outline-warning py-0 px-2" wire:click="edit({{ $paciente->id }})">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger py-0 px-2" wire:click="triggerDeleteConfirm({{ $paciente->id }})">
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
                        <small>No se encontraron pacientes registrados.</small>
                    </div>
                @endforelse
            </div>

            <!-- 2. VISTA ESCRITORIO (Tabla) -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle border-top mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" style="width: 140px;">N° Historia</th>
                            <th scope="col" style="width: 130px;">Cédula</th>
                            <th scope="col">Paciente</th>
                            <th scope="col">Centro Médico</th>
                            <th scope="col">Teléfono</th>
                            <th scope="col">Email</th>
                            <th scope="col" class="text-center" style="width: 100px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pacientes as $paciente)
                            <tr>
                                <td>
                                    <!-- numhistoria proveniente de la relación MedicoPaciente / Pivot -->
                                    <span class="badge bg-secondary font-monospace fs-6">
                                        {{ $paciente->numhistoria ?? ($paciente->pivot->numhistoria ?? 'Sin N°') }}
                                    </span>
                                </td>
                                <td class="fw-bold text-nowrap">
                                    {{ $paciente->nac ?? 'V' }}-{{ $paciente->cedula }}
                                </td>
                                <td class="fw-semibold">
                                    {{ $paciente->nombres }} {{ $paciente->apellidos }}
                                </td>
                                <td>
                                    <!-- Centro de salud obtenido mediante la relación de Historia -->
                                    <span class="badge bg-light text-dark border">
                                        <!-- {{ $paciente->historia->medicalCenter->name ?? ($paciente->medicalCenter->name ?? 'N/A') }} -->
                                    </span>
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
                                        <button class="btn btn-outline-danger" wire:click="triggerDeleteConfirm({{ $paciente->id }})" title="Eliminar">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    No se encontraron pacientes registrados para la selección actual.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div class="mt-3 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
                <div class="d-none d-md-block ms-auto">
                    {{ $pacientes->links() }}
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Formulario Paciente -->
    <div class="modal fade" id="modalPaciente" tabindex="-1" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold fs-6">
                        <i class="bi bi-person-lines-fill me-2"></i>
                        {{ $paciente_id ? 'Editar Paciente' : 'Nuevo Paciente' }}
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <form wire:submit.prevent="save">
                    <div class="modal-body p-3" style="max-height: 75vh; overflow-y: auto;">
                        
                        <!-- Identificación y N° de Historia -->
                        <div class="bg-light p-2 rounded mb-3">
                            <span class="text-primary fw-bold small text-uppercase"><i class="bi bi-card-heading me-1"></i> Identificación e Historia</span>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-semibold">N° Historia <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" wire:model="numhistoria" placeholder="Ej: H-1002">
                                @error('numhistoria') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-4 col-md-2">
                                <label class="form-label small fw-semibold">Nac.</label>
                                <select class="form-select form-select-sm" wire:model="nac">
                                    <option value="V">V</option>
                                    <option value="E">E</option>
                                    <option value="P">P</option>
                                </select>
                            </div>
                            <div class="col-8 col-md-6">
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

                        <!-- Contacto y Acceso -->
                        <div class="bg-light p-2 rounded mb-3">
                            <span class="text-primary fw-bold small text-uppercase"><i class="bi bi-telephone me-1"></i> Contacto y Acceso</span>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-semibold">Teléfono</label>
                                <input type="text" class="form-control form-control-sm" wire:model="telefono" placeholder="0414-0000000">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-semibold">Email</label>
                                <input type="email" class="form-control form-control-sm" wire:model="email">
                                @error('email') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-semibold">
                                    Contraseña {{ $paciente_id ? '(Opcional)' : '' }}
                                </label>
                                <div class="input-group input-group-sm">
                                    <input type="password" id="inputPasswordModal" class="form-control form-control-sm border-end-0" wire:model="password" placeholder="{{ $paciente_id ? 'Sin cambios' : 'Mín. 6 caracteres' }}">
                                    <button class="btn btn-outline-secondary border-start-0" type="button" id="togglePasswordBtn">
                                        <i class="bi bi-eye" id="togglePasswordIcon"></i>
                                    </button>
                                </div>
                                @error('password') <span class="text-danger small d-block">{{ $message }}</span> @enderror
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

    <!-- CDN SweetAlert2 por si no está cargado globalmente -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Scripts Bootstrap 5, SweetAlert2 y Livewire -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modalElement = document.getElementById('modalPaciente');
            const bsModal = new bootstrap.Modal(modalElement);

            window.addEventListener('open-modal-paciente', () => {
                bsModal.show();
            });

            window.addEventListener('close-modal-paciente', () => {
                bsModal.hide();
            });

            // Conmutador (toggle) para mostrar/ocultar contraseña
            const togglePasswordBtn = document.getElementById('togglePasswordBtn');
            const passwordInput = document.getElementById('inputPasswordModal');
            const passwordIcon = document.getElementById('togglePasswordIcon');

            if (togglePasswordBtn && passwordInput && passwordIcon) {
                togglePasswordBtn.addEventListener('click', function () {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    passwordIcon.classList.toggle('bi-eye');
                    passwordIcon.classList.toggle('bi-eye-slash');
                });
            }

            // Restablecer el campo de contraseña a tipo "password" e icono al cerrar el modal
            modalElement.addEventListener('hidden.bs.modal', function () {
                if (passwordInput && passwordIcon) {
                    passwordInput.setAttribute('type', 'password');
                    passwordIcon.classList.add('bi-eye');
                    passwordIcon.classList.remove('bi-eye-slash');
                }
            });

            // SweetAlert2 para Confirmación de Eliminación
            window.addEventListener('show-delete-confirm', event => {
                Swal.fire({
                    title: '¿Está seguro?',
                    text: "Se procederá a evaluar los registros asociados del paciente.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Sí, eliminar/desvincular',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Livewire.emit('confirmDeletePaciente', event.detail.id);
                    }
                });
            });

            // SweetAlert2 para Notificaciones
            window.addEventListener('swal-success', event => {
                Swal.fire({
                    icon: 'success',
                    title: 'Éxito',
                    text: event.detail.message,
                    timer: 2500,
                    showConfirmButton: false
                });
            });

            window.addEventListener('swal-warning', event => {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atención',
                    text: event.detail.message
                });
            });

            window.addEventListener('swal-error', event => {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: event.detail.message
                });
            });
        });
    </script>
</div>