<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $vm->titulo() }} · {{ $fecha }}</title>
    {{-- La hoja la imprime el navegador (PLAN-WEB.md, R6), como el resumen de historia clínica de WEB-3.5:
         pantalla aparte, sin el armazón de la web, con su propio CSS y su `@page`. El membrete sale del médico
         del contexto, no de la vista. --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-listado.css') }}">
</head>
<body>

    <div class="acciones">
        <button type="button" data-imprimir>Imprimir</button>
    </div>

    <header>
        <div class="membrete">{{ $medico }}</div>
        <h1>{{ $vm->titulo() }}</h1>
        {{-- Un listado sin su rango no dice nada en papel: los filtros con los que se armó van en la cabecera.
             El legado no los imprime (su DataWindow sólo llevaba los títulos de las columnas). --}}
        <dl class="datos">
            @foreach ($vm->descripcionDeLosFiltros() as $filtro)
                <div><dt>{{ $filtro['etiqueta'] }}:</dt><dd>{{ $filtro['valor'] }}</dd></div>
            @endforeach
            <div><dt>Listado:</dt><dd>{{ $fecha }}</dd></div>
        </dl>
    </header>

    @if ($vm->claveDelListado() === \App\Clinica\Listados\ListadosDisponibles::PACIENTES)
        @include('clinica.listados._imprimir-pacientes')
    @elseif ($vm->claveDelListado() === \App\Clinica\Listados\ListadosDisponibles::CONSULTAS)
        @include('clinica.listados._imprimir-consultas')
    @elseif ($vm->estaVacio())
        <p class="sin-datos">No hay embarazos que cumplan estos filtros.</p>
    @else
        <table>
            <thead>
            <tr>
                <th class="izquierda">Paciente</th>
                <th>F. ingreso</th>
                <th>Edad</th>
                <th>F.U.R.</th>
                <th>F.P.P.</th>
                <th class="izquierda">Procedencia</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($vm->filas() as $fila)
                <tr>
                    <td>{{ $fila['nombre'] }}</td>
                    <td class="centro">{{ $fila['fingreso'] }}</td>
                    <td class="centro">{{ $fila['edad'] ?? '—' }}</td>
                    <td class="centro">{{ $fila['fur'] }}</td>
                    <td class="centro">{{ $fila['fpp'] }}</td>
                    <td>{{ $fila['procedencia'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <p class="total">{{ $vm->total() }} {{ $vm->total() === 1 ? 'gesta' : 'gestas' }}.</p>
    @endif

    <script src="{{ asset('js/clinica/imprimir.js') }}"></script>
</body>
</html>
