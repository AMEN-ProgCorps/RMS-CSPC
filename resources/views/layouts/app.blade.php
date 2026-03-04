<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>CSPC RMS</title>
    
    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href = "{{asset($school->logo ?? 'images/favicon.ico')}}" >

    <!-- Favicon-->
    <link rel="icon" href="../../favicon.ico" type="image/x-icon">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&subset=latin,cyrillic-ext" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" type="text/css">

    <!-- Bootstrap Core Css -->
    <link href="{{asset('/plugins/bootstrap/css/bootstrap.css')}}" rel="stylesheet">

    <!-- Waves Effect Css -->
    <link href="{{asset('/plugins/node-waves/waves.css')}}" rel="stylesheet" />

    <!-- Animation Css -->
    <link href="{{asset('/plugins/animate-css/animate.css')}}" rel="stylesheet" />
    
    <!-- Sweetalert Css -->
    <link href="{{asset('/plugins/sweetalert/sweetalert.css')}}" rel="stylesheet" />

    <!-- Custom Css -->
    <link href="{{asset('/css/style.css')}}" rel="stylesheet">
    @yield('css_files')

    <!-- AdminBSB Themes. You can choose a theme from css/themes instead of get all themes -->
    <link href="{{asset('/css/themes/all-themes.css')}}" rel="stylesheet" />

    <!-- Background CSS -->
    <style>
        #login-page { 
            background: url({{$school->background_1 ?? '#000'}}) no-repeat center center fixed; 
            -webkit-background-size: cover;
            -moz-background-size: cover;
            -o-background-size: cover;
            background-size: cover;
        }
    </style>
</head>
    @guest
        <body id="login-page"  class = "login-page" onload="onloadFunction()">
    @else
        <body class = "theme-blue" onload="onloadFunction()">
    @endguest
        @guest
            @yield('content')
        @else
            @include('inc.navbar')
            @include('inc.sidebar')
                <section class="content">
                    @include('inc.messages')
                    @yield('content')
                </section>
        @endguest
        <!-- Jquery Core Js -->
        <script src="{{asset('/plugins/jquery/jquery.min.js')}}"></script>

        <!-- Bootstrap Core Js -->
        <script src="{{asset('/plugins/bootstrap/js/bootstrap.js')}}"></script>

        <!-- SweetAlert Plugin Js -->
        <script src="{{asset('/plugins/sweetalert/sweetalert.min.js')}}"></script>

        <!-- Slimscroll Plugin Js -->
        <script src="{{asset('/plugins/jquery-slimscroll/jquery.slimscroll.js')}}"></script>


        <!-- Waves Effect Plugin Js -->
        <script src="{{asset('/plugins/node-waves/waves.js')}}"></script>

        <!-- Custom Js -->
        <script src="{{asset('/js/admin.js')}}"></script>
        @yield('js_files')

        <!-- Demo Js -->
        <script src="{{asset('/js/demo.js')}}"></script>
    </body>
</html>