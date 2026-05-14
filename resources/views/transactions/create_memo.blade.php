@extends('layouts.app')

@section('css_files')
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
    <!-- Animation Css -->
    <link href="{{asset('/plugins/animate-css/animate.css')}}" rel="stylesheet" />
    <!-- Multi Select Css -->
    <link href="{{asset('/plugins/multi-select/css/multi-select.css')}}" rel="stylesheet">
@endsection

@section('content')
    <div class="container-fluid">
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">{{--"col-xs-12 col-sm-12 col-md-8 col-lg-8">--}}
            <div class="row clearfix">
                <div class="card">
                    <div class="header"><h2>Memorandum | Free Flow<h2></div>
                    <div class="body">
                        <form action="/store_transaction" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="row clearfix">
                                @if (Auth::user()->office == 1)
                                    <div class="col-md-6">
                                        <h2 class="card-inside-title">Control #: </h2>
                                        <div class="row clearfix">
                                            <div class="col-md-2">
                                                <div class="form-group">
                                                    <div class="form-line">
                                                        <input name='year' type="text" class="form-control">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="form-group">
                                                    <div class="form-line">
                                                        <input name="month" type="text" class="form-control">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="form-group">
                                                    <div class="form-line">
                                                        <input name="number" type="text" class="form-control">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                                <div class="col-md-12">
                                    <h2 class="card-inside-title">Particulars: </h2>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <textarea rows="4" class="form-control no-resize" name="description" required></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <h2 class="card-inside-title">Notes: </h2>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <textarea rows="4" class="form-control no-resize" name="notes"></textarea>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="isFreeFlow" id="isFreeFlow" value="1">
                                {{-- <div class="col-sm-12">
                                    <h2 class="card-inside-title">Free Flow?</h2>
                                    <div class="switch">
                                        <label>No<input type="checkbox" name="isFreeFlow" id="isFreeFlow" onclick="displayFunction()"><span class="lever switch-col-cyan"></span>Yes</label>
                                    </div>
                                </div> --}}
                                <div class="col-sm-12" id="offices_display" style="display:block">
                                    <h2 class="card-inside-title">Recieving Office(s): </h2>
                                    <select id="optgroup" class="ms" multiple="multiple" name="office_ids[]" required>
                                        @if(count($offices)>0)
                                            @foreach ($offices as $office)
                                                @if ($office->id != Auth::user()->office)
                                                    <option value="{!!$office->id!!}">{!!$office->description!!}</option>
                                                @endif
                                            @endforeach
                                        @endif
                                    </select>
                                </div>
                                <br> <br>
                                <div class="col-sm-6">
                                    <h4 class="card-inside-title">Upload File:</h4>
                                    <div class="form-group">
                                        <div>
                                            <input type="file" name="file" id="file">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-12 align-right">
                                    <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                                        <i class="material-icons">save</i>
                                        <span>CREATE TRANSACTION</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
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

    <script src="{{asset('/js/pages/forms/advanced-form-elements.js')}}"></script>
    <script>
        function displayFunction2() {
            // Get the checkbox
            var checkBox = document.getElementById("isFreeFlow");
            var select = $('#optgroup2'); //document.getElementById("optgroup");
            var multi_select = $('#optgroup');
            
            // Get the div
            var div1 = document.getElementById("flow_display");
            var div2 = document.getElementById("offices_display");

            // If the checkbox is checked, display the div
            if (checkBox.checked == true){
                div1.style.display = "none";
                div2.style.display = "block";
                select.attr('required', false);
                multi_select.attr('required', true);
            } else {
                div1.style.display = "block";
                div2.style.display = "none";
                select.attr('required', true);
                multi_select.attr('required', false);
            }
        }
    </script>
@endsection