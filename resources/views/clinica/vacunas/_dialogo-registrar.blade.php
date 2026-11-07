{{--
    **El diálogo de una dosis de vacuna** (WEB-7.4.4b; O-68/O-69), uno solo para toda la página. Lo abren:

     - **«Agregar una vacuna»** (`data-agregar-vacuna`): primero se elige **vacuna + dosis** del catálogo del médico (los dos
       desplegables, como el *Escoger* del escritorio: `w_escoger_razon_vacuna_consu.srw:1180`, «Escoja la vacuna y dosis», con
       `ddlb_1` de dosis en `:256`) y después **qué se hace**: se registra aplicada o queda programada (la decisión primero).
     - **«Aplicar»** de una fila de la lista o **«Registrar»** de una dosis del esquema (`data-registrar-dosis`): viene con la
       vacuna y la dosis ya puestas y la decisión fija en «ya se la apliqué».

    El `action` del formulario y el texto del botón los cambia `public/js/clinica/vacunas.js` según la decisión: el registro
    postea a `urlRegistrar()` y la programación a `urlProgramar()`. Las longitudes son las de las columnas del escritorio
    (lote, vía y zona 20). Recibe `$vm` (CalendarioDeVacunasVista).
--}}
<x-ui.dialogo id="dialogo-registrar-dosis" titulo="Agregar una vacuna" lateral>
    {{-- `data-sin-borrador`: es una acción de un solo uso, no texto que haya que recuperar al volver (y sus campos son de
         **esta** dosis: recuperarlos en otra sería peor que perderlos). --}}
    <form method="POST" action="{{ $vm->urlRegistrar() }}" data-sin-borrador data-dosis-formulario
          data-url-registrar="{{ $vm->urlRegistrar() }}" data-url-programar="{{ $vm->urlProgramar() }}">
        @csrf
        <input type="hidden" name="codi_vacuna" data-dosis-campo="codigo">
        <input type="hidden" name="dosis_numero" data-dosis-campo="numero">
        <input type="hidden" name="rotulo" data-dosis-campo="rotulo">
        {{-- La consulta abierta (O-63): el registro vuelve a la sección «Inmunizaciones» en vez de al módulo del niño.
             Sólo viaja cuando la pantalla se está viendo **dentro** de una consulta. --}}
        @if ($vm->consulta())
            <input type="hidden" name="consulta" value="{{ $vm->consulta() }}">
        @endif

        <div class="dialogo-cuerpo">
            <p class="mb-3"><strong data-dosis-titulo></strong></p>

            {{-- 1. Qué vacuna y qué dosis (sólo cuando se agrega). Los `name` los pone el JS en los campos ocultos. --}}
            <div class="row g-3" data-dosis-eleccion hidden>
                <x-ui.campo etiqueta="Vacuna" for="dosis-vacuna" class="col-md-6" :requerido="true">
                    <select id="dosis-vacuna" class="form-select" data-dosis-vacuna>
                        @foreach ($vm->catalogoParaAgregar() as $vacuna)
                            <option value="{{ $vacuna['codigo'] }}">{{ $vacuna['nombre'] }}</option>
                        @endforeach
                    </select>
                </x-ui.campo>
                <x-ui.campo etiqueta="Dosis" for="dosis-dosis" class="col-md-6" :requerido="true">
                    <select id="dosis-dosis" class="form-select" data-dosis-dosis>
                        @foreach ($vm->catalogoParaAgregar() as $vacuna)
                            @foreach ($vacuna['dosis'] as $dosis)
                                <option value="{{ $dosis['numero'] }}" data-vacuna="{{ $vacuna['codigo'] }}"
                                        data-rotulo="{{ $dosis['rotulo'] }}">{{ $dosis['rotulo'] }}</option>
                            @endforeach
                        @endforeach
                    </select>
                </x-ui.campo>
            </div>

            {{-- 2. La decisión (regla 9): sin opción marcada; recién entonces aparecen los campos de la elegida. --}}
            <div class="row g-3" data-dosis-modo-caja>
                <x-ui.campo etiqueta="¿Qué haces con esta dosis?" for="dosis-modo" class="col-12" :requerido="true">
                    <select name="modo" id="dosis-modo" class="form-select" data-dosis-modo>
                        <option value="">Elige…</option>
                        <option value="aplicada">Ya se la apliqué</option>
                        <option value="programada">Queda programada, para aplicarla después</option>
                    </select>
                </x-ui.campo>
            </div>

            {{-- 3a. Programada: la «F. a aplicar» del escritorio (`fecha_planeada`, `d_vacunas_consulta_dos.srd:37`). --}}
            <div class="row g-3" data-dosis-programada hidden>
                <x-ui.campo etiqueta="F. a aplicar" for="dosis-planeada" error="planeada" class="col-md-6" :requerido="true">
                    <input type="date" name="planeada" id="dosis-planeada" class="form-control" data-dosis-planeada data-dosis-obligatorio
                           @if ($vm->minima() !== '') min="{{ $vm->minima() }}" @endif>
                </x-ui.campo>
            </div>

            {{-- 3b. Aplicada: los datos de la aplicación (WEB-7.4.4b). --}}
            <div class="row g-3" data-dosis-aplicada hidden>
                <x-ui.campo etiqueta="Fecha de aplicación" for="dosis-fecha" error="fecha_ejecutada" class="col-md-6" :requerido="true">
                    <input type="date" name="fecha_ejecutada" id="dosis-fecha" class="form-control" data-dosis-obligatorio
                           value="{{ $vm->maxima() }}" max="{{ $vm->maxima() }}" @if ($vm->minima() !== '') min="{{ $vm->minima() }}" @endif>
                </x-ui.campo>
                <x-ui.campo etiqueta="Lote" for="dosis-lote" error="lote" class="col-md-6">
                    <input type="text" name="lote" id="dosis-lote" class="form-control" maxlength="20" autocomplete="off">
                </x-ui.campo>
                <x-ui.campo etiqueta="Vía" for="dosis-via" error="via" class="col-md-6">
                    <input type="text" name="via" id="dosis-via" class="form-control" maxlength="20" placeholder="Intramuscular, oral…" autocomplete="off">
                </x-ui.campo>
                <x-ui.campo etiqueta="Zona" for="dosis-zona" error="zona" class="col-md-6">
                    <input type="text" name="zona" id="dosis-zona" class="form-control" maxlength="20" placeholder="Muslo derecho…" autocomplete="off">
                </x-ui.campo>
                <x-ui.campo etiqueta="Vence" for="dosis-vence" error="fecha_vence" class="col-md-6">
                    <input type="date" name="fecha_vence" id="dosis-vence" class="form-control">
                </x-ui.campo>
                <x-ui.campo etiqueta="Próxima dosis" for="dosis-proxima" error="fecha_proxima_dosis" class="col-md-6">
                    <input type="date" name="fecha_proxima_dosis" id="dosis-proxima" class="form-control">
                </x-ui.campo>
            </div>

            {{-- 4. La nota es de la fila, se aplique o se programe. --}}
            <div class="row g-3 mt-0">
                <x-ui.campo etiqueta="Nota" for="dosis-nota" error="nota" class="col-12">
                    <textarea name="nota" id="dosis-nota" class="form-control" rows="2" maxlength="2000"></textarea>
                </x-ui.campo>
            </div>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary" data-dosis-enviar disabled>Registrar la dosis</button>
        </div>
    </form>
</x-ui.dialogo>
