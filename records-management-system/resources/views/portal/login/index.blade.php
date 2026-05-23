@extends('portal.login_head')

@section('styles')
    @vite('resources/css/login.css')
    <style>
        body {
            background-image: url('{{ asset('images/1cw2k34d.webp') }}');
        }
    </style>
@endsection

@section('content')
    <div class="body-container">
        <div class="td-container">
            <button class="td-button" onclick="window.location.href='{{ route('track-document') }}'">
                Track Document
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="24" viewBox="0 0 48 24">
                    <path fill="currentColor" d="M2,11V13H40L34.5,18.5L35.92,19.92L43.84,12L35.92,4.08L34.5,5.5L40,11H2Z" />
                </svg>
            </button>
        </div>
        <div class="header-container">
            <div class="institute">CSPC</div>
            <div class="system">Records Management System</div>
        </div>
        <div class="login-container">
            <div class="logo-container">
                <div class="logo">
                    <img src="{{ asset('images/cspc.png') }}" alt="CSPC Logo">
                </div>
            </div>
            <div class="lo-login">LOGIN</div>
            <div class="lo-instruction">Please Enter your login details to login your account</div>
            <form method="POST" action="{{ route('login.submit') }}" class="lo-form">
                    @csrf
                    <div class="form-group">
                        <div class="icon-usr">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z" />
                            </svg>
                        </div>
                        <input autocomplete="username" type="username" id="username" name="username" placeholder="Username" required>
                    </div>
                    <div class="form-group">
                        <div class="icon-psk">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M12,17A2,2 0 0,0 14,15C14,13.89 13.11,13 12,13A2,2 0 0,0 10,15A2,2 0 0,0 12,17M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V10C4,8.89 4.9,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z" />
                            </svg>
                        </div>
                        <input autocomplete="current-password" type="password" id="password" name="password" placeholder="Password" required>
                    </div>
                    <button class="la-button" type="submit">LOGIN</button>
            </form>
            <span class="notice">RECORDS AND FREEDOM OF INFORMATION OFFICE</span>
        </div>
    </div>
@endsection
