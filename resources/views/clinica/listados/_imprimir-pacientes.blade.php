{{-- La tabla de la hoja impresa del listado de pacientes por rango (WEB-6.8): las ocho columnas de *Indicadores
     pacientes*, sin totales (el legado no los tiene). Parte de `imprimir`: el membrete y los filtros los pone él. --}}
@if ($vm->estaVacio())
    <p class="sin-datos">No hay pacientes que cumplan estos filtros.</p>
@else
    <table>
        <thead>
        <tr>
            <th># Hist.</th>
            <th class="izquierda">Paciente</th>
            <th>Cédula</th>
            <th>Edad</th>
            <th>E. civil</th>
            <th>Sexo</th>
            <th>F. ingreso</th>
            <th class="izquierda">Empresa</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($vm->filas() as $fila)
            <tr>
                <td class="centro">{{ $fila['historia'] ?? '—' }}</td>
                <td>{{ $fila['nombre'] }}</td>
                <td class="centro">{{ $fila['cedula'] ?? '—' }}</td>
                <td class="centro">{{ $fila['edad'] ?? '—' }}</td>
                <td class="centro">{{ $fila['ecivil'] ?? '—' }}</td>
                <td class="centro">{{ $fila['sexo'] ?? '—' }}</td>
                <td class="centro">{{ $fila['fingreso'] }}</td>
                <td>{{ $fila['empresa'] ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <p class="total">{{ $vm->total() }} {{ $vm->total() === 1 ? 'paciente' : 'pacientes' }}.</p>
@endif
