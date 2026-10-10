@php
    // El buscador de pacientes es **de la vista de día**, que es la pantalla del mostrador: es el
    // listado que el escritorio tiene al lado de la agenda (`dw_2` = `d_pacientes_buscar_consulta_cita`
    // en `w_hacer_cita`), con "Hacer Cita" sobre la fila elegida (WEB-2.14).
    use App\Models\Cola;

    // La razón activa del filtro del encabezado también acompaña al alta de la cita.
    $razonDelDia = $razon ?? null;
@endphp

<div class="card mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <strong><i class="bi bi-person-lines-fill"></i> Pacientes de la agenda</strong>
            <span class="text-muted small ms-2">
                El listado que el escritorio tiene al lado de la agenda: buscá por apellido, nombre,
                cédula o n.º de historia y agendá desde la fila.
            </span>
        </div>
    </div>
    <div class="card-body py-2">
        <div class="position-relative" id="agenda-paciente-busqueda"
             data-url="{{ route('clinica.pacientes.buscar') }}">
            <input type="search" id="agenda-paciente-texto" class="form-control form-control-sm"
                   autocomplete="off" placeholder="Apellido, nombre, cédula o n.º de historia"
                   aria-label="Buscar paciente para hacerle una cita">
            <div class="list-group position-absolute w-100 shadow-sm" id="agenda-paciente-resultados"
                 style="z-index: 1030;" hidden></div>
        </div>
        <p class="form-text small mb-0" id="agenda-paciente-ayuda">
            Desde acá se agenda sin pasar por Pacientes: el "Hacer cita" abre el alta con el paciente
            ya elegido.
        </p>
    </div>
</div>

@if ($jornadas->isEmpty())
    <div class="card">
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <p>No hay citas para este día{{ $sedeId ? ' en la sede elegida' : '' }}@if ($razonDelDia) con esa razón @endif.</p>
            <p class="small">
                Probá con otra fecha, cambiá la sede del filtro
                @if ($razonDelDia) o quitá el filtro de razón @endif.
            </p>
        </div>
    </div>
@else
    @foreach ($jornadas as $jornada)
        @include('clinica.agenda._jornada', ['jornada' => $jornada, 'conDrag' => true])
    @endforeach
@endif

@section('scripts')
    <script>
        // Buscador de paciente dentro de la agenda (WEB-2.14). Es el mismo patrón del buscador del alta
        // de cita (`formulario.blade.php`): 250 ms de espera, dos caracteres como mínimo y `fetch` con
        // `Accept: application/json`. La diferencia es qué hace al elegir: en el alta el resultado se
        // marca, acá cada fila lleva su **"Hacer cita"** al formulario con el paciente ya elegido.
        (function () {
            const caja = document.getElementById('agenda-paciente-busqueda');
            if (!caja) return;

            const texto = document.getElementById('agenda-paciente-texto');
            const lista = document.getElementById('agenda-paciente-resultados');
            const ayuda = document.getElementById('agenda-paciente-ayuda');
            const base = caja.dataset.url;
            const fecha = @json($fecha->toDateString());
            const sede = @json($sedeId);
            let temporizador = null;

            function limpiar() {
                lista.innerHTML = '';
                lista.hidden = true;
            }

            function avisar(mensaje) {
                ayuda.textContent = mensaje;
            }

            function pintar(pacientes) {
                lista.innerHTML = '';

                if (!pacientes.length) {
                    limpiar();
                    avisar('Ningún paciente del consultorio coincide con lo buscado.');
                    return;
                }

                pacientes.forEach(function (paciente) {
                    const fila = document.createElement('div');
                    fila.className = 'list-group-item d-flex justify-content-between align-items-center gap-2 py-1';

                    const datos = document.createElement('span');
                    datos.className = 'small text-truncate';
                    datos.textContent = paciente.nombre
                        + (paciente.cedula ? ' · ' + paciente.cedula : '')
                        + (paciente.numhistoria ? ' · H. ' + paciente.numhistoria : ' · sin historia');
                    fila.appendChild(datos);

                    // El `&paciente_id=` final lo agrega el alta: la pantalla ya acepta el paciente
                    // elegido, así que el buscador no inventa un camino nuevo.
                    const enlace = document.createElement('a');
                    enlace.className = 'btn btn-sm btn-primary text-nowrap';
                    enlace.href = @json(route('clinica.agenda.nueva', array_filter(['fecha' => $fecha->toDateString(), 'sede' => $sedeId])))
                        + '&paciente_id=' + paciente.id;
                    enlace.innerHTML = '<i class="bi bi-calendar-plus"></i> Hacer cita';
                    fila.appendChild(enlace);

                    lista.appendChild(fila);
                });

                lista.hidden = false;
                avisar(pacientes.length + (pacientes.length === 1 ? ' paciente' : ' pacientes')
                    + ' — elegí "Hacer cita" para agendarle.');
            }

            function buscar() {
                const consulta = texto.value.trim();
                if (consulta.length < 2) {
                    limpiar();
                    return;
                }

                fetch(base + '?q=' + encodeURIComponent(consulta), { headers: { 'Accept': 'application/json' } })
                    .then(function (respuesta) { return respuesta.json(); })
                    .then(pintar)
                    .catch(function () {
                        limpiar();
                        avisar('No se pudo buscar: revisá la conexión con el servidor.');
                    });
            }

            texto.addEventListener('input', function () {
                clearTimeout(temporizador);
                if (texto.value.trim().length < 2) {
                    limpiar();
                }
                temporizador = setTimeout(buscar, 250);
            });

            document.addEventListener('click', function (evento) {
                if (evento.target !== texto && !lista.contains(evento.target)) {
                    limpiar();
                }
            });
        })();
    </script>
@endsection
