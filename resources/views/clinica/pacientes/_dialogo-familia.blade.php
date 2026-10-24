{{--
    El formulario de la **familia del niño** (WEB-7.4.2b), en un diálogo: los padres (nombre, nacimiento, ocupación y celular), el teléfono
    de la familia, la cédula del representante, el contacto y la dinámica familiar. Un solo diálogo para cargar y para editar. El botón que
    lo abre vive en el bloque de la ficha y lo resuelve `public/js/clinica/familia.js`; si la validación falló, la página lo abre sola.

    Las longitudes son las de las columnas del escritorio (nombres 120, ocupación 60, celulares 12, correo 120, localidad 200).

    Recibe `$familia` (App\Clinica\Pacientes\Vistas\FamiliaDePacienteVista).
--}}
@php
    $campos = ['nom_madre', 'fecn_madre', 'ocupa_madre', 'celu_madre', 'nom_padre', 'fecn_padre', 'ocupa_padre', 'cel_padre', 'telefono',
               'ced_representante', 'email', 'direccion', 'localidad', 'dinamica_familiar', 'familia_texto'];
@endphp
<x-ui.dialogo id="dialogo-familia" titulo="{{ $familia->botonEditar() }}" lateral
              data-abrir-al-cargar="{{ $errors->hasAny($campos) ? '1' : '0' }}">
    <form method="POST" action="{{ $familia->urlGuardar() }}">
        @csrf
        <div class="dialogo-cuerpo">
            <p class="mb-3 texto-tenue">
                Los padres y el contacto de la familia. Si el niño no tiene teléfono propio, los recordatorios salen al celular de la madre,
                del padre o al otro teléfono, en ese orden.
            </p>

            <div class="row g-3">
                <x-ui.campo etiqueta="Madre" for="familia-nom_madre" error="nom_madre" class="col-md-6">
                    <input type="text" name="nom_madre" id="familia-nom_madre" class="form-control" maxlength="120" autocomplete="off"
                           value="{{ old('nom_madre', $familia->valor('nom_madre')) }}">
                </x-ui.campo>
                <x-ui.campo etiqueta="Celular de la madre" for="familia-celu_madre" error="celu_madre" class="col-md-6">
                    <input type="text" name="celu_madre" id="familia-celu_madre" class="form-control" maxlength="12" inputmode="tel"
                           value="{{ old('celu_madre', $familia->valor('celu_madre')) }}">
                </x-ui.campo>
                <x-ui.campo etiqueta="Nacimiento de la madre" for="familia-fecn_madre" error="fecn_madre" class="col-md-6">
                    <input type="date" name="fecn_madre" id="familia-fecn_madre" class="form-control" max="{{ \App\Clinica\FechaDelConsultorio::hoyComoTexto() }}"
                           value="{{ old('fecn_madre', $familia->valor('fecn_madre')) }}">
                </x-ui.campo>
                <x-ui.campo etiqueta="Ocupación de la madre" for="familia-ocupa_madre" error="ocupa_madre" class="col-md-6">
                    <input type="text" name="ocupa_madre" id="familia-ocupa_madre" class="form-control" maxlength="60"
                           value="{{ old('ocupa_madre', $familia->valor('ocupa_madre')) }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Padre" for="familia-nom_padre" error="nom_padre" class="col-md-6">
                    <input type="text" name="nom_padre" id="familia-nom_padre" class="form-control" maxlength="120" autocomplete="off"
                           value="{{ old('nom_padre', $familia->valor('nom_padre')) }}">
                </x-ui.campo>
                <x-ui.campo etiqueta="Celular del padre" for="familia-cel_padre" error="cel_padre" class="col-md-6">
                    <input type="text" name="cel_padre" id="familia-cel_padre" class="form-control" maxlength="12" inputmode="tel"
                           value="{{ old('cel_padre', $familia->valor('cel_padre')) }}">
                </x-ui.campo>
                <x-ui.campo etiqueta="Nacimiento del padre" for="familia-fecn_padre" error="fecn_padre" class="col-md-6">
                    <input type="date" name="fecn_padre" id="familia-fecn_padre" class="form-control" max="{{ \App\Clinica\FechaDelConsultorio::hoyComoTexto() }}"
                           value="{{ old('fecn_padre', $familia->valor('fecn_padre')) }}">
                </x-ui.campo>
                <x-ui.campo etiqueta="Ocupación del padre" for="familia-ocupa_padre" error="ocupa_padre" class="col-md-6">
                    <input type="text" name="ocupa_padre" id="familia-ocupa_padre" class="form-control" maxlength="60"
                           value="{{ old('ocupa_padre', $familia->valor('ocupa_padre')) }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Otro teléfono" for="familia-telefono" error="telefono" class="col-md-6">
                    <input type="text" name="telefono" id="familia-telefono" class="form-control" maxlength="12" inputmode="tel"
                           value="{{ old('telefono', $familia->valor('telefono')) }}">
                </x-ui.campo>
                <x-ui.campo etiqueta="Cédula del representante" for="familia-ced_representante" error="ced_representante" class="col-md-6"
                            ayuda="Solo números. Es el documento del adulto, no el del niño.">
                    <input type="text" name="ced_representante" id="familia-ced_representante" class="form-control" maxlength="10" inputmode="numeric"
                           value="{{ old('ced_representante', $familia->valor('ced_representante')) }}">
                </x-ui.campo>
                <x-ui.campo etiqueta="Correo" for="familia-email" error="email" class="col-md-6">
                    <input type="email" name="email" id="familia-email" class="form-control" maxlength="120" autocapitalize="off"
                           value="{{ old('email', $familia->valor('email')) }}">
                </x-ui.campo>
                <x-ui.campo etiqueta="Localidad" for="familia-localidad" error="localidad" class="col-md-6">
                    <input type="text" name="localidad" id="familia-localidad" class="form-control" maxlength="200"
                           value="{{ old('localidad', $familia->valor('localidad')) }}">
                </x-ui.campo>
                <x-ui.campo etiqueta="Dirección" for="familia-direccion" error="direccion" class="col-12">
                    <textarea name="direccion" id="familia-direccion" class="form-control" rows="2" maxlength="2000">{{ old('direccion', $familia->valor('direccion')) }}</textarea>
                </x-ui.campo>
                <x-ui.campo etiqueta="Dinámica familiar" for="familia-dinamica_familiar" error="dinamica_familiar" class="col-12">
                    <textarea name="dinamica_familiar" id="familia-dinamica_familiar" class="form-control" rows="3" maxlength="5000">{{ old('dinamica_familiar', $familia->valor('dinamica_familiar')) }}</textarea>
                </x-ui.campo>
                <x-ui.campo etiqueta="Nota de la familia" for="familia-familia_texto" error="familia_texto" class="col-12">
                    <textarea name="familia_texto" id="familia-familia_texto" class="form-control" rows="2" maxlength="5000">{{ old('familia_texto', $familia->valor('familia_texto')) }}</textarea>
                </x-ui.campo>
            </div>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary">Guardar la familia</button>
        </div>
    </form>
</x-ui.dialogo>
