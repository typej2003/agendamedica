@extends('layouts.app')

@section('content')
    <div>
        {{--
            Aquí decidimos qué componente de dashboard mostrar.
            Asumimos que el rol del usuario está en `auth()->user()->rol`.
            Ajusta la condición según tu lógica de roles.
        --}}
        @if(auth()->user()->rol === 'root')
            @livewire('dashboard.root-dashboard')
        @else
            {{-- @livewire('dashboard.user-dashboard') --}}
            <p>Dashboard para otros usuarios.</p>
        @endif
    </div>
@endsection