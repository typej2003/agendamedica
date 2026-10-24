{{--
    El formulario para **registrar una dosis aplicada** (WEB-7.4.4b), en un diálogo. Es uno solo para toda la página: el botón «Registrar» de cada
    dosis lo llena (vacuna, número, rótulo y fecha planeada) y lo abre (`public/js/clinica/vacunas.js`). Las longitudes son las de las columnas
    del escritorio (lote, vía y zona 20). Recibe `$vm` (CalendarioDeVacunasVista).
--}}
<x-ui.dialogo id="dialogo-registrar-dosis" titulo="Registrar una dosis" lateral>
    <form method="POST" action="{{ $vm->urlRegistrar() }}">
        @csrf
        <input type="hidden" name="codi_vacuna" data-dosis-campo="codigo">
        <input type="hidden" name="dosis_numero" data-dosis-campo="numero">
        <input type="hidden" name="rotulo" data-dosis-campo="rotulo">
        <input type="hidden" name="planeada" data-dosis-campo="planeada">

        <div class="dialogo-cuerpo">
            <p class="mb-3"><strong data-dosis-titulo></strong></p>

            <div class="row g-3">
                <x-ui.campo etiqueta="Fecha de aplicación" for="dosis-fecha" error="fecha_ejecutada" class="col-md-6" :requerido="true">
                    <input type="date" name="fecha_ejecutada" id="dosis-fecha" class="form-control" max="{{ \App\Clinica\FechaDelConsultorio::hoyComoTexto() }}" required
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
                <x-ui.campo etiqueta="Nota" for="dosis-nota" error="nota" class="col-12">
                    <textarea name="nota" id="dosis-nota" class="form-control" rows="2" maxlength="2000"></textarea>
                </x-ui.campo>
            </div>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary">Registrar la dosis</button>
        </div>
    </form>
</x-ui.dialogo>
