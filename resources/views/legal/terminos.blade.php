@extends('layouts.app')

@section('title', 'Términos y condiciones · Doctorísimo')
@section('description', 'Las condiciones de uso de Doctorísimo: cuentas, planes, pagos, responsabilidad clínica y jurisdicción.')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}">
@endpush

@section('content')
    <div class="container legal">
        <p class="legal-volver"><a href="{{ route('welcome') }}">&larr; Volver a Doctorísimo</a></p>

        <x-legal.documento tipo="terminos" />
    </div>
@endsection
