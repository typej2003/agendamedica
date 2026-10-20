<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- El latido de la sesión (2026-10-19): el JS lo llama cada pocos minutos mientras la pantalla está a la
         vista, para que la sesión no se venza con la consulta abierta. Sin él, «Terminar la consulta» contestaba
         419 («tu sesión venció»). --}}
    <meta name="latido-sesion" content="{{ route('clinica.sesion') }}">
    <title>@yield('titulo', 'Consultorio') · Doctorísimo</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    {{-- La paleta vive en `gineco.css`; el sistema de la aplicación (Bootstrap recoloreado + armazón) en
         `doctorisimo-ui.css`. Ese orden importa: el sistema va después de Bootstrap. --}}
    <link rel="stylesheet" href="{{ asset('css/gineco.css') }}">
    <link rel="stylesheet" href="{{ asset('css/doctorisimo-ui.css') }}">
    @yield('estilos')
</head>
<body>

{{-- Para quien navega con teclado o lector de pantalla: salta el encabezado y el menú (una docena de enlaces
     antes del contenido en cada pantalla). Invisible hasta que recibe el foco con Tab. --}}
<a class="saltar-al-contenido" href="#contenido-principal">Saltar al contenido</a>

{{-- `$armazon` (ArmazonVista) lo arma `ArmazonClinicaComposer`: las fichas del contexto, los accesos y el
     menú salen de ahí, no de la vista. --}}
<div class="app-sidebar-fondo" id="clinica-sidebar-fondo" hidden></div>

<nav class="app-sidebar" id="clinica-sidebar" aria-label="Menú del consultorio">
    <a class="app-sidebar-marca" href="{{ route('clinica.inicio') }}">
        <span class="app-marca-ficha" aria-hidden="true"><i class="bi bi-heart-pulse-fill"></i></span>
        <span class="app-marca-nombre"><strong>Doctorísimo</strong><small>Consultorio</small></span>
    </a>

    <div class="app-sidebar-interior">
        <ul class="nav flex-column">
            @foreach ($armazon->menu() as $item)
                <li class="nav-item">
                    @if (! empty($item['hijos']))
                        {{-- Grupo plegable (Catálogos, Listados, Configuración y —dentro de una consulta— Consulta): `<details>` nativo,
                             sin JS. Nace abierto cuando se está dentro de uno de sus hijos; el hijo activo lleva `aria-current`. Con la
                             barra plegada a 72 px los hijos quedan como iconos con su `title`, como cualquier ítem. `data-menu` es el
                             asa estable del grupo (el nombre en slug) para los verificadores de Chrome, y `data-tour` el asa
                             del tour guiado (A-15): el paso del catálogo apunta al encabezado del grupo
                             (`[data-tour=menu-consulta]`), que es lo que se ve. --}}
                        <details class="nav-grupo" data-menu="{{ \Illuminate\Support\Str::slug($item['texto']) }}" {{ $item['activo'] ? 'open' : '' }}>
                            <summary class="nav-link {{ $item['activo'] ? 'activo-padre' : '' }}" title="{{ $item['texto'] }}"
                                     data-tour="menu-{{ \Illuminate\Support\Str::slug($item['texto']) }}">
                                <i class="bi {{ $item['icono'] }}" aria-hidden="true"></i>
                                <span class="link-text">{{ $item['texto'] }}</span>
                                <i class="bi bi-chevron-down nav-grupo-flecha link-text" aria-hidden="true"></i>
                            </summary>
                            <ul class="nav flex-column nav-submenu">
                                @foreach ($item['hijos'] as $hijo)
                                    <li class="nav-item">
                                        @if (empty($hijo['url']))
                                            {{-- Un ítem del grupo que todavía no tiene pantalla (la pre-factura de la consulta): se
                                                 muestra, pero no navega. Es el mismo tratamiento que un módulo declarado sin
                                                 pantalla, para que la lista no se rompa cuando la tarea lo habilite. --}}
                                            <span class="nav-link disabled" aria-disabled="true" title="{{ $hijo['texto'] }}">
                                                <i class="bi {{ $hijo['icono'] }}" aria-hidden="true"></i>
                                                <span class="link-text">{{ $hijo['texto'] }}</span>
                                            </span>
                                        @else
                                            <a class="nav-link {{ $hijo['activo'] ? 'active' : '' }}" href="{{ $hijo['url'] }}"
                                               data-tour="menu-{{ \Illuminate\Support\Str::slug($hijo['texto']) }}"
                                               @if ($hijo['activo']) aria-current="page" @endif title="{{ $hijo['texto'] }}">
                                                <i class="bi {{ $hijo['icono'] }}" aria-hidden="true"></i>
                                                <span class="link-text">{{ $hijo['texto'] }}</span>
                                            </a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @elseif ($item['url'])
                        <a class="nav-link {{ $item['activo'] ? 'active' : '' }}" href="{{ $item['url'] }}"
                           @if ($item['activo']) aria-current="page" @endif title="{{ $item['texto'] }}">
                            <i class="bi {{ $item['icono'] }}" aria-hidden="true"></i>
                            <span class="link-text">{{ $item['texto'] }}</span>
                        </a>
                    @else
                        <span class="nav-link disabled" title="Módulo declarado, todavía sin pantalla">
                            <i class="bi {{ $item['icono'] }}" aria-hidden="true"></i>
                            <span class="link-text">{{ $item['texto'] }}</span>
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    {{-- El logo de la especialidad del consultorio —el mismo del escritorio de esa especialidad—, encima del
         pie con el botón de plegar. Una especialidad que todavía no tiene logo no dibuja nada. --}}
    @if ($logo = $armazon->logotipo())
        <div class="app-sidebar-logo">
            <img src="{{ $logo['url'] }}" alt="{{ $logo['texto'] }}">
        </div>
    @endif

    <div class="app-sidebar-pie">
        <button class="app-sidebar-plegar" type="button" data-plegar-barra
                aria-label="Plegar el menú" title="Plegar el menú" aria-expanded="true" aria-controls="clinica-sidebar">
            <i class="bi bi-layout-sidebar-inset" aria-hidden="true"></i>
        </button>
    </div>
