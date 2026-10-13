{{-- Pestaña *Controles* del control de embarazo (WEB-6.6): los controles de la gesta, una fila por fecha
     (`pre_natal_desarrollo_fino`). El formulario es el de `_embarazo-fechas`. --}}
@include('clinica.consulta._embarazo-fechas', ['vm' => $vm, 'claveTab' => 'controles'])
