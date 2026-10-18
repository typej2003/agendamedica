{{-- La tabla de la hoja impresa del listado de consultas por rango (WEB-6.8): una fila por consulta, con sus motivos,
     diagnósticos y medicamentos uno por renglón. Parte de `imprimir`: el membrete y los filtros los pone él. --}}
{{-- Con seis o siete columnas de texto la hoja cabe mejor acostada. --}}
<style>@page { size: landscape; margin: 1.5cm; }</style>

@if ($vm->estaVacio())
    <p class="sin-datos">No hay consultas que cumplan estos filtros.</p>
@else
    @php $listas = $vm->columnasDeListas(); @endphp
    <table>
        <thead>
        <tr>
            <th># Hist.</th>
            <th class="izquierda">Paciente</th>
            <th>Edad</th>
            <th>Fecha</th>
            @foreach ($listas as $etiqueta)
                <th class="izquierda">{{ $etiqueta }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach ($vm->filas() as $fila)
            <tr>
                <td class="centro">{{ $fila['historia'] ?? '—' }}</td>
                <td>{{ $fila['nombre'] }}</td>
                <td class="centro">{{ $fila['edad'] ?? '—' }}</td>
                <td class="centro">{{ $fila['fecha'] }}</td>
                @foreach ($listas as $clave => $etiqueta)
                    <td>
                        @forelse ($fila[$clave] as $texto)
                            <div>{{ $texto }}</div>
                        @empty
                            —
                        @endforelse
                    </td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>

    <p class="total">{{ $vm->total() }} {{ $vm->total() === 1 ? 'consulta' : 'consultas' }}.</p>
@endif
