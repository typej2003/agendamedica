{{-- El buscador de pacientes es **de la vista de día**, que es la pantalla del mostrador: es el listado que
     el escritorio tiene al lado de la agenda (`dw_2` = `d_pacientes_buscar_consulta_cita` en
     `w_hacer_cita`), con "Hacer cita" sobre la fila elegida (WEB-2.14). El script es
     `public/js/clinica/agenda-pacientes.js`. --}}
<x-ui.tarjeta titulo="Pacientes de la agenda" icono="bi-person-lines-fill" class="mb-3">
    <div class="position-relative" id="agenda-paciente-busqueda"
         data-url="{{ $cuerpo->urlBuscar() }}" data-nueva="{{ $cuerpo->urlNueva() }}">
        <x-ui.buscador-vivo id="agenda-paciente-texto" resultados="agenda-paciente-resultados"
                            placeholder="Apellido, nombre, cédula o n.º de historia"
                            etiqueta="Buscar paciente para hacerle una cita" />
    </div>
    <p class="form-text mb-0" id="agenda-paciente-ayuda">
        Elige «Hacer cita» en el paciente para agendarle sin pasar por Pacientes.
    </p>

    {{-- Cómo se ve cada resultado: el script lo clona y le pone el paciente. --}}
    <template id="agenda-paciente-plantilla">
        <div class="resultado-paciente">
            <span class="resultado-paciente-datos"></span>
            <a class="btn btn-sm btn-primary" href="#"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Hacer cita</a>
        </div>
    </template>
</x-ui.tarjeta>

@if ($cuerpo->estaVacio())
    @php $vacio = $cuerpo->vacio(); @endphp
    <x-ui.tarjeta>
        <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
    </x-ui.tarjeta>
@else
    @foreach ($cuerpo->jornadas() as $jornada)
        @include('clinica.agenda._jornada', ['jornada' => $jornada])
    @endforeach
@endif
