{{-- Los formatos en blanco (WEB-5.5b): el «Imprimir Formatos» del escritorio. Cada documento sin datos de paciente, con el membrete, la firma, el
     sello y el pie GUARDADOS, para rellenarlo a mano cuando la impresora normal está fuera de servicio. Cada botón abre la hoja en una pestaña
     aparte (sin tocar lo guardado: es una lectura). --}}
<x-ui.tarjeta titulo="Formatos en blanco" icono="bi-file-earmark-text" class="mb-3" id="formato-en-blanco">
    <p class="form-text mt-0">
        Hojas sin datos de paciente, con tu membrete, firma, sello y pie, para llenar a mano si la impresora está fuera de servicio.
    </p>

    <ul class="lista-en-blanco list-unstyled mb-0">
        @foreach ($vm->formatosEnBlanco() as $formato)
            <li class="lista-en-blanco-fila d-flex align-items-center justify-content-between gap-2">
                <div>
                    <strong>{{ $formato['nombre'] }}</strong>
                    <span class="texto-tenue d-block">{{ $formato['descripcion'] }}</span>
                </div>

                <a class="btn btn-outline-secondary btn-sm" href="{{ $formato['url'] }}" target="_blank" rel="noopener"
                   aria-label="Imprimir {{ $formato['nombre'] }} en blanco">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                </a>
            </li>
        @endforeach
    </ul>
</x-ui.tarjeta>
