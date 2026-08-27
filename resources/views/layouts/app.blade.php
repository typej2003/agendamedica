<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <title>@yield('title', 'Agenda Médica - Bienvenidos')</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    @livewireStyles
    <style>
        :root {
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 80px;
            --navbar-height: 80px; /* Sincronizado con el alto de tu navbar */
            --purple-wifiexpres: #6500da;
            --orange-wifiexpres: #ff572f;
        }

        body { 
            background-color: #f8f9fa; 
            padding-top: var(--navbar-height); 
            overflow-x: hidden;
        }

        #wrapper {
            display: flex;
            min-height: calc(100vh - var(--navbar-height));
        }

        /* Sidebar y Contenido */
        #sidebarMenu {
            width: var(--sidebar-width);
            background: white;
            border-right: 1px solid #dee2e6;
            transition: width 0.3s ease-in-out;
            z-index: 1000;
            position: sticky;
            top: var(--navbar-height); /* Lo mantiene fijo debajo del navbar */
            height: calc(100vh - var(--navbar-height)); /* Ocupa el alto restante */
            overflow-y: auto; /* Muestra scrollbar vertical solo si es necesario */
        }

        #sidebarMenu:has(.minimized) {
            width: var(--sidebar-collapsed-width);
        }

        .main-content {
            flex: 1;
            padding: 0px;
            min-width: 0; 
        }

        /* Estilos personalizados para el Dropdown */
        .custom-dropdown {
            border-top: 4px solid var(--purple-wifiexpres) !important;
            border-radius: 8px !important;
            margin-top: 15px !important;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1) !important;
        }

        .custom-dropdown .dropdown-item:hover {
            background-color: var(--orange-wifiexpres) !important;
            color: white !important;
        }

        .text-purple { color: var(--purple-wifiexpres) !important; }

        /* Estilos del nuevo Sidebar */
        .sidebar-nav .nav-link {
            display: flex;
            align-items: center;
            font-size: 1rem;
            color: #495057;
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            margin-bottom: 0.25rem;
            transition: background-color 0.2s, color 0.2s;
        }
        .sidebar-nav .nav-link:hover {
            background-color: #f0e6ff;
            color: var(--purple-wifiexpres);
        }
        .sidebar-nav .nav-link.active {
            background-color: var(--purple-wifiexpres);
            color: white;
            font-weight: 500;
        }
        .sidebar-nav .nav-link i {
            font-size: 1.25rem;
            width: 30px;
            text-align: center;
            margin-right: 1rem;
            transition: margin 0.3s ease-in-out;
        }
        .sidebar-nav .nav-header {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: #adb5bd;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        /* Estilos para el modo minimizado */
        #sidebarMenu:has(.minimized) .sidebar-nav .link-text,
        #sidebarMenu:has(.minimized) .nav-header {
            display: none;
        }
        #sidebarMenu:has(.minimized) .sidebar-nav .nav-link i {
            margin-right: 0;
        }

        @media (max-width: 991.98px) {
            #sidebarMenu {
                position: fixed;
                left: -100%; 
                top: var(--navbar-height);
                width: 280px !important;
                height: 100%;
            }
            #sidebarMenu.show { left: 0; }
        }
    </style>
</head>
<body>

    @auth
        @livewire('layouts.navbar')
    @else
        @livewire('layouts.navbar')
    @endauth

    <div id="wrapper">
        @auth
            <div id="sidebarMenu">
                @livewire('layouts.aside')
            </div>
        @endauth

        <main class="main-content">
            {{-- $slot es para componentes Livewire de página completa, @yield es para vistas Blade --}}
            {{ $slot ?? '' }} @yield('content')
        </main>
    </div>

    @livewire('layouts.footer')

    @livewireScripts
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Función para inicializar dropdowns
        function initDropdowns() {
            const dropdownElementList = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
            dropdownElementList.map(function (dropdownToggleEl) {
                // Limpiar instancia previa para evitar que se bloquee
                const existing = bootstrap.Dropdown.getInstance(dropdownToggleEl);
                if(existing) existing.dispose();
                return new bootstrap.Dropdown(dropdownToggleEl);
            });
        }

        // Ejecución inicial
        document.addEventListener("DOMContentLoaded", initDropdowns);

        // Gancho para Livewire 2: Reinicia tras cada actualización
        document.addEventListener("livewire:load", function() {
            window.livewire.hook('message.processed', () => {
                initDropdowns();
            });
        });

        // Lógica Sidebar
        window.addEventListener('toggleSidebar', () => {
            const sidebar = document.getElementById('sidebarMenu');
            if(sidebar) sidebar.classList.toggle('show');
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    @livewireScripts
    @stack('js') {{-- ESTA LÍNEA ES VITAL --}}
</body>
</body>
</html>