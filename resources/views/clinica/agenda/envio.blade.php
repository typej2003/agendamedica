@extends('clinica.layout')

@section('titulo', 'Enviar a todos')

@php
    use App\Support\FechaClinica;

    // El texto arranca con el del primer paciente (el de la plantilla de su médico): en la mayoría de
    // los días todos los de la jornada son del mismo médico.
    $mensajePorDefecto = $mensajes === [] ? '' : reset($mensajes);
@endphp

@section('contenido')
    <div class="page-head">
        <div>
            <h1>Enviar a todos</h1>
            <p class="page-head-sub">
                {{ ucfirst(FechaClinica::tituloDia($fecha)) }}
                · {{ $sede->medicalCenter->name ?? 'Todas las sedes' }}
                · {{ $citas->count() }} {{ $citas->count() === 1 ? 'paciente' : 'pacientes' }}
                <span class="d-block small">
                    No se listan las citas ya confirmadas ni las atendidas: no hace falta recordárselas.
                </span>
            </p>
        </div>

        <a class="btn btn-sm btn-outline-secondary"
           href="{{ route('clinica.agenda', ['vista' => 'dia', 'fecha' => $fecha->toDateString(), 'sede' => $sedeId ?? 'todas']) }}">
            <i class="bi bi-arrow-left"></i> Volver a la agenda
        </a>
    </div>

    <form method="POST" action="{{ route('clinica.agenda.envio.enviar') }}">
        @csrf
        <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">
        <input type="hidden" name="sede" value="{{ $sedeId ?? 'todas' }}">

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <span class="form-label small mb-1 d-block">Por dónde</span>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach ($canales as $clave => $etiqueta)
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="canal"
                                           id="envio-canal-{{ $clave }}" value="{{ $clave }}"
                                           data-canal="{{ $clave }}" {{ $canal === $clave ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="envio-canal-{{ $clave }}">{{ $etiqueta }}</label>
                                </div>
                            @endforeach
                        </div>
                        <p class="small text-muted mb-0">
                            Los que no tengan el dato del canal elegido quedan fuera de la selección.
                        </p>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label small mb-1" for="envio-mensaje">Mensaje</label>
                        <textarea name="mensaje" id="envio-mensaje" rows="3" maxlength="500"
                                  class="form-control form-control-sm">{{ $mensajePorDefecto }}</textarea>
                        <p class="small text-muted mb-0" id="envio-contador"></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th style="width: 2.5rem;">
                            <input type="checkbox" class="form-check-input" id="envio-todos" checked
                                   aria-label="Marcar todos">
                        </th>
                        <th style="width: 4.5rem;">Hora</th>
                        <th>Paciente</th>
                        <th>Celular</th>
                        <th>E-Mail</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($citas as $cita)
                        <tr data-telefono="{{ $cita->tieneTelefono() ? '1' : '0' }}"
                            data-correo="{{ $cita->tieneCorreo() ? '1' : '0' }}">
                            <td>
                                <input type="checkbox" class="form-check-input envio-cita"
                                       name="citas[]" value="{{ $cita->id }}" checked
                                       aria-label="Enviarle a {{ $cita->paciente }}">
                            </td>
                            <td>{{ $cita->horaCorta() }}</td>
                            <td>
                                {{ $cita->paciente }}
                                @unless ($cita->numHistoria)
                                    <span class="badge bg-secondary">Sin historia</span>
                                @endunless
                            </td>
                            <td class="small">
                                {{ $cita->telefono ?: '—' }}
                                @unless ($cita->tieneTelefono())
                                    <span class="badge bg-warning text-dark">Sin celular válido</span>
                                @endunless
                            </td>
                            <td class="small">
                                {{ $cita->email ?: '—' }}
                                @unless ($cita->tieneCorreo())
                                    <span class="badge bg-warning text-dark">Sin correo</span>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted">
                                No hay pacientes para recordar en ese día y sede.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <span class="small text-muted" id="envio-resumen"></span>
                {{-- `@disabled` no existe en Blade de Laravel 8 (llega en el 9): se escribe el atributo. --}}
                <button class="btn btn-sm btn-primary" @if ($citas->isEmpty()) disabled @endif>
                    <i class="bi bi-send"></i> Enviar a los seleccionados
                </button>
            </div>
        </div>
    </form>

    <script>
        // El canal manda: los pacientes sin el dato de ese canal no se pueden seleccionar (y se dice
        // por qué en su fila). Un SMS de más de 160 caracteres se avisa.
        const filas = Array.from(document.querySelectorAll('tr[data-telefono]'));
        const casillas = () => Array.from(document.querySelectorAll('.envio-cita'));

        function canalElegido() {
            const elegido = document.querySelector('#envio-canal-sms:checked, #envio-canal-whatsapp:checked, #envio-canal-correo:checked')
                || document.querySelector('[data-canal]:checked');

            return elegido ? elegido.value : 'sms';
        }

        function aplicarCanal() {
            const canal = canalElegido();
            const atributo = canal === 'correo' ? 'correo' : 'telefono';

            filas.forEach(function (fila) {
                const puede = fila.dataset[atributo] === '1';
                const casilla = fila.querySelector('.envio-cita');
                const estabaFuera = casilla.disabled;

                casilla.disabled = !puede;
                fila.classList.toggle('text-muted', !puede);

                // Al cambiar de canal, el que vuelve a poder recibir entra marcado; el que ya estaba
                // disponible conserva lo que el usuario eligió.
                if (!puede) {
                    casilla.checked = false;
                } else if (estabaFuera) {
                    casilla.checked = true;
                }
            });

            actualizarResumen();
        }

        function actualizarResumen() {
            const marcadas = casillas().filter((casilla) => casilla.checked).length;
            document.getElementById('envio-resumen').textContent =
                marcadas + ' de ' + filas.length + ' seleccionados';
        }

        function actualizarContador() {
            const largo = document.getElementById('envio-mensaje').value.length;
            const aviso = canalElegido() === 'sms' && largo > 160 ? ' — pasa los 160 caracteres de un SMS' : '';
            document.getElementById('envio-contador').textContent = largo + ' caracteres' + aviso;
        }

        document.querySelectorAll('[data-canal]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                aplicarCanal();
                actualizarContador();
            });
        });

        document.getElementById('envio-todos').addEventListener('change', function (evento) {
            casillas().forEach(function (casilla) {
                if (!casilla.disabled) {
                    casilla.checked = evento.target.checked;
                }
            });
            actualizarResumen();
        });

        casillas().forEach((casilla) => casilla.addEventListener('change', actualizarResumen));
        document.getElementById('envio-mensaje').addEventListener('input', actualizarContador);

        aplicarCanal();
        actualizarContador();
    </script>
@endsection
