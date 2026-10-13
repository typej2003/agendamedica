{{-- Pestaña *Laboratorio* del control de embarazo (WEB-6.6): el laboratorio de la gesta, una fila por
     fecha (`prena_exames_b`). El formulario es el de `_embarazo-fechas`. --}}
@include('clinica.consulta._embarazo-fechas', ['vm' => $vm, 'claveTab' => 'laboratorio'])
