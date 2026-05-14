@extends('layouts.app')

@section('css_files')
@endsection

@section('content') 
    <div class="login-box">
        <div class="logo">
            <a href="javascript:void(0);"><b>{{ $school->code ?? 'RMS' }}</b></a>
            <small>Records Management System</small>
        </div>
        <div class="card">
            <div class="header"><h2 class="align-center">Log-In</h2></div>
            <div class="body">
                <div class="card-body">
                    <form method="POST" action="{{ route('login') }}">
                        @csrf
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">person</i>
                            </span>
                            <div class="form-line">
                                {{-- <input id="emailadd" type="email" class="form-control" name="emailadd" placeholder="email" required autofocus> --}}
                                <input id="uname" type="text" class="form-control" name="uname" placeholder="username" required autofocus>
                            </div>
                            @if ($errors->has('email'))
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $errors->first('email') }}</strong>
                                </span>
                            @endif
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">lock</i>
                            </span>
                            <div class="form-line">
                                <input id="password" type="password" class="form-control" name="password" placeholder="Password" required>
                            </div>
                            @if ($errors->has('password'))
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $errors->first('password') }}</strong>
                                </span>
                            @endif
                        </div>

                        <div class="row">
                            <div class="col-xs-12">
                                <button type="submit" class="btn btn-block bg-light-blue waves-effect">
                                    {{ __('Login') }}
                                </button>
                            </div>
                            {{-- <div class="col-xs-6 align-right">
                                <a href="{{ route('password.request') }}">Forgot Password?</a>
                            </div> --}}
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js_files')
@endsection