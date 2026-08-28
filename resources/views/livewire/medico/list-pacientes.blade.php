<div>
    <!-- Filtros y Botones de Acción -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" placeholder="Buscar por cédula, nombre o apellido..." wire:model.debounce.300ms="search">
                    </div>
                </div>
                <div class="col-md-4">
                    <select class="form-select" wire:model="medical_center_id_filtro">
                        <option value="">Todos los Centros Médicos</option>
                        @foreach($centrosSalud as $centro)
                            <option value="{{ $centro->id }}">{{ $centro->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-primary w-100" wire:click="create">
                        <i class="bi bi-person-plus-fill me-1"></i> Nuevo Paciente
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de Pacientes -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>N° Historia</th>
                            <th>Cédula</th>
                            <th>Paciente</th>
                            <th>Teléfono / Email</th>
                            <th>Centro Médico</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pacientes as $paciente)
                            <tr>
                                <td><span class="badge bg-secondary">{{ $paciente->numhistoria ?? $paciente->num_historia_actual ?? 'N/A' }}</span></td>
                                <td><strong>{{ $paciente->nac }}-{{ $paciente->cedula }}</strong></td>
                                <td>
                                    <div class="fw-bold">{{ $paciente->nombres }} {{ $paciente->apellidos }}</div>
                                    <small class="text-muted">{{ $paciente->sexo == 'M' ? 'Masculino' : ($paciente->sexo == 'F' ? 'Femenino' : '') }}</small>
                                </td>
                                <td>
                                    <div><i class="bi bi-telephone me-1"></i>{{ $paciente->telefono ?? 'S/N' }}</div>
                                    <small class="text-muted"><i class="bi bi-envelope me-1"></i>{{ $paciente->email ?? 'S/R' }}</small>
                                </td>
                                <td>{{ $paciente->medicalCenter->name ?? 'No asignado' }}</td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-warning me-1" wire:click="edit({{ $paciente->id }})" title="Editar">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" wire:click="triggerDeleteConfirm({{ $paciente->id }})" title="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">No se encontraron pacientes registrados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($pacientes->hasPages())
            <div class="card-footer bg-white py-3">
                {{ $pacientes->links() }}
            </div>
        @endif
    </div>

    <!-- Modal Formulario Paciente -->
    <div class="modal fade" id="modalPaciente" tabindex="-1" aria-labelledby="modalPacienteLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalPacienteLabel">
                        {{ $paciente_id ? 'Editar Paciente' : 'Registrar Nuevo Paciente' }}
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form wire:submit.prevent="save">
                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- Centro Médico y N° Historia -->
                            <div class="col-md-8">
                                <label class="form-label">Centro Médico</label>
                                <select class="form-select @error('medical_center_id') is-invalid @enderror" wire:model.defer="medical_center_id">
                                    <option value="">Seleccione Centro Médico...</option>
                                    @foreach($allMedicalCenters as $centro)
                                        <option value="{{ $centro->id }}">{{ $centro->name }}</option>
                                    @endforeach
                                </select>
                                @error('medical_center_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">N° Historia</label>
                                <input type="text" class="form-control @error('numhistoria') is-invalid @enderror" wire:model.defer="numhistoria" placeholder="Ej: H-10023">
                                @error('numhistoria') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Nacionalidad y Cédula -->
                            <div class="col-md-3">
                                <label class="form-label">Nac.</label>
                                <select class="form-select @error('nac') is-invalid @enderror" wire:model.defer="nac">
                                    <option value="V">V</option>
                                    <option value="E">E</option>
                                    <option value="P">P</option>
                                </select>
                                @error('nac') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-9">
                                <label class="form-label">Cédula / Documento <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('cedula') is-invalid @enderror" wire:model.defer="cedula" placeholder="Ingrese número de cédula">
                                @error('cedula') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Nombres y Apellidos -->
                            <div class="col-md-6">
                                <label class="form-label">Nombres <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('nombres') is-invalid @enderror" wire:model.defer="nombres" placeholder="Nombres del paciente">
                                @error('nombres') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Apellidos <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('apellidos') is-invalid @enderror" wire:model.defer="apellidos" placeholder="Apellidos del paciente">
                                @error('apellidos') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Sexo y Fecha de Nacimiento -->
                            <div class="col-md-4">
                                <label class="form-label">Sexo</label>
                                <select class="form-select @error('sexo') is-invalid @enderror" wire:model.defer="sexo">
                                    <option value="">Seleccione...</option>
                                    <option value="M">Masculino</option>
                                    <option value="F">Femenino</option>
                                </select>
                                @error('sexo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Fecha Nacimiento</label>
                                <input type="date" class="form-control @error('fnacimiento') is-invalid @enderror" wire:model.defer="fnacimiento">
                                @error('fnacimiento') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Teléfono</label>
                                <input type="text" class="form-control @error('telefono') is-invalid @enderror" wire:model.defer="telefono" placeholder="04XX-XXXXXXX">
                                @error('telefono') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Email y Password (Juntos en la misma fila) -->
                            <div class="col-md-6">
                                <label class="form-label">Correo Electrónico</label>
                                <input type="email" class="form-control @error('email') is-invalid @enderror" wire:model.defer="email" placeholder="ejemplo@correo.com">
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contraseña {{ $paciente_id ? '(Opcional)' : '' }}</label>
                                <input type="password" class="form-control @error('password') is-invalid @enderror" wire:model.defer="password" placeholder="{{ $paciente_id ? 'Dejar en blanco para mantener' : 'Nueva contraseña' }}">
                                @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Lugar de Nacimiento -->
                            <div class="col-md-12">
                                <label class="form-label">Lugar de Nacimiento</label>
                                <input type="text" class="form-control @error('lnacimiento') is-invalid @enderror" wire:model.defer="lnacimiento" placeholder="Ciudad / Estado">
                                @error('lnacimiento') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Escolaridad, Ocupación y Profesión -->
                            <div class="col-md-4">
                                <label class="form-label">Escolaridad</label>
                                <input type="text" class="form-control @error('escolaridad') is-invalid @enderror" wire:model.defer="escolaridad" placeholder="Ej: Universitaria">
                                @error('escolaridad') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Ocupación</label>
                                <input type="text" class="form-control @error('ocupacion') is-invalid @enderror" wire:model.defer="ocupacion" placeholder="Ocupación actual">
                                @error('ocupacion') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Profesión</label>
                                <input type="text" class="form-control @error('profesion') is-invalid @enderror" wire:model.defer="profesion" placeholder="Profesión">
                                @error('profesion') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Dirección -->
                            <div class="col-md-12">
                                <label class="form-label">Dirección de Habitación</label>
                                <textarea class="form-control @error('direccion') is-invalid @enderror" wire:model.defer="direccion" rows="2" placeholder="Dirección detallada..."></textarea>
                                @error('direccion') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i> {{ $paciente_id ? 'Actualizar' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript Integración Modales y SweetAlert2 -->
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modalElement = document.getElementById('modalPaciente');
        const modalInstance = new bootstrap.Modal(modalElement);

        window.addEventListener('open-modal-paciente', event => {
            modalInstance.show();
        });

        window.addEventListener('close-modal-paciente', event => {
            modalInstance.hide();
        });

        window.addEventListener('swal-success', event => {
            Swal.fire({
                icon: 'success',
                title: '¡Éxito!',
                text: event.detail.message,
                timer: 2500,
                showConfirmButton: false
            });
        });

        window.addEventListener('swal-error', event => {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: event.detail.message,
            });
        });

        window.addEventListener('show-delete-confirm', event => {
            Swal.fire({
                title: '¿Estás seguro?',
                text: "Esta acción no se puede deshacer.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    Livewire.emit('confirmDeletePaciente', event.detail.id);
                }
            });
        });
    });
</script>
@endpush