@extends('layouts.app')

@section('css_files')
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
    <!-- Animation Css -->
    <link href="{{asset('/plugins/animate-css/animate.css')}}" rel="stylesheet" />
    <!-- Multi Select Css -->
    <link href="{{asset('/plugins/multi-select/css/multi-select.css')}}" rel="stylesheet">
    <!-- Bootstrap DatePicker Css -->
    <link href="{{asset('/plugins/bootstrap-datepicker/css/bootstrap-datepicker.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="container-fluid">
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
            <div class="row clearfix">
                <div class="card">
                    <div class="header"><h2>UPDATE RECORD</h2></div>
                    <div class="body" >
                        <form action="{{ action('RecordsController@update', $record->id) }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            @method('put')
                            <div class="row clearfix">
                                <div class="col-sm-12">
                                    <h4 class="card-inside-title">Code: </h4>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <select id="code" class="form-control show-tick" name="category_id" title="Select Record Code:" required>
                                                @if(count($categories)>0)
                                                    @foreach($categories as $category)
                                                        <optgroup label="{!!$category->code!!}) {!!$category->description!!}">
                                                            @foreach ($category->child->sortBy('code', SORT_NATURAL, false)->values() as $opt)
                                                                @if ($opt->id == $record->category_id)
                                                                    <option value="{{$opt->id}}" selected>{!!$opt->code!!}) {!!$opt->description!!}</option>
                                                                @else
                                                                    <option value="{{$opt->id}}">{!!$opt->code!!}) {!!$opt->description!!}</option>
                                                                @endif
                                                            @endforeach
                                                        </optgroup>
                                                    @endforeach
                                                @endif
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-12">
                                    <h4 class="card-inside-title">CF to Office(s):</h4>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <select id="optgroup" class="ms" multiple="multiple" name="office_ids[]">
                                                @if(count($offices)>0)
                                                    @foreach($offices as $office)
                                                        @if(Auth::user()->office != $office->id)   
                                                            @if (in_array($office->id, $office_ids))
                                                                <option value="{{$office->id}}" selected>{!!$office->description!!}</option>
                                                            @else
                                                                <option value="{{$office->id}}">{!!$office->description!!}</option>
                                                            @endif
                                                        @endif
                                                    @endforeach
                                                @endif
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-12">
                                    <h4 class="card-inside-title">Description: </h4>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <textarea rows="4" class="form-control no-resize disabled" name="description" id="description" required>{!!$record->description!!}</textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <h4 class="card-inside-title">Start Date: </h4>
                                    <div class="form-group">
                                        <div id="bs_datepicker_container">
                                            @php
                                                $date = explode('-', $record->start_date);
                                            @endphp
                                            <input type="text" class="form-control" placeholder="Please choose a date..." name="start_date" value="{{$date[1]."/".$date[2]."/".$date[0]}}" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <h4 class="card-inside-title">Upload File:</h4>
                                    <div>
                                        <input type="file" name="file" id="file">
                                        <h6>(Set BLANK if you don't intend to replace existing file)</h6>
                                    </div>
                                </div>
                                <div class="col-sm-12 align-right">
                                    <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                                        <i class="material-icons">update</i>
                                        <span>UPDATE RECORD</span>
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
<!-- Autosize Plugin Js -->
<script src="{{asset('/plugins/autosize/autosize.js')}}"></script>
<!-- Moment Plugin Js -->
<script src="{{asset('/plugins/momentjs/moment.js')}}"></script>
<!-- Bootstrap Material Datetime Picker Plugin Js -->
<script src="{{asset('/plugins/bootstrap-material-datetimepicker/js/bootstrap-material-datetimepicker.js')}}"></script>
<!-- Bootstrap Datepicker Plugin Js -->
<script src="{{asset('/plugins/bootstrap-datepicker/js/bootstrap-datepicker.js')}}"></script>

<script src="{{asset('/js/pages/forms/basic-form-elements.js')}}"></script>
<script src="{{asset('/js/pages/forms/advanced-form-elements.js')}}"></script>
@endsection