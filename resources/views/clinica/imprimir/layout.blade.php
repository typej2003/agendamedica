<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('titulo') · Doctorísimo</title>
    {{-- La página de impresión de los documentos de la web (WEB-5.2, PLAN-WEB.md R6): la imprime el navegador, no
         el servidor. Es una pantalla aparte, sin el armazón de la web, como el listado del día de la agenda y el
         resumen de la historia. La barra de arriba (`.barra-impresion`) no se imprime; lo que sigue es el
         documento. Cada documento aporta su hoja de estilos (`@yield('estilos')`) con su `@page`. --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-impresion.css') }}">
    @yield('estilos')
</head>
<body>

    {{-- La hoja de prueba incrustada en la pantalla del formato (WEB-8.2) no define barra: sin ella tampoco se pinta el contenedor. --}}
    @hasSection('barra')
        <div class="barra-impresion">
            @yield('barra')
        </div>
    @endif

    @yield('contenido')

    @yield('dialogos')

    @yield('scripts')
</body>
</html>
