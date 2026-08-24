<div>
    <div class="search-box-card p-4 mb-5">
        <form wire:submit.prevent="search">
            <div class="row g-3 align-items-end">
                <!-- Select de País -->
                <div class="col-md-3">
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
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-geo-alt text-muted"></i></span>
                        <select class="form-select border-start-0 bg-light" id="selectedState" wire:model.live="selectedState" {{ empty($states) ? 'disabled' : '' }}>
                            <option value="">Selecciona un Estado</option>
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
                            <option value="">Selecciona una Especialidad</option>
                            @foreach($specialties as $specialty)
                                <option value="{{ $specialty->id }}">{{ $specialty->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <!-- Botón Buscar -->
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary-gineco w-100 d-flex justify-content-center align-items-center" style="height: 38px;">
                        <i class="bi bi-search fs-6"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>