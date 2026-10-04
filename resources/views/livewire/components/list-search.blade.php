<div>
    <div class="search-box-card p-4 mb-5">
        <form wire:submit.prevent="$refresh">
            <div class="row g-3">
                <!-- Buscar Doctor Directamente -->
                <div class="col-12">
                    <label for="searchDoctor" class="form-label fw-bold small text-muted">Buscar Doctor por Nombre</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-person-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0 bg-light" id="searchDoctor" 
                               wire:model.live.debounce.300ms="searchDoctor" placeholder="Ej: Dra. María Pérez">
                    </div>
                </div>

                <!-- Select de País -->
                <div class="col-md-4">
                    <label for="selectedCountry" class="form-label fw-bold small text-muted">País</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-globe text-muted"></i></span>
                        <select class="form-select border-start-0 bg-light" id="selectedCountry" wire:model.live="selectedCountry">
                            <option value="">Seleccionar País</option>
                            @foreach($countries as $country)
                                <option value="{{ $country->id }}">{{ $country->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <!-- Select de Estado -->
                <div class="col-md-4">
                    <label for="selectedState" class="form-label fw-bold small text-muted">Estado</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-map text-muted"></i></span>
                        <select class="form-select border-start-0 bg-light" id="selectedState" wire:model.live="selectedState" {{ empty($states) ? 'disabled' : '' }}>
                            <option value="">Seleccione un Estado</option>
                            @foreach($states as $state)
                                <option value="{{ $state->id }}">{{ $state->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <!-- Select de Especialidad -->
                <div class="col-md-4">
                    <label for="selectedSpecialty" class="form-label fw-bold small text-muted">Especialidad</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-heart-pulse text-muted"></i></span>
                        <select class="form-select border-start-0 bg-light" id="selectedSpecialty" wire:model.live="selectedSpecialty">
                            <option value="">Seleccione una Especialidad</option>
                            @foreach($specialties as $specialty)
                                <option value="{{ $specialty->id }}">{{ $specialty->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Indicador de Carga Livewire -->
    <div wire:loading class="text-center w-100 my-4">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Buscando...</span>
        </div>
    </div>

    <!-- Contenedor de Fichas (Cards) de Médicos -->
    <div wire:loading.remove class="row g-4">
        @forelse($medicos as $medico)
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm p-3">
                    <div class="d-flex align-items-center mb-3">
                        <img src="{{ $medico->photo_path ? asset('storage/' . $medico->photo_path) : 'https://via.placeholder.com/150' }}" 
                             alt="{{ $medico->name }}" class="rounded-circle me-3" style="width: 65px; height: 65px; object-fit: cover;">
                        <div>
                            <h5 class="fw-bold mb-0">Dr(a). {{ $medico->name }} {{ $medico->lastname }}</h5>
                            <small class="text-muted">MPPS: {{ $medico->license_number }}</small>
                        </div>
                    </div>

                    <!-- Especialidades -->
                    <div class="mb-2">
                        @foreach($medico->specialties as $spec)
                            <span class="badge bg-light text-primary border me-1">{{ $spec->name }}</span>
                        @endforeach
                    </div>

                    <!-- Ubicación y Centro Médico -->
                    <p class="small text-muted mb-1">
                        <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                        {{ $medico->office->medicalCenter->city->name ?? 'N/A' }}, {{ $medico->office->medicalCenter->estado->name ?? '' }}
                    </p>

                    @if($medico->office)
                        <p class="small text-muted mb-2">
                            <i class="bi bi-hospital me-1"></i>
                            {{ $medico->office->medicalCenter->name ?? '' }} - Consultorio {{ $medico->office->office_number }}
                        </p>
                    @endif

                    <div class="pt-3 border-top mt-auto d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted extra-small d-block">Consulta desde:</span>
                            <strong>${{ number_format($medico->consultation_fee, 2) }}</strong>
                        </div>
                        @auth
                            <a href="{{ route('agendar.cita', ['medicoId' => $medico->id, 'medicalCenterId' => $medico->office->medicalCenter->id ?? 0]) }}" class="btn btn-sm btn-primary">Agendar Cita</a>
                        @else
                            <a href="{{ route('login') }}" class="btn btn-sm btn-primary">Agendar Cita</a>
                        @endauth
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12 text-center py-5">
                <i class="bi bi-person-x fs-1 text-muted"></i>
                <h5 class="mt-3 text-muted">No se encontraron médicos que coincidan con los criterios de búsqueda.</h5>
            </div>
        @endforelse
    </div>

    <!-- Links de Paginación -->
    <div class="d-flex justify-content-center mt-5">
        {{ $medicos->links() }}
    </div>
</div>