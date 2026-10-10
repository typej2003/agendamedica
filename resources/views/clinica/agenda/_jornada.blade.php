{{-- Una jornada (una sede en un día): su cabecera con la modalidad, el horario y el cupo, y la lista de citas.
     Recibe `$jornada` (JornadaVista). --}}
@php
    $modalidad = $jornada->modalidad();
    $reordenable = $jornada->puedeReordenar();
@endphp

<x-ui.tarjeta sin-relleno class="jornada mb-3">
    <div class="jornada-cabecera">
        <div class="jornada-titulo">
            <strong class="jornada-sede"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $jornada->sede() }}</strong>
            <x-ui.estado :tono="$modalidad['tono']" :icono="$modalidad['icono']">{{ $modalidad['texto'] }}</x-ui.estado>
            <span class="texto-tenue small"><i class="bi bi-clock" aria-hidden="true"></i> {{ $jornada->horario() }}</span>
        </div>

        <div class="jornada-resumen">
            <span class="jornada-dato">{{ $jornada->cantidadTexto() }}</span>
            <span class="jornada-dato jornada-cupo">
                @if ($jornada->tieneCupo())
                    <progress class="cupo-barra {{ $jornada->llena() ? 'cupo-lleno' : '' }}" max="100"
                              value="{{ $jornada->cupoPorcentaje() }}" aria-hidden="true"></progress>
                @endif
                <span>{{ $jornada->cupoTexto() }}</span>
                @if ($jornada->llena())
                    <x-ui.estado tono="aviso" icono="bi-exclamation-triangle">Cupo completo</x-ui.estado>
                @endif
            </span>
            <span class="jornada-dato">próximo {{ $jornada->proximo() }}</span>
            @if ($jornada->movidasTexto())
                <span class="jornada-dato texto-tenue">{{ $jornada->movidasTexto() }}</span>
            @endif
            <a class="btn btn-sm btn-outline-primary" href="{{ $jornada->urlAgendar() }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Agendar
            </a>
        </div>
    </div>

    <x-ui.tabla class="tabla-agenda">
        <thead>
        <tr>
            @if ($reordenable)
                <th class="col-estrecha"><span class="visually-hidden">Arrastrar</span></th>
            @endif
            <th class="col-numero">Turno</th>
            <th class="col-hora">Hora</th>
            <th>Paciente</th>
            <th>Estado</th>
            <th class="text-end">Acciones</th>
        </tr>
        </thead>
        <tbody @if ($reordenable)
                   class="lista-cola" data-fecha="{{ $jornada->fecha() }}"
                   data-reordenar="{{ $jornada->urlReordenar() }}" data-csrf="{{ csrf_token() }}"
               @endif>
        @foreach ($jornada->filas() as $fila)
            @include('clinica.agenda._fila', ['fila' => $fila, 'reordenable' => $reordenable])
        @endforeach
        </tbody>
    </x-ui.tabla>
</x-ui.tarjeta>
