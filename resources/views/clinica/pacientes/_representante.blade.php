{{-- El bloque **«Representante»** de la ficha (WEB-3.4): el adulto que responde por el paciente, con su parentesco y su
     contacto. Lo usan la ficha y la sección «Datos del paciente» de la consulta (2026-11-03), y el diálogo que lo edita es
     `_dialogo-representante`. Recibe `$rep` (App\Clinica\Pacientes\Vistas\RepresentanteDePacienteVista). --}}
<x-ui.tarjeta id="ficha-representante" titulo="Representante" icono="bi-person-hearts" class="mb-3">
    <x-slot name="acciones">
        @if ($rep->puedeEditar())
            <button type="button" class="btn btn-outline-primary" data-dialogo="dialogo-representante">
                <i class="bi bi-pencil" aria-hidden="true"></i> {{ $rep->botonEditar() }}
            </button>
        @endif
    </x-slot>

    @if ($rep->cargado())
        <div class="representante-datos">
            <div class="representante-cabeza">
                <span class="representante-nombre">{{ $rep->nombre() ?? 'Representante sin nombre' }}</span>
                @if ($rep->parentesco())
                    <x-ui.estado tono="neutro" icono="bi-people">{{ $rep->parentesco() }}</x-ui.estado>
                @endif
            </div>
            <dl class="datos-rejilla">
                <x-ui.dato etiqueta="Cédula" :valor="$rep->cedula()" />
                <x-ui.dato etiqueta="Teléfono" :valor="$rep->telefono()" />
                <x-ui.dato etiqueta="Correo" :valor="$rep->correo()" />
                <x-ui.dato etiqueta="Dirección" :valor="$rep->direccion()" />
            </dl>

            {{-- Un representante puede tener varios hijos: los otros pacientes del consultorio que comparten su
                 cédula. La lista la resuelve el controlador con `RepresentantePorCedula`; si no hay ninguno, no se pinta. --}}
            @if ($rep->hayOtrosHijos())
                <h3 class="h6 mt-3">También es representante de:</h3>
                <ul class="list-unstyled mb-0">
                    @foreach ($rep->otrosHijos() as $otroHijo)
                        <li>
                            @if ($otroHijo['url'])
                                <a href="{{ $otroHijo['url'] }}">{{ $otroHijo['nombre'] }}</a>
                            @else
                                {{ $otroHijo['nombre'] }}
                            @endif
                            @if ($otroHijo['historia'])
                                <span class="texto-tenue small">· H. {{ $otroHijo['historia'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @else
        @php $sinRepresentante = $rep->vacio(); @endphp
        <x-ui.vacio icono="bi-person-hearts" :titulo="$sinRepresentante['titulo']">
            {{ $sinRepresentante['texto'] }}
        </x-ui.vacio>
    @endif
</x-ui.tarjeta>
