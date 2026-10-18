{{-- El eco pélvico o ginecológico de la consulta (WEB-6.1, ítem 5 del panel del legado): el formulario de
     los nueve bloques que tiene la pantalla del escritorio (`d_eco_pelvico.srd`), con **guardado
     explícito**.

     Todo lo editable va en **un solo formulario** —una fila de `eco_pelvico` por consulta— y el médico
     aprieta "Guardar" una vez. Los campos salen del mapa del dato (`App\Clinica\Consulta\EcoGinecologico`),
     que es el mismo que usan la Action y el informe, así que una columna nueva se agrega en un solo lugar.

     El aviso de "cambios sin guardar" y el bloqueo del navegador al salir los pone `consulta.js` desde los
     `data-*`: la vista no lleva JS ni estilos. El informe en **texto libre** del escritorio (`puro_texto`,
     el modo "puro texto" de la ventana independiente) se muestra si está escrito y no se edita todavía. --}}
@php
    $estado = $vm->estado();
@endphp

<div class="consulta-seccion" id="consulta-estudio-ginecologico">
    <x-ui.tarjeta titulo="{{ $vm->titulo() }}" icono="bi-activity">
        <x-slot name="acciones">
            <span class="texto-tenue small me-2">{{ $vm->cargados() }} de {{ $vm->totalCampos() }} campos con dato</span>
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

        <form method="POST" action="{{ $vm->urlGuardar() }}" id="estudio-form"
              data-consulta-estudio data-aviso="{{ $vm->avisoSinGuardar() }}">
            @csrf

            @foreach ($vm->grupos() as $grupo)
                <h3 class="paso-titulo mb-1">{{ $grupo['titulo'] }}</h3>
                <p class="texto-tenue small mb-2">{{ $grupo['ayuda'] }}</p>

                @include('clinica.consulta._estudio-plantillas', ['grupo' => $grupo])

                <div class="row g-3 mb-3">
                    @foreach ($grupo['campos'] as $campo)
                        @include('clinica.consulta._estudio-campo', [
                            'campo' => $campo,
                            'columnas' => $campo['tipo'] === 'area' ? 'col-12' : 'col-6 col-md-4',
                        ])
                    @endforeach
                </div>
            @endforeach

            {{-- El aviso de "hay cambios sin guardar": `consulta.js` lo muestra cuando algún control del
                 formulario cambió respecto de lo guardado. --}}
            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="estudio-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $vm->urlVolver() }}">Cancelar</a>
                {{-- O-16/O-20: el botón se ofrece siempre que la sección tenga hoja: «Imprimir» **guarda lo escrito y
                     después imprime**, así un estudio recién cargado se imprime sin apretar «Guardar» primero. Si no hay
                     nada que imprimir, la hoja lo dice («Guarda los datos del estudio para poder imprimir»). --}}
                @if ($vm->urlImprimir() !== '')
                    <x-ui.imprimir-guardando :url="$vm->urlImprimir()" form="estudio-form" documento="{{ $vm->documento() }}" class="btn btn-outline-secondary">
                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir el informe
                    </x-ui.imprimir-guardando>
                @endif
                {{-- La única acción primaria de la sección: guardar lo cargado. --}}
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el estudio
                </button>
            </div>
        </form>
    </x-ui.tarjeta>

    {{-- El informe en texto libre del escritorio: fuera del formulario porque es de lectura. --}}
    @if ($vm->hayPuroTexto())
        <x-ui.tarjeta titulo="Informe en texto libre del escritorio" icono="bi-card-text" class="mt-3">
            <p class="texto-tenue small mb-2">
                Lo escribió el escritorio con su modo <em>puro texto</em>. Se muestra tal como quedó y todavía
                no se edita desde acá.
            </p>
            <div class="examen-resumen">{{ $vm->puroTexto() }}</div>
        </x-ui.tarjeta>
    @endif
</div>
