{{-- La regionalización del dispositivo (WEB-13.5): detecta orden de fecha y reloj y los deja en la cookie `ddr_region`. --}}
<script src="{{ asset('js/regionalizacion.js') }}?v={{ @filemtime(public_path('js/regionalizacion.js')) }}"></script>
