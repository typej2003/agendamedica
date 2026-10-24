{{-- Las tarjetas de campos de la ficha del paciente (cédula, contacto y datos del consultorio): las comparten el formulario de la ficha y
     la sección «Datos personales» de la consulta (WEB-11.25). Recibe `$vm` (FormularioDePacienteVista). Sin <form>: lo pone quien la incluye. --}}
<div class="campos-paciente"><div class="campos-paciente-rejilla">
<x-ui.tarjeta titulo="Quién es" icono="bi-person-vcard" class="campos-paciente-ancha">
    <div class="row g-3">
        <x-ui.campo etiqueta="Cédula" for="cedula" :requerido="! $vm->sinCedula()" class="col-md-6">
            <div class="input-group">
                <select name="nac" id="nac" class="form-select campo-nacionalidad" aria-label="Nacionalidad">
                    @foreach ($vm->nacionalidades() as $opcion)
                        <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                    @endforeach
                </select>
                <input type="text" name="cedula" id="cedula" class="form-control" inputmode="numeric"
                       maxlength="10" autocomplete="off" value="{{ $vm->cedula() }}">
                <label class="input-group-text" for="sin_cedula">
                    <input class="form-check-input mt-0 me-2" type="checkbox" name="sin_cedula" id="sin_cedula"
                           value="1" {{ $vm->sinCedula() ? 'checked' : '' }}>
                    Sin cédula (S.C.)
                </label>
            </div>
            @error('cedula')
                <span class="campo-error d-block">{{ $message }}</span>
            @enderror
        </x-ui.campo>

        <x-ui.campo etiqueta="Apellidos" for="apellidos" requerido class="col-md-3">
            <input type="text" name="apellidos" id="apellidos" class="form-control" maxlength="25"
                   autocomplete="off" value="{{ $vm->valor('apellidos') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Nombres" for="nombres" requerido class="col-md-3">
            <input type="text" name="nombres" id="nombres" class="form-control" maxlength="25"
                   autocomplete="off" value="{{ $vm->valor('nombres') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Sexo" for="sexo" class="col-md-2">
            <select name="sexo" id="sexo" class="form-select">
                <option value="">— Sin dato —</option>
                @foreach ($vm->sexos() as $opcion)
                    <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                @endforeach
            </select>
        </x-ui.campo>

        <x-ui.campo etiqueta="Fecha de nacimiento" for="fnacimiento" class="col-md-3">
            <input type="date" name="fnacimiento" id="fnacimiento" class="form-control" max="{{ \App\Clinica\FechaDelConsultorio::hoyComoTexto() }}" value="{{ $vm->fecha('fnacimiento') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Lugar de nacimiento" for="lnacimiento" class="col-md-4">
            <input type="text" name="lnacimiento" id="lnacimiento" class="form-control" maxlength="100"
                   value="{{ $vm->valor('lnacimiento') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Estado civil" for="codeestado" class="col-md-3">
            <select name="codeestado" id="codeestado" class="form-select">
                <option value="">— Sin dato —</option>
                @foreach ($vm->estadosCiviles() as $opcion)
                    <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                @endforeach
            </select>
        </x-ui.campo>
    </div>
</x-ui.tarjeta>

<x-ui.tarjeta titulo="Cómo se lo contacta" icono="bi-telephone">
    <div class="row g-3">
        <x-ui.campo etiqueta="Dirección" for="direccion" class="col-12">
            <input type="text" name="direccion" id="direccion" class="form-control" maxlength="200"
                   value="{{ $vm->valor('direccion') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Celular" for="telefono" class="col-md-6">
            <input type="text" name="telefono" id="telefono" class="form-control" maxlength="20"
                   autocapitalize="off" value="{{ $vm->valor('telefono') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="E-mail" for="email" class="col-md-6">
            <input type="email" name="email" id="email" class="form-control" maxlength="100"
                   autocapitalize="off" value="{{ $vm->valor('email') }}">
        </x-ui.campo>
    </div>
</x-ui.tarjeta>

<x-ui.tarjeta titulo="Datos del consultorio" icono="bi-briefcase">
    <div class="row g-3">
        <x-ui.campo etiqueta="Profesión" for="profesion" class="col-md-4">
            <input type="text" name="profesion" id="profesion" class="form-control" maxlength="50"
                   value="{{ $vm->valor('profesion') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Ocupación" for="ocupacion" class="col-md-4">
            <input type="text" name="ocupacion" id="ocupacion" class="form-control" maxlength="100"
                   value="{{ $vm->valor('ocupacion') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Escolaridad" for="escolaridad" class="col-md-4">
            <input type="text" name="escolaridad" id="escolaridad" class="form-control" maxlength="100"
                   value="{{ $vm->valor('escolaridad') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Seguro / empresa" for="codesegemp" class="col-md-4"
                    :ayuda="$vm->sinSeguros() ? 'Este consultorio todavía no tiene seguros cargados: queda en blanco.' : null">
            <select name="codesegemp" id="codesegemp" class="form-select">
                <option value="">— Sin seguro —</option>
                @foreach ($vm->seguros() as $opcion)
                    <option value="{{ $opcion['valor'] }}" {{ $vm->seguroElegido() === $opcion['valor'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                @endforeach
            </select>
        </x-ui.campo>

        <x-ui.campo etiqueta="Referido por" for="dependencia" class="col-md-4">
            <input type="text" name="dependencia" id="dependencia" class="form-control" maxlength="30"
                   value="{{ $vm->valor('dependencia') }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Fecha de ingreso" for="fingreso" class="col-md-4">
            <input type="date" name="fingreso" id="fingreso" class="form-control" value="{{ $vm->fecha('fingreso') }}">
        </x-ui.campo>
    </div>
</x-ui.tarjeta>
</div></div>
