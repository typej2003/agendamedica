@props(['membrete'])

{{-- La franja de firma de un documento impreso (WEB-5.2): la firma del médico sobre su línea con el nombre
     debajo y el sello en la posición que eligió (izquierda, centro o derecha). Lo que va en `$slot` queda a la
     izquierda de la firma (en el récipe, la próxima cita y el lugar reservado para el QR).

     El nombre es el del **doctor que atendió** (`nombreDelFirmante`), aunque el membrete de arriba sea el del
     centro médico (Fase 5 del cambio de `reg_medico`): la firma y el sello nunca son del centro. --}}
<div class="firmas">
    <div class="firmas-izquierda">{{ $slot }}</div>

    <div class="firma">
        @if ($membrete->firmaUrl)
            <img class="firma-imagen" src="{{ $membrete->firmaUrl }}" alt="Firma del médico">
        @endif
        <div class="firma-linea"></div>
        <p class="firma-nombre">{{ $membrete->nombreDelFirmante() }}</p>
    </div>

    @if ($membrete->selloUrl)
        <img class="sello sello-{{ $membrete->selloPosicion }}" src="{{ $membrete->selloUrl }}" alt="Sello del médico">
    @endif
</div>
