{{--
    El formulario del **representante del paciente** (WEB-3.4), en un diálogo: nombre, parentesco del
    catálogo de AppDDR, estado civil, cédula, teléfono, correo y dirección. El estado civil es el del
    adulto (O-59): en pediatría es el dato que el alta del niño ya no pide.

    Un solo diálogo para toda la página y uno solo para cargar y para editar: en el legado la tabla
    `representante` tiene `PRIMARY KEY (numhistoria)`, así que **es una fila por paciente** y "cargar" y
    "editar" son el mismo POST (lo resuelve `App\Actions\Pacientes\GuardarRepresentante`). El botón que lo
    abre vive en el bloque de la ficha y lo resuelve `public/js/clinica/representante.js`; si la validación
    falló, la página lo abre sola para que el error no quede detrás de una ventana cerrada.

    Las longitudes son las de las columnas (nombre 40, cédula 10, teléfono 20, correo 100 y dirección 60,
    que es el ancho de la columna legada): que el navegador no deje escribir lo que la base va a recortar.

    Recibe `$rep` (App\Clinica\Pacientes\Vistas\RepresentanteDePacienteVista).
--}}
<x-ui.dialogo id="dialogo-representante" titulo="{{ $rep->tituloFormulario() }}" lateral
              data-abrir-al-cargar="{{ $errors->hasAny(['nombre', 'codeparentesco', 'estado_civil', 'cedula', 'telefono', 'email', 'direccion']) ? '1' : '0' }}">
    <form method="POST" action="{{ $rep->urlGuardar() }}">
        @csrf
        @isset($volverA)<input type="hidden" name="volver_a_consulta" value="{{ $volverA }}">@endisset
        <div class="dialogo-cuerpo">
            <p class="mb-3 texto-tenue">
                Es el adulto que responde por el paciente y el que presta su teléfono para el recordatorio
                de la cita.
            </p>

            <div class="row g-3">
                {{-- El `id` de cada control lleva el prefijo `representante-` para no chocar con los `id`
                     que ya usa la ficha (el diálogo del cambio de cédula de la pantalla de citas manda
                     `cedula` y `email` igual que este): el `name` —que es lo que valida el servidor— no lo
                     lleva, y por eso cada campo declara su clave de error con `error="…"`. --}}
                <x-ui.campo etiqueta="Nombre y apellido" for="representante-nombre" error="nombre" class="col-12">
                    <input type="text" name="nombre" id="representante-nombre" class="form-control" maxlength="40"
                           autocomplete="off" value="{{ $rep->valor('nombre') }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Parentesco" for="representante-parentesco" error="codeparentesco" class="col-md-6"
                            ayuda="Si no lo sabes, déjalo en “Sin dato”: no es obligatorio.">
                    <select name="codeparentesco" id="representante-parentesco" class="form-select">
                        @foreach ($rep->parentescos() as $opcion)
                            <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                        @endforeach
                    </select>
                </x-ui.campo>

                {{-- El estado civil es **del adulto** (O-59, 2026-11-05): en pediatría el del niño no se
                     pide y el que vale es este. La lista es la misma del alta del paciente
                     (`FormularioDePacienteVista::ESTADOS_CIVILES`), no una nueva. --}}
                <x-ui.campo etiqueta="Estado civil" for="representante-estado_civil" error="estado_civil" class="col-md-6"
                            ayuda="Es el del representante, no el del paciente.">
                    <select name="estado_civil" id="representante-estado_civil" class="form-select">
                        @foreach ($rep->estadosCiviles() as $opcion)
                            <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                        @endforeach
                    </select>
                </x-ui.campo>

                <x-ui.campo etiqueta="Cédula" for="representante-cedula" error="cedula" class="col-md-6"
                            ayuda="Solo números. Es el documento del adulto, no el del paciente.">
                    <input type="text" name="cedula" id="representante-cedula" class="form-control"
                           inputmode="numeric" maxlength="10" autocomplete="off" value="{{ $rep->valor('cedula') }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Teléfono" for="representante-telefono" error="telefono" class="col-md-6"
                            ayuda="Es el número al que sale el recordatorio de la cita.">
                    <input type="text" name="telefono" id="representante-telefono" class="form-control"
                           maxlength="20" autocapitalize="off" value="{{ $rep->valor('telefono') }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Correo" for="representante-email" error="email" class="col-md-6">
                    <input type="email" name="email" id="representante-email" class="form-control" maxlength="100"
                           autocapitalize="off" value="{{ $rep->valor('email') }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Dirección" for="representante-direccion" error="direccion" class="col-12">
                    <input type="text" name="direccion" id="representante-direccion" class="form-control"
                           maxlength="60" value="{{ $rep->valor('direccion') }}">
                </x-ui.campo>
            </div>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary">Guardar el representante</button>
        </div>
    </form>
</x-ui.dialogo>
