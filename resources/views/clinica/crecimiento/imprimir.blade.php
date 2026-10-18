<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crecimiento · {{ $vm->nombre() }}</title>
    {{-- Lo imprime el navegador (PLAN-WEB.md, R6): pantalla aparte, sin el armazón de la web, como el resumen de la historia. --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-crecimiento.css') }}">
    <link rel="stylesheet" href="{{ asset('css/clinica-crecimiento-impresion.css') }}">
</head>
<body>

    <div class="acciones">
        <button type="button" data-imprimir>Imprimir</button>
    </div>

    <header>
        <div class="membrete">{{ $medico }}</div>
        <h1>Curvas de crecimiento</h1>
        <dl class="datos">
            <div><dt>Paciente:</dt><dd>{{ $vm->nombre() }}</dd></div>
            <div><dt>Datos:</dt><dd>{{ $vm->subtitulo() }}</dd></div>
            <div><dt>Edades:</dt><dd>{{ $vm->textoDelJuego() }}</dd></div>
            <div><dt>Fecha:</dt><dd>{{ $vm->fechaDeHoy() }}</dd></div>
        </dl>
    </header>

    @if ($vm->avisoDeReferencia())
        <p class="aviso">{{ $vm->avisoDeReferencia()['titulo'] }}. {{ $vm->avisoDeReferencia()['texto'] }}</p>
    @endif

    @foreach ($vm->todas() as $clave => $grafica)
        <section class="grafica-bloque">
            <h2>{{ $grafica->titulo() }}</h2>
            @include('clinica.crecimiento._grafica', ['grafica' => $grafica, 'id' => 'g-' . $clave])
        </section>
    @endforeach

    @if ($vm->hayMediciones())
        <section class="tabla-bloque">
            <h2>Mediciones</h2>
            @include('clinica.crecimiento._tabla')
        </section>
    @else
        <p class="sin-datos">Este niño no tiene mediciones registradas.</p>
    @endif

    <script src="{{ asset('js/clinica/imprimir.js') }}"></script>
</body>
</html>
