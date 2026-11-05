{{-- La hoja de los documentos de texto (2026-11-04): vertical, media vertical, horizontal o estilo récipe. Es del doctor
     que firma y se guarda en `recipe_formatos.formato_imprimibles`; los récipes no la leen (siempre media hoja horizontal). --}}
<x-ui.tarjeta titulo="Hoja de los imprimibles" icono="bi-layout-text-window-reverse" class="mb-3" id="formato-hoja">
    <p class="form-text mt-0">
        Cómo salen la constancia, el reposo, la referencia, el informe, la hospitalización, la dieta, el laboratorio, los
        estudios y el resumen médico. <strong>Los récipes no cambian</strong>: siempre salen en media hoja horizontal.
    </p>

    <form method="POST" action="{{ $vm->urlGuardarHoja() }}" data-formato-form data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        <div class="row g-2" role="radiogroup" aria-label="Hoja de los imprimibles">
            @foreach ($vm->opcionesDeHoja() as $hoja)
                <div class="col-12 col-sm-6">
                    <input type="radio" class="btn-check" name="formato_imprimibles" id="hoja-{{ $hoja['valor'] }}"
                           value="{{ $hoja['valor'] }}" autocomplete="off" @checked($hoja['activa'])>
                    <label class="btn btn-outline-primary w-100 h-100 text-start" for="hoja-{{ $hoja['valor'] }}">
                        <i class="bi {{ $hoja['icono'] }} d-block fs-4 mb-1" aria-hidden="true"></i>
                        <strong class="d-block">{{ $hoja['titulo'] }}</strong>
                        <span class="small">{{ $hoja['descripcion'] }}</span>
                    </label>
                </div>
            @endforeach
        </div>

        @error('formato_imprimibles')
            <span class="campo-error d-block mt-2">{{ $message }}</span>
        @enderror

        <p class="form-text mt-3 mb-0">
            @if ($vm->hojaActual() === null)
                Todavía no elegiste: cada documento sale como hasta hoy. Al elegir una, se recuerda para todas tus impresiones.
            @else
                Se recuerda para todas tus impresiones. La vista previa de arriba sigue siendo la del récipe.
            @endif
        </p>

        @include('clinica.formato._guardar', ['texto' => 'Guardar la hoja'])
    </form>

    @if ($vm->hojaActual() !== null)
        <form method="POST" action="{{ $vm->urlGuardarHoja() }}" class="mt-2">
            @csrf
            <input type="hidden" name="formato_imprimibles" value="">
            <button class="btn btn-link btn-sm p-0">Volver a como está hoy</button>
        </form>
    @endif
</x-ui.tarjeta>
