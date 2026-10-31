@extends('layouts.app')

@section('title', 'Política de privacidad · Doctorísimo')
@section('description', 'Qué datos trata Doctorísimo, para qué, con quién se comparten y cómo pedir su eliminación.')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}">
@endpush

@section('content')
    <div class="container legal">
        <p class="legal-volver"><a href="{{ route('welcome') }}">&larr; Volver a Doctorísimo</a></p>

        <x-legal.documento tipo="privacidad" />
    </div>
@endsection
