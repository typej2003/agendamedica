@props(['membrete'])

{{-- El membrete de un documento impreso (WEB-5.2): el logo y el nombre, la especialidad y el RIF del médico con
     la configuración de cada bloque (alineación, fuente, negrita, itálica, subrayado y borde). Lo reusan todos los
     documentos de la web (récipe hoy; constancias, reposos y referencias después). Sólo clases: la alineación, la
     fuente y el tamaño del logo salen del `Membrete` y se resuelven en `public/css/clinica-impresion.css`. --}}
<header class="membrete {{ $membrete->distribucion() }}">
    @if ($membrete->conLogo())
        <img class="membrete-logo {{ $membrete->clasesDelLogo() }}" src="{{ $membrete->logoUrl }}" alt="Logo del consultorio">
    @endif

    <div class="membrete-textos">
        @foreach ($membrete->textos() as $texto)
            <p class="membrete-{{ $texto['elemento'] }} {{ $texto['clases'] }}"><span class="caja-de-texto">{{ $texto['texto'] }}</span></p>
        @endforeach
    </div>
</header>
