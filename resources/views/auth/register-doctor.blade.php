<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'GinecoReport') }} | Registro Médico</title>

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

        .register-card {
            background: var(--bg-surface);
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(194, 143, 214, 0.2);
            border: 1px solid var(--primary-border);
            max-width: 960px;
            width: 100%;
        }

        .register-header-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            transition: transform 0.3s ease;
        }

        .brand-link {
            text-decoration: none;
            color: inherit;
            display: inline-flex;
            align-items: center;
            gap: 12px;
        }

        .brand-link:hover .register-header-icon {
            transform: scale(1.05);
        }

        .form-control:focus, .form-select:focus {
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
            padding: 10px 20px;
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

        .terms-box {
            background-color: var(--primary-light);
            border: 1px solid var(--primary-border);
            border-radius: 50px;
            padding: 8px 20px;
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

        .modal-content {
            border-radius: 15px;
            border: 1px solid var(--primary-border);
        }

        .modal-header {
            background-color: var(--primary-light);
            border-bottom: 1px solid var(--primary-border);
        }

        .terms-content h6 {
            color: var(--primary-color);
            font-weight: 700;
            margin-top: 1rem;
            margin-bottom: 0.5rem;
        }

        .terms-content ul {
            padding-left: 1.2rem;
        }

        .terms-content li {
            margin-bottom: 0.4rem;
            font-size: 0.9rem;
            color: #555;
        }
    </style>
</head>
<body>

    <div class="container d-flex justify-content-center align-items-center py-5">
        <div class="card register-card p-4 p-md-5">
            <!-- Título y Logotipo con Enlace a la Raíz / Welcome -->
            <div class="text-center mb-4">
                <a href="{{ url('/') }}" class="brand-link">
                    <div class="register-header-icon">
                        <i class="bi bi-heart-pulse-fill"></i>
                    </div>
                    <h3 class="fw-bold m-0" style="color: var(--dark-color);">
                        Gineco<span style="color: var(--primary-color);">Report</span>
                    </h3>
                </a>
                <p class="text-muted small mt-2">Registro Especialista / Médico</p>
            </div>

            <form action="{{ route('register.doctor') }}" method="post">
                @csrf

                <div class="row g-3">
                    <!-- Botón Registro Google -->
                    <div class="col-12 col-md-3 d-flex align-items-end mb-2">
                        <a href="#" class="btn btn-google w-100">
                            <svg width="18" height="18" viewBox="0 0 18 18">
                                <path fill="#4285F4" d="M17.64 9.2c0-.74-.06-1.28-.19-1.84H9v3.34h4.96c-.1.83-.64 2.08-1.84 2.92l2.84 2.2c1.7-1.57 2.68-3.88 2.68-6.62z"/>
                                <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.84-2.2c-.76.53-1.78.9-3.12.9-2.38 0-4.41-1.57-5.13-3.72L.97 13.01C2.47 15.98 5.5 18 9 18z"/>
                                <path fill="#FBBC05" d="M3.87 10.8c-.18-.53-.28-1.1-.28-1.8s.1-1.27.28-1.8L.97 4.99C.35 6.22 0 7.57 0 9s.35 2.78.97 4.01l2.9-2.21z"/>
                                <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0 5.5 0 2.47 2.02.97 4.99l2.9 2.21c.72-2.15 2.75-3.62 5.13-3.62z"/>
                            </svg>
                            Registrarme con Google
                        </a>
                    </div>

                    <!-- Prefijo -->
                    <div class="col-12 col-md-3">
                        <label for="prefix" class="form-label small fw-bold" style="color: var(--dark-color);">Prefijo:</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                            <select name="prefix" id="prefix" class="form-select @error('prefix') is-invalid @enderror">
                                <option value="Dr." {{ old('prefix', 'Dr.') == 'Dr.' ? 'selected' : '' }}>Dr.</option>
                                <option value="Dra." {{ old('prefix') == 'Dra.' ? 'selected' : '' }}>Dra.</option>
                                <option value="Lic." {{ old('prefix') == 'Lic.' ? 'selected' : '' }}>Lic.</option>
                                <option value="Mtr." {{ old('prefix') == 'Mtr.' ? 'selected' : '' }}>Mtr.</option>
                            </select>
                            @error('prefix')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                        </div>
                    </div>

                    <!-- Nombre(s) -->
                    <div class="col-12 col-md-3">
                        <label for="name" class="form-label small fw-bold" style="color: var(--dark-color);">* Nombre(s):</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" name="name" id="name" value="{{ old('name') }}" 
                                   class="form-control @error('name') is-invalid @enderror" 
                                   placeholder="Escriba su(s) nombre(s)" required autofocus>
                            @error('name')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                        </div>
                    </div>

                    <!-- Apellido Paterno -->
                    <div class="col-12 col-md-3">
                        <label for="paternal_surname" class="form-label small fw-bold" style="color: var(--dark-color);">* Apellido Paterno:</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user-plus"></i></span>
                            <input type="text" name="paternal_surname" id="paternal_surname" value="{{ old('paternal_surname') }}" 
                                   class="form-control @error('paternal_surname') is-invalid @enderror" 
                                   placeholder="Escriba su apellido paterno" required>
                            @error('paternal_surname')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                        </div>
                    </div>

                    <!-- Apellido Materno -->
                    <div class="col-12 col-md-3">
                        <label for="maternal_surname" class="form-label small fw-bold" style="color: var(--dark-color);">* Apellido Materno:</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user-plus"></i></span>
                            <input type="text" name="maternal_surname" id="maternal_surname" value="{{ old('maternal_surname') }}" 
                                   class="form-control @error('maternal_surname') is-invalid @enderror" 
                                   placeholder="Escriba su apellido materno">
                            @error('maternal_surname')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                        </div>
                    </div>

                    <!-- Correo electrónico -->
                    <div class="col-12 col-md-3">
                        <label for="email" class="form-label small fw-bold" style="color: var(--dark-color);">* Correo electrónico:</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            <input type="email" name="email" id="email" value="{{ old('email') }}" 
                                   class="form-control @error('email') is-invalid @enderror" 
                                   placeholder="Escriba su correo electrónico" required autocomplete="email">
                            @error('email')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                        </div>
                    </div>

                    <!-- Contraseña -->
                    <div class="col-12 col-md-3">
                        <label for="password" class="form-label small fw-bold" style="color: var(--dark-color);">* Contraseña:</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" name="password" id="password" 
                                   class="form-control @error('password') is-invalid @enderror" 
                                   placeholder="Contraseña" required autocomplete="new-password">
                            @error('password')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                        </div>
                    </div>

                    <!-- Confirmar Contraseña -->
                    <div class="col-12 col-md-3">
                        <label for="password_confirmation" class="form-label small fw-bold" style="color: var(--dark-color);">* Confirmar Contraseña:</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" name="password_confirmation" id="password_confirmation" 
                                   class="form-control" placeholder="Confirmar contraseña" required autocomplete="new-password">
                        </div>
                    </div>

                    <!-- ¿Dónde se enteró de GinecoReport? -->
                    <div class="col-12 col-md-4">
                        <label for="referral_source" class="form-label small fw-bold" style="color: var(--dark-color);">¿Dónde se enteró de GinecoReport?</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-question-circle"></i></span>
                            <input type="text" name="referral_source" id="referral_source" value="{{ old('referral_source') }}" 
                                   class="form-control" placeholder="Escriba donde se enteró">
                        </div>
                    </div>

                    <!-- Términos y condiciones y Botones finales -->
                    <div class="col-12 col-md-8 d-flex flex-wrap align-items-center justify-content-end gap-2 mt-4">
                        <div class="form-check terms-box d-flex align-items-center m-0">
                            <input class="form-check-input mt-0 me-2" type="checkbox" name="terms" id="terms" required {{ old('terms') ? 'checked' : '' }}>
                            <label class="form-check-label small" for="terms" style="color: var(--dark-color);">
                                Leí y acepto los términos y condiciones.
                            </label>
                        </div>

                        <!-- Botón para disparar el Modal -->
                        <button type="button" class="btn btn-outline-gineco btn-sm" data-bs-toggle="modal" data-bs-target="#termsModal">
                            Ver Términos
                        </button>

                        <button type="submit" class="btn btn-accent-gineco">
                            Registrarme
                        </button>
                    </div>
                </div>

                <hr class="my-4" style="border-color: var(--primary-border);">

                <!-- Enlace de inicio de sesión -->
                <div class="text-center auth-links">
                    <span class="small text-muted me-2">¿Ya tienes una cuenta?</span>
                    <a href="{{ route('login') }}">Iniciar Sesión</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Términos y Condiciones -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-header-title fw-bold m-0" id="termsModalLabel" style="color: var(--dark-color);">
                        <i class="fas fa-file-contract me-2" style="color: var(--primary-color);"></i>Términos y Condiciones
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body terms-content p-4">
                    <ul class="list-unstyled">
                        <li><strong>1.</strong> Este servicio es de paga (Cobro por resultados).</li>
                        <li><strong>2.</strong> Puede haber descuentos y promociones para los doctores. GinecoReport está dedicado a facilitar la obtención de pacientes tanto como para ayudar al paciente a buscar un doctor especializado en su tema.</li>
                    </ul>

                    <h6>(A). Al Registrarse</h6>
                    <ul>
                        <li><strong>3.</strong> Usted está brindando información verdadera.</li>
                        <li><strong>4.</strong> Si usted no brinda su información verdadera y hace mal uso de esta plataforma, será totalmente responsable de todo lo que haga y rendirá cargos a las autoridades correspondientes penalizando sus actos. Inmediatamente se le dará de baja de esta plataforma y no podrá acceder más.</li>
                    </ul>

                    <h6>(B). Datos Seguros y Totalmente Confidenciales</h6>
                    <ul>
                        <li><strong>5.</strong> Sus datos serán totalmente confidenciales, no se entregará ninguna de su información personal a nadie, solo se le entregará los datos requeridos al paciente para tener su cita programada y mantenerse comunicado con el paciente a excepción del punto 4.</li>
                        <li><strong>6.</strong> Usted siempre podrá contar con todos los beneficios de GinecoReport.</li>
                    </ul>

                    <h6>(C). Condiciones Generales y Permanencia</h6>
                    <p class="small text-muted mb-2">Usted puede registrarse si está de acuerdo con los términos y condiciones mencionados anteriormente.</p>
                    <ul>
                        <li><strong>7.</strong> Tiene derecho y es aconsejable que dé sus puntos de vista para la mejora de la plataforma (contacto con soporte).</li>
                        <li><strong>8.</strong> Si no verifica la dirección de su correo electrónico por un lapso de tiempo, se eliminará su cuenta.</li>
                        <li><strong>9.</strong> Cancelación de cuenta por hacer reportes falsos al paciente.</li>
                        <li><strong>10.</strong> Suspensión permanente de la plataforma por multicuenta.</li>
                        <li><strong>11.</strong> Se enviarán mensajes en algunas ocasiones a su correo electrónico sobre temas de ayuda y soporte de GinecoReport, será libre de cancelar su suscripción a dichos mensajes.</li>
                        <li><strong>12.</strong> Usted puede ser vetado permanentemente de GinecoReport por no pagar el precio de los servicios ofrecidos.</li>
                        <li><strong>13.</strong> Leíste y aceptaste el aviso de privacidad.</li>
                    </ul>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--primary-border);">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>