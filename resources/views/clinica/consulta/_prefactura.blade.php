{{-- **Pre-factura** (F9): los conceptos que se cobran por la consulta (`his_con_pre_factura`), del catálogo
     `motivo_factura` del consultorio, con su cantidad, su monto y el total. Cada fila se corrige en el lugar (el mismo
     `POST` con su código) y se quita con su `DELETE`. --}}
@php
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-prefactura">
    <x-ui.tarjeta titulo="Pre-factura" icono="bi-receipt">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <x-ui.errores />

        @if ($seccion->tieneItems())
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle" id="prefactura-items">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Concepto</th>
                            <th class="text-end">Cantidad</th>
                            <th class="text-end">Monto</th>
                            <th class="text-end">Subtotal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($seccion->items() as $item)
                            <tr>
                                <td><code>{{ $item['codigo'] }}</code></td>
                                <td>{{ $item['descripcion'] }}</td>
                                <td colspan="2">
                                    <form method="POST" action="{{ $seccion->urlGuardar() }}"
                                          class="d-flex justify-content-end gap-2" aria-label="Corregir {{ $item['descripcion'] }}">
                                        @csrf
                                        <input type="hidden" name="codigo" value="{{ $item['codigo'] }}">
                                        <input type="number" class="form-control form-control-sm text-end" style="max-width: 6rem"
                                               name="cantidad" min="0.01" step="0.01" required value="{{ $item['cantidadValor'] }}"
                                               aria-label="Cantidad">
                                        <input type="number" class="form-control form-control-sm text-end" style="max-width: 9rem"
                                               name="monto" min="0" step="0.01" value="{{ $item['montoValor'] }}" aria-label="Monto">
                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Guardar la corrección">
                                            <i class="bi bi-check2" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-end">{{ $item['subtotal'] }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ $seccion->urlQuitar() }}" data-confirmar="¿Quitar este concepto de la pre-factura?">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="codigo" value="{{ $item['codigo'] }}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Quitar">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" class="text-end">Total</th>
                            <th class="text-end" id="prefactura-total">{{ $seccion->total() }}</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <x-ui.vacio icono="bi-receipt" titulo="Todavía no hay conceptos en la pre-factura">
                Agrega los conceptos que se le cobrarán al paciente por esta consulta.
            </x-ui.vacio>
        @endif

        @if ($seccion->tieneCatalogo())
            <form method="POST" action="{{ $seccion->urlGuardar() }}" id="prefactura-form">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="prefactura-codigo">Concepto</label>
                        <select class="form-select" id="prefactura-codigo" name="codigo" required>
                            <option value="">Elige un concepto…</option>
                            @foreach ($seccion->opciones() as $opcion)
                                <option value="{{ $opcion['valor'] }}" @if (old('codigo') === $opcion['valor']) selected @endif>{{ $opcion['texto'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="prefactura-cantidad">Cantidad</label>
                        <input type="number" class="form-control text-end" id="prefactura-cantidad" name="cantidad"
                               min="0.01" step="0.01" required value="{{ old('cantidad', '1') }}">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="prefactura-monto">Monto</label>
                        <input type="number" class="form-control text-end" id="prefactura-monto" name="monto"
                               min="0" step="0.01" value="{{ old('monto') }}" placeholder="Del catálogo">
                    </div>
                    <div class="col-12 col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar
                        </button>
                    </div>
                </div>
                <p class="form-text mb-0">Vacío, el monto es el del catálogo. Si el concepto ya está en la pre-factura, se corrigen su cantidad y su monto.</p>
            </form>
        @else
            <p class="alert alert-info py-2 mb-0">
                El consultorio todavía no tiene conceptos de facturación en su catálogo: cárgalos primero en el catálogo de motivos de facturación.
            </p>
        @endif
    </x-ui.tarjeta>
</div>
