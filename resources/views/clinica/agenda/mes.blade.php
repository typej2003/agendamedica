{{-- El mes: el calendario con el conteo de citas de cada día. Recibe `$cuerpo` (MesVista). --}}
<x-ui.tarjeta>
    <x-ui.tabla sin-tarjetas class="table-bordered calendario-mes">
        <thead>
        <tr>
            @foreach ($cuerpo->cabeceras() as $etiqueta)
                <th class="text-center">{{ $etiqueta }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach ($cuerpo->semanas() as $semana)
            <tr>
                @foreach ($semana as $dia)
                    <td class="calendario-celda {{ $dia['enMes'] ? '' : 'fuera-del-mes' }} {{ $dia['noLaborable'] ? 'no-laborable' : '' }}">
                        <a class="calendario-dia {{ $dia['esHoy'] ? 'es-hoy' : '' }}" href="{{ $dia['url'] }}"
                           aria-label="{{ $dia['numero'] }}: {{ $dia['conteoTexto'] }}">
                            <span class="calendario-numero">{{ $dia['numero'] }}</span>
                            @if ($dia['conteo'] > 0)
                                <span class="calendario-conteo">{{ $dia['conteo'] }}</span>
                            @endif
                        </a>
                        {{-- Día no laborable (WEB-2.8): se marca aunque no tenga citas. --}}
                        @if ($dia['noLaborable'])
                            <x-ui.estado tono="aviso" icono="bi-calendar-x" class="calendario-marca"
                                         :title="$dia['noLaborable']['titulo']">{{ $dia['noLaborable']['texto'] }}</x-ui.estado>
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </x-ui.tabla>
</x-ui.tarjeta>
