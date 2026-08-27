@props(['title' => null])
@php
    $metaTitle = $title ?: 'CSPC - Document Control System';
    $metaDescription = 'Camarines Sur Polytechnic Colleges Document Control System for registering, revising, stamping, and reporting controlled documents.';
    $metaImage = asset('images/logo.png');
@endphp
<meta name="description" content="{{ $metaDescription }}">
<meta name="author" content="Camarines Sur Polytechnic Colleges — Records and Freedom of Information Office">
<meta name="theme-color" content="#000080">
<meta property="og:title" content="{{ $metaTitle }}">
<meta property="og:description" content="{{ $metaDescription }}">
<meta property="og:image" content="{{ $metaImage }}">
<meta property="og:type" content="website">
