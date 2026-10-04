<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white d-flex align-items-center">
                    <i class="bi bi-file-earmark-arrow-up fs-4 me-2"></i>
                    <h5 class="mb-0">Carga y Actualización de Archivos SQL (PowerBuilder)</h5>
                </div>

                <div class="card-body p-4">
                    {{-- Mensajes de estado --}}
                    @if (session()->has('message'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i>{{ session('message') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    @if (session()->has('error'))
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    <form wire:submit.prevent="procesarSql">
                        {{-- Selección del Médico --}}
                        <div class="mb-3">
                            <label for="medico_id" class="form-label fw-bold">1. Seleccionar Médico en Sistema</label>
                            <select id="medico_id" class="form-select @error('medico_id') is-invalid @enderror" wire:model="medico_id">
                                <option value="">-- Seleccione un Médico --</option>
                                @foreach($medicos as $medico)
                                    <option value="{{ $medico->id }}">
                                        {{ $medico->name }} {{ $medico->lastname ?? '' }} {{ !empty($medico->reg_medico_calculado) ? '[Reg: '.$medico->reg_medico_calculado.']' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('medico_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror

                            @if(!empty($regMedicoSeleccionado))
                                <div class="mt-2 p-2 bg-light rounded border d-flex align-items-center">
                                    <i class="bi bi-person-badge-fill text-primary fs-5 me-2"></i>
                                    <div>
                                        <small class="text-muted d-block">Reg-Médico asociado para la importación:</small>
                                        <span class="fw-bold text-dark fs-6">{{ $regMedicoSeleccionado }}</span>
                                    </div>
                                </div>
                            @endif
                        </div>

                        {{-- Carga de archivos SQL --}}
                        <div class="mb-4">
                            <label for="nuevosArchivos" class="form-label fw-bold">2. Adjuntar Archivos SQL (.sql)</label>
                            <input type="file" id="nuevosArchivos" class="form-control @error('archivosSql') is-invalid @enderror @error('nuevosArchivos.*') is-invalid @enderror" wire:model="nuevosArchivos" multiple accept=".sql">
                            <div class="form-text">Puedes agregar uno o varios archivos progresivamente sin perder los seleccionados previamente.</div>
                            
                            @error('archivosSql')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                            @error('nuevosArchivos.*')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Indicador de subida de archivos --}}
                        <div wire:loading wire:target="nuevosArchivos" class="mb-3">
                            <div class="spinner-border spinner-border-sm text-primary" role="status">
                                <span class="visually-hidden">Cargando archivos...</span>
                            </div>
                            <span class="text-primary ms-2 fw-bold">Cargando y validando archivos en el servidor...</span>
                        </div>

                        {{-- Previsualización y eliminación de archivos seleccionados --}}
                        @if(!empty($archivosSql))
                            <div class="card bg-light border-0 mb-4">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="fw-bold mb-0">Archivos acumulados para procesar ({{ count($archivosSql) }}):</h6>
                                        <button type="button" class="btn btn-sm btn-outline-danger" wire:click="$set('archivosSql', [])">
                                            <i class="bi bi-trash me-1"></i> Limpiar todos
                                        </button>
                                    </div>
                                    <ul class="list-group list-group-flush small" style="max-height: 250px; overflow-y: auto;">
                                        @foreach($archivosSql as $index => $file)
                                            <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center py-2 border-bottom">
                                                <div>
                                                    <i class="bi bi-filetype-sql text-primary me-2 fs-6"></i>
                                                    <span class="fw-semibold">{{ $file->getClientOriginalName() }}</span>
                                                    <span class="badge bg-secondary ms-2">{{ number_format($file->getSize() / 1024, 2) }} KB</span>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-danger py-0 px-2" wire:click="eliminarArchivo({{ $index }})" title="Eliminar este archivo">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endif

                        {{-- Botón para procesar --}}
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg" wire:loading.attr="disabled" wire:target="procesarSql, nuevosArchivos">
                                <span wire:loading.remove wire:target="procesarSql">
                                    <i class="bi bi-arrow-repeat me-1"></i> Actualizar e Importar Datos
                                </span>
                                <span wire:loading wire:target="procesarSql">
                                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                    Procesando tablas e inserts en la base de datos...
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>