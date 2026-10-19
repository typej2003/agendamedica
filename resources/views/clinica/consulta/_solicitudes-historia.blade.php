{{-- **La historia clínica dentro de la consulta** (pestaña de *Solicitudes*, 2026-10-19): la hoja que el escritorio
     muestra en vista previa al abrir esa solapa (`tabpage_15` de `tab_4`, el DataWindow `d_historia_clinica_dos`), con su
     botón «Imprimir». En pediatría la solapa se llama *Historia pediátrica*.

     La web no duplica la hoja: «Visualizar» abre en el diálogo de vista previa de la consulta (WEB-11.24) la misma
     página que imprime `/clinica/pacientes/{paciente}/historia/imprimir`, y «Imprimir» la abre en otra pestaña. Lo que
     no hay es un formulario: la historia es de sólo lectura. --}}
<x-ui.tarjeta :titulo="$vm->rotulo()" icono="bi-journal-medical">
    <x-slot name="acciones">
        @if ($vm->puedeImprimir())
            <x-ui.imprimir-con-vista :url="$vm->urlImprimir()" :titulo="$vm->rotulo()">
                <i class="bi bi-printer" aria-hidden="true"></i> Imprimir la historia
            </x-ui.imprimir-con-vista>
        @endif
        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlHistoria() }}">
            <i class="bi bi-journal-text" aria-hidden="true"></i> Abrir la historia
        </a>
    </x-slot>

    <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

    @if (! $vm->puedeImprimir())
        <x-ui.estado tono="aviso" icono="bi-info-circle">{{ $vm->sinHistoria() }}</x-ui.estado>
    @endif
</x-ui.tarjeta>
