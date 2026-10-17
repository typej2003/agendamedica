{{-- La familia del niño del escritorio de pediatría (WEB-7.4.2a): los padres, el contacto de la familia y los hermanos. Sólo lectura; no existe
     para un paciente que no es un `paciente_chamo`. Sin estilos ni JS inline: usa la rejilla de datos y las tarjetas de la guía de UI. --}}
@if ($familia->cargada())
    <x-ui.tarjeta id="ficha-familia" titulo="Familia" icono="bi-people" class="mb-3">
        <x-slot name="acciones">
            @if ($familia->puedeEditar())
                <button type="button" class="btn btn-outline-primary" data-dialogo="dialogo-familia">
                    <i class="bi bi-pencil" aria-hidden="true"></i> {{ $familia->botonEditar() }}
                </button>
            @endif
        </x-slot>

        @if ($familia->conFamilia())
            @php
                $padre = $familia->padre();
                $madre = $familia->madre();
            @endphp
            <dl class="datos-rejilla">
                <x-ui.dato etiqueta="Madre" :valor="collect([$madre['nombre'], $madre['edad'] !== null ? $madre['edad'] . ' años' : null])->filter()->implode(' · ') ?: null" />
                <x-ui.dato etiqueta="Ocupación de la madre" :valor="$madre['ocupacion']" />
                <x-ui.dato etiqueta="Padre" :valor="collect([$padre['nombre'], $padre['edad'] !== null ? $padre['edad'] . ' años' : null])->filter()->implode(' · ') ?: null" />
                <x-ui.dato etiqueta="Ocupación del padre" :valor="$padre['ocupacion']" />
                <x-ui.dato etiqueta="Cédula del representante" :valor="$familia->cedulaDelRepresentante()" />
                @foreach ($familia->telefonos() as $telefono)
                    <x-ui.dato :etiqueta="$telefono['rotulo']" :valor="$telefono['numero']" />
                @endforeach
                <x-ui.dato etiqueta="Correo" :valor="$familia->correo()" />
                <x-ui.dato etiqueta="Dirección" :valor="$familia->direccion()" />
                <x-ui.dato etiqueta="Localidad" :valor="$familia->localidad()" />
            </dl>
            @if ($familia->dinamica())
                <p class="mb-1"><strong>Dinámica familiar</strong></p>
                <p class="texto-tenue">{{ $familia->dinamica() }}</p>
            @endif
            @if ($familia->notaDeLaFamilia())
                <p class="mb-1"><strong>Nota de la familia</strong></p>
                <p class="texto-tenue">{{ $familia->notaDeLaFamilia() }}</p>
            @endif
        @else
            @php $vacio = $familia->vacio(); @endphp
            <x-ui.vacio icono="bi-people" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
        @endif

        @if ($familia->hermanos() !== [])
            <h3 class="h6 mt-3">Hermanos</h3>
            <ul class="list-unstyled mb-0">
                @foreach ($familia->hermanos() as $hermano)
                    <li>
                        @if ($hermano['url'])
                            <a href="{{ $hermano['url'] }}">{{ $hermano['nombre'] }}</a>
                        @else
                            {{ $hermano['nombre'] }}
                        @endif
                        <span class="texto-tenue small">· {{ $hermano['numero'] }}@if ($hermano['edad']) · {{ $hermano['edad'] }}@endif @if ($hermano['sexo']) · {{ $hermano['sexo'] }}@endif</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.tarjeta>
@endif
