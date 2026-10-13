{{-- El eco obstétrico del 2.º y 3.er trimestre (WEB-6.2): el mismo parcial que el del primer trimestre, que ya

     arma la tarjeta con el formulario de los bloques del mapa que le pase su ViewModel
     (`EcoObstetricoTerceroVista`). El contenedor de la sección busca `_estudio-<clave>`, así que este archivo
     existe para que `&tipo=tercero` tenga su parcial, como los de las pestañas del embarazo. --}}
@include('clinica.consulta._estudio-obstetrico', ['vm' => $vm])
