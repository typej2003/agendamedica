{{-- La tabla de mediciones del niño con dónde cae cada una respecto a las curvas (WEB-7.4.3c; el «percentiles totales» del escritorio).
     Variable: `$vm` (CrecimientoVista). La usan la pantalla y la hoja impresa. --}}
<div class="table-responsive">
    <table class="table table-sm align-middle crecimiento-tabla">
        <thead>
            <tr>
                @foreach ($vm->columnas() as $columna)
                    <th scope="col">{{ $columna }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($vm->filas() as $fila)
                <tr>
                    <th scope="row" class="fw-normal">{{ $fila['fecha'] }}</th>
                    <td>{{ $fila['edad'] }}</td>
                    @foreach ($fila['celdas'] as $celda)
                        <td>
                            {{ $celda['valor'] }}
                            @if ($celda['posicion'])
                                <span class="d-block small {{ $celda['fuera'] ? 'text-danger' : 'texto-tenue' }}">{{ $celda['posicion'] }}</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
