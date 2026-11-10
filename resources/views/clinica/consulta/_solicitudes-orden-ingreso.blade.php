{{-- La orden de ingreso (Traumatología, T3): delega en el parcial común de las dos solicitudes. --}}
@include('clinica.consulta._solicitudes-solicitud-traumatologia', ['vm' => $vm, 'clave' => 'orden-ingreso'])
