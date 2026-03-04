@extends('layouts.app')

@section('css_files')
    <!-- Multi Select Css -->
    <link href="{{asset('/plugins/multi-select/css/multi-select.css')}}" rel="stylesheet">
    <!-- Bootstrap Select Css -->
    <link href="{{asset('plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="row clearfix">
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
            <div class="card">
                <div class="header">
                    <h2>
                        FUNCTIONAL CATEGORIES OF RECORDS
                    </h2>
                </div>
                <div class="body">
                    <br>
                    @if(count($categories)>0)
                        <ul>
                            @foreach ($categories as $category)
                                @include('inc.category',$category)
                            @endforeach
                        </ul>
                    @else
                        <h3>NO RECORD CATEGORY</h3>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js_files')
    <!-- Bootstrap Colorpicker Js -->
    <script src="{{asset('/plugins/bootstrap-colorpicker/js/bootstrap-colorpicker.js')}}"></script>
    <!-- Dropzone Plugin Js -->
    <script src="{{asset('/plugins/dropzone/dropzone.js')}}"></script>
    <!-- Input Mask Plugin Js -->
    <script src="{{asset('/plugins/jquery-inputmask/jquery.inputmask.bundle.js')}}"></script>
    <!-- Multi Select Plugin Js -->
    <script src="{{asset('/plugins/multi-select/js/jquery.multi-select.js')}}"></script>
    <!-- Select Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-select/js/bootstrap-select.js')}}"></script>
    <!-- Jquery Spinner Plugin Js -->
    <script src="{{asset('/plugins/jquery-spinner/js/jquery.spinner.js')}}"></script>
    
    <script src="{{asset('/js/pages/forms/advanced-form-elements.js')}}"></script>
    <script src="{{asset('/js/pages/ui/tooltips-popovers.js')}}"></script>
@endsection