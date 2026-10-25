<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión · Doctorísimo</title>
    @include('layouts._favicon')

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Paleta + sistema de la aplicación (mismo aspecto que el panel y el consultorio) -->
    <link rel="stylesheet" href="{{ asset('css/gineco.css') }}">
    <link rel="stylesheet" href="{{ asset('css/doctorisimo-ui.css') }}?v={{ @filemtime(public_path('css/doctorisimo-ui.css')) }}">

</head>
<body class="auth-pagina">

    {{-- Panel de marca: en escritorio ocupa la mitad izquierda con el lema; en el celular queda de franja. --}}
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
                <h1 class="auth-titulo">Iniciar sesión</h1>
                <p class="text-muted small mb-0">Ingresa con tu correo y tu contraseña. No tienes que elegir un tipo de usuario.</p>
            </div>

            {{-- El aviso que deja el restablecimiento de contraseña. --}}
            @if (session('status'))
                <div class="alert alert-success py-2 small" role="status">{{ session('status') }}</div>
            @endif

            {{-- El aviso del armazón clínico cuando un envío se quedó sin sesión (`VerifyCsrfToken`): la
                 pantalla a la que se volvía ya no existía, así que se llega acá con el motivo. --}}
            @if (session('aviso'))
                <div class="alert alert-warning py-2 small" role="status">{{ session('aviso') }}</div>
            @endif

            <form action="{{ route('login') }}" method="post" id="loginForm">
                @csrf

                <!-- Correo -->
                <div class="mb-3">
                    <label for="email" class="form-label small fw-bold">Correo electrónico</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" id="email" value="{{ old('email') }}"
                               class="form-control @error('email') is-invalid @enderror"
                               placeholder="ejemplo@correo.com" required autocomplete="email" autofocus>
                        @error('email')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>
                </div>

                <!-- Contraseña -->
                <div class="mb-3">
                    <label for="password" class="form-label small fw-bold">Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" id="password"
                               class="form-control @error('password') is-invalid @enderror"
                               placeholder="••••••••" required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword"
                                aria-label="Mostrar u ocultar la contraseña">
                            <i class="bi bi-eye-slash" id="toggleIcon"></i>
                        </button>
                        @error('password')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
                    <label class="form-check-label small" for="remember">
                        Recuérdame
                    </label>
                </div>

                <div class="d-grid mb-4">
                    <button type="submit" class="btn btn-primary">Ingresar</button>
                </div>

                <div class="d-flex justify-content-between align-items-center auth-links">
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
                    @else
                        <a href="#">¿Olvidaste tu contraseña?</a>
                    @endif
                </div>

                {{-- El paciente crea su cuenta al momento; el médico deja una solicitud que aprueba un
                     administrador (ROADMAP §10.4, fase 2). --}}
                <div class="text-center mt-4">
                    <p class="small mb-1">
                        ¿Eres paciente y es tu primera vez?
                        <a href="{{ route('paciente.registro') }}">Crea tu cuenta</a>
                    </p>
                    <p class="small mb-0">
                        ¿Eres médico?
                        <a href="{{ route('medico.registro') }}">Solicita tu cuenta</a>
                    </p>
                </div>
            </form>
        </div>
    </main>

    <!-- Lógica JavaScript para el control de la interfaz -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Mostrar/ocultar contraseña
            document.getElementById('togglePassword').addEventListener('click', function () {
                const passwordInput = document.getElementById('password');
                const icon = document.getElementById('toggleIcon');

                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.classList.replace('bi-eye-slash', 'bi-eye');
                } else {
                    passwordInput.type = 'password';
                    icon.classList.replace('bi-eye', 'bi-eye-slash');
                }
            });
        });
    </script>
</body>
</html>
