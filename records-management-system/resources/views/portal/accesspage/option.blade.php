@extends('portal.login_head')

@section('styles')
    @vite('resources/css/accesspoint.css')
    <style>
        body {
            background-image: url('{{ asset('images/1cw2k34d.webp') }}');
        }
    </style>
@endsection

@section('content')
    <header>
        <span class="office-name">Records and Freedom of Information Office</span>
    </header>
    <section>
        <div class="logout-con">
            <button title="Logout" class="logout" onclick="window.location.href='{{ route('logout') }}'">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    <polyline points="16 17 21 12 16 7" /><line x1="21" y1="12" x2="9" y2="12" />
                </svg>
            </button>
        </div>
        <div class="portal_header">
            <div class="ico_con">
                <img class="ico" src="{{ asset('images/cspc.png') }}" alt="CSPC">
            </div>
            <span>Welcome, {User}</span>
        </div>
        <div class="systems-container">
            <button class="system-con" id="profile" onclick="window.location.href='{{ route('dts') }}'">
                <div class="display-box">
                    <span>Profile</span>
                </div>
            </button>
            <button class="system-con" id="dts" onclick="window.location.href='{{ route('dts') }}'">
                <div class="display-box">
                    <span>Document Tracking System</span>
                </div>
            </button>
        </div>
    </section>
    <footer>
        <div class="copy-right">Copyright 2026. All Rights Reserved.</div>
    </footer>
@endsection
@section('scripts')

@endsection