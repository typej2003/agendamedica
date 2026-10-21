{{-- La ficha de la sede (nombre, dirección, ciudad, teléfono): el país y el estado salen de la ciudad (`GuardarSede`). --}}
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label" for="sede-nombre">Nombre <span class="text-danger">*</span></label>
        <input id="sede-nombre" type="text" class="form-control @error('name') is-invalid @enderror" wire:model.defer="name"
               placeholder="Ej.: Clínica Metropolitana">
        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="sede-ciudad">Ciudad <span class="text-danger">*</span></label>
        <select id="sede-ciudad" class="form-select @error('city_id') is-invalid @enderror" wire:model.defer="city_id">
            <option value="">Elige…</option>
            @foreach ($ciudades as $ciudad)
                <option value="{{ $ciudad->id }}">{{ $ciudad->name }}{{ $ciudad->state ? ' (' . $ciudad->state->name . ')' : '' }}</option>
            @endforeach
        </select>
        @error('city_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-8">
        <label class="form-label" for="sede-direccion">Dirección <span class="text-danger">*</span></label>
        <input id="sede-direccion" type="text" class="form-control @error('address') is-invalid @enderror" wire:model.defer="address">
        @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="sede-telefono">Teléfono</label>
        <input id="sede-telefono" type="text" class="form-control @error('phone') is-invalid @enderror" wire:model.defer="phone">
        @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
</div>
