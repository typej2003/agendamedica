<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listado del día · {{ $fecha->format('d/m/Y') }}</title>

    <style>
        /* Lo imprime el navegador (PLAN-WEB.md, R6): esta pantalla es la que va a la impresora del
           consultorio, sin el armazón de la web. */
        @page { size: portrait; margin: 1.5cm; }

        * { box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #201048;
            font-size: 12px;
            margin: 0 auto;
            max-width: 19cm;
            padding: 1.5rem 1rem;
        }

        header { border-bottom: 2px solid #783080; padding-bottom: .5rem; margin-bottom: .75rem; }
        h1 { font-size: 16px; margin: 0; }
        .sub { color: #55555f; font-size: 11px; }

        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #d8d8e0; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #f3f0f6; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
        td.num, th.num { text-align: right; width: 2.5rem; }
        td.hora { width: 4.5rem; white-space: nowrap; }
        tfoot td { border-bottom: none; padding-top: .75rem; font-weight: bold; }

        .acciones { margin-bottom: 1rem; }
        .acciones button {
            font: inherit; padding: .5rem 1rem; border: 0; border-radius: .35rem;
            background: #783080; color: #fff; cursor: pointer;
        }

        @media print {
            .acciones { display: none; }
            body { padding: 0; max-width: none; }
        }
    </style>
</head>
<body>

    <div class="acciones">
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>

    <header>
        <h1>Listado del día</h1>
        <div class="sub">
            {{ $medico->nombreMostrar }}
            @if ($sede) · {{ $sede->medicalCenter->name ?? 'Sede' }}@if ($sede->office_number) ({{ $sede->office_number }})@endif @endif
            · {{ $fecha->format('d/m/Y') }}
        </div>
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
        @forelse ($citas as $numero => $cita)
            <tr>
                <td class="num">{{ $numero + 1 }}</td>
                <td class="hora">{{ $cita->horaCorta() }}</td>
                <td>{{ $cita->paciente }}</td>
                <td>{{ $cita->cedula ?: '—' }}</td>
                <td>{{ $cita->telefono ?: '—' }}</td>
                <td>{{ $cita->razon() }}</td>
                <td>{{ $cita->atendida() ? 'Atendido' : 'Pendiente' }}</td>
            </tr>
        @empty
            <tr><td colspan="7">No hay citas para ese día y sede.</td></tr>
        @endforelse
        </tbody>
        <tfoot>
        <tr>
            <td colspan="7">{{ $citas->count() }} Pacientes para el {{ $fecha->format('d/m/Y') }}</td>
        </tr>
        </tfoot>
    </table>

</body>
</html>
