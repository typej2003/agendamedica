{{--
    La tarjeta **«Representante» del alta de pediatría** (2026-10-19): un representante puede tener varios hijos.

    Solo se incluye cuando el alta es de pediatría (`$representanteAlta->ofrece()`); en la edición y fuera de pediatría el
    representante se sigue cargando desde la ficha, con su diálogo.

    La cédula es el vínculo: al escribirla y pulsar «Buscar», `public/js/clinica/representante-alta.js` le pide los datos al
    servidor y rellena el resto de los campos, además de listar los otros pacientes de los que ese adulto ya es representante.
    El servidor **vuelve a resolver la cédula al guardar**: la familia no viaja en un campo oculto.

    Los `name` llevan el prefijo `representante_` porque este formulario ya manda `cedula`, `telefono` y `email` del niño: sin
    el prefijo, el representante pisaría los datos del paciente. Los `id` llevan el prefijo `representante-` por el mismo
    motivo (mismo criterio que el diálogo de la ficha) y por eso cada campo declara su clave de error con `error="…"`.

    Recibe `$representanteAlta` (App\Clinica\Pacientes\Vistas\RepresentanteDeAltaVista).
--}}
<x-ui.tarjeta titulo="Representante" icono="bi-person-hearts" class="mb-3">
    <p class="mb-3 texto-tenue">
        Es el adulto que responde por el niño. Si ya es representante de un hermano, búscalo por cédula: se
        reutilizan sus datos y la familia.
    </p>

    <div class="row g-3">
        <x-ui.campo etiqueta="Cédula del representante" for="representante-cedula" error="representante_cedula" class="col-md-6"
                    ayuda="Solo números. Búscala primero: si ya atiende a un hermano, sus datos se reutilizan.">
            <div class="input-group">
                <input type="text" name="representante_cedula" id="representante-cedula" class="form-control"
                       inputmode="numeric" maxlength="10" autocomplete="off"
                       value="{{ $representanteAlta->valor('cedula') }}">
                <button type="button" class="btn btn-outline-secondary" data-representante-buscar="{{ $representanteAlta->urlBuscar() }}">
                    <i class="bi bi-search" aria-hidden="true"></i> Buscar
                </button>
            </div>
        </x-ui.campo>

        <x-ui.campo etiqueta="Nombre y apellido" for="representante-nombre" error="representante_nombre" class="col-md-6">
            <input type="text" name="representante_nombre" id="representante-nombre" class="form-control" maxlength="40"
                   autocomplete="off" value="{{ $representanteAlta->valor('nombre') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Parentesco" for="representante-parentesco" error="representante_parentesco" class="col-md-4"
                    ayuda="Si no lo sabes, dejalo en “Sin dato”: no es obligatorio.">
            <select name="representante_parentesco" id="representante-parentesco" class="form-select">
                @foreach ($representanteAlta->parentescos() as $opcion)
                    <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                @endforeach
            </select>
        </x-ui.campo>

        <x-ui.campo etiqueta="Teléfono" for="representante-telefono" error="representante_telefono" class="col-md-4"
                    ayuda="Es el número al que sale el recordatorio de la cita.">
            <input type="text" name="representante_telefono" id="representante-telefono" class="form-control"
                   maxlength="20" autocapitalize="off" value="{{ $representanteAlta->valor('telefono') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Correo" for="representante-email" error="representante_email" class="col-md-4">
            <input type="email" name="representante_email" id="representante-email" class="form-control" maxlength="100"
                   autocapitalize="off" value="{{ $representanteAlta->valor('email') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Dirección" for="representante-direccion" error="representante_direccion" class="col-12">
            <input type="text" name="representante_direccion" id="representante-direccion" class="form-control"
                   maxlength="60" value="{{ $representanteAlta->valor('direccion') }}">
        </x-ui.campo>
    </div>

    {{-- El resultado de la búsqueda: los otros pacientes de los que ese adulto ya es representante, o el aviso de que no se
         encontró. Lo pinta `public/js/clinica/representante-alta.js`. --}}
    <div class="mt-3" data-representante-hijos aria-live="polite"></div>
</x-ui.tarjeta>
