@extends('layouts.app')

@section('css_files')
    <!-- Multi Select Css -->
    <link href="{{asset('/plugins/multi-select/css/multi-select.css')}}" rel="stylesheet">
    <!-- Bootstrap Select Css -->
    <link href="{{asset('plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="container-fluid">
        <div class="col-md-12">
            <div class="row clearfix">
                <div class="card">
                    <div class="header"><h2>UPDATE RECORD CATEGORY INFORMATION<h2></div>
                    <div class="body">
                        <form action="{{action('CategoryController@update', $category->id)}}" method="POST">
                            @method('put')
                            @csrf
                            <div class="row clearfix">
                                <div class="col-md-12">
                                    <h2 class="card-inside-title">Select Office(s)</h2>
                                    <select id="optgroup" class="ms" multiple="multiple" name="office_ids[]">
                                        @foreach($offices as $office)
                                            @if (in_array($office->id, $office_ids))
                                                <option value="{!!$office->id!!}" selected>{!!$office->description!!}</option>
                                            @else
                                                <option value="{!!$office->id!!}">{!!$office->description!!}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <h2 class="card-inside-title">Code</h2>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <input type="text" class="form-control" name="code" value="{!!$category->code!!}" placeholder="Code" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <h2 class="card-inside-title">Type: </h2>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <select id="type" class="form-control show-tick" name="type" required>
                                                <option value="1" {!!$category->type == 1 ? "selected": ""!!}>Internal</option>
                                                <option value="2" {!!$category->type == 2 ? "selected": ""!!}>To External</option>
                                                <option value="3" {!!$category->type == 3 ? "selected": ""!!}>From External</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <h2 class="card-inside-title">Description</h2>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <textarea rows="2" class="form-control no-resize" placeholder="Description" name="description">{!!$category->description!!}</textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6" style="{!! $category->parent_id ? 'display:block':'display:none' !!}">
                                    <h5 class="card-inside-title">is Permanent?</h5>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <div class="switch">
                                                <label>No<input type="checkbox" name="isPermanent" id="isPermanent" onclick="displayFunction()" {!! $category->isPermanent? "checked":"" !!}><span class="lever switch-col-cyan"></span>Yes</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-3" id="active" style="{!! $category->isPermanent? 'display:none':'display:block' !!}">
                                    <h4 class="card-inside-title">Active:</h4>
                                    <div class="form-group">
                                        <div class="input-group spinner" data-trigger="spinner">
                                            <div class="form-line">
                                                <input type="text" class="form-control text-center" value="{!!$category->years_active ? $category->years_active : 0!!}" data-rule="currency" id="years_active" name="years_active" data-min="0.0" data-precision="1" data-step="0.1">
                                            </div>
                                            <span class="input-group-addon">
                                                <a href="javascript:;" class="spin-up" data-spin="up"><i class="glyphicon glyphicon-chevron-up"></i></a>
                                                <a href="javascript:;" class="spin-down" data-spin="down"><i class="glyphicon glyphicon-chevron-down"></i></a>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-3" id="storage" style="{!! $category->isPermanent? 'display:none':'display:block' !!}">
                                    <h5 class="card-inside-title">Storage:</h5>
                                    <div class="form-group">
                                        <div class="input-group spinner" data-trigger="spinner">
                                            <div class="form-line">
                                                <input type="text" class="form-control text-center" value="{!!$category->years_storage ? $category->years_storage : 0!!}" data-rule="currency" id="years_storage" name="years_storage" data-min="0.0" data-precision="1" data-step="0.1">
                                            </div>
                                            <span class="input-group-addon">
                                                <a href="javascript:;" class="spin-up" data-spin="up"><i class="glyphicon glyphicon-chevron-up"></i></a>
                                                <a href="javascript:;" class="spin-down" data-spin="down"><i class="glyphicon glyphicon-chevron-down"></i></a>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="button-demo align-right">
                                <a class="btn btn-sm bg-light-blue btn-primary waves-effect" href="/categories">
                                    <span>BACK</span>
                                </a>
                                <button class="btn btn-sm btn-primary waves-effect" type="submit">
                                   <span>UPDATE RECORD CATEGORY</span>
                                </button>
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
    <!-- Jquery Spinner Plugin Js -->
    <script src="{{asset('/plugins/jquery-spinner/js/jquery.spinner.js')}}"></script>
    <!-- Select Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-select/js/bootstrap-select.js')}}"></script>
    <script src="{{asset('/js/pages/forms/advanced-form-elements.js')}}"></script>
    <script>
        $('#optgroup').multiSelect()
        function displayFunction() {
            // Get the checkbox
            var checkBox = document.getElementById("isPermanent");
            // Get the div
            var active = document.getElementById("active");
            var storage = document.getElementById("storage");

            // If the checkbox is checked, display the div
            if (checkBox.checked == false){
                active.style.display = "block";
                storage.style.display = "block";
            } else {
                active.style.display = "none";
                storage.style.display = "none";
            }
        } 
    </script>
@endsection