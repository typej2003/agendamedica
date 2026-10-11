@extends('errors::minimal')

@section('title', 'Demasiados intentos')
@section('code', '429')
@section('mensaje', 'Demasiados intentos seguidos')
@section('detalle', 'Espera un momento antes de volver a intentarlo.')
