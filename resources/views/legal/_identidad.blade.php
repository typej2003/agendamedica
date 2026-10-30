{{-- El bloque del responsable, repetido en los tres documentos: sale de `config/legal.php`. --}}
<div class="legal-identidad">
    <p><strong>{{ config('legal.responsable.razon_social') }}</strong> — RIF {{ config('legal.responsable.rif') }}</p>
    <p>{{ config('legal.responsable.domicilio') }}</p>
    <p>Contacto: <a href="mailto:{{ config('legal.contacto') }}">{{ config('legal.contacto') }}</a></p>
    <p class="legal-vigencia">Vigente desde el {{ \Illuminate\Support\Carbon::parse(config('legal.vigente_desde'))->translatedFormat('j \d\e F \d\e Y') }}.</p>
</div>
