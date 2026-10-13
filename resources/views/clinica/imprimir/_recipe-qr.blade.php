{{-- El código QR de verificación del récipe (WEB-5.6): el dibujo (SVG del servidor, de 16 × 16 mm con su zona de silencio)
     y, a su lado, la dirección donde se verifica y el código corto. Recibe `$qr` (`App\Clinica\Impresion\QrDelRecipe`). En la
     hoja de prueba es un ejemplo rotulado como tal. --}}
<div class="recipe-qr {{ $qr->ejemplo ? 'recipe-qr-ejemplo' : '' }}">
    <div class="recipe-qr-codigo">{!! $qr->svg !!}</div>
    <div class="recipe-qr-leyenda">
        <p class="recipe-qr-linea">Verifique en <strong>{{ $qr->direccion }}</strong></p>
        <p class="recipe-qr-linea">Código <strong class="recipe-qr-numero">{{ $qr->codigo }}</strong></p>
        @if ($qr->ejemplo)
            <p class="recipe-qr-linea recipe-qr-aviso">Código de ejemplo: no verifica ningún récipe.</p>
        @endif
    </div>
</div>
