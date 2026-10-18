@extends('clinica.layout')

@section('titulo', 'Usuarios')

@section('contenido')
    {{-- Usuarios del consultorio (WEB-8.5): la secretaria del médico. Una sola acción primaria (Nueva secretaria). La clave
         temporal de una cuenta recién creada o restablecida se pinta una vez, desde la sesión flash, y se pierde al recargar. --}}
    <x-ui.page-head titulo="Usuarios" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <button type="button" class="btn btn-primary" data-dialogo="dialogo-usuario">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva secretaria
            </button>
        </x-slot>
    </x-ui.page-head>

    <x-ui.errores />

    @if ($vm->claveNueva())
        @php $clave = $vm->claveNueva(); @endphp
        <x-ui.tarjeta :titulo="$vm->tituloDeLaClave()" icono="bi-key" class="mb-3 clave-temporal-tarjeta">
            <p class="mb-2">
                Entrégale estos datos a <strong>{{ $clave['nombre'] }}</strong>. Entra con su correo y esta clave, y el sistema le pide
                cambiarla en el primer ingreso.
            </p>
            <dl class="datos-rejilla mb-2">
                <x-ui.dato etiqueta="Correo" :valor="$clave['correo']" />
                <div class="dato">
                    <dt class="etiqueta">Clave temporal</dt>
                    <dd>
                        <code class="clave-temporal" id="clave-temporal">{{ $clave['clave'] }}</code>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" data-copiar="clave-temporal">
                            <i class="bi bi-clipboard" aria-hidden="true"></i> Copiar
                        </button>
                    </dd>
                </div>
            </dl>
            <p class="texto-tenue small mb-0">
                <i class="bi bi-eye-slash" aria-hidden="true"></i>
                Se muestra una sola vez: si recargas la página ya no se vuelve a ver (podrás restablecerla).
            </p>
        </x-ui.tarjeta>
    @endif

    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    <button type="button" class="btn btn-primary" data-dialogo="dialogo-usuario">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear la cuenta de mi secretaria
                    </button>
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Secretaria</th>
                    <th>Estado</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal">
                            <x-ui.identidad :nombre="$fila['nombre']" :detalle="$fila['correo']" />
                        </td>
                        <td data-etiqueta="Estado">
                            <x-ui.estado :tono="$fila['estado']['tono']" :icono="$fila['estado']['icono']">{{ $fila['estado']['texto'] }}</x-ui.estado>
                            @if ($fila['motivo'])
                                <span class="d-block texto-tenue small mt-1">Motivo: {{ $fila['motivo'] }}</span>
                            @endif
                            @if ($fila['compartida'])
                                <x-ui.estado tono="info" icono="bi-people" class="mt-1">También trabaja con otro consultorio</x-ui.estado>
                            @endif
                        </td>
                        <td class="celda-acciones text-end">
                            {{-- Pauta de Alexander: hasta dos acciones van como botones directos y **desde tres en el menú
                                 «…»**; un menú de una sola opción está prohibido. La cuenta compartida con otro consultorio
                                 sólo se puede quitar (menos acciones por regla de `CuentaService`): esa fila lleva el botón
                                 directo. En el menú, lo de todos los días primero y **quitar el acceso al final, en rojo**. --}}
                            @if ($fila['puedeAdministrar'])
                                <x-ui.acciones-fila etiqueta="Acciones de {{ $fila['nombre'] }}">
                                    <x-ui.menu :etiqueta="'Más acciones de ' . $fila['nombre']">
                                        <form method="POST" action="{{ $fila['urlRestablecer'] }}" data-confirmar="{{ $fila['avisoRestablecer'] }}">
                                            @csrf
                                            <button class="menu-item">
                                                <i class="bi bi-key" aria-hidden="true"></i> Restablecer clave
                                            </button>
                                        </form>
                                        @if ($fila['activa'])
                                            <button type="button" class="menu-item"
                                                    data-bloquear data-nombre="{{ $fila['nombre'] }}" data-accion="{{ $fila['urlBloquear'] }}">
                                                <i class="bi bi-slash-circle" aria-hidden="true"></i> Bloquear
                                            </button>
                                        @else
                                            <form method="POST" action="{{ $fila['urlDesbloquear'] }}">
                                                @csrf
                                                <button class="menu-item">
                                                    <i class="bi bi-unlock" aria-hidden="true"></i> Desbloquear
                                                </button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ $fila['urlQuitar'] }}" data-confirmar="{{ $fila['avisoQuitar'] }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="menu-item menu-item-peligro">
                                                <i class="bi bi-person-x" aria-hidden="true"></i> Quitar acceso
                                            </button>
                                        </form>
                                    </x-ui.menu>
                                </x-ui.acciones-fila>
                            @else
                                <x-ui.acciones-fila etiqueta="Acciones de {{ $fila['nombre'] }}">
                                    <form method="POST" action="{{ $fila['urlQuitar'] }}" data-confirmar="{{ $fila['avisoQuitar'] }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="Quitar acceso"
                                                aria-label="Quitarle el acceso a {{ $fila['nombre'] }}">
                                            <i class="bi bi-person-x" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </x-ui.acciones-fila>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.tabla>
        @endif
    </x-ui.tarjeta>

    <p class="texto-tenue small mt-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        La secretaria agenda, confirma, cobra y reordena la cola. No entra a las consultas, a los catálogos ni a la configuración.
        Quitarle el acceso no borra nada: su cuenta queda sin acceso.
    </p>

    <x-ui.dialogo id="dialogo-usuario" titulo="Nueva secretaria" lateral
                  data-abrir-al-cargar="{{ ($errors->has('name') || $errors->has('email')) ? '1' : '0' }}">
        <form method="POST" action="{{ $vm->urlCrear() }}">
            @csrf
            <div class="dialogo-cuerpo">
                <div class="row g-3">
                    <x-ui.campo etiqueta="Nombre completo" for="usuario-nombre" error="name" class="col-12" :requerido="true">
                        <input type="text" name="name" id="usuario-nombre" class="form-control" data-foco-inicial
                               maxlength="100" autocomplete="off" value="{{ old('name') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Correo" for="usuario-correo" error="email" class="col-12" :requerido="true"
                                ayuda="Con este correo entra al sistema. Debe ser uno que no tenga cuenta todavía.">
                        <input type="email" name="email" id="usuario-correo" class="form-control"
                               maxlength="150" autocomplete="off" value="{{ old('email') }}">
                    </x-ui.campo>

                    <p class="texto-tenue small mb-0">
                        Al crearla se genera una <strong>clave temporal</strong> que verás una sola vez; la secretaria tiene que cambiarla al entrar.
                    </p>
                </div>
            </div>

            <div class="dialogo-pie">
                <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
                <button class="btn btn-primary">Crear la cuenta</button>
            </div>
        </form>
    </x-ui.dialogo>

    <x-ui.dialogo id="dialogo-bloquear" titulo="Bloquear el acceso" lateral>
        <form method="POST" action="" id="bloquear-form">
            @csrf
            <div class="dialogo-cuerpo">
                <p>
                    <strong id="bloquear-nombre"></strong> no podrá entrar al sistema hasta que la desbloquees. No se borra nada.
                </p>
                <x-ui.campo etiqueta="Motivo (opcional)" for="bloquear-motivo" error="motivo"
                            ayuda="Lo ves tú en la lista; ella lo ve al intentar entrar.">
                    <input type="text" name="motivo" id="bloquear-motivo" class="form-control" maxlength="200" autocomplete="off">
                </x-ui.campo>
            </div>
            <div class="dialogo-pie">
                <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
                <button class="btn btn-primary">Bloquear</button>
            </div>
        </form>
    </x-ui.dialogo>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/usuarios.js') }}"></script>
@endsection
