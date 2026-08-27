<div>
    <!DOCTYPE html>
    <html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Agenda Médica - Directorio Médico y Salud Ginecológica</title>

        <!-- Bootstrap 5 CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        
        <!-- Bootstrap Icons -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

        <!-- Estilos Personalizados Gineco -->
        <link rel="stylesheet" href="{{ asset('css/gineco.css') }}">

        @livewireStyles
    </head>
    <body class="pt-5 mt-4">

        <!-- Navbar Integrada -->
        @livewire('layouts.navbar')

        <!-- Hero Section con Componente Livewire Integrado -->
        <section class="hero-section" id="inicio">
            <div class="container">
                <div class="text-center max-w-720 mx-auto mb-5">
                    <span class="badge badge-gineco mb-3"><i class="bi bi-shield-check me-1"></i> Atención Especializada 24/7</span>
                    <h1 class="display-4 hero-title mb-3">Encuentra a tu <span>Especialista</span> y Agenda en Minutos</h1>
                    <p class="lead text-muted">Conectamos a pacientes con los mejores profesionales en ginecología, obstetricia y salud integral.</p>
                </div>

                <!-- Invocación del Componente Livewire de Búsqueda -->
                <div id="busqueda">
                    @livewire('components.search-specialties')
                </div>
            </div>
        </section>

        <!-- Características Rápidas -->
        <section class="py-5 bg-white">
            <div class="container">
                <div class="row g-4 text-center">
                    <div class="col-md-4">
                        <div class="p-3">
                            <div class="service-icon mx-auto">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                            <h5 class="fw-bold">Cita Directa</h5>
                            <p class="text-muted small mb-0">Reserva turnos en tiempo real según la disponibilidad del especialista.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3">
                            <div class="service-icon accent-icon mx-auto">
                                <i class="bi bi-camera-video"></i>
                            </div>
                            <h5 class="fw-bold">Telemedicina</h5>
                            <p class="text-muted small mb-0">Videollamadas en vivo y privadas con encriptación para tu seguridad.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3">
                            <div class="service-icon mx-auto">
                                <i class="bi bi-file-earmark-medical"></i>
                            </div>
                            <h5 class="fw-bold">Historial Digital</h5>
                            <p class="text-muted small mb-0">Accede a tus recetas y resultados médicos de forma instantánea.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Banner Telemedicina / Llamado a la Acción -->
        <section class="py-5 bg-white" id="servicios">
            <div class="container">
                <div class="banner-telemedicina p-4 p-md-5">
                    <div class="row align-items-center gy-4">
                        <div class="col-md-8">
                            <span class="badge badge-gineco mb-2">Consulta Médica Online</span>
                            <h3 class="fw-bold">¿Tienes dudas o necesitas orientaciones rápidas?</h3>
                            <p class="text-muted mb-0">Conéctate por chat o videollamada de forma segura con doctores certificados sin salir de casa.</p>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <a href="#" class="btn btn-accent-gineco btn-lg">Iniciar Consulta</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="footer-gineco py-5">
            <div class="container">
                <div class="row g-4">
                    <div class="col-lg-4">
                        <h5 class="fw-bold mb-3"><i class="bi bi-heart-pulse-fill me-2" style="color: #c28fd6;"></i>GinecoReport</h5>
                        <p class="small text-muted mb-3">Plataforma integral para la gestión y reserva de citas médicas especializadas.</p>
                    </div>
                    <div class="col-lg-2 col-6">
                        <h6 class="fw-bold mb-3">Plataforma</h6>
                        <ul class="list-unstyled small">
                            <li class="mb-2"><a href="#">Buscar Doctores</a></li>
                            <li class="mb-2"><a href="#">Especialidades</a></li>
                        </ul>
                    </div>
                    <div class="col-lg-2 col-6">
                        <h6 class="fw-bold mb-3">Para Médicos</h6>
                        <ul class="list-unstyled small">
                            <li class="mb-2"><a href="#">Unirse como Doctor</a></li>
                            <li class="mb-2"><a href="#">Gestión de Agenda</a></li>
                        </ul>
                    </div>
                    <div class="col-lg-4">
                        <h6 class="fw-bold mb-3">Contacto</h6>
                        <p class="small text-muted mb-1"><i class="bi bi-envelope me-2"></i>soporte@ginecoreport.com</p>
                    </div>
                </div>
                <hr class="my-4 border-secondary">
                <div class="text-center small text-muted">
                    <p class="mb-0">&copy; {{ date('Y') }} GinecoReport. Todos los derechos reservados.</p>
                </div>
            </div>
        </footer>

        @livewireScripts

        <!-- Bootstrap 5 JS Bundle -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        
        <!-- JS Personalizado Gineco -->
        <script src="{{ asset('js/gineco.js') }}"></script>

        <script>
            // Función para re-inicializar Dropdowns de Bootstrap en Livewire
            function initDropdowns() {
                const dropdownElementList = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
                dropdownElementList.map(function (dropdownToggleEl) {
                    const existing = bootstrap.Dropdown.getInstance(dropdownToggleEl);
                    if(existing) existing.dispose();
                    return new bootstrap.Dropdown(dropdownToggleEl);
                });
            }

            document.addEventListener("DOMContentLoaded", initDropdowns);

            // Re-vincular eventos al actualizar componentes Livewire (v2/v3)
            document.addEventListener("livewire:navigated", initDropdowns);
            document.addEventListener("livewire:initialized", function () {
                Livewire.hook('morph.updated', () => { initDropdowns(); });
            });
        </script>
    </body>
    </html>
</div>