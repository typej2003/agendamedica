@props(['name' => 'terminos', 'id' => 'aceptacion-legal'])

@once
    @push('estilos')
        <link rel="stylesheet" href="{{ asset('css/legal.css') }}">
    @endpush
@endonce

{{-- **Aceptación de los términos y la privacidad al registrarse.** La casilla no se marca directo: abre
     el diálogo, que muestra los dos documentos desde sus parciales (nada de texto duplicado) y sólo
     habilita «Aceptar» cuando se llegó al final. El enlace de cada documento también abre el diálogo en
     esa sección; sin JavaScript siguen siendo enlaces normales a las páginas públicas. --}}
<div class="legal-aceptacion" data-aceptacion-legal>
    <div class="form-check legal-aceptacion-casilla">
        <input class="form-check-input @error($name) is-invalid @enderror"
               type="checkbox" name="{{ $name }}" id="{{ $id }}" value="1"
               @checked(old($name)) required data-aceptacion-casilla>
        <label class="form-check-label" for="{{ $id }}">
            He leído y acepto los
            <a href="{{ route('legal.terminos') }}" target="_blank" rel="noopener"
               data-aceptacion-abrir="terminos">términos y condiciones</a>
            y la
            <a href="{{ route('legal.privacidad') }}" target="_blank" rel="noopener"
               data-aceptacion-abrir="privacidad">política de privacidad</a>.
        </label>
        @error($name)
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>

    <x-ui.dialogo :id="$id . '-dialogo'" titulo="Términos y condiciones y política de privacidad"
                  class="legal-dialogo" data-aceptacion-dialogo="1">
        <p class="legal-dialogo-aviso" id="{{ $id }}-aviso" data-aceptacion-aviso aria-live="polite">
            Desplázate hasta el final para habilitar «Aceptar».
        </p>

        <div class="legal-dialogo-cuerpo" data-aceptacion-scroll tabindex="0"
             aria-describedby="{{ $id }}-aviso"></div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-aceptacion-cancelar>Cancelar</button>
            <button type="button" class="btn btn-primary" data-aceptacion-aceptar disabled>
                Aceptar términos y condiciones
            </button>
        </div>
    </x-ui.dialogo>

    {{-- El texto no se copia: es el mismo parcial que usan `/terminos` y `/privacidad`. --}}
    <template data-aceptacion-documento="terminos">
        <x-legal.documento tipo="terminos" data-legal-documento="terminos" />
    </template>
    <template data-aceptacion-documento="privacidad">
        <x-legal.documento tipo="privacidad" data-legal-documento="privacidad" />
    </template>
</div>

@once
    @push('js')
        <script src="{{ asset('js/legal-aceptacion.js') }}"></script>
    @endpush
@endonce
