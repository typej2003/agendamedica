<div>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="card my-4">
                    <div class="card-header p-0 position-relative mt-n4 mx-3 z-index-2">
                        <div class="bg-gradient-primary shadow-primary border-radius-lg pt-4 pb-3 d-flex justify-content-between align-items-center px-3">
                            <h6 class="text-white text-capitalize ps-3 mb-0">Médicos y Centros Médicos</h6>
                            <button wire:click="openModal" class="btn btn-sm btn-light mb-0">
                                <i class="fas fa-plus me-1"></i> Asignar Centro Médico
                            </button>
                        </div>
                    </div>

                    <div class="card-body px-0 pb-2">
                        <!-- Buscador Principal -->
                        <div class="row px-4 mb-3">
                            <div class="col-md-4">
                                <div class="input-group input-group-outline">
                                    <input type="text" wire:model.debounce.300ms="search" class="form-control text-dark" placeholder="Buscar por médico, N° Licencia, Centro o Reg. Médico...">
                                </div>
                            </div>
                        </div>

                        <!-- Tabla de Registros -->
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">#</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Médico</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">N° Licencia</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Centro Médico</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Reg. Médico</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($relaciones as $item)
                                        <tr>
                                            <td class="ps-4">
                                                <p class="text-xs font-weight-bold mb-0 text-dark">{{ $item->id }}</p>
                                            </td>
                                            <td>
                                                <p class="text-xs font-weight-bold mb-0 text-dark">
                                                    {{ $item->medico_name }} {{ $item->medico_lastname }}
                                                </p>
                                            </td>
                                            <td>
                                                <span class="text-xs font-weight-bold text-dark">
                                                    {{ $item->medico_license ?? 'S/L' }}
                                                </span>
                                            </td>
                                            <td>
                                                <p class="text-xs font-weight-bold mb-0 text-dark">
                                                    {{ $item->center_name ?? 'N/A' }}
                                                </p>
                                            </td>
                                            <td>
                                                <span class="badge badge-sm bg-gradient-info">
                                                    {{ $item->reg_medico_val ?? 'Sin Registro' }}
                                                </span>
                                            </td>
                                            <td class="align-middle text-center">
                                                <button wire:click="edit({{ $item->id }})" class="btn btn-link text-dark px-2 mb-0" title="Editar">
                                                    <i class="fas fa-pencil-alt text-dark me-2"></i>Editar
                                                </button>
                                                <button wire:click="confirmDelete({{ $item->id }})" class="btn btn-link text-danger text-gradient px-2 mb-0" title="Eliminar">
                                                    <i class="far fa-trash-alt me-2"></i>Eliminar
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center py-4">
                                                <p class="text-xs text-secondary mb-0">No se encontraron asignaciones registradas.</p>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginación -->
                        <div class="px-4 pt-3 d-flex justify-content-end">
                            {{ $relaciones->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Formulario -->
    <div wire:ignore.self class="modal fade" id="modalMedicoCenter" tabindex="-1" aria-labelledby="modalMedicoCenterLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-dark" id="modalMedicoCenterLabel">
                        {{ $isEdit ? 'Editar Asignación' : 'Nueva Asignación' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="closeModal"></button>
                </div>
                <div class="modal-body">
                    <form>
                        <!-- Autocompletado de Médico -->
                        <div class="mb-3 position-relative">
                            <label class="form-label font-weight-bold text-xs text-dark">Médico <span class="text-danger">*</span></label>
                            
                            @if($selectedMedicoText)
                                <div class="input-group">
                                    <input type="text" class="form-control border px-2 bg-light text-dark font-weight-bold" value="{{ $selectedMedicoText }}" readonly>
                                    <button class="btn btn-outline-danger mb-0 px-3" type="button" wire:click="clearSelectedMedico">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            @else
                                <input type="text" wire:model.debounce.300ms="searchMedicoModal" class="form-control border px-2 text-dark @error('medico_id') is-invalid @enderror" placeholder="Escriba nombre, apellido o N° licencia...">
                                
                                @if(!empty($medicosSearchResults) && count($medicosSearchResults) > 0)
                                    <ul class="list-group position-absolute w-100 shadow-lg mt-1" style="z-index: 1050; max-height: 200px; overflow-y: auto;">
                                        @foreach($medicosSearchResults as $m)
                                            <li class="list-group-item list-group-item-action cursor-pointer py-2 px-3 bg-white" wire:click="selectMedico({{ $m->id }})">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="text-xs font-weight-bold text-dark">{{ $m->name }} {{ $m->lastname }}</span>
                                                    <span class="badge bg-secondary text-xxs">Lic: {{ $m->license_number ?? 'S/L' }}</span>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            @endif

                            @error('medico_id') <span class="text-danger text-xs">{{ $message }}</span> @enderror
                        </div>

                        <!-- Selección de Centro Médico -->
                        <div class="mb-3">
                            <label class="form-label font-weight-bold text-xs text-dark">Centro Médico <span class="text-danger">*</span></label>
                            <select wire:model="medical_center_id" class="form-select border px-2 text-dark @error('medical_center_id') is-invalid @enderror">
                                <option value="" class="text-dark">-- Seleccione Centro Médico --</option>
                                @foreach ($centrosMedicos as $centro)
                                    <option value="{{ $centro->id }}" class="text-dark">{{ $centro->name }}</option>
                                @endforeach
                            </select>
                            @error('medical_center_id') <span class="text-danger text-xs">{{ $message }}</span> @enderror
                        </div>

                        <!-- Registro Médico -->
                        <div class="mb-3">
                            <label class="form-label font-weight-bold text-xs text-dark">Reg. Médico</label>
                            <input type="text" wire:model="reg_medico" class="form-control border px-2 text-dark @error('reg_medico') is-invalid @enderror" placeholder="Número de Registro Médico">
                            @error('reg_medico') <span class="text-danger text-xs">{{ $message }}</span> @enderror
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" wire:click="closeModal">Cancelar</button>
                    <button type="button" wire:click.prevent="{{ $isEdit ? 'update' : 'store' }}" class="btn btn-primary">
                        {{ $isEdit ? 'Actualizar' : 'Guardar' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('js')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var modalElement = document.getElementById('modalMedicoCenter');
        var bsModal = new bootstrap.Modal(modalElement, {
            backdrop: 'static',
            keyboard: false
        });

        window.addEventListener('open-modal', event => {
            bsModal.show();
        });

        window.addEventListener('close-modal', event => {
            bsModal.hide();
        });

        window.addEventListener('swal:alert', event => {
            Swal.fire({
                icon: event.detail.type,
                title: event.detail.title,
                text: event.detail.text,
                timer: 3000,
                showConfirmButton: false
            });
        });

        window.addEventListener('swal:confirm', event => {
            Swal.fire({
                title: event.detail.title,
                text: event.detail.text,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    Livewire.emit('deleteRelationConfirmed', event.detail.id);
                }
            });
        });
    });
</script>
@endpush