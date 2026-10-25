<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Elegir área · Doctorísimo</title>
    @include('layouts._favicon')

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Paleta + sistema de la aplicación (mismo aspecto que el login) -->
    <link rel="stylesheet" href="{{ asset('css/gineco.css') }}">
    <link rel="stylesheet" href="{{ asset('css/doctorisimo-ui.css') }}?v={{ @filemtime(public_path('css/doctorisimo-ui.css')) }}">

</head>
<body class="auth-pagina">

    <aside class="auth-marca" aria-label="Doctorísimo">
        <a href="{{ url('/') }}" class="auth-marca-logo">
            <span class="app-marca-ficha" aria-hidden="true"><i class="bi bi-heart-pulse-fill"></i></span>
            <span class="auth-marca-nombre">Doctorísimo<span>.App</span></span>
        </a>

        <div class="auth-marca-lema">
            <p class="auth-lema-titulo">Tu consultorio,<br>siempre a la mano</p>
            <p class="auth-lema-texto">Agenda, historia clínica y récipes en un solo lugar, con o sin conexión.</p>
        </div>
    </aside>

    <main class="auth-formulario">
        <div class="card login-card">
            <div class="mb-4">
                <h1 class="auth-titulo">¿Dónde quieres entrar?</h1>
                <p class="text-muted small mb-0">
                    Tu cuenta tiene acceso a más de un área. Elige la que quieres usar ahora.
                </p>
            </div>

            <div class="auth-panel-opciones">
                @foreach ($paneles as $panel)
                    <a href="{{ route($panel['ruta']) }}" class="auth-panel-opcion">
                        <span class="auth-panel-opcion-icono" aria-hidden="true"><i class="bi {{ $panel['icono'] }}"></i></span>
                        <span class="auth-panel-opcion-texto">
                            <span class="auth-panel-opcion-titulo">{{ $panel['titulo'] }}</span>
                            <span class="auth-panel-opcion-descripcion">{{ $panel['descripcion'] }}</span>
                        </span>
                        <i class="bi bi-chevron-right auth-panel-opcion-flecha" aria-hidden="true"></i>
                    </a>
                @endforeach
            </div>

            <hr class="my-4">

            <div class="d-flex justify-content-between align-items-center gap-3">
                <span class="text-muted small text-truncate" title="{{ $usuario->email }}">{{ $usuario->email }}</span>

                <form method="POST" action="{{ route('logout') }}" onsubmit="return confirm('¿Cerrar sesión?');">
                    @csrf
                    <button type="submit" class="btn btn-link btn-sm text-muted p-0">Cerrar sesión</button>
                </form>
            </div>
        </div>
    </main>

</body>
</html>
