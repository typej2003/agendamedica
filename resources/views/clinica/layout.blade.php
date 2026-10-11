<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('titulo', 'Consultorio') · Doctorísimo</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    {{-- La paleta vive en `gineco.css`; el sistema de la aplicación (Bootstrap recoloreado + armazón) en
         `doctorisimo-ui.css`. Ese orden importa: el sistema va después de Bootstrap. --}}
    <link rel="stylesheet" href="{{ asset('css/gineco.css') }}">
    <link rel="stylesheet" href="{{ asset('css/doctorisimo-ui.css') }}">
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
                    @if ($item['url'])
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

    <div class="app-sidebar-pie">
        <button class="app-sidebar-plegar" type="button" data-plegar-barra
                aria-label="Plegar el menú" title="Plegar el menú" aria-expanded="true" aria-controls="clinica-sidebar">
            <i class="bi bi-layout-sidebar-inset" aria-hidden="true"></i>
        </button>
    </div>
</nav>

<div class="app-columna">
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
                    @foreach ($armazon->fichas() as $ficha)
                        <span class="chip"><i class="bi {{ $ficha['icono'] }}" aria-hidden="true"></i> {{ $ficha['texto'] }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="d-flex align-items-center gap-2">
            @foreach ($armazon->accesos() as $acceso)
                <a href="{{ $acceso['url'] }}" class="btn btn-sm {{ $acceso['primario'] ? 'btn-outline-primary' : 'btn-outline-secondary' }}"
                   title="{{ $acceso['texto'] }}">
                    <i class="bi {{ $acceso['icono'] }}" aria-hidden="true"></i> <span class="d-none d-sm-inline">{{ $acceso['texto'] }}</span>
                </a>
            @endforeach

            {{-- La cuenta: cambiar la contraseña y **cerrar sesión**. Antes no había cómo salir desde el consultorio
                 (el cierre de sesión solo existía en el panel de administración). --}}
            @auth
                <x-ui.menu etiqueta="Mi cuenta" icono="bi-person-circle" :texto="\Illuminate\Support\Str::words(auth()->user()->name, 1, '')">
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
        {{-- Avisos que no son el resultado de la operación (el día no laborable que se eligió al agendar, el
             solape de un bloque de horario con otro consultorio del mismo médico): van en lista para que no
             se pisen con `estado` ni entre ellos. --}}
        @foreach ((array) session('avisos', []) as $aviso)
            <div class="alert alert-warning py-2 d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> {{ $aviso }}
            </div>
        @endforeach

        @yield('contenido')
    </main>
</div>

<script src="{{ asset('js/clinica/shell.js') }}"></script>

{{-- Los scripts de cada pantalla (buscadores, arrastre, diálogos) van acá y no dentro del contenido: así el
     HTML de la vista queda solo el HTML. --}}
@yield('scripts')

</body>
</html>
