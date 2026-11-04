{{-- La cabecera de pediatría (2026-11-03), con el orden del escritorio: el paciente y la fecha en la misma línea, la
     edad con el sexo y el representante, las medidas (si esa mitad las trae) y la historia sola, alineada a la derecha.
     **Sin la cédula del paciente**: un paciente de pediatría no la lleva. La usan el récipe y los documentos; recibe
     `$mitad` y, si esa mitad imprime la dirección (la de «Rp.»), `$conDireccion`. Los altos los mide
     `MedidasDeLaMitad::altoDeLosDatos(..., $fechaConElPaciente: true)`. --}}
<div class="recipe-datos recipe-datos-pediatricos">
    <p class="recipe-datos-fila recipe-datos-fila-paciente">
        <span class="recipe-dato recipe-dato-paciente"><span class="recipe-dato-etiqueta">PACIENTE:</span> {{ $mitad['paciente'] }}</span>
        <span class="recipe-dato recipe-dato-fecha"><span class="recipe-dato-etiqueta">FECHA:</span> {{ $mitad['fecha'] }}</span>
    </p>

    {{-- La edad, el sexo y el representante: cada dato con su rótulo y sin el que no hay (no queda un «Rep.:» colgando). --}}
    @if (($mitad['edad'] ?? '') !== '' || ($mitad['sexo'] ?? null) || ($mitad['representante'] ?? null))
        <p class="recipe-dato-linea">
            @if (($mitad['edad'] ?? '') !== '')
                <span class="recipe-dato"><span class="recipe-dato-etiqueta">EDAD:</span> {{ $mitad['edad'] }}</span>
            @endif
            @if ($mitad['sexo'] ?? null)
                <span class="recipe-dato"><span class="recipe-dato-etiqueta">SEXO:</span> {{ $mitad['sexo'] }}</span>
            @endif
            @if ($mitad['representante'] ?? null)
                <span class="recipe-dato"><span class="recipe-dato-etiqueta">Rep.:</span> {{ $mitad['representante'] }}</span>
            @endif
        </p>
    @endif

    {{-- Las medidas de la consulta: sólo las que existen y no son cero (`MedidasDeLaConsulta`); sin ninguna, no sale. --}}
    @if (($mitad['medidas'] ?? []) !== [])
        <p class="recipe-dato-linea recipe-dato-medidas">
            @foreach ($mitad['medidas'] as $medida)
                <span class="recipe-dato"><span class="recipe-dato-etiqueta">{{ $medida['etiqueta'] }}</span> {{ $medida['valor'] }}</span>
            @endforeach
        </p>
    @endif

    @if ($mitad['historia'] ?? null)
        <p class="recipe-dato-linea recipe-dato-historia"><span class="recipe-dato"><span class="recipe-dato-etiqueta">Historia:</span> {{ $mitad['historia'] }}</span></p>
    @endif

    @if (($conDireccion ?? false) && ($mitad['direccion'] ?? null))
        <p class="recipe-dato recipe-dato-direccion"><span class="recipe-dato-etiqueta">DIRECCIÓN:</span> {{ $mitad['direccion'] }}</p>
    @endif
</div>
