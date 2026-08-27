<div class="container-fluid pt-4 mt-3">
    <!-- Encabezado -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h3 mb-0 text-gray-800 fw-bold">Gestión de Historias Médicas</h2>
            <p class="text-muted small mb-0">Administración y seguimiento de registros clínicos</p>
        </div>
        <button class="btn btn-primary shadow-sm" wire:click="createHistoria">
            <i class="bi bi-plus-circle me-1"></i> Nueva Historia
        </button>
    </div>

    @if (session()->has('message'))
        <div class="alert alert-success alert-dismissible fade show shadow-sm mb-3" role="alert">
            <i class="bi bi-check-circle me-2"></i> {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Buscador Principal -->
    <div class="mb-4">
        <div class="input-group shadow-sm">
            <span class="input-group-text bg-white border-end-0 text-muted">
                <i class="bi bi-search"></i>
            </span>
            <input type="text" class="form-control border-start-0 ps-0" 
                placeholder="Buscar por Registro Médico, N° Historia, Paciente, Médico o Centro..." 
                wire:model.debounce.300ms="search">
            @if(!empty($search))
                <button class="btn btn-outline-secondary" type="button" wire:click="$set('search', '')">
                    <i class="bi bi-x-lg"></i> Limpiar
                </button>
            @endif
        </div>
        @if($isSearching)
            <div class="form-text text-primary mt-1">
                <i class="bi bi-info-circle me-1"></i> Modo de búsqueda activo: Mostrando todos los registros individuales que coinciden.
            </div>
        @endif
    </div>

    <!-- Tabla Principal -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-bordered align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Reg-Médico</th>
                            <th>Centro Médico</th>
                            <th class="text-center">Historia</th>
                            <th class="text-center">Paciente</th>
                            <th>Médico</th>
                            <th class="text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($historias as $historia)
                            @php
                                $regCode = $historia->getAttribute('reg-medico');
                                $totalRegistros = $countsByReg[$regCode] ?? 1;
                            @endphp
                            <tr>
                                <!-- Reg-Médico -->
                                <td>
                                    <button class="btn btn-link btn-sm p-0 fw-bold text-decoration-none" 
                                        wire:click="showByRegMedico('{{ $regCode }}')" 
                                        title="Ver todas las historias de este registro">
                                        <i class="bi bi-folder2-open me-1"></i> {{ $regCode ?? 'Sin Código' }}
                                    </button>
                                </td>

                                <!-- Centro Médico -->
                                <td>
                                    @if($historia->medicalCenter)
                                        <button class="btn btn-link btn-sm p-0 text-start text-dark text-decoration-none" 
                                            wire:click="showByCentro({{ $historia->medical_center_id }})">
                                            <i class="bi bi-hospital me-1 text-primary"></i> {{ $historia->medicalCenter->name }}
                                        </button>
                                    @else
                                        <span class="text-muted small">Sin Asignar</span>
                                    @endif
                                </td>

                                <!-- Historia -->
                                <td class="text-center">
                                    @if($isSearching)
                                        <span class="badge bg-info text-dark">
                                            <i class="bi bi-file-earmark-text me-1"></i> {{ $historia->numhistoria ?? 'Sin N°' }}
                                        </span>
                                    @else
                                        @if($totalRegistros > 1)
                                            <button class="btn btn-sm btn-info text-white" 
                                                wire:click="showByRegMedico('{{ $regCode }}')">
                                                <i class="bi bi-file-earmark-text me-1"></i> Detalles ({{ $totalRegistros }})
                                            </button>
                                        @else
                                            <button class="btn btn-sm btn-outline-info" 
                                                wire:click="showByRegMedico('{{ $regCode }}')">
                                                <i class="bi bi-file-earmark-text me-1"></i> {{ $historia->numhistoria ?? 'Ver Historia' }}
                                            </button>
                                        @endif
                                    @endif
                                </td>

                                <!-- Paciente -->
                                <td class="text-center">
                                    @if($isSearching)
                                        @if($historia->paciente)
                                            <span class="fw-semibold text-dark">
                                                <i class="bi bi-person me-1 text-success"></i> {{ $historia->paciente->nombres }} {{ $historia->paciente->apellidos }}
                                            </span>
                                        @else
                                            <span class="text-muted small">Sin Paciente</span>
                                        @endif
                                    @else
                                        @if($totalRegistros > 1)
                                            <button class="btn btn-sm btn-success text-white" 
                                                wire:click="showPacientesByRegMedico('{{ $regCode }}')">
                                                <i class="bi bi-people me-1"></i> Detalles ({{ $totalRegistros }})
                                            </button>
                                        @else
                                            @if($historia->paciente)
                                                <button class="btn btn-sm btn-outline-success" 
                                                    wire:click="showPacientesByRegMedico('{{ $regCode }}')">
                                                    <i class="bi bi-person me-1"></i> {{ $historia->paciente->nombres }} {{ $historia->paciente->apellidos }}
                                                </button>
                                            @else
                                                <span class="text-muted small">Sin Paciente</span>
                                            @endif
                                        @endif
                                    @endif
                                </td>

                                <!-- Médico -->
                                <td>
                                    @if($historia->medico)
                                        <button class="btn btn-link btn-sm p-0 text-start text-decoration-none" 
                                            wire:click="showMedicoDetail({{ $historia->medico_id }})">
                                            <i class="bi bi-person-badge me-1"></i> {{ $historia->medico->name }} {{ $historia->medico->lastname }}
                                        </button>
                                    @else
                                        <span class="text-muted small">Sin Asignar</span>
                                    @endif
                                </td>

                                <!-- Acciones -->
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-outline-primary py-0 px-2" 
                                            wire:click="editCentro({{ $historia->id }})" title="Editar Registro">
                                            <i class="bi bi-pencil-square me-1"></i> Editar
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger py-0 px-2" 
                                            type="button"
                                            onclick="confirmDeleteHistoria({{ $historia->id }})" 
                                            title="Eliminar Historia">
                                            <i class="bi bi-trash me-1"></i> Eliminar
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">No se encontraron historias registradas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Paginación -->
    <div class="mt-3">
        {{ $historias->links() }}
    </div>

    <!-- 1. Modal Formulario (Crear / Editar Historia) -->
    <div class="modal fade" id="modalEditCentro" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fs-6">
                        <i class="bi bi-journal-plus me-2"></i> 
                        {{ $isEditMode ? 'Editar Historia Médica' : 'Nueva Historia Médica' }}
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form wire:submit.prevent="saveHistoria">
                    <div class="modal-body">
                        <div class="row g-3">
                            
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Registro Médico (reg-medico)</label>
                                <input type="text" class="form-control" wire:model.defer="reg_medico" placeholder="Ej. RM-2026-001">
                                @error('reg_medico') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold">Número de Historia (numhistoria)</label>
                                <input type="text" class="form-control" wire:model.defer="numhistoria" placeholder="Ej. HIST-09823">
                                @error('numhistoria') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <!-- Autocomplete: Centro Médico -->
                            <div class="col-md-12 position-relative">
                                <label class="form-label fw-bold">Centro Médico</label>
                                @if($medical_center_id)
                                    <div class="input-group">
                                        <input type="text" class="form-control bg-light" value="{{ $selectedCentroName }}" readonly>
                                        <button class="btn btn-outline-danger" type="button" wire:click="clearCentro">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                @else
                                    <input type="text" class="form-control" placeholder="Escriba para buscar un Centro Médico..." wire:model.debounce.300ms="searchCentro">
                                    @if(count($centrosResult) > 0)
                                        <div class="list-group position-absolute w-100 shadow-sm z-3 mt-1" style="max-height: 200px; overflow-y: auto;">
                                            @foreach($centrosResult as $centro)
                                                <button type="button" class="list-group-item list-group-item-action py-2" 
                                                    wire:click="selectCentro({{ $centro->id }}, '{{ addslashes($centro->name) }}')">
                                                    <i class="bi bi-hospital me-2 text-primary"></i> {{ $centro->name }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @elseif(strlen(trim($searchCentro)) >= 1)
                                        <div class="list-group position-absolute w-100 shadow-sm z-3 mt-1">
                                            <div class="list-group-item text-muted small">No se encontraron centros coincidentes.</div>
                                        </div>
                                    @endif
                                @endif
                                @error('medical_center_id') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <!-- Autocomplete: Paciente -->
                            <div class="col-md-6 position-relative">
                                <label class="form-label fw-bold">Paciente</label>
                                @if($paciente_id)
                                    <div class="input-group">
                                        <input type="text" class="form-control bg-light text-truncate" value="{{ $selectedPacienteName }}" readonly>
                                        <button class="btn btn-outline-danger" type="button" wire:click="clearPaciente">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                @else
                                    <input type="text" class="form-control" placeholder="Escriba cédula o nombre del paciente..." wire:model.debounce.300ms="searchPaciente">
                                    @if(count($pacientesResult) > 0)
                                        <div class="list-group position-absolute w-100 shadow-sm z-3 mt-1" style="max-height: 200px; overflow-y: auto;">
                                            @foreach($pacientesResult as $paciente)
                                                @php
                                                    $fullNameP = $paciente->nombres . ' ' . $paciente->apellidos . ' (C.I: ' . $paciente->cedula . ')';
                                                @endphp
                                                <button type="button" class="list-group-item list-group-item-action py-2" 
                                                    wire:click="selectPaciente({{ $paciente->id }}, '{{ addslashes($fullNameP) }}')">
                                                    <i class="bi bi-person me-2 text-success"></i> {{ $fullNameP }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @elseif(strlen(trim($searchPaciente)) >= 1)
                                        <div class="list-group position-absolute w-100 shadow-sm z-3 mt-1">
                                            <div class="list-group-item text-muted small">No se encontraron pacientes coincidentes.</div>
                                        </div>
                                    @endif
                                @endif
                                @error('paciente_id') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            <!-- Autocomplete: Médico -->
                            <div class="col-md-6 position-relative">
                                <label class="form-label fw-bold">Médico / Doctor</label>
                                @if($medico_id)
                                    <div class="input-group">
                                        <input type="text" class="form-control bg-light text-truncate" value="{{ $selectedMedicoName }}" readonly>
                                        <button class="btn btn-outline-danger" type="button" wire:click="clearMedico">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                @else
                                    <input type="text" class="form-control" placeholder="Escriba nombre o matrícula del médico..." wire:model.debounce.300ms="searchMedico">
                                    @if(count($medicosResult) > 0)
                                        <div class="list-group position-absolute w-100 shadow-sm z-3 mt-1" style="max-height: 200px; overflow-y: auto;">
                                            @foreach($medicosResult as $medico)
                                                @php
                                                    $fullNameM = trim($medico->name . ' ' . ($medico->lastname ?? '')) . ($medico->license_number ? ' - Lic: ' . $medico->license_number : '');
                                                @endphp
                                                <button type="button" class="list-group-item list-group-item-action py-2" 
                                                    wire:click="selectMedico({{ $medico->id }}, '{{ addslashes($fullNameM) }}')">
                                                    <i class="bi bi-person-badge me-2 text-info"></i> {{ $fullNameM }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @elseif(strlen(trim($searchMedico)) >= 1)
                                        <div class="list-group position-absolute w-100 shadow-sm z-3 mt-1">
                                            <div class="list-group-item text-muted small">No se encontraron médicos coincidentes.</div>
                                        </div>
                                    @endif
                                @endif
                                @error('medico_id') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            {{ $isEditMode ? 'Actualizar' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 2. Modal Genérico de Detalles -->
    <div class="modal fade" id="modalDetail" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fs-6">{{ $detailTitle }}</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    
                    <!-- Caso A: Lista de Historias Médicas -->
                    @if($detailType === 'historias_list')
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>N° Historia</th>
                                        <th>Reg. Médico</th>
                                        <th>Centro Médico</th>
                                        <th>Paciente</th>
                                        <th>Médico</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($historiasDetalle as $item)
                                        <tr>
                                            <td>{{ $item->id }}</td>
                                            <td><span class="badge bg-info text-dark">{{ $item->numhistoria ?? 'S/H' }}</span></td>
                                            <td>{{ $item->getAttribute('reg-medico') }}</td>
                                            <td>{{ $item->medicalCenter->name ?? 'N/A' }}</td>
                                            <td>{{ $item->paciente ? $item->paciente->nombres . ' ' . $item->paciente->apellidos : 'N/A' }}</td>
                                            <td>{{ $item->medico ? $item->medico->name . ' ' . $item->medico->lastname : 'N/A' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-2">Sin registros de historias</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                    <!-- Caso B: Lista de Pacientes -->
                    @elseif($detailType === 'pacientes_list')
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Cédula</th>
                                        <th>Nombre Completo</th>
                                        <th>Teléfono</th>
                                        <th>Email</th>
                                        <th>Dirección</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($pacientesDetalle as $paciente)
                                        <tr>
                                            <td><strong>{{ $paciente->nac ?? '' }}-{{ $paciente->cedula ?? 'N/A' }}</strong></td>
                                            <td>{{ $paciente->nombres }} {{ $paciente->apellidos }}</td>
                                            <td>{{ $paciente->telefono ?? 'N/A' }}</td>
                                            <td>{{ $paciente->email ?? 'N/A' }}</td>
                                            <td>{{ $paciente->direccion ?? 'N/A' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-2">Sin información de pacientes</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                    <!-- Caso C: Detalle del Médico -->
                    @elseif($detailType === 'medico' && $selectedMedico)
                        <div class="row g-2">
                            <div class="col-md-6"><strong>Nombre Completo:</strong> {{ $selectedMedico->name }} {{ $selectedMedico->lastname }}</div>
                            <div class="col-md-6"><strong>N° Licencia / Matrícula:</strong> {{ $selectedMedico->license_number ?? 'N/A' }}</div>
                            <div class="col-md-6"><strong>Teléfono:</strong> {{ $selectedMedico->phone ?? 'N/A' }}</div>
                            <div class="col-md-6"><strong>Email:</strong> {{ $selectedMedico->email ?? 'N/A' }}</div>
                            <div class="col-md-6"><strong>Costo Consulta:</strong> {{ $selectedMedico->consultation_fee ?? 'N/A' }}</div>
                            <div class="col-md-6">
                                <strong>Especialidades:</strong> 
                                @if($selectedMedico->specialties->count() > 0)
                                    {{ $selectedMedico->specialties->pluck('name')->implode(', ') }}
                                @else
                                    N/A
                                @endif
                            </div>
                            <div class="col-md-12"><strong>Biografía:</strong> {{ $selectedMedico->biography ?? 'N/A' }}</div>
                        </div>
                    @endif

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts Bootstrap 5 / Livewire 2 / SweetAlert2 -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modalEdit = new bootstrap.Modal(document.getElementById('modalEditCentro'));
            const modalDetail = new bootstrap.Modal(document.getElementById('modalDetail'));

            window.addEventListener('open-modal-edit-centro', () => modalEdit.show());
            window.addEventListener('close-modal-edit-centro', () => modalEdit.hide());
            window.addEventListener('open-modal-detail', () => modalDetail.show());
        });

        function confirmDeleteHistoria(historiaId) {
            Swal.fire({
                title: '¿Estás seguro?',
                text: "Esta acción no se puede deshacer. Se eliminará el registro de la historia médica.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    @this.call('deleteHistoria', historiaId);
                }
            });
        }
    </script>
</div>