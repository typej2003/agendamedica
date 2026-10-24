{{-- La ficha de la sede (nombre, dirección, ciudad, teléfono): el país y el estado salen de la ciudad (`GuardarSede`). --}}
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label" for="sede-nombre">Nombre <span class="text-danger">*</span></label>
        <input id="sede-nombre" type="text" class="form-control @error('name') is-invalid @enderror" wire:model="name"
               placeholder="Ej.: Clínica Metropolitana">
        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-6">
        {{-- Son ~500 ciudades: el selector las pide al servidor. El administrador puede crear la que falte (pide el estado). --}}
        <x-ui.selector-buscable name="city_id" id="sede-ciudad" label="Ciudad" required wire:model="city_id"
            :value="$city_id" :value-label="$ciudadElegida" :url="route('admin.ciudades.buscar')"
            placeholder="Escribe la ciudad…" :error="$errors->first('city_id')"
            puede-crear :crear-url="route('admin.ciudades.crear')" crear-etiqueta="Crear"
            :crear-extra="['name' => 'state_id', 'label' => 'Estado', 'options' => $estados]" />
    </div>
    <div class="col-md-8">
        <label class="form-label" for="sede-direccion">Dirección <span class="text-danger">*</span></label>
        <input id="sede-direccion" type="text" class="form-control @error('address') is-invalid @enderror" wire:model="address">
        @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="sede-telefono">Teléfono</label>
        <input id="sede-telefono" type="text" class="form-control @error('phone') is-invalid @enderror" wire:model="phone">
        @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
</div>
