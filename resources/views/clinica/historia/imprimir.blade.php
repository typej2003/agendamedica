<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $vm->titulo() }} · {{ $vm->paciente() }}</title>
    {{-- Lo imprime el navegador (PLAN-WEB.md, R6): pantalla aparte, sin el armazón de la web, como el
         listado del día de la agenda (WEB-2.7). El membrete sale del médico del contexto, no de la vista. --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-resumen-historia.css') }}">
</head>
<body>

    <div class="acciones">
        <button type="button" data-imprimir>Imprimir</button>
    </div>

    <header>
        <div class="membrete">{{ $vm->medico() }}</div>
        <h1>{{ $vm->titulo() }}</h1>
        <dl class="datos">
            <div><dt>Paciente:</dt><dd>{{ $vm->paciente() }}</dd></div>
            <div><dt>Cédula:</dt><dd>{{ $vm->cedula() }}</dd></div>
            <div><dt>Historia:</dt><dd>{{ $vm->historia() }}</dd></div>
            <div><dt>Fecha:</dt><dd>{{ $vm->fecha() }}</dd></div>
        </dl>
    </header>

    @forelse ($vm->consultas() as $consulta)
        <section class="consulta">
            <h2>
                <span>{{ $consulta['fecha'] }}</span>
                <span class="numero">{{ $consulta['numero'] }}</span>
            </h2>

            @foreach ($consulta['secciones'] as $seccion)
                <p class="seccion">
                    <span class="seccion-titulo">{{ $seccion['titulo'] }}:</span>
                    <span>{{ $seccion['texto'] }}</span>
                </p>
            @endforeach
        </section>
    @empty
        <p class="sin-datos">Este paciente no tiene consultas registradas.</p>
    @endforelse

    <script src="{{ asset('js/clinica/imprimir.js') }}"></script>
</body>
</html>
