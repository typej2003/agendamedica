@extends('errors::minimal')

@section('title', 'Sin permiso')
@section('code', '403')
{{-- Si quien abortó escribió su propio motivo (suele estar en español), se muestra: dice más que el genérico. --}}
@section('mensaje', 'No tienes permiso para hacer esto')
@section('detalle', $exception->getMessage() ?: 'Tu usuario no puede ver esta página o hacer esa acción. Si crees que es un error, avísale a quien administra el consultorio.')
