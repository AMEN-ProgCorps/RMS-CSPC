@php($error = \App\Support\ErrorDiagnosis::from($exception))
@include('errors.diagnosed')
