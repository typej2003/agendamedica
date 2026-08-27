<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'GinecoReport') }} | Iniciar Sesión</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome-free/css/all.min.css') }}">
    <!-- Estilos Personalizados GinecoReport -->
    <link rel="stylesheet" href="{{ asset('css/gineco.css') }}">

    <style>
        body {
            background-color: var(--bg-light);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            background: var(--bg-surface);
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(194, 143, 214, 0.2);
            border: 1px solid var(--primary-border);
            max-width: 440px;
            width: 100%;
        }

        .login-header-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 15px;
            transition: transform 0.3s ease;
        }

        .brand-link {
            text-decoration: none;
            color: inherit;
            display: inline-block;
        }

        .brand-link:hover .login-header-icon {
            transform: scale(1.05);
        }

        .brand-link:hover h3 {
            opacity: 0.9;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(194, 143, 214, 0.25);
        }

        .input-group-text {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-color: #dee2e6;
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-google {
            background-color: #ffffff;
            border: 1px solid #e0e0e0;
            color: var(--dark-color);
            font-weight: 600;
            border-radius: 50px;
            padding: 10px 24px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-google:hover {
            background-color: #f8f9fa;
            border-color: #cccccc;
            color: var(--dark-color);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .auth-links a {
            color: var(--accent-color);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .auth-links a:hover {
            color: var(--accent-hover);
            text-decoration: underline;
        }

        /* Selector del tipo de usuario */
        .user-type-selector .btn-check:checked + .btn-outline-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: #ffffff;
        }

        /* Estilo para deshabilitar visualmente el grupo de botones */
        .disabled-group {
            opacity: 0.5;
            pointer-events: none;
        }
    </style>
</head>
<body>

    <div class="container d-flex justify-content-center align-items-center py-5">
        <div class="card login-card p-4 p-md-5">
            <!-- Título y Logotipo -->
            <div class="text-center mb-4">
                <a href="{{ url('/') }}" class="brand-link">
                    <div class="login-header-icon">
                        <i class="bi bi-heart-pulse-fill"></i>
                    </div>
                    <h3 class="fw-bold mb-1" style="color: var(--dark-color);">
                        Gineco<span style="color: var(--primary-color);">Report</span>
                    </h3>
                </a>
                <p class="text-muted small mt-1">Inicia sesión para acceder al sistema</p>
            </div>

            <form action="{{ route('login') }}" method="post" id="loginForm">
                @csrf

                <!-- Campo Hidden para enviar la opción de usuario cuando esté deshabilitado el radio -->
                <input type="hidden" name="user_type" id="hidden_user_type" value="{{ old('user_type', 'Paciente') }}">

                <!-- Toggle para acceso de Administración/Sistema -->
                <div class="form-check form-switch mb-3 d-flex justify-content-center align-items-center gap-2 ps-0">
                    <input class="form-check-input ms-0" type="checkbox" role="switch" id="systemAccessCheck" {{ old('user_type') == 'Root' ? 'checked' : '' }}>
                    <label class="form-check-label small fw-bold" for="systemAccessCheck" style="color: var(--dark-color);">
                        Acceso de Administración / Sistema
                    </label>
                </div>

                <!-- Selector del Tipo de Usuario (Paciente / Médico) -->
                <div class="mb-4" id="userTypeContainer">
                    <label class="form-label small fw-bold d-block text-center mb-2" style="color: var(--dark-color);">Acceder como:</label>
                    <div class="btn-group w-100 user-type-selector" role="group" id="btnGroupUserType">
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
                        <small class="text-danger d-block text-center mt-1">{{ $message }}</small>
                    @enderror
                </div>

                <!-- Campo Email -->
                <div class="mb-3">
                    <label for="email" class="form-label small fw-bold" style="color: var(--dark-color);">Correo electrónico</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" name="email" id="email" value="{{ old('email', 'root@admin.com') }}" 
                               class="form-control @error('email') is-invalid @enderror" 
                               placeholder="ejemplo@correo.com" required autocomplete="email" autofocus>
                        @error('email')
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>
                </div>

                <!-- Campo Contraseña -->
                <div class="mb-3">
                    <label for="password" class="form-label small fw-bold" style="color: var(--dark-color);">Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" id="password" value="12345678" 
                               class="form-control @error('password') is-invalid @enderror" 
                               placeholder="••••••••" required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="bi bi-eye-slash" id="toggleIcon"></i>
                        </button>
                        @error('password')
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>
                </div>

                <!-- Recuérdame -->
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
                    <label class="form-check-label small" for="remember" style="color: var(--dark-color);">
                        Recuérdame
                    </label>
                </div>

                <!-- Botones de Acción -->
                <div class="d-grid gap-3 mb-4">
                    <button type="submit" class="btn btn-primary-gineco w-100">
                        Ingresar
                    </button>

                    <a href="#" class="btn btn-google w-100">
                        <svg width="18" height="18" viewBox="0 0 18 18">
                            <path fill="#4285F4" d="M17.64 9.2c0-.74-.06-1.28-.19-1.84H9v3.34h4.96c-.1.83-.64 2.08-1.84 2.92l2.84 2.2c1.7-1.57 2.68-3.88 2.68-6.62z"/>
                            <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.84-2.2c-.76.53-1.78.9-3.12.9-2.38 0-4.41-1.57-5.13-3.72L.97 13.01C2.47 15.98 5.5 18 9 18z"/>
                            <path fill="#FBBC05" d="M3.87 10.8c-.18-.53-.28-1.1-.28-1.8s.1-1.27.28-1.8L.97 4.99C.35 6.22 0 7.57 0 9s.35 2.78.97 4.01l2.9-2.21z"/>
                            <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0 5.5 0 2.47 2.02.97 4.99l2.9 2.21c.72-2.15 2.75-3.62 5.13-3.62z"/>
                        </svg>
                        Iniciar con Google
                    </a>
                </div>

                <!-- Enlaces Inferiores -->
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

            // Toggle para mostrar/ocultar contraseña
            document.getElementById('togglePassword').addEventListener('click', function () {
                const passwordInput = document.getElementById('password');
                const icon = document.getElementById('toggleIcon');
                
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                } else {
                    passwordInput.type = 'password';
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                }
            });
        });
    </script>
</body>
</html>