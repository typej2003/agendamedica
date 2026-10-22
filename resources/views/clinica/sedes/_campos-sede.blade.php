{{-- Los campos de una sede, para el panel de la sede y el de la sede rápida (el que se abre desde el consultorio).

     - `$prefijo`: el de los `id` (dos paneles en la misma página).
     - `$conValores`: el panel de la sede trae lo escrito (`old()` del formulario `sede`) o la sede que se edita; el de la
       sede rápida nace vacío y sus errores los escribe `sedes.js` en los `[data-error-de]` (llega por `fetch`, no hay
       `old()`), así que su clave de error no es la del servidor: un error del otro panel no se le pinta. --}}
<div class="row g-3">
    <x-ui.campo etiqueta="Nombre" for="{{ $prefijo }}name" :error="($conValores ? '' : $prefijo) . 'name'" requerido class="col-12">
        <input type="text" name="name" id="{{ $prefijo }}name" class="form-control" maxlength="200" autofocus
               placeholder="Clínica Metropolitana" value="{{ $conValores ? $vm->valorSede('name') : '' }}">
        @unless ($conValores)<span class="campo-error" data-error-de="name" hidden></span>@endunless
    </x-ui.campo>

    <x-ui.campo etiqueta="Dirección" for="{{ $prefijo }}address" :error="($conValores ? '' : $prefijo) . 'address'" requerido class="col-12">
        <input type="text" name="address" id="{{ $prefijo }}address" class="form-control" maxlength="1000"
               value="{{ $conValores ? $vm->valorSede('address') : '' }}">
        @unless ($conValores)<span class="campo-error" data-error-de="address" hidden></span>@endunless
    </x-ui.campo>

    <x-ui.campo etiqueta="Ciudad" for="{{ $prefijo }}city_id" :error="($conValores ? '' : $prefijo) . 'city_id'" requerido class="col-12">
        {{-- Sin `puede-crear`: en la web clínica sólo se elige de la lista (crear ciudades es del administrador). --}}
        @php $ciudad = $vm->ciudadElegida($conValores); @endphp
        <x-ui.selector-buscable name="city_id" id="{{ $prefijo }}city_id" required :con-old="false"
            :value="$ciudad['valor']" :value-label="$ciudad['etiqueta']" :url="route('clinica.ciudades.buscar')"
            placeholder="Escribe la ciudad…" />
        @unless ($conValores)<span class="campo-error" data-error-de="city_id" hidden></span>@endunless
    </x-ui.campo>

    <x-ui.campo etiqueta="Teléfono" for="{{ $prefijo }}phone" :error="($conValores ? '' : $prefijo) . 'phone'" class="col-12">
        <input type="text" name="phone" id="{{ $prefijo }}phone" class="form-control" maxlength="50"
               value="{{ $conValores ? $vm->valorSede('phone') : '' }}">
        @unless ($conValores)<span class="campo-error" data-error-de="phone" hidden></span>@endunless
    </x-ui.campo>

    @if ($conValores)
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="activo" id="{{ $prefijo }}activo" value="1" {{ $vm->sedeSeSigueUsando() ? 'checked' : '' }}>
                <label class="form-check-label" for="{{ $prefijo }}activo">Se sigue usando</label>
            </div>
        </div>
    @else
        <input type="hidden" name="activo" value="1">
    @endif
</div>
