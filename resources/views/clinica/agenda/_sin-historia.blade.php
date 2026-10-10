{{-- Pacientes sin historia a atender (WEB-2.11): el panel que el escritorio tiene al lado del listado de la
     agenda (`dw_3` de `w_hacer_cita`, `:807-819`). Lista las citas del día que se está mirando cuyo
     paciente todavía no tiene número de historia, y desde cada fila se le asigna con "Pasar a cita
     formal" —la acción del `pb_3` (`:801-805`) → `w_pasar_pacientes_n_reg_consulta_cita`, que es la
     Action compartida: el número lo asigna el servidor y la cita queda enlazada.

     El panel no bloquea nada y se ve siempre: vacío es la buena noticia del día. --}}
<x-ui.tarjeta id="panel-sin-historia" :titulo="$sinHistoria->titulo()" icono="bi-journal-medical" class="mb-3">
    <x-slot name="acciones">
        <span class="texto-tenue small">{{ $sinHistoria->conteo() }}</span>
    </x-slot>

    @if ($sinHistoria->estaVacio())
        @php $vacio = $sinHistoria->vacio(); @endphp
        <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
    @else
        <p class="form-text">{{ $sinHistoria->subtitulo() }}</p>

        <x-ui.tabla>
            <thead>
            <tr>
                <th class="col-hora">Hora</th>
                <th>Paciente</th>
                <th class="text-end">Acciones</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($sinHistoria->filas() as $fila)
                <tr>
                    <td class="celda-hora" data-etiqueta="Hora">{{ $fila->hora() }}</td>
                    <td class="celda-principal" data-etiqueta="Paciente">
                        {{-- Sin pastilla de estado: el panel **es** "pacientes sin historia", decirlo en
                             cada fila sería el mismo dato dos veces (guía de UI §1). --}}
                        <x-ui.identidad :nombre="$fila->paciente()" :detalle="$fila->razon()"
                                        :href="$fila->urlPaciente()" tamano="sm" />
                    </td>
                    <td class="celda-acciones text-end" data-etiqueta="Acciones">
                        {{-- La acción tiene efecto permanente (el número de historia no se puede
                             deshacer desde la web) y no se puede adelantar cuál va a ser: el número lo
                             calcula el servidor al asignarlo. Por eso se confirma **antes** nombrando
                             el efecto, y el número se informa **después**, en el mensaje de éxito. --}}
                        <form method="POST" action="{{ $fila->urlPasarACitaFormal() }}"
                              data-confirmar="{{ $fila->confirmar() }}">
                            @csrf
                            <button class="btn btn-sm btn-primary">
                                <i class="bi bi-journal-medical" aria-hidden="true"></i> Pasar a cita formal
                            </button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-ui.tabla>
    @endif
</x-ui.tarjeta>
