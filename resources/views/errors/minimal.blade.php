{{-- Diseño común de las páginas de error (403, 404, 419, 429, 500, 503). Reemplaza el `errors::minimal` de
     Laravel, que sale en inglés, sin marca, sin salida y pide una tipografía a Google (sin internet queda a
     medias, y una página de error se ve justo cuando algo anda mal). Acá todo es local: los colores salen de
     `doctorisimo-ui.css` (con valores de respaldo por si no cargara) y la tipografía es la del sistema.
     Cada página define: `title`, `code`, `mensaje` (el encabezado) y `detalle` (una línea que dice qué hacer). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Doctorísimo</title>
    @include('layouts._favicon')
    <link rel="stylesheet" href="{{ asset('css/doctorisimo-ui.css') }}?v={{ @filemtime(public_path('css/doctorisimo-ui.css')) }}">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--ds-texto, #201048);
            background: linear-gradient(160deg, var(--ds-violeta-50, #f6eef7) 0%, var(--ds-fondo, #f5f4f9) 55%, var(--ds-celeste-50, #e8f4f8) 100%);
        }
        .error-tarjeta {
            width: 100%;
            max-width: 460px;
            padding: 2rem 1.75rem;
            text-align: center;
            background: var(--ds-superficie, #fff);
            border: 1px solid var(--ds-borde, #e3dfee);
            border-radius: var(--ds-radio-lg, 14px);
            box-shadow: var(--ds-sombra-3, 0 10px 30px rgba(32, 16, 72, .12));
        }
        .error-marca { font-weight: 700; font-size: 1.15rem; color: var(--ds-tinta, #1a0b3d); }
        .error-codigo { margin: 1rem 0 .25rem; font-size: 3.25rem; font-weight: 800; line-height: 1; color: var(--ds-violeta-700, #7b2f80); }
        .error-mensaje { margin: 0 0 .5rem; font-size: 1.25rem; font-weight: 700; color: var(--ds-tinta, #1a0b3d); }
        .error-detalle { margin: 0 0 1.5rem; color: var(--ds-texto-2, #5a5170); line-height: 1.5; }
        .error-salida {
            display: inline-block;
            padding: .6rem 1.25rem;
            border-radius: 999px;
            background: var(--ds-violeta-700, #7b2f80);
            color: #fff;
            font-weight: 600;
            text-decoration: none;
        }
        .error-salida:hover, .error-salida:focus-visible { background: var(--ds-violeta-800, #66256b); }
        .error-salida:focus-visible { outline: 3px solid var(--ds-celeste-700, #0f6e8c); outline-offset: 2px; }
    </style>
</head>
<body>
    <main class="error-tarjeta">
        <div class="error-marca">Doctorísimo</div>
        <p class="error-codigo" aria-hidden="true">@yield('code')</p>
        <h1 class="error-mensaje">@yield('mensaje')</h1>
        <p class="error-detalle">@yield('detalle')</p>
        <a class="error-salida" href="{{ url('/') }}">Volver al inicio</a>
    </main>
</body>
</html>
