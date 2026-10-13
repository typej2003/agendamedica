@props(['membrete'])

{{-- El pie de página de un documento impreso (WEB-5.2): hasta tres líneas (dirección, teléfonos y correo, de
     `evolucion.linea_1..3`) con el estilo que el médico eligió para el pie. --}}
@if ($membrete->lineasDelPie() !== [])
    <footer class="pie-de-pagina">
        @foreach ($membrete->lineasDelPie() as $linea)
            <p class="{{ $membrete->clasesDeTexto('pie') }}"><span class="caja-de-texto">{{ $linea }}</span></p>
        @endforeach
    </footer>
@endif