</nav>

<div class="app-columna">
    @if ($armazon->muestraLaBarraDeContexto())
        <header class="app-topbar d-flex align-items-center justify-content-between gap-2 px-3 py-2">
            <div class="d-flex align-items-center gap-2 min-w-0">
                <button class="btn btn-sm btn-light d-lg-none" type="button" id="clinica-menu-boton"
                        aria-label="Abrir menú" aria-controls="clinica-sidebar" aria-expanded="false">
                    <i class="bi bi-list fs-5" aria-hidden="true"></i>
                </button>

                {{-- La marca vive en la barra lateral; en el celular, donde ésta se esconde, vuelve acá. --}}
                <div class="app-marca d-lg-none">
                    <i class="bi bi-heart-pulse-fill app-marca-icono" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline">Doctorísimo</span>
                </div>

                @if ($armazon->tieneContexto())
                    <div class="d-none d-md-flex flex-wrap gap-1">
                        @foreach ($armazon->fichasDeLaBarra() as $ficha)
                            <span class="chip chip-barra" title="{{ $ficha['titulo'] ?? $ficha['texto'] }}"><i class="bi {{ $ficha['icono'] }}" aria-hidden="true"></i> {{ $ficha['texto'] }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="d-flex align-items-center gap-2">
                {{-- El panel de administración es la única puerta que se queda en la barra, y sólo la ve el administrador. --}}
                @foreach ($armazon->accesos() as $acceso)
                    @if ($acceso['primario'])
                        <a href="{{ $acceso['url'] }}" class="btn btn-sm btn-outline-primary" title="{{ $acceso['texto'] }}">
                            <i class="bi {{ $acceso['icono'] }}" aria-hidden="true"></i> <span class="d-none d-sm-inline">{{ $acceso['texto'] }}</span>
                        </a>
                    @endif
                @endforeach

                {{-- La cuenta: el nombre completo del médico en el botón; dentro, su registro, sedes, consultorios, cambiar de
                     consultorio, la contraseña y **cerrar sesión**. Antes no había cómo salir desde el consultorio. --}}
                @auth
                    @php $cuenta = $armazon->cuenta(); @endphp
                    <x-ui.menu etiqueta="Mi cuenta" icono="bi-person-circle" :texto="$cuenta['nombre']" class="menu-cuenta">
                        <div class="menu-cuenta-cabecera">
                            <strong>{{ $cuenta['nombre'] }}</strong>
                            @if ($cuenta['registro'])
                                <span class="texto-tenue small"><i class="bi bi-hash" aria-hidden="true"></i> {{ $cuenta['registro'] }}</span>
                            @endif
                        </div>
                        @if (count(auth()->user()->panelesDisponibles()) > 1)
                            <a class="menu-item" href="{{ route('elegir-panel') }}">
                                <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Cambiar de área
                            </a>
                        @endif
                        @foreach ($armazon->accesosDeLaCuenta() as $acceso)
                            <a class="menu-item" href="{{ $acceso['url'] }}">
                                <i class="bi {{ $acceso['icono'] }}" aria-hidden="true"></i> {{ $acceso['texto'] }}
                            </a>
                        @endforeach
                        <a class="menu-item" href="{{ route('cuenta.password') }}">
                            <i class="bi bi-key" aria-hidden="true"></i> Cambiar contraseña
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="menu-item menu-item-peligro">
                                <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Cerrar sesión
                            </button>
                        </form>
                    </x-ui.menu>
                @endauth
            </div>
        </header>
    @else
        {{-- Dentro de la consulta (A-11) no hay barra de contexto: ni fichas, ni «Cambiar consultorio», ni cuenta. En el
             celular queda sólo la barra que abre el menú lateral —sin ella no habría forma de navegar— y en el
             escritorio no se pinta nada, así que la pantalla empieza en la fila de la consulta (`d-lg-none`). --}}
        <header class="app-topbar app-topbar-solo-menu d-lg-none d-flex align-items-center gap-2 px-3 py-2">
            <button class="btn btn-sm btn-light" type="button" id="clinica-menu-boton"
                    aria-label="Abrir menú" aria-controls="clinica-sidebar" aria-expanded="false">
                <i class="bi bi-list fs-5" aria-hidden="true"></i>
            </button>
            <div class="app-marca">
                <i class="bi bi-heart-pulse-fill app-marca-icono" aria-hidden="true"></i>
                <span class="d-none d-sm-inline">Doctorísimo</span>
            </div>
        </header>
    @endif

    <main class="app-contenido p-3 p-lg-4" id="contenido-principal" tabindex="-1">
        @if (session('estado'))
            <div class="alert alert-success py-2 d-flex align-items-center gap-2" role="status">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i> {{ session('estado') }}
            </div>
        @endif
        @if (session('error'))
            <div class="alert alert-warning py-2 d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ session('error') }}
            </div>
        @endif
        {{-- La guía de puesta en marcha: el siguiente dato esencial que le falta al médico, de uno en uno
             (`PasosEsenciales`). Tras guardar una sede, un consultorio o un bloque de horario (`guiado`), además se
             le pregunta en un diálogo si quiere seguir ahora. --}}
        @if (! empty($pasoEsencial))
            <div class="alert alert-info py-2 d-flex align-items-center gap-2" role="status" data-paso-esencial="{{ $pasoEsencial->clave }}">
                <i class="bi bi-signpost-split-fill" aria-hidden="true"></i>
                <span>{{ $pasoEsencial->banner }} <a class="alert-link" href="{{ $pasoEsencial->url() }}">{{ $pasoEsencial->enlace }} aquí</a>.</span>
            </div>
        @endif

        {{-- Avisos que no son el resultado de la operación (el día no laborable que se eligió al agendar, el
             solape de un bloque de horario con otro consultorio del mismo médico): van en lista para que no
             se pisen con `estado` ni entre ellos. --}}
        @foreach ((array) session('avisos', []) as $aviso)
            <div class="alert alert-warning py-2 d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> {{ $aviso }}
            </div>
        @endforeach

        {{-- El tour guiado de la pantalla (A-15), si la ruta tiene uno y el usuario todavía no lo vio: el botón
             de reabrir y los pasos. Lo resuelve `TourDeLaPantallaComposer`; acá sólo se incluye. --}}
        @if (! empty($tour))
            @include('clinica._tour')
        @endif

        @yield('contenido')
    </main>
</div>

@if (! empty($pasoEsencial) && session('guiado'))
    <x-ui.dialogo id="dialogo-paso-esencial" titulo="Sigamos con tu configuración" data-abrir-al-cargar>
        <div class="dialogo-cuerpo"><p class="mb-0">{{ $pasoEsencial->pregunta }}</p></div>
        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Más tarde</button>
            <a class="btn btn-primary" href="{{ $pasoEsencial->url() }}">Sí, ahora</a>
        </div>
    </x-ui.dialogo>
@endif

<script src="{{ asset('js/clinica/shell.js') }}"></script>
<script src="{{ asset('js/clinica/chips.js') }}"></script>
{{-- El latido de la sesión (2026-10-19): en **todas** las pantallas del consultorio, no sólo en la consulta.
     Un GET vacío cada pocos minutos con la pestaña a la vista; la URL sale del `meta` de arriba. --}}
<script src="{{ asset('js/clinica/latido-sesion.js') }}"></script>
@if (! empty($tour))
    {{-- El tour guiado (A-15) sólo se carga en las cinco pantallas que tienen uno. --}}
    <script src="{{ asset('js/clinica/tour.js') }}"></script>
@endif

{{-- Los scripts de cada pantalla (buscadores, arrastre, diálogos) van acá y no dentro del contenido: así el
     HTML de la vista queda solo el HTML. --}}
@yield('scripts')

</body>
</html>
