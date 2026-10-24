{{--
    Favicon de la app (corazón-pulso de la marca sobre violeta).

    Va con `asset()` y no con `/favicon...` para que en QA, donde la app vive bajo
    `/medic-report`, el navegador pida `https://ddrsistemas.com/medic-report/favicon...` y no la
    raíz del dominio (que daba 404). Los archivos se generaron desde `favicon.svg`.
--}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
