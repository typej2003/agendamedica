<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión · {{ config('app.name', 'Doctorísimo') }}</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome-free/css/all.min.css') }}">
    <!-- Paleta + sistema de la aplicación (mismo aspecto que el panel y el consultorio) -->
    <link rel="stylesheet" href="{{ asset('css/gineco.css') }}">
    <link rel="stylesheet" href="{{ asset('css/doctorisimo-ui.css') }}">

    <style>
        body {
            background: linear-gradient(160deg, var(--ds-violeta-50) 0%, var(--ds-fondo) 55%, var(--ds-celeste-50) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
        }

        .login-card {
            background: var(--ds-superficie);
            border-radius: var(--ds-radio-lg);
            box-shadow: var(--ds-sombra-3);
            border: 1px solid var(--ds-borde);
            max-width: 430px;
            width: 100%;
        }

        .login-header-icon {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background-color: var(--ds-violeta-100);
            color: var(--ds-violeta-700);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 31px;
            margin-bottom: 14px;
            transition: transform .2s ease;
        }

        .brand-link { text-decoration: none; color: inherit; display: inline-block; }
        .brand-link:hover .login-header-icon { transform: scale(1.04); }

        .auth-links a {
            color: var(--ds-celeste-700);
            text-decoration: none;
            font-weight: 600;
            font-size: .88rem;
        }

        .auth-links a:hover { text-decoration: underline; }

        /* Selector del tipo de usuario */
        .user-type-selector .btn { border-color: var(--ds-borde-2); color: var(--ds-texto-2); }
        .user-type-selector .btn:hover { background: var(--ds-violeta-50); color: var(--ds-violeta-800); }
        .user-type-selector .btn-check:checked + .btn {
            background-color: var(--ds-violeta-700);
            border-color: var(--ds-violeta-700);
            color: #fff;
        }

        .disabled-group { opacity: .55; pointer-events: none; }

        .acceso-sistema {
            background: var(--ds-superficie-2);
            border: 1px solid var(--ds-borde);
            border-radius: var(--ds-radio);
            padding: .6rem .85rem;
        }
    </style>
</head>
<body>

    <div class="w-100 d-flex justify-content-center">
        <div class="card login-card p-4 p-md-5">
            <!-- Título y Logotipo -->
            <div class="text-center mb-4">
                <a href="{{ url('/') }}" class="brand-link">
                    <span class="login-header-icon">
                        <i class="bi bi-heart-pulse-fill"></i>
                    </span>
                    <h1 class="h3 fw-bold mb-1" style="color: var(--ds-tinta);">
                        Doctorísimo<span style="color: var(--ds-violeta-700);">.App</span>
                    </h1>
                </a>
                <p class="text-muted small mt-1 mb-0">Inicia sesión para acceder al sistema</p>
            </div>

            <form action="{{ route('login') }}" method="post" id="loginForm">
                @csrf

                <!-- Campo oculto: envía el tipo de usuario cuando el selector está deshabilitado -->
                <input type="hidden" name="user_type" id="hidden_user_type" value="{{ old('user_type', 'Paciente') }}">

                <!-- Acceso del sistema (administración y secretaría): usa la tabla `users`. -->
                <div class="acceso-sistema mb-3">
                    <div class="form-check form-switch d-flex align-items-center gap-2 mb-0 ps-0">
                        <input class="form-check-input ms-0 mt-0" type="checkbox" role="switch" id="systemAccessCheck" {{ old('user_type') == 'Root' ? 'checked' : '' }}>
                        <label class="form-check-label small fw-semibold mb-0" for="systemAccessCheck" style="color: var(--ds-texto-2);">
                            Acceso de administración / sistema
                        </label>
                    </div>
                </div>

                <!-- Selector del tipo de usuario -->
                <div class="mb-4" id="userTypeContainer">
                    <span class="form-label small fw-bold d-block text-center mb-2" style="color: var(--ds-texto-2);">Acceder como:</span>
                    <div class="btn-group w-100 user-type-selector" role="group" id="btnGroupUserType" aria-label="Tipo de usuario">
                        <input type="radio" class="btn-check user-type-radio" name="user_type_option" id="type_paciente" value="Paciente" {{ old('user_type', 'Paciente') == 'Paciente' ? 'checked' : '' }}>
                        <label class="btn btn-outline-primary fw-semibold" for="type_paciente">
                            <i class="bi bi-person me-1"></i> Paciente
                        </label>

                        <input type="radio" class="btn-check user-type-radio" name="user_type_option" id="type_medico" value="Medico" {{ old('user_type') == 'Medico' ? 'checked' : '' }}>
                        <label class="btn btn-outline-primary fw-semibold" for="type_medico">
                            <i class="bi bi-person-badge me-1"></i> Médico
                        </label>
                    </div>
                    @error('user_type')
                        <small class="text-danger d-block text-center mt-1" role="alert">{{ $message }}</small>
                    @enderror
                </div>

                <!-- Correo -->
                <div class="mb-3">
                    <label for="email" class="form-label small fw-bold">Correo electrónico</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
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
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
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
                    <label class="form-check-label small" for="remember" style="color: var(--ds-texto-2);">
                        Recuérdame
                    </label>
                </div>

                <div class="d-grid mb-4">
                    <button type="submit" class="btn btn-primary-gineco">Ingresar</button>
                </div>

                <div class="d-flex justify-content-between align-items-center auth-links">
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}">Crear una cuenta</a>
                    @else
                        <a href="#">Crear una cuenta</a>
                    @endif

                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
                    @else
                        <a href="#">¿Olvidaste tu contraseña?</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!-- Lógica JavaScript para el control de la interfaz -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const systemCheck = document.getElementById('systemAccessCheck');
            const btnGroupUserType = document.getElementById('btnGroupUserType');
            const radios = document.querySelectorAll('.user-type-radio');
            const hiddenUserType = document.getElementById('hidden_user_type');

            function toggleUserTypeSelector() {
                if (systemCheck.checked) {
                    btnGroupUserType.classList.add('disabled-group');
                    radios.forEach(radio => radio.disabled = true);
                    hiddenUserType.value = 'Root';
                } else {
                    btnGroupUserType.classList.remove('disabled-group');
                    radios.forEach(radio => radio.disabled = false);

                    const checkedRadio = document.querySelector('.user-type-radio:checked');
                    hiddenUserType.value = checkedRadio ? checkedRadio.value : 'Paciente';
                }
            }

            radios.forEach(radio => {
                radio.addEventListener('change', function () {
                    if (!systemCheck.checked) {
                        hiddenUserType.value = this.value;
                    }
                });
            });

            systemCheck.addEventListener('change', toggleUserTypeSelector);

            // Inicialización según el estado precargado
            toggleUserTypeSelector();

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
