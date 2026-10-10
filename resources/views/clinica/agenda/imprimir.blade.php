<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listado del día · {{ $vm->fecha() }}</title>
    {{-- Lo imprime el navegador (PLAN-WEB.md, R6): esta pantalla es la que va a la impresora del
         consultorio, sin el armazón de la web. --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-imprimir.css') }}">
</head>
<body>

    <div class="acciones">
        <button type="button" data-imprimir>Imprimir</button>
    </div>

    <header>
        <h1>Listado del día</h1>
        <div class="sub">{{ $vm->subtitulo() }}</div>
    </header>

    <table>
        <thead>
        <tr>
            <th class="num">#</th>
            <th class="hora">Hora</th>
            <th>Paciente</th>
            <th>Cédula</th>
            <th>Teléfono</th>
            <th>Descripción</th>
            <th>Estatus</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($vm->filas() as $fila)
            <tr>
                <td class="num">{{ $fila['numero'] }}</td>
                <td class="hora">{{ $fila['hora'] }}</td>
                <td>{{ $fila['paciente'] }}</td>
                <td>{{ $fila['cedula'] }}</td>
                <td>{{ $fila['telefono'] }}</td>
                <td>{{ $fila['razon'] }}</td>
                <td>{{ $fila['estatus'] }}</td>
            </tr>
        @empty
            <tr><td colspan="7">No hay citas para ese día y sede.</td></tr>
        @endforelse
        </tbody>
        <tfoot>
        <tr>
            <td colspan="7">{{ $vm->total() }}</td>
        </tr>
        </tfoot>
    </table>

    <script src="{{ asset('js/clinica/imprimir.js') }}"></script>
</body>
</html>
