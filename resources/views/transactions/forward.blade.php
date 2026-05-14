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
    <div class="col-xs-12 col-sm-12 col-md-8 col-lg-8"> {{-- col-lg-6 col-md-6 col-sm-12 col-xs-12 --}}
        <div class="card">
            <div class="header">
                <h2>
                    FORWARD TRANSACTION
                </h2>
            </div>
            <div class="body">
                {{-- Free Flow Transactions --}}
                @if($transaction->freeFlow)
                    <form action="/forwarded_transaction" method="POST">
                        @csrf
                        <div class="row clearfix">
                            <div class="col-sm-12">
                                <h2 class="card-inside-title">Forward to: </h2>
                                <div class="form-group">
                                    <div class="form-line">
                                        <select id="optgroup" class="ms" multiple="multiple" name="office_ids[]" required>
                                            @if(count($offices)>0)
                                                @foreach($offices as $office)
                                                    @if (!in_array($office->id, $ids))
                                                        <option value="{!!$office->id!!}">{!!$office->Code!!}</option>   
                                                    @endif
                                                @endforeach
                                            @endif
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row clearfix">
                            <div class="col-sm-12">
                                <h2 class="card-inside-title">Notes: </h2>
                                <div class="form-group">
                                    <div class="form-line">
                                        <textarea rows="4" class="form-control no-resize" name="notes"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" value="{!!$transaction->id!!}" name="transaction_id">
                        <input type="hidden" value="{!!$current_flow!!}" name="current_flow">
                        <div class="align-right button-demo">
                            <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                                <i class="material-icons">arrow_forward</i>
                                <span>FORWARD TRANSACTION</span>
                            </button>
                        </div>
                    </form>
                {{-- With a Path --}}
                @else
                    <form action="/forwarded_transaction" method="POST">
                        @csrf
                        <div class="row clearfix">
                            <div class="col-sm-12">
                                <h2 class="card-inside-title">Forward to: </h2>
                                <div class="form-group">
                                    <div class="form-line">
                                        <select id="office_id" class="form-control show-tick" name="office_id" title="Select Office:" required>
                                            @if(count($offices)>0)
                                                @foreach($offices as $office)
                                                    @if($transaction->path[$current_flow]->office_id == $office->id)
                                                        <option value="{!!$office->id!!}" selected>{!!$office->description!!}</option>
                                                    @else
                                                        <option value="{!!$office->id!!}">{!!$office->description!!}</option>  
                                                    @endif
                                                @endforeach
                                            @endif
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row clearfix">
                            <div class="col-sm-12">
                                <h2 class="card-inside-title">Notes: </h2>
                                <div class="form-group">
                                    <div class="form-line">
                                        <textarea rows="4" class="form-control no-resize" name="notes"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" value="{!!$transaction->id!!}" name="transaction_id">
                        <input type="hidden" value="{!!$current_flow!!}" name="current_flow">
                        <input type="hidden" value="{!!$transaction->path[$current_flow]->office_id!!}" name="next_office">
                        <div class="align-right button-demo">
                            <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                                <i class="material-icons">arrow_forward</i>
                                <span>FORWARD TRANSACTION</span>
                            </button>
                        </div>
                    </form>
                @endif
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
@endsection