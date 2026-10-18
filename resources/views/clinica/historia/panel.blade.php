{{-- **El resumen del paciente para el cajón de la consulta** (O-30): el cuerpo de la pantalla de la historia
     (`/clinica/pacientes/{paciente}/historia`), sin el armazón de la web, para mostrarlo mientras el médico
     escribe una constancia, un reposo, una referencia, un informe, una dieta o un récipe.

     Lo pide `public/js/clinica/resumen-panel.js` con `?panel=1` **la primera vez que se abre el cajón**: así la
     consulta no paga la lectura de la historia al cargar y el resumen que se ve es el de ese momento. No es un
     segundo resumen: es el mismo parcial que la pantalla de la historia (`clinica.historia._resumen`), con el
     mismo ViewModel, así que lo que se ve acá y lo que se ve allá no pueden divergir. --}}
<div class="resumen-panel" data-resumen-panel>
    <p class="resumen-panel-identidad">
        <strong>{{ $vm->nombre() }}</strong>
        <span class="texto-tenue">{{ $vm->subtitulo() }}</span>
    </p>

    @include('clinica.historia._resumen')
</div>
