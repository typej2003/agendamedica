{{--
    El alta de un paciente nuevo desde «Nueva cita», en un **diálogo lateral** (2026-11-03): antes era un
    `<details>` en el paso 2 y, al desplegarlo, empujaba «Agendar la cita» fuera de la pantalla.

    El diálogo vive dentro del `<form>` de la cita, así que sus campos se envían con «Agendar la cita»; sus
    botones no envían nada (son `type="button"`): «Usar estos datos» cierra el diálogo y deja lo escrito.
    Si la validación falló en el servidor, `data-abrir-al-cargar` lo vuelve a abrir y el script elige la
    pestaña del primer error.

    En **pediatría** se piden todos los campos de PediaReport, en dos pestañas (el paciente y el
    representante/familia), con la fecha de nacimiento y el representante obligatorios. Fuera de pediatría
    se mantienen los campos de siempre y el representante queda en un desplegable **opcional**.

    Recibe `$vm` (App\Clinica\Agenda\Vistas\FormularioDeCitaVista).
--}}
@php
    $pediatria = $vm->esPediatria();
    $tab = fn (string $campo) => $errors->has($campo) ? ' is-invalid' : '';
@endphp

<x-ui.dialogo id="dialogo-paciente-nuevo" titulo="Cargar un paciente nuevo" lateral
              data-abrir-al-cargar="{{ $vm->altaAbierta() ? '1' : '0' }}">
    <div class="dialogo-cuerpo">
        <p class="texto-tenue small">
            Queda <strong>sin número de historia</strong>: lo asigna el sistema cuando se complete la historia.
            Si la cédula ya existe, se enlaza a esa ficha en vez de duplicarla.
        </p>

        @if ($pediatria)
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button type="button" class="nav-link active" data-paciente-tab="paciente" role="tab"
                            aria-selected="true" aria-controls="paciente-nuevo-panel">Paciente</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button type="button" class="nav-link" data-paciente-tab="representante" role="tab"
                            aria-selected="false" aria-controls="representante-nuevo-panel">Representante</button>
                </li>
            </ul>

            <div class="tab-content pt-3" data-paciente-paneles>
                {{-- Pestaña del paciente: los campos de PediaReport. --}}
                <div id="paciente-nuevo-panel" data-paciente-panel="paciente" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="nuevo_nombres">Nombre completo *</label>
                            <input type="text" name="nuevo_nombres" id="nuevo_nombres" class="form-control{{ $tab('nuevo_nombres') }}"
                                   maxlength="200" autocomplete="off" value="{{ $vm->valor('nuevo_nombres') }}">
                            @error('nuevo_nombres') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_cedula">Cédula</label>
                            <input type="text" name="nuevo_cedula" id="nuevo_cedula" class="form-control" inputmode="numeric"
                                   maxlength="20" value="{{ $vm->valor('nuevo_cedula') }}">
                            @error('nuevo_cedula') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_fnacimiento">Fecha de nacimiento *</label>
                            <input type="date" name="nuevo_fnacimiento" id="nuevo_fnacimiento" class="form-control{{ $tab('nuevo_fnacimiento') }}"
                                   max="{{ \App\Clinica\FechaDelConsultorio::hoyComoTexto() }}" value="{{ $vm->valor('nuevo_fnacimiento') }}">
                            @error('nuevo_fnacimiento') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_sexo">Sexo</label>
                            <select name="nuevo_sexo" id="nuevo_sexo" class="form-select">
                                <option value="">Sin dato</option>
                                <option value="F" {{ $vm->valor('nuevo_sexo', $vm->sexoPorDefectoDelNuevoPaciente()) === 'F' ? 'selected' : '' }}>Femenino</option>
                                <option value="M" {{ $vm->valor('nuevo_sexo', $vm->sexoPorDefectoDelNuevoPaciente()) === 'M' ? 'selected' : '' }}>Masculino</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_fingreso">Fecha de ingreso</label>
                            <input type="date" name="nuevo_fingreso" id="nuevo_fingreso" class="form-control"
                                   max="{{ \App\Clinica\FechaDelConsultorio::hoyComoTexto() }}" value="{{ $vm->fechaDeIngreso() }}">
                            @error('nuevo_fingreso') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_grupo_sanguineo">G.S.</label>
                            <select name="nuevo_grupo_sanguineo" id="nuevo_grupo_sanguineo" class="form-select">
                                <option value="">Sin dato</option>
                                @foreach ($vm->gruposSanguineos() as $grupo)
                                    <option value="{{ $grupo }}" {{ $vm->valor('nuevo_grupo_sanguineo') === $grupo ? 'selected' : '' }}>{{ $grupo }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_grupo_etario">Grupo etario</label>
                            <select name="nuevo_grupo_etario" id="nuevo_grupo_etario" class="form-select">
                                <option value="">Sin dato</option>
                                @foreach ($vm->gruposEtarios() as $grupo)
                                    <option value="{{ $grupo }}" {{ $vm->valor('nuevo_grupo_etario') === $grupo ? 'selected' : '' }}>{{ $grupo }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_lnacimiento">Lugar de nacimiento</label>
                            <input type="text" name="nuevo_lnacimiento" id="nuevo_lnacimiento" class="form-control"
                                   maxlength="100" value="{{ $vm->valor('nuevo_lnacimiento') }}">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_historia_anterior">Historia anterior</label>
                            <input type="text" name="nuevo_historia_anterior" id="nuevo_historia_anterior" class="form-control"
                                   maxlength="20" value="{{ $vm->valor('nuevo_historia_anterior') }}">
                            @error('nuevo_historia_anterior') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_tipo">Tipo</label>
                            <select name="nuevo_tipo" id="nuevo_tipo" class="form-select">
                                @foreach ($vm->tiposDeAsistencia() as $valor => $texto)
                                    <option value="{{ $valor }}" {{ $vm->valor('nuevo_tipo', 'C') === $valor ? 'selected' : '' }}>{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_telefono">Teléfono del niño</label>
                            <input type="text" name="nuevo_telefono" id="nuevo_telefono" class="form-control"
                                   maxlength="30" value="{{ $vm->valor('nuevo_telefono') }}">
                            @error('nuevo_telefono') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_email">Correo</label>
                            <input type="email" name="nuevo_email" id="nuevo_email" class="form-control"
                                   maxlength="120" value="{{ $vm->valor('nuevo_email') }}">
                            @error('nuevo_email') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="nuevo_nac">Nacionalidad</label>
                            <select name="nuevo_nac" id="nuevo_nac" class="form-select">
                                <option value="V" {{ $vm->valor('nuevo_nac', 'V') === 'V' ? 'selected' : '' }}>Venezolano</option>
                                <option value="E" {{ $vm->valor('nuevo_nac', 'V') === 'E' ? 'selected' : '' }}>Extranjero</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="nuevo_direccion">Dirección</label>
                            <input type="text" name="nuevo_direccion" id="nuevo_direccion" class="form-control"
                                   maxlength="200" value="{{ $vm->valor('nuevo_direccion') }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="nuevo_referido">Referido</label>
                            <textarea name="nuevo_referido" id="nuevo_referido" class="form-control" rows="2"
                                      maxlength="2000">{{ $vm->valor('nuevo_referido') }}</textarea>
                            @error('nuevo_referido') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="nuevo_foto">Foto</label>
                            <input type="file" name="nuevo_foto" id="nuevo_foto" class="form-control" accept="image/*">
                            @error('nuevo_foto') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>

                {{-- Pestaña del representante y la familia: los datos de PediaReport. --}}
                <div id="representante-nuevo-panel" data-paciente-panel="representante" role="tabpanel" hidden>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="representante_cedula">Cédula del representante</label>
                            <div class="input-group">
                                <input type="text" name="representante_cedula" id="representante_cedula" class="form-control"
                                       inputmode="numeric" maxlength="10" autocomplete="off" value="{{ $vm->valor('representante_cedula') }}">
                                <label class="input-group-text" for="representante_sin_cedula">
                                    <input class="form-check-input mt-0 me-2" type="checkbox" name="representante_sin_cedula"
                                           id="representante_sin_cedula" value="1" {{ $vm->valor('representante_sin_cedula') ? 'checked' : '' }}>
                                    S.C.
                                </label>
                            </div>
                            @error('representante_cedula') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="representante_parentesco">Parentesco</label>
                            <select name="representante_parentesco" id="representante_parentesco" class="form-select">
                                @foreach ($vm->parentescos() as $opcion)
                                    <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <span class="form-label d-block">¿Quién es?</span>
                            <div class="d-flex gap-3">
                                <label class="form-check">
                                    <input class="form-check-input" type="radio" name="representante_tipo" value="F"
                                           {{ $vm->valor('representante_tipo', 'F') === 'F' ? 'checked' : '' }}>
                                    <span class="form-check-label">Madre</span>
                                </label>
                                <label class="form-check">
                                    <input class="form-check-input" type="radio" name="representante_tipo" value="M"
                                           {{ $vm->valor('representante_tipo') === 'M' ? 'checked' : '' }}>
                                    <span class="form-check-label">Padre</span>
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="representante_nombre">Nombre y apellido *</label>
                            <input type="text" name="representante_nombre" id="representante_nombre" class="form-control{{ $tab('representante_nombre') }}"
                                   maxlength="40" autocomplete="off" value="{{ $vm->valor('representante_nombre') }}">
                            @error('representante_nombre') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="representante_celular">Celular</label>
                            <input type="text" name="representante_celular" id="representante_celular" class="form-control"
                                   maxlength="12" inputmode="tel" value="{{ $vm->valor('representante_celular') }}">
                            @error('representante_celular') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="representante_telefono">Teléfono del representante *</label>
                            <input type="text" name="representante_telefono" id="representante_telefono" class="form-control{{ $tab('representante_telefono') }}"
                                   maxlength="20" autocomplete="off" value="{{ $vm->valor('representante_telefono') }}">
                            @error('representante_telefono') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="representante_grupo_sanguineo">G.S.</label>
                            <select name="representante_grupo_sanguineo" id="representante_grupo_sanguineo" class="form-select">
                                <option value="">Sin dato</option>
                                @foreach ($vm->gruposSanguineos() as $grupo)
                                    <option value="{{ $grupo }}" {{ $vm->valor('representante_grupo_sanguineo') === $grupo ? 'selected' : '' }}>{{ $grupo }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="representante_ocupacion">Ocupación</label>
                            <input type="text" name="representante_ocupacion" id="representante_ocupacion" class="form-control"
                                   maxlength="60" value="{{ $vm->valor('representante_ocupacion') }}">
                            @error('representante_ocupacion') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="representante_empresa">Empresa</label>
                            <select name="representante_empresa" id="representante_empresa" class="form-select">
                                @foreach ($vm->empresas() as $opcion)
                                    <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                                @endforeach
                            </select>
                            @error('representante_empresa') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="representante_situacion_pareja">Situación de pareja</label>
                            <select name="representante_situacion_pareja" id="representante_situacion_pareja" class="form-select">
                                <option value="">Sin dato</option>
                                @foreach ($vm->situacionesDePareja() as $valor => $texto)
                                    <option value="{{ $valor }}" {{ $vm->valor('representante_situacion_pareja') === $valor ? 'selected' : '' }}>{{ $texto }}</option>
                                @endforeach
                            </select>
                            @error('representante_situacion_pareja') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="representante_email">E-mail</label>
                            <input type="email" name="representante_email" id="representante_email" class="form-control"
                                   maxlength="100" value="{{ $vm->valor('representante_email') }}">
                            @error('representante_email') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="representante_direccion">Dirección</label>
                            <input type="text" name="representante_direccion" id="representante_direccion" class="form-control"
                                   maxlength="200" value="{{ $vm->valor('representante_direccion') }}">
                            @error('representante_direccion') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>
            </div>
        @else
            {{-- Fuera de pediatría: los campos de siempre. --}}
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label" for="nuevo_cedula">Cédula</label>
                    <input type="text" name="nuevo_cedula" id="nuevo_cedula" class="form-control" value="{{ $vm->valor('nuevo_cedula') }}">
                    @error('nuevo_cedula') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="nuevo_nombres">Nombres *</label>
                    <input type="text" name="nuevo_nombres" id="nuevo_nombres" class="form-control" maxlength="100" value="{{ $vm->valor('nuevo_nombres') }}">
                    @error('nuevo_nombres') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="nuevo_apellidos">Apellidos *</label>
                    <input type="text" name="nuevo_apellidos" id="nuevo_apellidos" class="form-control" maxlength="100" value="{{ $vm->valor('nuevo_apellidos') }}">
                    @error('nuevo_apellidos') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="nuevo_telefono">Teléfono *</label>
                    <input type="text" name="nuevo_telefono" id="nuevo_telefono" class="form-control" value="{{ $vm->valor('nuevo_telefono') }}">
                    @error('nuevo_telefono') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="nuevo_email">Correo</label>
                    <input type="email" name="nuevo_email" id="nuevo_email" class="form-control" value="{{ $vm->valor('nuevo_email') }}">
                    @error('nuevo_email') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="nuevo_sexo">Sexo</label>
                    <select name="nuevo_sexo" id="nuevo_sexo" class="form-select">
                        <option value="">Sin dato</option>
                        <option value="F" {{ $vm->valor('nuevo_sexo', $vm->sexoPorDefectoDelNuevoPaciente()) === 'F' ? 'selected' : '' }}>Femenino</option>
                        <option value="M" {{ $vm->valor('nuevo_sexo', $vm->sexoPorDefectoDelNuevoPaciente()) === 'M' ? 'selected' : '' }}>Masculino</option>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="nuevo_fnacimiento">Fecha de nacimiento</label>
                    <input type="date" name="nuevo_fnacimiento" id="nuevo_fnacimiento" class="form-control" max="{{ \App\Clinica\FechaDelConsultorio::hoyComoTexto() }}" value="{{ $vm->valor('nuevo_fnacimiento') }}">
                    @error('nuevo_fnacimiento') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="nuevo_nac">Nacionalidad</label>
                    <select name="nuevo_nac" id="nuevo_nac" class="form-select">
                        <option value="V" {{ $vm->valor('nuevo_nac', 'V') === 'V' ? 'selected' : '' }}>Venezolano</option>
                        <option value="E" {{ $vm->valor('nuevo_nac', 'V') === 'E' ? 'selected' : '' }}>Extranjero</option>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="nuevo_lnacimiento">Lugar de nacimiento</label>
                    <input type="text" name="nuevo_lnacimiento" id="nuevo_lnacimiento" class="form-control" maxlength="100" value="{{ $vm->valor('nuevo_lnacimiento') }}">
                </div>
                <div class="col-12">
                    <label class="form-label" for="nuevo_direccion">Dirección</label>
                    <input type="text" name="nuevo_direccion" id="nuevo_direccion" class="form-control" maxlength="200" value="{{ $vm->valor('nuevo_direccion') }}">
                </div>
            </div>

            {{-- Representante **opcional** fuera de pediatría: un desplegable, no una pestaña obligatoria. --}}
            <details class="mt-3" {{ $vm->valor('representante_nombre') || $vm->valor('representante_cedula') ? 'open' : '' }}>
                <summary class="small">Representante (opcional)</summary>
                <div class="row g-3 mt-0">
                    <div class="col-sm-6">
                        <label class="form-label" for="representante_cedula">Cédula del representante</label>
                        <input type="text" name="representante_cedula" id="representante_cedula" class="form-control"
                               inputmode="numeric" maxlength="10" autocomplete="off" value="{{ $vm->valor('representante_cedula') }}">
                        @error('representante_cedula') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="representante_nombre">Nombre y apellido</label>
                        <input type="text" name="representante_nombre" id="representante_nombre" class="form-control"
                               maxlength="40" autocomplete="off" value="{{ $vm->valor('representante_nombre') }}">
                        @error('representante_nombre') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="representante_telefono">Teléfono</label>
                        <input type="text" name="representante_telefono" id="representante_telefono" class="form-control"
                               maxlength="20" autocomplete="off" value="{{ $vm->valor('representante_telefono') }}">
                        @error('representante_telefono') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="representante_parentesco">Parentesco</label>
                        <select name="representante_parentesco" id="representante_parentesco" class="form-select">
                            @foreach ($vm->parentescos() as $opcion)
                                <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </details>
        @endif
    </div>

    <div class="dialogo-pie">
        <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
        <button type="button" class="btn btn-primary" data-cerrar-dialogo data-paciente-nuevo-listo>Usar estos datos</button>
    </div>
</x-ui.dialogo>
