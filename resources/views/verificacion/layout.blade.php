<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Página pública (WEB-5.6): que ningún buscador la indexe (además de la cabecera `X-Robots-Tag`). --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>@yield('titulo') · Doctorisimo.app</title>
    @hasSection('descripcion')
    <meta name="description" content="@yield('descripcion')">
    @endif
    @include('layouts._favicon')
    {{-- Sólo lo propio: los tokens y la tipografía de `gineco.css` y la hoja de esta página. Sin scripts ni CDN. --}}
    <link rel="stylesheet" href="{{ asset('css/gineco.css') }}">
    <link rel="stylesheet" href="{{ asset('css/verificacion.css') }}">
</head>
<body class="verif-cuerpo">
    <header class="verif-marca">
        <div class="verif-marca-interior">
            <span class="verif-marca-nombre">Doctorisimo<span class="verif-marca-punto">.app</span></span>
            <span class="verif-marca-titulo">Verificación de récipe</span>
        </div>
    </header>

    <main class="verif-pagina">
        @yield('contenido')
    </main>

    <footer class="verif-pie">
        <p>Doctorisimo.app — verificación de récipes emitidos desde la plataforma.</p>
    </footer>
</body>
</html>
