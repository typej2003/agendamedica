<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'AgendaMédica') }} | Registro</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome-free/css/all.min.css') }}">
    <!-- Estilos Personalizados Agenda Médica -->
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
            max-width: 900px;
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

        /* Estilos de Pestañas (Tabs) */
        .nav-pills .nav-link {
            color: var(--dark-color);
            font-weight: 600;
            border-radius: 50px;
            padding: 10px 24px;
            transition: all 0.3s ease;
            background-color: var(--primary-light);
            border: 1px solid var(--primary-border);
        }

        .nav-pills .nav-link.active {
            background-color: var(--primary-color);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(194, 143, 214, 0.4);
        }

        .form-section-title {
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--primary-color);
            border-bottom: 1px solid var(--primary-border);
            padding-bottom: 4px;
            margin-bottom: 12px;
            margin-top: 8px;
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

        .btn-toggle-password {
            background-color: transparent;
            color: var(--dark-color);
            border-color: #dee2e6;
            transition: all 0.2s ease;
        }

        .btn-toggle-password:hover {
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
        <div class="card register-card p-3 p-md-5">
            <!-- Título y Logotipo -->
            <div class="text-center mb-4">
                <a href="{{ url('/') }}" class="brand-link">
                    <div class="register-header-icon">
                        <i class="bi bi-heart-pulse-fill"></i>
                    </div>
                    <h3 class="fw-bold m-0" style="color: var(--dark-color);">
                        Agenda<span style="color: var(--primary-color);">Médica</span>
                    </h3>
                </a>
                <p class="text-muted small mt-2">Crea tu cuenta seleccionando tu perfil</p>
            </div>

            <!-- Selector de Pestañas (Tabs) -->
            <ul class="nav nav-pills justify-content-center mb-4 gap-2" id="registerTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="patient-tab" data-bs-toggle="pill" data-bs-target="#patient-panel" type="button" role="tab" aria-controls="patient-panel" aria-selected="true">
                        <i class="bi bi-person-fill me-2"></i>Paciente
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="doctor-tab" data-bs-toggle="pill" data-bs-target="#doctor-panel" type="button" role="tab" aria-controls="doctor-panel" aria-selected="false">
                        <i class="bi bi-person-badge-fill me-2"></i>Médico / Especialista
                    </button>
                </li>
            </ul>

            <!-- Contenido de las Pestañas -->
            <div class="tab-content" id="registerTabsContent">
                
                <!-- ==================== FORMULARIO PACIENTE ==================== -->
                <div class="tab-pane fade show active" id="patient-panel" role="tabpanel" aria-labelledby="patient-tab">
                    
                    <!-- Botón Registro Google -->
                    <div class="mb-4">
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

                    <form action="{{ route('register.patient') }}" method="post">
                        @csrf

                        <!-- Sección 1: Datos Personales -->
                        <div class="form-section-title"><i class="bi bi-person-vcard me-2"></i>Información Personal</div>
                        <div class="row g-2 g-md-3 mb-3">
                            <!-- Nombre(s) -->
                            <div class="col-12 col-md-4">
                                <label for="patient_name" class="form-label small fw-bold" style="color: var(--dark-color);">* Nombre(s):</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                    <input type="text" name="name" id="patient_name" value="{{ old('name') }}" 
                                           class="form-control @error('name') is-invalid @enderror" 
                                           placeholder="Nombre(s)" required autofocus>
                                    @error('name')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Apellido Paterno -->
                            <div class="col-12 col-sm-6 col-md-4">
                                <label for="patient_paternal_surname" class="form-label small fw-bold" style="color: var(--dark-color);">* Ap. Paterno:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person-add"></i></span>
                                    <input type="text" name="paternal_surname" id="patient_paternal_surname" value="{{ old('paternal_surname') }}" 
                                           class="form-control @error('paternal_surname') is-invalid @enderror" 
                                           placeholder="Paterno" required>
                                    @error('paternal_surname')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Apellido Materno -->
                            <div class="col-12 col-sm-6 col-md-4">
                                <label for="patient_maternal_surname" class="form-label small fw-bold" style="color: var(--dark-color);">Ap. Materno:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person-add"></i></span>
                                    <input type="text" name="maternal_surname" id="patient_maternal_surname" value="{{ old('maternal_surname') }}" 
                                           class="form-control @error('maternal_surname') is-invalid @enderror" 
                                           placeholder="Materno">
                                    @error('maternal_surname')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Fecha de Nacimiento -->
                            <div class="col-12 col-sm-6 col-md-4">
                                <label for="patient_birthdate" class="form-label small fw-bold" style="color: var(--dark-color);">* F. Nacimiento:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                                    <input type="date" name="birthdate" id="patient_birthdate" value="{{ old('birthdate') }}" 
                                           class="form-control @error('birthdate') is-invalid @enderror" required onchange="checkAge()">
                                    @error('birthdate')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Cédula de Identidad -->
                            <div class="col-12 col-sm-6 col-md-4">
                                <label for="patient_id_number" class="form-label small fw-bold" style="color: var(--dark-color);">Cédula ID:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-card-heading"></i></span>
                                    <input type="text" name="id_number" id="patient_id_number" value="{{ old('id_number') }}" 
                                           class="form-control @error('id_number') is-invalid @enderror" 
                                           placeholder="Cédula">
                                    @error('id_number')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Cédula del Representante (Condicional para menor de edad) -->
                            <div class="col-12 col-sm-6 col-md-4" id="guardian_id_container" style="display: none;">
                                <label for="guardian_id_number" class="form-label small fw-bold text-danger">* Cédula Tutor:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
                                    <input type="text" name="guardian_id_number" id="guardian_id_number" value="{{ old('guardian_id_number') }}" 
                                           class="form-control @error('guardian_id_number') is-invalid @enderror" 
                                           placeholder="Tutor">
                                    @error('guardian_id_number')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Género -->
                            <div class="col-12 col-sm-6 col-md-4">
                                <label for="patient_gender" class="form-label small fw-bold" style="color: var(--dark-color);">* Género:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-gender-ambiguous"></i></span>
                                    <select name="gender" id="patient_gender" class="form-select @error('gender') is-invalid @enderror" required>
                                        <option value="" selected disabled>...</option>
                                        <option value="female" {{ old('gender') == 'female' ? 'selected' : '' }}>Femenino</option>
                                        <option value="male" {{ old('gender') == 'male' ? 'selected' : '' }}>Masculino</option>
                                        <option value="other" {{ old('gender') == 'other' ? 'selected' : '' }}>Otro</option>
                                    </select>
                                    @error('gender')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <!-- Sección 2: Contacto y Cuenta -->
                        <div class="form-section-title"><i class="bi bi-envelope-at me-2"></i>Contacto y Seguridad</div>
                        <div class="row g-2 g-md-3 mb-3">
                            <!-- Teléfono Móvil -->
                            <div class="col-12 col-sm-6 col-md-6">
                                <label for="patient_phone" class="form-label small fw-bold" style="color: var(--dark-color);">* Teléfono:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                    <input type="tel" name="phone" id="patient_phone" value="{{ old('phone') }}" 
                                           class="form-control @error('phone') is-invalid @enderror" 
                                           placeholder="04121234567" required>
                                    @error('phone')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Correo electrónico -->
                            <div class="col-12 col-sm-6 col-md-6">
                                <label for="patient_email" class="form-label small fw-bold" style="color: var(--dark-color);">* Correo:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="email" id="patient_email" value="{{ old('email') }}" 
                                           class="form-control @error('email') is-invalid @enderror" 
                                           placeholder="correo@ejemplo.com" required autocomplete="email">
                                    @error('email')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Contraseña -->
                            <div class="col-12 col-md-6">
                                <label for="patient_password" class="form-label small fw-bold" style="color: var(--dark-color);">* Contraseña:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" name="password" id="patient_password" 
                                           class="form-control @error('password') is-invalid @enderror" 
                                           placeholder="Mínimo 8 caracteres" required autocomplete="new-password">
                                    <button class="btn btn-outline-secondary btn-toggle-password" type="button" onclick="togglePasswordVisibility('patient_password', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    @error('password')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Confirmar Contraseña -->
                            <div class="col-12 col-md-6">
                                <label for="patient_password_confirmation" class="form-label small fw-bold" style="color: var(--dark-color);">* Confirmar:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" name="password_confirmation" id="patient_password_confirmation" 
                                           class="form-control" placeholder="Repita contraseña" required autocomplete="new-password">
                                    <button class="btn btn-outline-secondary btn-toggle-password" type="button" onclick="togglePasswordVisibility('patient_password_confirmation', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Términos y Botón -->
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-4 pt-2 border-top">
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check terms-box d-flex align-items-center m-0">
                                    <input class="form-check-input mt-0 me-2" type="checkbox" name="terms" id="patient_terms" required {{ old('terms') ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="patient_terms" style="color: var(--dark-color);">
                                        Leí y acepto los términos y condiciones.
                                    </label>
                                </div>
                                <button type="button" class="btn btn-outline-gineco btn-sm" data-bs-toggle="modal" data-bs-target="#termsModal">
                                    Ver Términos
                                </button>
                            </div>

                            <button type="submit" class="btn btn-accent-gineco px-4 w-100 w-sm-auto mt-2 mt-sm-0">
                                Registrarme como Paciente
                            </button>
                        </div>
                    </form>
                </div>

                <!-- ==================== FORMULARIO MÉDICO ==================== -->
                <div class="tab-pane fade" id="doctor-panel" role="tabpanel" aria-labelledby="doctor-tab">
                    
                    <!-- Botón Registro Google -->
                    <div class="mb-4">
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

                    <form action="{{ route('register.doctor') }}" method="post">
                        @csrf

                        <!-- Sección 1: Datos Profesionales -->
                        <div class="form-section-title"><i class="bi bi-person-badge me-2"></i>Información Profesional</div>
                        <div class="row g-2 g-md-3 mb-3">
                            <!-- Prefijo -->
                            <div class="col-4 col-sm-3 col-md-2">
                                <label for="doctor_prefix" class="form-label small fw-bold" style="color: var(--dark-color);">Prefijo:</label>
                                <div class="input-group">
                                    <span class="input-group-text px-2"><i class="bi bi-award"></i></span>
                                    <select name="prefix" id="doctor_prefix" class="form-select px-1 @error('prefix') is-invalid @enderror">
                                        <option value="Dr." {{ old('prefix', 'Dr.') == 'Dr.' ? 'selected' : '' }}>Dr.</option>
                                        <option value="Dra." {{ old('prefix') == 'Dra.' ? 'selected' : '' }}>Dra.</option>
                                        <option value="Lic." {{ old('prefix') == 'Lic.' ? 'selected' : '' }}>Lic.</option>
                                        <option value="Mtr." {{ old('prefix') == 'Mtr.' ? 'selected' : '' }}>Mtr.</option>
                                    </select>
                                    @error('prefix')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Nombre(s) -->
                            <div class="col-8 col-sm-9 col-md-5">
                                <label for="doctor_name" class="form-label small fw-bold" style="color: var(--dark-color);">* Nombre(s):</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                    <input type="text" name="name" id="doctor_name" value="{{ old('name') }}" 
                                           class="form-control @error('name') is-invalid @enderror" 
                                           placeholder="Nombre(s)" required>
                                    @error('name')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Apellido Paterno -->
                            <div class="col-12 col-sm-6 col-md-5">
                                <label for="doctor_paternal_surname" class="form-label small fw-bold" style="color: var(--dark-color);">* Ap. Paterno:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person-add"></i></span>
                                    <input type="text" name="paternal_surname" id="doctor_paternal_surname" value="{{ old('paternal_surname') }}" 
                                           class="form-control @error('paternal_surname') is-invalid @enderror" 
                                           placeholder="Paterno" required>
                                    @error('paternal_surname')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Apellido Materno -->
                            <div class="col-12 col-sm-6 col-md-6">
                                <label for="doctor_maternal_surname" class="form-label small fw-bold" style="color: var(--dark-color);">Ap. Materno:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person-add"></i></span>
                                    <input type="text" name="maternal_surname" id="doctor_maternal_surname" value="{{ old('maternal_surname') }}" 
                                           class="form-control @error('maternal_surname') is-invalid @enderror" 
                                           placeholder="Materno">
                                    @error('maternal_surname')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Cédula Profesional / Licencia Médica -->
                            <div class="col-12 col-sm-12 col-md-6">
                                <label for="medical_license" class="form-label small fw-bold" style="color: var(--dark-color);">* Cédula / Licencia Médica:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-file-earmark-medical"></i></span>
                                    <input type="text" name="medical_license" id="medical_license" value="{{ old('medical_license') }}" 
                                           class="form-control @error('medical_license') is-invalid @enderror" 
                                           placeholder="N° Cédula o Licencia" required>
                                    @error('medical_license')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <!-- Sección 2: Contacto y Cuenta -->
                        <div class="form-section-title"><i class="bi bi-envelope-at me-2"></i>Contacto y Seguridad</div>
                        <div class="row g-2 g-md-3 mb-3">
                            <!-- Teléfono Móvil -->
                            <div class="col-12 col-sm-6 col-md-6">
                                <label for="doctor_phone" class="form-label small fw-bold" style="color: var(--dark-color);">* Teléfono:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                    <input type="tel" name="phone" id="doctor_phone" value="{{ old('phone') }}" 
                                           class="form-control @error('phone') is-invalid @enderror" 
                                           placeholder="04121234567" required>
                                    @error('phone')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Correo electrónico -->
                            <div class="col-12 col-sm-6 col-md-6">
                                <label for="doctor_email" class="form-label small fw-bold" style="color: var(--dark-color);">* Correo:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="email" id="doctor_email" value="{{ old('email') }}" 
                                           class="form-control @error('email') is-invalid @enderror" 
                                           placeholder="correo@ejemplo.com" required autocomplete="email">
                                    @error('email')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Contraseña -->
                            <div class="col-12 col-md-6">
                                <label for="doctor_password" class="form-label small fw-bold" style="color: var(--dark-color);">* Contraseña:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" name="password" id="doctor_password" 
                                           class="form-control @error('password') is-invalid @enderror" 
                                           placeholder="Mínimo 8 caracteres" required autocomplete="new-password">
                                    <button class="btn btn-outline-secondary btn-toggle-password" type="button" onclick="togglePasswordVisibility('doctor_password', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    @error('password')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <!-- Confirmar Contraseña -->
                            <div class="col-12 col-md-6">
                                <label for="doctor_password_confirmation" class="form-label small fw-bold" style="color: var(--dark-color);">* Confirmar:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" name="password_confirmation" id="doctor_password_confirmation" 
                                           class="form-control" placeholder="Repita contraseña" required autocomplete="new-password">
                                    <button class="btn btn-outline-secondary btn-toggle-password" type="button" onclick="togglePasswordVisibility('doctor_password_confirmation', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Términos y Botón -->
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-4 pt-2 border-top">
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check terms-box d-flex align-items-center m-0">
                                    <input class="form-check-input mt-0 me-2" type="checkbox" name="terms" id="doctor_terms" required {{ old('terms') ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="doctor_terms" style="color: var(--dark-color);">
                                        Leí y acepto los términos y condiciones.
                                    </label>
                                </div>
                                <button type="button" class="btn btn-outline-gineco btn-sm" data-bs-toggle="modal" data-bs-target="#termsModal">
                                    Ver Términos
                                </button>
                            </div>

                            <button type="submit" class="btn btn-accent-gineco px-4 w-100 w-sm-auto mt-2 mt-sm-0">
                                Registrarme como Médico
                            </button>
                        </div>
                    </form>
                </div>

            </div>

            <hr class="my-4" style="border-color: var(--primary-border);">

            <!-- Enlace de inicio de sesión -->
            <div class="text-center auth-links">
                <span class="small text-muted me-2">¿Ya tienes una cuenta?</span>
                <a href="{{ route('login') }}">Iniciar Sesión</a>
            </div>
        </div>
    </div>

    <!-- Modal Términos y Condiciones -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-header-title fw-bold m-0" id="termsModalLabel" style="color: var(--dark-color);">
                        <i class="bi bi-file-earmark-text me-2" style="color: var(--primary-color);"></i>Términos y Condiciones
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

    <!-- Scripts de la Interfaz -->
    <script>
        // Cálculo de Edad para Paciente Menor
        function checkAge() {
            const birthdateInput = document.getElementById('patient_birthdate').value;
            const guardianContainer = document.getElementById('guardian_id_container');
            const guardianInput = document.getElementById('guardian_id_number');
            const patientIdInput = document.getElementById('patient_id_number');

            if (!birthdateInput) return;

            const birthDate = new Date(birthdateInput);
            const today = new Date();
            
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();

            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }

            if (age < 18) {
                guardianContainer.style.display = 'block';
                guardianInput.setAttribute('required', 'required');
                patientIdInput.removeAttribute('required');
            } else {
                guardianContainer.style.display = 'none';
                guardianInput.removeAttribute('required');
                guardianInput.value = '';
            }
        }

        // Mostrar / Ocultar Contraseña con Bootstrap Icons
        function togglePasswordVisibility(inputId, buttonElement) {
            const input = document.getElementById(inputId);
            const icon = buttonElement.querySelector('i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
    </script>
</body>
</html>