<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('titulo', 'Consultorio') · Doctorísimo</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('css/gineco.css') }}">

    <style>
        body { background-color: #f4f6f9; }
        .clinica-topbar { background: #fff; border-bottom: 1px solid #e3e6ea; }
        .clinica-marca { font-weight: 600; letter-spacing: .2px; }
        .clinica-contexto { font-size: .86rem; color: #55606c; }
        .clinica-contexto .chip { background: #eef2f7; border-radius: 999px; padding: .15rem .6rem; margin-right: .35rem; }
        .clinica-sidebar { background: #fff; border-right: 1px solid #e3e6ea; min-height: calc(100vh - 64px); }
        .clinica-sidebar .nav-link { color: #3d4753; border-radius: .5rem; padding: .5rem .75rem; }
        .clinica-sidebar .nav-link:hover { background: #eef2f7; }
        .clinica-sidebar .nav-link.active { background: #6500da; color: #fff; }
        .clinica-contenido { padding: 1.5rem; }

        /* Agenda (F2): la fila que se arrastra y el diálogo de cobro. */
        .lista-cola tr[draggable="true"] { cursor: grab; }
        .lista-cola tr.arrastrando { opacity: .5; }
        .cobro-fondo { position: fixed; inset: 0; background: rgba(0, 0, 0, .35); display: flex; align-items: center; justify-content: center; z-index: 1050; }
        .cobro-caja { background: #fff; border-radius: .5rem; padding: 1.25rem; width: min(24rem, 92vw); box-shadow: 0 1rem 3rem rgba(0, 0, 0, .25); }
        .calendario-mes td { width: 14.28%; }
    </style>
</head>
<body>

<header class="clinica-topbar d-flex align-items-center justify-content-between px-3 py-2">
    <div>
        <div class="clinica-marca">Doctorísimo <span class="text-muted fw-normal">· Consultorio</span></div>
        @isset($contexto)
            <div class="clinica-contexto">
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

    <div class="d-flex align-items-center gap-2">
        <a href="{{ route('clinica.contexto') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-repeat"></i> Cambiar consultorio
        </a>
        <a href="{{ route('dashboard') }}" class="btn btn-sm btn-link">Panel</a>
    </div>
</header>

<div class="container-fluid">
    <div class="row">
        <nav class="col-auto clinica-sidebar py-3" style="width: 240px;">
            <ul class="nav flex-column gap-1">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('clinica.inicio') ? 'active' : '' }}"
                       href="{{ route('clinica.inicio') }}">
                        <i class="bi bi-house-door"></i> Inicio
                    </a>
                </li>

                {{-- El menú sale de los módulos implementados de la especialidad: nada escrito a mano. --}}
                @foreach (($modulos ?? collect()) as $modulo)
                    @php $rutaModulo = 'clinica.' . $modulo->slug; @endphp
                    <li class="nav-item">
                        @if (\Illuminate\Support\Facades\Route::has($rutaModulo))
                            <a class="nav-link {{ request()->routeIs($rutaModulo . '*') ? 'active' : '' }}"
                               href="{{ route($rutaModulo) }}">
                                {{ $modulo->nombre }}
                            </a>
                        @else
                            <span class="nav-link disabled" title="Módulo declarado, todavía sin pantalla">
                                {{ $modulo->nombre }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>

        <main class="col clinica-contenido">
            @if (session('estado'))
                <div class="alert alert-success py-2">{{ session('estado') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-warning py-2">{{ session('error') }}</div>
            @endif

            @yield('contenido')
        </main>
    </div>
</div>

</body>
</html>
