{{-- El campo para tipear el código corto de la hoja (`ABCD-EFGH`) cuando el QR no se lee. Es un GET (sin sesión ni CSRF). --}}
<form class="verif-buscar" method="GET" action="{{ $vm->urlBuscar() }}">
    <label class="verif-etiqueta" for="verif-codigo">Código de la hoja</label>
    <div class="verif-buscar-fila">
        <input type="text" id="verif-codigo" name="codigo" class="verif-campo" value="{{ $vm->codigoEscrito() }}"
               placeholder="ABCD-EFGH" maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false"
               @if ($vm->mensajeDelCodigo()) aria-describedby="verif-codigo-error" aria-invalid="true" @endif>
        <button class="verif-boton">Verificar</button>
    </div>
    @if ($vm->mensajeDelCodigo())
        <p class="verif-error" id="verif-codigo-error" role="alert">{{ $vm->mensajeDelCodigo() }}</p>
    @endif
</form>
