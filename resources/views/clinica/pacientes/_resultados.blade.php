{{--
    La **región de resultados** del listado de pacientes: la nota de la corrección, la tabla (o el vacío) y el pie
    con el rango y el paginador. Es lo que reemplaza la búsqueda en vivo (`public/js/clinica/buscar-auto.js`), así
    que también se devuelve sola —sin armazón, menú ni barra de herramientas— cuando la petición es AJAX: el
    servidor no renderiza la pantalla entera para tirar la mitad (ver `PacienteController::index`).

    El segmentado "Todos / Sin historia / Con historia" queda **fuera** de esta región: sus conteos no viajan en la
    respuesta en vivo (y por eso el controlador no los calcula en ese camino).
--}}
<div data-buscar-resultados>
    {{-- La corrección de errores de tecleo (PecleSearch) se avisa y se puede deshacer: el listado busca
         con el término corregido y este aviso ofrece buscar lo escrito tal cual (`?literal=1`). Va dentro
         de la región que reemplaza la búsqueda en vivo, para que aparezca y desaparezca con ella. --}}
    @if ($vm->hayCorreccion())
        <p class="small texto-tenue mb-2" data-busqueda-corregida>
            Se buscó «{{ $vm->terminoBuscado() }}» porque «{{ $vm->buscar() }}» parece un error de tecleo.
            <a href="{{ $vm->urlBuscarLoEscrito() }}">Buscar «{{ $vm->buscar() }}» tal cual</a>.
        </p>
    @endif

    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                @if ($vm->hayBusqueda() || $vm->hayFiltro())
                    <x-slot name="acciones">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlTodos() }}">Ver todos los pacientes</a>
                    </x-slot>
                @endif
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Paciente</th>
                    <th>Historia</th>
                    <th>Edad</th>
                    <th>Contacto</th>
                    <th><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal">
                            <x-ui.identidad :nombre="$fila['nombre']" :detalle="$fila['cedula']" :href="$fila['url']" />
                        </td>
                        <td data-etiqueta="Historia">
                            <x-ui.estado :tono="$fila['historia']['tono']" :icono="$fila['historia']['icono']">{{ $fila['historia']['texto'] }}</x-ui.estado>
                        </td>
                        <td data-etiqueta="Edad" @class(['celda-vacia' => ! $fila['edad']])>
                            @if ($fila['edad'])
                                <span>{{ $fila['edad'] }}</span>
                                <span class="texto-tenue small d-block">{{ $fila['nacimiento'] }}</span>
                            @else
                                <span class="texto-tenue">—</span>
                            @endif
                        </td>
                        <td data-etiqueta="Contacto" @class(['celda-vacia' => ! ($fila['telefono'] || $fila['correo'])])>
                            @if ($fila['telefono'] || $fila['correo'])
                                @if ($fila['telefono'])
                                    <span class="d-block"><i class="bi bi-telephone texto-tenue" aria-hidden="true"></i> {{ $fila['telefono'] }}</span>
                                @endif
                                @if ($fila['correo'])
                                    <span class="d-block small texto-tenue text-break"><i class="bi bi-envelope" aria-hidden="true"></i> {{ $fila['correo'] }}</span>
                                @endif
                            @else
                                <span class="texto-tenue">—</span>
                            @endif
                        </td>
                        <td class="celda-acciones text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ $fila['url'] }}">
                                Ver ficha <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.tabla>
        @endif
    </x-ui.tarjeta>

    @unless ($vm->estaVacio())
        <div class="lista-pie">
            <span class="texto-tenue small">{{ $vm->rango() }}</span>
            @if ($vm->haySobrantes())
                {{ $vm->paginador()->links() }}
            @endif
        </div>
    @endunless
</div>
