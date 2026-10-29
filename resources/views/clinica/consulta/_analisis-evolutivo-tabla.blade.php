{{-- **Análisis evolutivo** (MI-4): los valores de cada consulta de la historia, con una línea por valor. Es una lectura: los
     valores se cargan en *Evaluación física → Signos vitales* y en *Plan de diagnóstico → Laboratorio*. --}}
@if (! $seccion->tieneEvolucion())
    <p class="text-muted mb-0" id="analisis-vacio">
        Esta historia todavía no tiene valores para comparar. Se cargan en Signos vitales y en Plan de diagnóstico → Laboratorio.
    </p>
@else
    <div class="table-responsive">
        <table class="table table-sm align-middle tabla-evolucion" id="analisis-tabla">
            <thead>
                <tr>
                    <th scope="col">Valor</th>
                    @foreach ($seccion->consultasDeLaEvolucion() as $columna)
                        <th scope="col" class="text-end">N.º {{ $columna['nro'] }}<br><span class="texto-tenue small fw-normal">{{ $columna['fecha'] }}</span></th>
                    @endforeach
                    <th scope="col">Evolución</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($seccion->series() as $serie)
                    <tr>
                        <th scope="row">
                            {{ $serie['etiqueta'] }}
                            @if ($serie['unidad'])<span class="texto-tenue small fw-normal">({{ $serie['unidad'] }})</span>@endif
                        </th>
                        @foreach ($serie['celdas'] as $celda)
                            <td class="text-end">{{ $celda }}</td>
                        @endforeach
                        <td>
                            <svg class="grafica-evolucion" viewBox="{{ $seccion->viewBox() }}" role="img"
                                 aria-label="Evolución de {{ $serie['etiqueta'] }}: último valor {{ $serie['ultimo'] }}">
                                @if (count($serie['marcas']) > 1)
                                    <polyline points="{{ $serie['puntos'] }}"></polyline>
                                @endif
                                @foreach ($serie['marcas'] as $marca)
                                    <circle cx="{{ $marca['x'] }}" cy="{{ $marca['y'] }}" r="3"></circle>
                                @endforeach
                            </svg>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
