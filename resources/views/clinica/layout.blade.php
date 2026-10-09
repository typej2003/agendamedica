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

    <style>
        /* Agenda (F2): la fila que se arrastra y el diálogo de cobro. */
        .lista-cola tr[draggable="true"] { cursor: grab; }
        .lista-cola tr.arrastrando { opacity: .5; }
        .cobro-fondo { position: fixed; inset: 0; background: rgba(32, 16, 72, .45); display: flex; align-items: center; justify-content: center; z-index: 1050; }
        .cobro-caja { background: var(--ds-superficie); border-radius: var(--ds-radio-lg); padding: 1.25rem; width: min(24rem, 92vw); box-shadow: var(--ds-sombra-3); }
        .calendario-mes td { width: 14.28%; }

        @media (min-width: 992px) {
            /* El menú acompaña el scroll (`.app-sidebar-interior`) mientras el fondo blanco cubre todo
               el alto; la barra superior no es fija, así que el menú se pega al borde de la ventana. */
            .clinica-cuerpo { display: flex; min-height: calc(100vh - 62px); --app-topbar-alto: 0px; }
            .app-contenido { flex: 1; min-width: 0; }
        }
    </style>
</head>
<body>

<header class="app-topbar d-flex align-items-center justify-content-between px-3 py-2">
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-light d-lg-none" type="button" id="clinica-menu-boton"
                aria-label="Abrir menú" aria-controls="clinica-sidebar" aria-expanded="false">
            <i class="bi bi-list fs-5"></i>
        </button>

        <div>
            <div class="app-marca">
                <i class="bi bi-heart-pulse-fill app-marca-icono"></i>
                Doctorísimo <span class="text-muted fw-normal">· Consultorio</span>
            </div>
            @isset($contexto)
                <div class="d-none d-md-flex flex-wrap gap-1 mt-1">
                    <span class="chip"><i class="bi bi-person-badge"></i>
                        {{ trim(($contexto['medico']->prefix ?? '') . ' ' . $contexto['medico']->name . ' ' . $contexto['medico']->lastname) }}
                    </span>
                    <span class="chip"><i class="bi bi-clipboard2-pulse"></i>
                        {{ $contexto['specialty']->name ?? 'Sin especialidad asignada' }}
                    </span>
                    <span class="chip"><i class="bi bi-geo-alt"></i>
                        {{ $contexto['office']->office_number ?? ($contexto['office']->medicalCenter->name ?? 'Sin sede') }}
                    </span>
                    <span class="chip"><i class="bi bi-hash"></i>{{ $contexto['reg_medico'] }}</span>
                </div>
            @endisset
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">
        <a href="{{ route('clinica.contexto') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-repeat"></i> <span class="d-none d-sm-inline">Cambiar consultorio</span>
        </a>
        {{-- El panel es de administración: a un médico no se le ofrece una puerta que ya no le corresponde. --}}
        @if (auth()->user()->esAdministrador())
            <a href="{{ route('dashboard') }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-speedometer2"></i> <span class="d-none d-sm-inline">Panel</span>
            </a>
        @endif
    </div>
</header>

<div class="clinica-cuerpo">
    <div class="app-sidebar-fondo" id="clinica-sidebar-fondo" hidden></div>

    <nav class="app-sidebar" id="clinica-sidebar" aria-label="Menú del consultorio">
      <div class="app-sidebar-interior py-2 px-2">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('clinica.inicio') ? 'active' : '' }}"
                   href="{{ route('clinica.inicio') }}">
                    <i class="bi bi-house-door"></i> Inicio
                </a>
            </li>

            {{-- El menú sale de los módulos implementados de la especialidad: nada escrito a mano. --}}
            @foreach (($modulos ?? collect()) as $modulo)
                @php
                    $rutaModulo = 'clinica.' . $modulo->slug;
                    $iconoModulo = \App\Support\IconoModulo::para($modulo->slug);
                @endphp
                <li class="nav-item">
                    @if (\Illuminate\Support\Facades\Route::has($rutaModulo))
                        <a class="nav-link {{ request()->routeIs($rutaModulo . '*') ? 'active' : '' }}"
                           href="{{ route($rutaModulo) }}">
                            <i class="bi {{ $iconoModulo }}"></i> {{ $modulo->nombre }}
                        </a>
                    @else
                        <span class="nav-link disabled" title="Módulo declarado, todavía sin pantalla">
                            <i class="bi {{ $iconoModulo }}"></i> {{ $modulo->nombre }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
      </div>
    </nav>

    <main class="app-contenido p-3 p-lg-4">
        @if (session('estado'))
            <div class="alert alert-success py-2 d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill"></i> {{ session('estado') }}
            </div>
        @endif
        @if (session('error'))
            <div class="alert alert-warning py-2 d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill"></i> {{ session('error') }}
            </div>
        @endif

        @yield('contenido')
    </main>
</div>

<script>
    // Menú lateral en celular: se desliza sobre el contenido en vez de empujarlo (antes el menú ocupaba
    // el ancho completo y la agenda quedaba debajo, fuera de la pantalla).
    (function () {
        const menu = document.getElementById('clinica-sidebar');
        const fondo = document.getElementById('clinica-sidebar-fondo');
        const boton = document.getElementById('clinica-menu-boton');
        if (!menu || !boton) return;

        function abrir(estado) {
            menu.classList.toggle('abierto', estado);
            fondo.hidden = !estado;
            boton.setAttribute('aria-expanded', estado ? 'true' : 'false');
        }

        boton.addEventListener('click', () => abrir(!menu.classList.contains('abierto')));
        fondo.addEventListener('click', () => abrir(false));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') abrir(false); });
    })();
</script>

{{-- Los scripts de cada pantalla (buscadores, arrastre, diálogos) van acá y no dentro del contenido:
     así el HTML de la vista queda solo el HTML. --}}
@yield('scripts')

</body>
</html>
