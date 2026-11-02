@extends('clinica.layout')

@section('titulo', 'Mis ingresos')

@section('contenido')
    <x-ui.page-head titulo="Mis ingresos" :subtitulo="$vm->subtitulo()"></x-ui.page-head>

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <x-ui.tarjeta titulo="Cobrado" icono="bi-check2-circle">
                <p class="display-6 mb-0">{{ $vm->pagado() }}</p>
                <p class="texto-tenue mb-0">{{ $vm->cuantosPagados() }} {{ $vm->cuantosPagados() === 1 ? 'pago' : 'pagos' }}</p>
            </x-ui.tarjeta>
        </div>
        <div class="col-12 col-md-4">
            <x-ui.tarjeta titulo="En proceso" icono="bi-hourglass-split">
                <p class="display-6 mb-0">{{ $vm->enProceso() }}</p>
                <p class="texto-tenue mb-0">{{ $vm->cuantosEnProceso() }} esperando confirmarse</p>
            </x-ui.tarjeta>
        </div>
        <div class="col-12 col-md-4">
            <x-ui.tarjeta titulo="Sin cobrar" icono="bi-x-circle">
                <p class="display-6 mb-0">{{ $vm->sinCobrar() }}</p>
                <p class="texto-tenue mb-0">{{ $vm->cuantosSinCobrar() }} rechazados, vencidos o reembolsados</p>
            </x-ui.tarjeta>
        </div>
    </div>

    <x-ui.tarjeta titulo="Los pagos" icono="bi-receipt">
        <form method="GET" action="{{ $vm->urlFiltros() }}" class="row g-2 align-items-end mb-3">
            <div class="col-12 col-sm-4">
                <label class="form-label" for="estado">Estado</label>
                <select class="form-select" id="estado" name="estado">
                    @foreach ($vm->opcionesDeEstado() as $valor => $texto)
                        <option value="{{ $valor }}" @selected($vm->estadoElegido() === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-sm-3">
                <label class="form-label" for="desde">Desde</label>
                <input type="date" class="form-control" id="desde" name="desde" value="{{ $vm->desde() }}">
            </div>
            <div class="col-6 col-sm-3">
                <label class="form-label" for="hasta">Hasta</label>
                <input type="date" class="form-control" id="hasta" name="hasta" value="{{ $vm->hasta() }}">
            </div>
            <div class="col-12 col-sm-2">
                <button class="btn btn-primary w-100">Filtrar</button>
            </div>
        </form>

        @if ($vm->hayPagos())
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Fecha</th>
                            <th scope="col">Cita</th>
                            <th scope="col">Paciente</th>
                            <th scope="col">Monto</th>
                            <th scope="col">Medio</th>
                            <th scope="col">Canal</th>
                            <th scope="col">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($vm->filas() as $fila)
                            <tr data-pago="{{ $fila['id'] }}">
                                <td>{{ $fila['fecha'] }}</td>
                                <td>{{ $fila['concepto'] }}</td>
                                <td>{{ $fila['paciente'] ?: '—' }}</td>
                                <td>{{ $fila['monto'] }}</td>
                                <td>{{ $fila['medio'] }}</td>
                                <td>{{ $fila['canal'] }}</td>
                                <td><x-ui.estado :tono="$fila['tono']">{{ $fila['estado'] }}</x-ui.estado></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-ui.vacio icono="bi-receipt" titulo="No hay pagos de citas en este período">
                No hay pagos de citas en este período. Cuando cobres una cita —en el mostrador o con el link de
                pago— aparece acá.
            </x-ui.vacio>
        @endif
    </x-ui.tarjeta>
@endsection
