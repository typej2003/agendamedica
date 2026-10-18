@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    {{-- Vacunas del catálogo del médico (WEB-7.4.4e): con ellas se arma el calendario de cada niño. Una sola acción primaria (Nueva vacuna); el
         buscador está en su barra de herramientas; los demás catálogos son submenús del menú lateral. --}}
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNueva() }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva vacuna
            </a>
        </x-slot>
    </x-ui.page-head>

    {{-- Las novedades: vacunas del catálogo inicial que el médico no tiene. Nunca se agregan solas: las acepta con el botón. --}}
    @if ($vm->hayNovedades())
        <x-ui.tarjeta id="vacunas-novedades" class="mb-3">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <i class="bi bi-gift fs-4" aria-hidden="true"></i>
                <p class="mb-0 flex-grow-1">{{ $vm->textoDeNovedades() }}</p>
                <form method="POST" action="{{ $vm->urlCargarInicial() }}">
                    @csrf
                    <button class="btn btn-outline-primary">
                        <i class="bi bi-download" aria-hidden="true"></i> Cargar el catálogo inicial
                    </button>
                </form>
            </div>
        </x-ui.tarjeta>
    @endif

    <x-ui.barra-herramientas>
        <x-ui.buscador :accion="$vm->urlBase()" :valor="$vm->buscar()" :limpiar="$vm->urlBase()"
                       placeholder="Nombre, código o cobertura" etiqueta="Buscar vacuna" />
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    @if ($vm->hayBusqueda())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlBase() }}">Ver todas las vacunas</a>
                    @else
                        <a class="btn btn-primary" href="{{ $vm->urlNueva() }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear la primera vacuna
                        </a>
                    @endif
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Vacuna</th>
                    <th>Edad inicial</th>
                    <th>Tipo</th>
                    <th>Dosis</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal">
                            <strong>{{ $fila['vacuna'] }}</strong>
                            <span class="texto-tenue small d-block">Código {{ $fila['codigo'] }}@if ($fila['cobertura']) · {{ $fila['cobertura'] }}@endif</span>
                            @if ($fila['sexo'])
                                <x-ui.estado tono="info" class="mt-1">{{ $fila['sexo'] }}</x-ui.estado>
                            @endif
                        </td>
                        <td data-etiqueta="Edad inicial">{{ $fila['edad'] }}</td>
                        <td data-etiqueta="Tipo"><x-ui.estado :tono="$fila['tipoTono']">{{ $fila['tipo'] }}</x-ui.estado></td>
                        <td data-etiqueta="Dosis">
                            @if ($fila['dosis'] === [])
                                <span class="texto-tenue small">Sin dosis: una sola, a la edad inicial.</span>
                            @else
                                {{-- Una dosis por renglón de chips que se parten: en el celular la fila no puede ensancharse. --}}
                                <div class="d-flex flex-wrap gap-1 justify-content-md-start justify-content-end">
                                    @foreach ($fila['dosis'] as $dosis)
                                        <span class="badge bg-light text-dark border text-wrap">{{ $dosis }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="celda-acciones text-end">
                            <div class="acciones-fila">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlDosis'] }}">
                                    <i class="bi bi-list-ol" aria-hidden="true"></i> Dosis
                                </a>
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                <x-ui.menu :etiqueta="'Más acciones de ' . $fila['vacuna']">
                                    <form method="POST" action="{{ $fila['urlQuitar'] }}" data-confirmar="{{ $fila['avisoQuitar'] }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="menu-item menu-item-peligro">
                                            <i class="bi bi-trash" aria-hidden="true"></i> Quitar del catálogo
                                        </button>
                                    </form>
                                </x-ui.menu>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.tabla>
        @endif
    </x-ui.tarjeta>

    {{-- El alta y la edición de la vacuna, en un diálogo lateral (lo abre `vacunas-catalogo.js` con `?nuevo=1`, con `?editar=` o si la validación falló). --}}
    <x-ui.dialogo id="dialogo-vacuna" :titulo="$vm->tituloDelFormulario()" lateral
                  data-abrir-al-cargar="{{ ($vm->abrirVacuna() || $errors->any()) ? '1' : '0' }}">
        <form method="POST" action="{{ $vm->urlGuardar() }}">
            @csrf
            <input type="hidden" name="id" value="{{ $vm->idEditando() }}">

            <div class="dialogo-cuerpo">
                @if ($vm->codigoEditando())
                    <p class="texto-tenue small mb-3">Código {{ $vm->codigoEditando() }}. El código no se cambia: lo usan las dosis ya aplicadas.</p>
                @else
                    <p class="texto-tenue small mb-3">El código se asigna solo: el que sigue en tu lista.</p>
                @endif

                <div class="row g-3">
                    <x-ui.campo etiqueta="Vacuna" for="vacuna-nombre" error="vacuna" class="col-12" :requerido="true"
                                :ayuda="'Se guarda en mayúsculas. Hasta ' . $vm->largoNombre() . ' caracteres.'">
                        <input type="text" name="vacuna" id="vacuna-nombre" class="form-control" data-foco-inicial maxlength="{{ $vm->largoNombre() }}"
                               autocomplete="off" value="{{ old('vacuna', $vm->valor('vacuna')) }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Edad inicial (meses)" for="vacuna-edad" error="edad_inicial_meses" class="col-md-6"
                                ayuda="A qué edad le toca la primera dosis. Si no defines dosis, es la única.">
                        <input type="number" min="0" name="edad_inicial_meses" id="vacuna-edad" class="form-control" inputmode="numeric"
                               value="{{ old('edad_inicial_meses', $vm->valor('edad_inicial_meses')) }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Tipo" for="vacuna-fija" error="fija" class="col-md-6">
                        <select name="fija" id="vacuna-fija" class="form-select">
                            @foreach (\App\Clinica\Vacunas\ConvencionDeVacunas::FIJA as $codigo => $texto)
                                <option value="{{ $codigo }}" {{ old('fija', $vm->fijaSeleccionada()) === $codigo ? 'selected' : '' }}>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Aplica a" for="vacuna-sexo" error="sexo" class="col-md-6">
                        <select name="sexo" id="vacuna-sexo" class="form-select">
                            @foreach (\App\Clinica\Vacunas\ConvencionDeVacunas::SEXO as $codigo => $texto)
                                <option value="{{ $codigo }}" {{ old('sexo', $vm->sexoSeleccionado()) === $codigo ? 'selected' : '' }}>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Cobertura" for="vacuna-cobertura" error="cobertura" class="col-12"
                                ayuda="Contra qué enfermedades protege.">
                        <input type="text" name="cobertura" id="vacuna-cobertura" class="form-control" maxlength="{{ $vm->largoCobertura() }}"
                               value="{{ old('cobertura', $vm->valor('cobertura')) }}">
                    </x-ui.campo>
                </div>
            </div>

            <div class="dialogo-pie">
                @if ($vm->editando())
                    <a class="btn btn-outline-secondary" href="{{ $vm->urlBase() }}">Cancelar</a>
                @else
                    <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
                @endif
                <button class="btn btn-primary">{{ $vm->botonGuardar() }}</button>
            </div>
        </form>
    </x-ui.dialogo>

    {{-- Las dosis de una vacuna (`?dosis_de=`): la lista, y el formulario para agregar o corregir una. El error de la dosis se dice acá adentro,
         porque el diálogo está abierto encima del aviso de la página. --}}
    @if ($vm->abrirDosis())
        <x-ui.dialogo id="dialogo-dosis" :titulo="'Dosis de ' . $vm->nombreConDosis()" lateral data-abrir-al-cargar="1">
            <div class="dialogo-cuerpo">
                @if (session('error'))
                    <x-ui.estado tono="peligro" icono="bi-exclamation-triangle" class="mb-3" role="alert">{{ session('error') }}</x-ui.estado>
                @endif

                <p class="texto-tenue small mb-3">
                    Cada dosis lleva su número, su rótulo (un <strong>refuerzo</strong> se escribe en el rótulo) y la edad en meses a la que le toca al niño.
                    Sin dosis, la vacuna cuenta como una sola, a su edad inicial.
                </p>

                @if ($vm->dosis() === [])
                    <p class="texto-tenue">Esta vacuna todavía no tiene dosis.</p>
                @else
                    <ul class="list-unstyled mb-3" id="lista-de-dosis">
                        @foreach ($vm->dosis() as $dosis)
                            <li class="d-flex flex-wrap align-items-center gap-2 py-1 border-bottom">
                                <span class="fw-semibold">{{ $dosis['numero'] }}.</span>
                                <span class="flex-grow-1">{{ $dosis['rotulo'] }} <span class="texto-tenue small">· {{ $dosis['meses'] }} meses</span></span>
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $dosis['urlEditar'] }}" aria-label="Editar la dosis {{ $dosis['numero'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                </a>
                                <form method="POST" action="{{ $dosis['urlQuitar'] }}" data-confirmar="¿Quitar la dosis {{ $dosis['numero'] }}?">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" aria-label="Quitar la dosis {{ $dosis['numero'] }}">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form method="POST" action="{{ $vm->urlGuardarDosis() }}" class="border rounded p-3">
                    @csrf
                    <input type="hidden" name="dosis_id" value="{{ $vm->idDosisEditando() }}">
                    <h3 class="h6 mb-3">{{ $vm->editandoDosis() ? 'Editar la dosis' : 'Agregar una dosis' }}</h3>
                    <div class="row g-3">
                        <x-ui.campo etiqueta="N.º" for="dosis-numero" error="dosis" class="col-4">
                            <input type="number" min="1" max="99" name="dosis" id="dosis-numero" class="form-control" data-foco-inicial
                                   value="{{ old('dosis', $vm->valorDeDosis('dosis')) }}">
                        </x-ui.campo>
                        <x-ui.campo etiqueta="Rótulo" for="dosis-rotulo" error="dosis_descri" class="col-8">
                            <input type="text" name="dosis_descri" id="dosis-rotulo" class="form-control" maxlength="50" placeholder="1era. Dosis, Refuerzo…"
                                   value="{{ old('dosis_descri', $vm->valorDeDosis('dosis_descri')) }}">
                        </x-ui.campo>
                        <x-ui.campo etiqueta="Edad (meses)" for="dosis-meses" error="meses" class="col-12">
                            <input type="text" inputmode="decimal" name="meses" id="dosis-meses" class="form-control"
                                   value="{{ old('meses', $vm->valorDeDosis('meses')) }}">
                        </x-ui.campo>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        @if ($vm->editandoDosis())
                            <a class="btn btn-outline-secondary" href="{{ route('clinica.catalogos.vacunas', ['dosis_de' => request('dosis_de')]) }}">Cancelar edición</a>
                        @endif
                        <button class="btn btn-primary">{{ $vm->editandoDosis() ? 'Guardar la dosis' : 'Agregar la dosis' }}</button>
                    </div>
                </form>
            </div>

            <div class="dialogo-pie">
                <a class="btn btn-outline-secondary" href="{{ $vm->urlCerrarDosis() }}">Cerrar</a>
            </div>
        </x-ui.dialogo>
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/vacunas-catalogo.js') }}"></script>
@endsection
