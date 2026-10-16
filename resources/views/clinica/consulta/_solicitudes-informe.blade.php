{{-- El informe médico (WEB-5.3a): el «Para», el texto y la fecha, con las plantillas del médico y los informes anteriores del
     mismo paciente para empezar. Recibe `$vm` (InformeVista). Uno por consulta; se guarda con el botón. Elegir una plantilla o copiar
     uno anterior **escribe en el editor y no guarda**. --}}
@php $estado = $vm->estado(); @endphp

<x-ui.tarjeta titulo="Informe médico" icono="bi-file-earmark-text">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        @if ($vm->esReal())
            <x-ui.imprimir-con-vista :url="$vm->urlImprimir()" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
            </x-ui.imprimir-con-vista>
        @endif
        {{-- El resumen médico (WEB-5.3c): la hoja auto-generada de la consulta, la otra mitad de esta
             pestaña del escritorio. Sólo se ofrece si hay algo que resumir. --}}
        @if ($vm->ofreceResumen())
            <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlImprimirResumen() }}" target="_blank" rel="noopener">
                <i class="bi bi-journal-medical" aria-hidden="true"></i> Resumen médico
            </a>
        @endif
    </x-slot>

    <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

    <form method="POST" action="{{ $vm->urlGuardar() }}" id="informe-form" data-solicitud-form data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        {{-- El panel de plantillas va **arriba** del campo (pedido del usuario, 2026-10-16): en el escritorio es
             una columna a la derecha y acá, al no caber, caía debajo del texto. Los informes anteriores siguen
             al lado del editor: se copian, no son plantillas del médico. --}}
        <x-ui.plantillas :plantillas="$vm->plantillas()" destino="#informe-descripcion" tipo="IM"
                         nombre="informe" class="mb-3" />

        <div class="row g-3">
            <div class="col-12 col-xl-8">
                <div class="row g-3 mb-3">
                    <x-ui.campo etiqueta="Para" for="informe-para" error="para" class="col-12 col-md-8">
                        <input type="text" name="para" id="informe-para" class="form-control" maxlength="{{ $vm->largoDelPara() }}" value="{{ $vm->para() }}">
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Fecha" for="informe-fecha" error="fecha" class="col-12 col-md-4">
                        <input type="date" name="fecha" id="informe-fecha" class="form-control" value="{{ $vm->fecha() }}">
                    </x-ui.campo>
                </div>

                <x-ui.campo etiqueta="Texto del informe" for="informe-descripcion" error="descripcion" requerido>
                    <textarea name="descripcion" id="informe-descripcion" class="form-control" rows="14">{{ $vm->texto() }}</textarea>
                </x-ui.campo>
            </div>

            <div class="col-12 col-xl-4">
                <p class="etiqueta mb-2">Informes anteriores del paciente</p>
                @if ($vm->hayAnteriores())
                    <ul class="evolucion-plantillas">
                        @foreach ($vm->anteriores() as $anterior)
                            <li>
                                <button type="button" class="evolucion-plantilla" data-copiar-a="#informe-descripcion"
                                        data-texto="{{ $anterior['texto'] }}" data-copiar-para="#informe-para" data-para="{{ $anterior['para'] }}">
                                    <span class="evolucion-plantilla-titulo">{{ $anterior['titulo'] }}</span>
                                    <span class="texto-tenue small">{{ $anterior['resumen'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="texto-tenue small mb-0">Este paciente no tiene otros informes.</p>
                @endif
            </div>
        </div>

        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-3" data-solicitud-aviso hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay cambios sin guardar.</span>
        </p>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el informe
            </button>
        </div>
    </form>

    @if ($vm->puedeEliminar())
        <form method="POST" action="{{ $vm->urlEliminar() }}" class="mt-3" data-confirmar="{{ $vm->avisoEliminar() }}" data-solicitud-salir>
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash" aria-hidden="true"></i> Eliminar el informe
            </button>
        </form>
    @endif
</x-ui.tarjeta>
