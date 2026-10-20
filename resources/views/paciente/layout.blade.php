<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo', 'Mis citas · Doctorísimo')</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('css/gineco.css') }}">
    <link rel="stylesheet" href="{{ asset('css/doctorisimo-ui.css') }}">
    <link rel="stylesheet" href="{{ asset('css/paciente.css') }}">
    @stack('estilos')
</head>
<body class="paciente-pagina">
    <header class="paciente-barra">
        <div class="container paciente-barra-interior">
            <a href="{{ route('paciente.citas') }}" class="paciente-marca">
                <span class="app-marca-ficha" aria-hidden="true"><i class="bi bi-heart-pulse-fill"></i></span>
                <span class="paciente-marca-nombre">Doctorísimo<span>.App</span></span>
            </a>

            <nav class="paciente-nav" aria-label="Mi cuenta">
                <a href="{{ route('paciente.citas') }}"
                   class="paciente-nav-enlace {{ request()->routeIs('paciente.citas') ? 'paciente-nav-activo' : '' }}"
                   @if (request()->routeIs('paciente.citas')) aria-current="page" @endif>
                    <i class="bi bi-calendar2-check" aria-hidden="true"></i> Mis citas
                </a>
                <a href="{{ route('reservas.catalogo') }}" class="paciente-nav-enlace">
                    <i class="bi bi-plus-circle" aria-hidden="true"></i> Reservar cita
                </a>
            </nav>

            <div class="paciente-cuenta">
                <span class="paciente-cuenta-nombre" title="{{ auth()->user()->email }}">
                    {{ auth()->user()->name }}
                </span>
                <form method="POST" action="{{ route('logout') }}" onsubmit="return confirm('¿Cerrar sesión?');">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Cerrar sesión</button>
                </form>
            </div>
        </div>
    </header>

    <main class="container paciente-contenido" id="contenido">
        @if (session('estado'))
            <div class="alert alert-success" role="status">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i> {{ session('estado') }}
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-warning" role="alert">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ session('error') }}
            </div>
        @endif

        @yield('contenido')
    </main>

    <footer class="paciente-pie">
        <div class="container">
            <p>Doctorísimo.App · Tu consultorio, siempre a la mano.</p>
        </div>
    </footer>

    @stack('js')
</body>
</html>
