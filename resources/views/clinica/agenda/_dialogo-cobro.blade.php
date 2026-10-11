{{-- Un solo diálogo de cobro para toda la página: el botón "Cobrar" de cada cita lo llena con sus datos
     (`public/js/clinica/agenda.js`). El monto solo se pide si la cita todavía no tiene precio (lo común en
     los datos del legado). --}}
<x-ui.dialogo id="dialogo-cobro" titulo="Registrar cobro" lateral>
    <form method="POST" id="cobro-form">
        @csrf
        <div class="dialogo-cuerpo">
            <p class="mb-2">Paciente: <strong id="cobro-paciente"></strong></p>

            <div class="mb-3" id="cobro-monto-fila" hidden>
                <label class="form-label" for="cobro-monto">Monto de la consulta</label>
                <input type="number" step="0.01" min="0" name="monto" id="cobro-monto" class="form-control">
            </div>

            <div class="mb-2">
                <label class="form-label" for="cobro-recibido">Recibido ahora</label>
                <input type="number" step="0.01" min="0" name="recibido" id="cobro-recibido" class="form-control" required>
            </div>

            <p class="form-text mb-0" id="cobro-detalle"></p>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary">Registrar cobro</button>
        </div>
    </form>
</x-ui.dialogo>
