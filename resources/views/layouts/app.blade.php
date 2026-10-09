<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <title>@yield('title', 'Doctorísimo')</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    {{-- La paleta vive en `gineco.css` y el sistema de la aplicación (Bootstrap recoloreado + armazón) en
         `doctorisimo-ui.css`; el orden importa, el sistema va después de Bootstrap. --}}
    <link rel="stylesheet" href="{{ asset('css/gineco.css') }}">
    <link rel="stylesheet" href="{{ asset('css/doctorisimo-ui.css') }}">

    @livewireStyles
    <style>
        :root {
            --navbar-height: 62px;
        }

        body {
            background-color: var(--ds-fondo);
            padding-top: var(--navbar-height);
            overflow-x: hidden;
        }

        /* La barra superior del sitio público (sin sesión) es más alta: tiene el menú de secciones. */
        body.sitio-publico { --navbar-height: 76px; }

        #wrapper { display: flex; min-height: calc(100vh - var(--navbar-height)); }

        .main-content { flex: 1; padding: 0; min-width: 0; }

        @media (min-width: 992px) {
            #sidebarMenu { min-height: calc(100vh - var(--navbar-height)); }
        }
    </style>
</head>
<body class="@auth panel @else sitio-publico @endauth">

    @livewire('layouts.navbar')

    <div id="wrapper">
        @auth
            <div class="app-sidebar-fondo" id="panel-sidebar-fondo" hidden></div>
            @livewire('layouts.aside')
        @endauth

        <main class="main-content">
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>

    @livewire('layouts.footer')

    @livewireScripts

    <!-- Bootstrap 5 JS Bundle (Debe ir DESPUÉS de Livewire Scripts) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="{{ asset('js/gineco.js') }}"></script>

    <script>
        // Cerrar sesión pide confirmación: un toque por error ya no saca a nadie (lo usan el panel lateral y el
        // menú del usuario). Sin SweetAlert (si el CDN no cargó) cae al confirm() del navegador.
        function confirmarCierreSesion(evento, formulario) {
            evento.preventDefault();
            if (!formulario) return false;
            if (typeof Swal === 'undefined') {
                if (window.confirm('¿Cerrar sesión?')) formulario.submit();
                return false;
            }
            Swal.fire({
                title: '¿Cerrar sesión?',
                text: 'Tendrás que iniciar sesión de nuevo para volver a entrar.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, cerrar sesión',
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
                focusCancel: true,
                confirmButtonColor: '#783080',
                cancelButtonColor: '#6b6580',
            }).then(function (resultado) {
                if (resultado.isConfirmed) formulario.submit();
            });
            return false;
        }

        function initDropdowns() {
            const dropdownElementList = document.querySelectorAll('[data-bs-toggle="dropdown"]');
            dropdownElementList.forEach(dropdownToggleEl => {
                const existing = bootstrap.Dropdown.getInstance(dropdownToggleEl);
                if (existing) {
                    existing.dispose();
                }
                new bootstrap.Dropdown(dropdownToggleEl);
            });
        }

        document.addEventListener("DOMContentLoaded", initDropdowns);

        // Soporte tanto para Livewire v2 como v3
        document.addEventListener("livewire:load", function() {
            if (window.livewire) {
                window.livewire.hook('message.processed', () => initDropdowns());
            }
        });
        document.addEventListener("livewire:initialized", function () {
            if (typeof Livewire !== 'undefined') {
                Livewire.hook('morph.updated', () => initDropdowns());
            }
        });

        // Menú lateral del panel en pantallas chicas: se desliza sobre el contenido.
        (function () {
            const menu = document.getElementById('sidebarMenu');
            const fondo = document.getElementById('panel-sidebar-fondo');

            function abrir(estado) {
                if (!menu) return;
                menu.classList.toggle('abierto', estado);
                if (fondo) fondo.hidden = !estado;
                const boton = document.querySelector('[data-toggle-sidebar]');
                if (boton) boton.setAttribute('aria-expanded', estado ? 'true' : 'false');
            }

            window.addEventListener('toggleSidebar', () => abrir(!(menu && menu.classList.contains('abierto'))));

            if (fondo) fondo.addEventListener('click', () => abrir(false));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') abrir(false); });
        })();
    </script>

    @stack('js')
</body>
</html>
