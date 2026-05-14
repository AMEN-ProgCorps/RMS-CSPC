@extends('layouts.app')

@section('css_files')
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
    <style>
        .barcode{
            text-align: center;
            font-size: 14px;
            font-family: 'Times New Roman', Times, serif;
        }
    </style>
@endsection

@section('content')
    <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
        <div class="card">
            <div class="body">
                <div class="row clearfix">
                    <div class="col-sm-6">
                        <h2 class="card-inside-title">Date Created: </h2>
                        <div class="form-group">
                            <div class="form-line disabled">
                                <h2 type="text" class="form-control">{!!$transaction->Date_created!!}</h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <h2 class="card-inside-title">Created By: </h2>
                        <div class="form-group">
                            <div class="form-line disabled">
                                <h2 type="text" class="form-control">{!!$transaction->office->description!!}</h2>
                            </div>
                        </div>
                    </div>
                    @if ($transaction->control_no != NULL)
                        <div class="col-sm-3">
                            <h2 class="card-inside-title">Control #: </h2>
                            <div class="form-group">
                                <div class="form-line disabled">
                                    <h2 type="text" class="form-control"><span>{!!$transaction->control_no!!}</span></h2>
                                </div>
                            </div>
                        </div>
                        @if (Auth::user()->office == 1)
                            <div class="col-sm-3">
                                <br><br><br>
                                <span>
                                    <a href="#" data-toggle="modal" data-target="#updateControlNo" data-transaction_id="{!!$transaction->id!!}">
                                        Update
                                    </a>
                                    |
                                    <a href="#" data-toggle="modal" data-target="#deleteControlNo" data-transaction_id="{!!$transaction->id!!}">
                                        Delete
                                    </a>
                                </span>
                            </div>
                        @endif
                    @else
                        @if(Auth::user()->office == 1)
                            <div class="col-sm-3">
                                {{-- <h2 class="card-inside-title">Control #: </h2> --}}
                                <span>
                                    <a href="#" data-toggle="modal" data-target="#addControlNo" data-transaction_id="{!!$transaction->id!!}">
                                        Add Control No.
                                    </a>
                                </span>
                            </div>
                        @endif
                    @endif
                    <div class="col-sm-12">
                        <h2 class="card-inside-title">Particulars: </h2>
                        <div class="form-group">
                            <textarea rows="4" class="form-control no-resize disabled" readonly>{!!$transaction->description!!}</textarea>
                        </div>
                    </div>
                    {{-- Free Flow Transactions --}}
                    @if($transaction->freeFlow)
                        @if(count($transaction->status)>0)
                            <div class="col-sm-12">
                                <h2 class="card-inside-title">Receiving Office(s): </h2>
                                <div class="form-group">
                                    <table class="table table-hover" style="width: 100%">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Office</th>
                                                <th>From</th>
                                                <th>Date In</th>
                                                <th>Notes</th>
                                                <th>Action</th>
                                                <th>Info</th>
                                                <th style="width: 15%"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($transaction->status as $index =>$status)
                                                {{-- @if($transaction->requestorid == Auth::user()->office ||( $status->originating_office == Auth::user()->office || $status->office_id == Auth::user()->office) || $status->flow == 0) --}}
                                                    <tr>
                                                        <th scope="row">{{$status->flow + 1}}</th>
                                                        <td>{{$status->office->Code}}</td>
                                                        @if ($index == 0)
                                                            <td>N/A</td>
                                                            <td>N/A</td>
                                                        @else
                                                            <td>{{$status->originating_office_details->Code}}</td>
                                                            <td>{{ $status->date_in == NULL ? 'Not Yet Received' : $status->date_in }}</td>
                                                        @endif
                                                        <td>
                                                            @if($status->notes && $index > 0)
                                                                <div class="icon-button-demo m-t-25">
                                                                    <button type="button" class="btn btn-default btn-circle waves-effect waves-circle waves-float" data-trigger="focus" data-container="body" data-toggle="popover"
                                                                            data-placement="top" title="Notes from {!!$status->originating_office_details->Code!!}" data-content="{!!$status->notes!!}">
                                                                        <i class="material-icons">more_horiz</i>
                                                                    </button>
                                                                </div>
                                                            @endif
                                                        </td>
                                                        <td>
                                                            @if ($status->action)
                                                                <div class="icon-button-demo m-t-25">
                                                                    <button type="button" class="btn btn-default btn-circle waves-effect waves-circle waves-float" data-trigger="focus" data-container="body" data-toggle="popover"
                                                                            data-placement="top" title="Action Taken" data-content="{!!$status->action!!}">
                                                                        <i class="material-icons">more_horiz</i>
                                                                    </button>
                                                                </div>
                                                            @endif
                                                        </td>
                                                        <td>
                                                            @php
                                                                $text = NULL;
                                                                if($status->forwarded_by != NULL){
                                                                    $text = 'Forwarded By: '.$status->forwarder->lname.', '.$status->forwarder->fname.' '.$status->forwarder->mname.'<br>';
                                                                }
                                                                if($status->received_by != NULL){
                                                                    $text = $text.'Received By: '.'&nbsp&nbsp'.$status->receiver->lname.', '.$status->receiver->fname.' '.$status->receiver->mname.'<br>';
                                                                }
                                                            @endphp
                                                            @if ($text != NULL && $index > 0)
                                                                <div class="icon-button-demo m-t-25">
                                                                    <button type="button" class="btn btn-default btn-circle waves-effect waves-circle waves-float" data-trigger="focus" data-container="body" data-toggle="popover"
                                                                            data-placement="top" title="" data-content="{!!$text!!}" data-html="true">
                                                                        <i class="material-icons">more_horiz</i>
                                                                    </button>
                                                                </div>
                                                            @endif
                                                        </td>
                                                        <td style="width: 10%" class="align-center">
                                                            @if ($status->office_id == Auth::user()->office && !$transaction->completed)
                                                                @if($status->originating_office != 0 && $status->date_in != NULL)
                                                                    <a href="#" data-toggle="modal" data-target="#addActionModal" data-barcode="{!!$status->barcode_value!!}" data-flow="{!!$status->flow!!}" data-action="{!!$status->action!!}">
                                                                        @if ($status->action == NULL)
                                                                            Action Taken
                                                                        @else
                                                                            Edit Action
                                                                        @endif
                                                                    </a>
                                                                    |
                                                                @endif
                                                                @if($status->originating_office == 0 || $status->date_in != NULL)
                                                                    <a href="/forward_transaction/{!!$status->transaction->freeFlow!!}/{!!$status->transaction->id!!}/{!!$status->flow!!}">
                                                                        Forward
                                                                    </a>
                                                                @endif
                                                            @endif
                                                            @if (($status->originating_office == Auth::user()->office && $status->date_in == NULL) && !$transaction->completed)
                                                                <a href="#" data-toggle="modal" data-target="#deleteStatus" data-office_id="{!!$status->office_id!!}" data-barcode="{!!$status->barcode_value!!}" data-flow="{!!$status->flow!!}" data-office="{!!$status->office->description!!}">
                                                                    Delete{{-- <i class="material-icons" style="width: 25%; height: 25%"/>delete</i> --}}
                                                                </a>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                {{-- @endif --}}
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @else 
                            <div class="col-sm-12"><h5>NO RECEIVING OFFICES!!!</h5></div>
                        @endif
                    {{--With Specified Path--}}
                    @else
                        @if(count($transaction->status)>0)
                            <div class="col-sm-12">
                                <h2 class="card-inside-title">Transaction Path: </h2>
                                <div class="form-group">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Office</th>
                                                <th>Date In</th>
                                                <th>Date Out</th>
                                                <th>Status</th>
                                                <th>Notes</th>
                                                <th>Info</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($transaction->status as $index => $status)
                                                <tr>
                                                    <th>{{$index + 1}}</th>
                                                    <td>{{$status->office->Code}}</td>
                                                    <td>
                                                        @if (!$status->date_in)
                                                            @if($status->flow == 1)
                                                                N/A
                                                            @else
                                                                Not Yet Received
                                                            @endif
                                                        @else
                                                            {{$status->date_in}}
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if(!$status->date_out)
                                                            @if ($status->status == 'Completed')
                                                                {{-- BLANK --}}
                                                            @else
                                                                @if (!$status->date_in)
                                                                    {{-- BLANK --}}
                                                                @else
                                                                    Current
                                                                @endif
                                                            @endif
                                                        @else
                                                            {!!$status->date_out!!}
                                                        @endif
                                                    </td>
                                                    <td>{{$status->status}}</td>
                                                    <td>
                                                        @if($status->notes && $index > 0)
                                                            <div class="icon-button-demo m-t-25">
                                                                <button type="button" class="btn btn-default btn-circle waves-effect waves-circle waves-float" data-trigger="focus" data-container="body" data-toggle="popover"
                                                                        data-placement="top" title="Notes from {!!$status->originating_office_details->Code!!}" data-content="{{$status->notes}}">
                                                                    <i class="material-icons">more_horiz</i>
                                                                </button>
                                                            </div>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @php
                                                            $text = NULL;
                                                            if($status->forwarded_by != NULL){
                                                                $text = 'Forwarded By: '.$status->forwarder->lname.', '.$status->forwarder->fname.' '.$status->forwarder->mname.'<br>';
                                                            }
                                                            if($status->received_by != NULL){
                                                                $text = $text.'Received By: '.'&nbsp&nbsp'.$status->receiver->lname.', '.$status->receiver->fname.' '.$status->receiver->mname.'<br>';
                                                            }
                                                        @endphp
                                                        @if ($text != NULL && $index > 0)
                                                            <div class="icon-button-demo m-t-25">
                                                                <button type="button" class="btn btn-default btn-circle waves-effect waves-circle waves-float" data-trigger="focus" data-container="body" data-toggle="popover"
                                                                        data-placement="top" title="" data-content="{!!$text!!}" data-html="true">
                                                                    <i class="material-icons">more_horiz</i>
                                                                </button>
                                                            </div>
                                                        @endif
                                                    </td>
                                                    <td class="align-center">
                                                        @if (!$transaction->completed)
                                                            @if ($status->office_id == Auth::user()->office && $status->date_out == NULL && $status->date_in != NULL)
                                                                <a href="/return_transaction/{!!$transaction->id!!}">
                                                                    Return
                                                                </a>
                                                                @php
                                                                    $show_forward = true;
                                                                    if($transaction->path()->count() == $status->chrono){
                                                                        $show_forward = false;
                                                                    }
                                                                @endphp
                                                                @if ($show_forward)
                                                                    |
                                                                    <a href="/forward_transaction/{!!$transaction->freeFlow!!}/{!!$transaction->id!!}/{!!$status->chrono!!}">
                                                                        Forward
                                                                    </a>
                                                                @endif
                                                            @endif
                                                            @if (($status->originating_office == Auth::user()->office && $status->date_in == NULL) && $status->flow > 2)
                                                                <a href="#" data-toggle="modal" data-target="#deleteStatus" data-office_id="{!!$status->office_id!!}" data-barcode="{!!$status->barcode_value!!}" data-flow="{!!$status->flow!!}" data-office="{!!$status->office->description!!}">
                                                                    Delete
                                                                </a>
                                                            @endif
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @else
                            <div class="col-sm-12"><h5>NO TRANSACTION PATH!!!</h5></div>
                        @endif
                    @endif
                    {{-- DISPLAY CF --}}
                    @if (count($transaction->copy_furnished) > 0)
                        <div class="col-sm-6">
                            <h4 class="card-inside-title">CF: </h4>
                            <div class="form-group">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Office</th>
                                            <th>Date In</th>
                                            <th>Received By</th>
                                            @if((Auth::user()->office == 1 || Auth::user()->priv != "Standard User" || Auth::user()->office == $transaction->requestorid) && !$transaction->completed)
                                                <th></th>
                                            @endif
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($transaction->copy_furnished as $i => $cf)
                                            <tr>
                                                <th scope="row">{{$i + 1}}</th>
                                                <td>{{$cf->office->Code}}</td>
                                                <td>{{$cf->date_in ? $cf->date_in : "Not Yet Received"}}</td>
                                                <td>
                                                    @php
                                                        $text = NULL;
                                                        if($cf->received_by != NULL){
                                                            $text = $cf->receiver->lname.', '.$cf->receiver->fname.' '.$cf->receiver->mname.'<br>';
                                                        }
                                                    @endphp
                                                    @if ($text != NULL && $cf->received_by != NULL)
                                                        {!!$text!!}
                                                    @endif
                                                </td>
                                                @if((Auth::user()->office == 1 || Auth::user()->priv != "Standard User" || Auth::user()->office == $transaction->requestorid) && !$transaction->completed)
                                                    <td>
                                                        <a href="#" data-toggle="modal" data-target="#deleteCF" data-barcode="{!!$cf->barcode_value!!}" data-office_id="{!!$cf->office_id!!}">
                                                            Delete{{-- <i class="material-icons" style="width: 25%; height: 25%"/>delete</i> --}}
                                                        </a>
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                    {{-- END DISPLAY CF --}}
                    {{-- DISPLAY BUTTONS --}}
                    <div class="col-sm-12 button-demo align-right">
                        {{--Display Download button for Memo--}}
                        @if ($transaction->freeFlow)
                            @if ($transaction->upload_location)
                                <a href="/memo/download/{!!$transaction->id!!}" class="btn btn-sm bg-light-blue waves-effect" type="button">
                                    <i class="material-icons"/>file_download</i>
                                    <span>DOWNLOAD FILE</span>
                                </a>
                                @if (Auth::user()->office == 1 || Auth::user()->priv != "Standard User" || Auth::user()->office == $transaction->requestorid)
                                    <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#deleteMemo" data-transaction_id="{{$transaction->id}}">
                                        <i class="material-icons"/>clear</i>
                                        <span>DELETE FILE</span>
                                    </a>
                                @endif
                            @else
                                @if (Auth::user()->office == 1 || Auth::user()->priv != "Standard User" || Auth::user()->office == $transaction->requestorid)
                                    <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#uploadMemo" data-transaction_id="{{$transaction->id}}">
                                        <i class="material-icons">file_upload</i>
                                        <span>UPLOAD FILE</span>
                                    </a>
                                @endif
                            @endif
                        @endif
                        @if (!$transaction->freeFlow && !$transaction->completed)
                            <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#listedPathModal" data-path="{{$transaction->path}}">
                                <i class="material-icons">visibility</i>
                                <span>VIEW LISTED PATH</span>
                            </a>
                        @endif
                        {{-- Check if Completed button should be displayed --}}
                        @php
                            $display_completed = false;
                            if(!$transaction->completed){
                                if($transaction->freeFlow){
                                    if($transaction->requestorid == Auth::user()->office || Auth::user()->office == 1 || Auth::user()->priv != "Standard User"){
                                        $display_completed = true;
                                    }
                                }else{
                                    if($transaction->requestorid == Auth::user()->office || $transaction->current_location->id == Auth::user()->office || Auth::user()->office == 1 || Auth::user()->priv != "Standard User"){
                                        $display_completed = true;
                                    }
                                }
                            }
                        @endphp
                        @if($display_completed)
                            <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#completeTransaction" data-id="{!!$transaction->id!!}" data-particulars="{!!$transaction->description!!}">
                                <i class="material-icons">done</i>
                                <span>COMPLETED</span>
                            </a>
                        @endif
                        @if($transaction->requestorid == Auth::user()->office || Auth::user()->office == 1 || Auth::user()->priv != "Standard User")
                            @if(!$transaction->completed)
                                <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#updateTransaction" data-id="{!!$transaction->id!!}"
                                    data-particulars="{!!$transaction->description!!}">
                                    <i class="material-icons">edit</i>
                                    <span>EDIT</span>
                                </a>
                            @endif
                            <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#deleteTransaction" data-id="{!!$transaction->id!!}" data-particulars="{!!$transaction->description!!}">
                                <i class="material-icons">delete</i>
                                <span>DELETE</span>
                            </a>
                        @endif
                        @if((Auth::user()->office == 1 || Auth::user()->priv != "Standard User" || Auth::user()->office == $transaction->requestorid) && !$transaction->completed)
                            <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#addCF" data-id="{!!$transaction->Barcode!!}">
                                <i class="material-icons">add</i>
                                <span>ADD CF</span>
                            </a>
                        @endif
                        <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#barcodeModal">
                            <i class="material-icons">view_week</i>
                            <span>BARCODE</span>
                        </a>
                    </div>
                    {{-- END DISPLAY BUTTONS --}}
                </div>
            </div>
        </div>
    </div>
    @if ( $display_completed)
        {{-- complete Transaction Modal --}}
        <div class="modal fade" id="completeTransaction" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="defaultModalLabel">COMPLETED TRANSACTION?</h4>
                    </div>
                    <div class="modal-body">
                        <form action="/complete_transaction" method="POST">
                            @csrf
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="material-icons">done</i>
                                </span>
                                <h4 id="particulars"></h4>
                                <h6>Offices Can No Longer Receive This Transaction After This!</h6>
                            </div>
                            <input type="hidden" id="id" name="id">
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect"> YES </button>
                                <br>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                    </div>
                </div>
            </div>
        </div>
        {{-- End complete Transaction Modal --}}
    @endif
    @if (!$transaction->freeFlow)
        {{-- Show Listed Path Modal --}}
        <div class="modal fade" id="listedPathModal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="defaultModalLabel">LISTED TRANSACTION PATH</h4>
                    </div>
                    <div class="modal-body">
                        @if (!$transaction->path_change)
                            @if(count($transaction->path) > 0)
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <h4 class="card-inside-title">{!!$transaction->path_type->description!!}</h4>
                                        <div class="form-line">
                                            <ul class="list-group">
                                                @foreach ($transaction->path as $i=>$path)
                                                    <li class="list-group-item">{!!$i+1!!}) {!!$path->office->description!!}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            @else
                                <h5>NO LISTED PATH</h5>
                            @endif
                        @else
                        <div class="col-sm-12">
                            <div class="form-group">
                                <h4 class="card-inside-title">LISTED TRANSACTION PATH WAS CHANGED!!!</h4>
                                <div class="form-line">
                                </div>
                            </div>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <div class="align-right button-demo col-sm-12">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        {{-- End Show Listed Path Modal --}}
    @endif
    {{-- Barcode Modal --}}
    <div class="modal fade" id="barcodeModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">BARCODE</h4>
                </div>
                <div class="modal-body" id="print" class="aligh-right">
                    @php
                        //change false to true for the value to be displayed
                        echo '<img src="data:image/png;base64,' . DNS1D::getBarcodePNG($transaction->Barcode, "C128A",3.5,25,array(1,1,1), false) . '" alt="barcode"   />';
                    @endphp
                    <h5 class="barcode">{{$transaction->Barcode}}</h5>
                </div>
                <div class="col-sm-12">
                    <h4 class="card-inside-title">Orientation: </h4>
                    <select id="orientation" class="form-control show-tick" name="orientation" required>
                        <option value="portrait" selected>Portrait</option>
                        <option value="landscape">Landscape</option>
                    </select>
                </div>
                <input type="hidden" id="transaction_id" value="{!!$transaction->id!!}">
                <div class="col-sm-12">
                    <br><br>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">Close</button>
                    <a role="button" class="btn btn-link waves-effect" onclick="print()">
                        <span>Print</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
    {{-- End Barcode Modal --}}
    @if($transaction->requestorid == Auth::user()->office || Auth::user()->office == 1 || Auth::user()->priv != "Standard User")
        {{-- Delete Transaction Modal --}}
        <div class="modal fade" id="deleteTransaction" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="defaultModalLabel">ARE YOU SURE YOU WANT TO DELETE TRANSACTION?</h4>
                    </div>
                    <div class="modal-body">
                        <form action="/delete_transaction" method="POST">
                            @csrf
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="material-icons">delete</i>
                                </span>
                                <h4 id="particulars"></h4>
                            </div>
                            <input type="hidden" id="id" name="id">
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect">DELETE TRANSACTION</button>
                                <br>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                    </div>
                </div>
            </div>
        </div>
        {{-- End Delete Transaction Modal --}}
        @php
            if($transaction->freeFlow){
                $notes = $transaction->notes;
            }else{
                $notes = $transaction->status[1]->notes;
            }
        @endphp
        {{-- Update Transaction Modal --}}
        <div class="modal fade" id="updateTransaction" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="defaultModalLabel">UPDATE TRANSACTION DETAILS?</h4>
                    </div>
                    <div class="modal-body">
                        <form action="/update_transaction" method="POST">
                            @csrf
                            <div class="row clearfix">
                                <div class="col-md-12">
                                    <h4 class="card-inside-title">Particulars: </h4>
                                    <div class="form-group">
                                        <div class="form-line"><textarea rows="4" class="form-control no-resize" id="particulars" name="description" required>{!!$transaction->description!!}</textarea></div>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <h4 class="card-inside-title">Notes: </h4>
                                    <div class="form-group">
                                        <div class="form-line"><textarea rows="4" class="form-control no-resize" name="notes" id="notes">{!!$notes!!}</textarea></div>
                                    </div>
                                </div>
                                <input type="hidden" name="id" value="{!!$transaction->id!!}">
                            </div>
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect">UPDATE TRANSACTION</button>
                                <br>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                    </div>
                </div>
            </div>
        </div>
        {{-- End Update Transaction Modal  --}}
    @endif
    {{-- MODAL for CF --}}
    @if (Auth::user()->office == 1 || Auth::user()->priv != "Standard User" || Auth::user()->office == $transaction->requestorid)
        {{-- ADD CF --}}
        <div class="modal fade" id="addCF" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="defaultModalLabel">ADD COPY FURNISHED</h4>
                    </div>
                    <div class="modal-body">
                        <form action="{{action('TransactionsCFController@store')}}" method="POST">
                            @csrf
                            <div class="row clearfix">
                                <div class="col-sm-12">
                                    <h4 class="card-inside-title">Choose Office(s): </h4>
                                    <select id="office_ids" class="form-control show-tick" name="office_ids[]" required multiple> {{--data-live-search="true" --}}
                                        @foreach ($offices as $office)
                                            @if (!in_array($office->id, $cf_ids)) 
                                                <option value="{!!$office->id!!}">{!!$office->description!!}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                                <input type="hidden" name="barcode" value="{!!$transaction->Barcode!!}">
                            </div>
                            <br><br>
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect"> ADD CF </button>
                                <br>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                    </div>
                </div>
            </div>
        </div>
        {{-- END ADD CF --}}
        {{-- DELETE CF  --}}
        <div class="modal fade" id="deleteCF" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="defaultModalLabel">ARE YOU SURE YOU WANT TO DELETE?</h4>
                    </div>
                    <div class="modal-body">
                        <form action="{{ action('TransactionsCFController@destroy', 'delete') }}" method="POST">
                            @method('delete')
                            @csrf
                            <input type="hidden" id="barcode" name="barcode">
                            <input type="hidden" id="office_id" name="office_id">
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal"> CLOSE </button>
                                <button type="submit" class="btn btn-link waves-effect"> DELETE </button>
                                <br>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                    </div>
                </div>
            </div>
        </div>
        {{-- END DELETE CF --}}
    @endif 
    {{-- End Modal for CF --}}
    {{-- Delete Status Modal --}}
    <div class="modal fade" id="deleteStatus" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">ARE YOU SURE YOU WANT TO DELETE THE FOLLOWING?</h4>
                </div>
                <div class="modal-body">
                    <form action="/delete_status" method="POST">
                        @csrf
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">delete</i>
                            </span>
                            <h4 id="office"></h4>
                        </div>
                        <input type="hidden" id="office_id" name="office_id">
                        <input type="hidden" id="flow" name="flow">
                        <input type="hidden" id="barcode_value" name="barcode_value">
                        <div class="align-right">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">DELETE</button>
                            <br>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                </div>
            </div>
        </div>
    </div>
    {{-- End Delete Status Modal --}}
    {{-- Add Action Modal for free flow only --}}
    <div class="modal fade" id="addActionModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    {{-- <h4 class="modal-title" id="defaultModalLabel">ADD ACTION</h4> --}}
                </div>
                <div class="modal-body">
                    <form action="/add_action" method="POST">
                        @csrf
                        <div class="row clearfix">
                            <div class="col-md-12">
                                <h4 class="card-inside-title">Action Taken: </h4>
                                <div class="form-group">
                                    <div class="form-line"><textarea rows="4" class="form-control no-resize" id="action" name="action" required></textarea></div>
                                </div>
                            </div>
                            <input type="hidden" name="barcode" id="barcode">
                            <input type="hidden" name="flow" id="flow">
                        </div>
                        <div class="align-right">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">SAVE</button>
                            <br>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                </div>
            </div>
        </div>
    </div>
    {{-- End Add Action Modal for free flow only --}}
    @if (Auth::user()->office == 1)
        {{-- Add Control No --}}
        <div class="modal fade" id="addControlNo" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="defaultModalLabel">ADD CONTROL NO.</h4>
                    </div>
                    <div class="modal-body">
                        <form action="/add_control_no" method="POST">
                            @csrf
                            <div class="row clearfix">
                                @if (Auth::user()->office == 1)
                                @php
                                    $curYear = date('Y');
                                    $curMonth = date('m');
                                @endphp
                                    <div class="col-md-12">
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <div class="form-line">
                                                    <input name='year' type="text" class="form-control" value="{!!$curYear!!}">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <div class="form-line">
                                                    <input name="month" type="text" class="form-control" value="{!!$curMonth!!}">
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
                                @endif
                            </div>
                            <input type="hidden" name="transaction_id" id="transaction_id">
                            <br><br>
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect"> ADD </button>
                                <br>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                    </div>
                </div>
            </div>
        </div>
        {{-- End Add Control No --}}
        @if ($transaction->control_no != NULL)
            {{-- Update Control No --}}
            <div class="modal fade" id="updateControlNo" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h4 class="modal-title" id="defaultModalLabel">UPDATE CONTROL NO.</h4>
                        </div>
                        <div class="modal-body">
                            <form action="/update_control_no" method="POST">
                                @csrf
                                <div class="row clearfix">
                                    @php
                                        $control_no_array = explode("-", $transaction->control_no);
                                    @endphp
                                    <div class="col-md-12">
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <div class="form-line">
                                                    <input name='year' type="text" class="form-control" value="{!!$control_no_array[0]!!}">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <div class="form-line">
                                                    <input name="month" type="text" class="form-control" value="{!!$control_no_array[1]!!}">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <div class="form-line">
                                                    <input name="number" type="text" class="form-control" value="{!!$control_no_array[2]!!}">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="transaction_id" id="transaction_id">
                                <br><br>
                                <div class="align-right">
                                    <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                    <button type="submit" class="btn btn-link waves-effect"> UPDATE </button>
                                    <br>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                        </div>
                    </div>
                </div>
            </div>
            {{-- End Update Control No --}}
            {{-- Delete Control No--}}
            <div class="modal fade" id="deleteControlNo" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                        </div>
                        <div class="modal-body">
                            <form action="/delete_control_no" method="POST">
                                @csrf
                                <h3>Are you sure you want to delete the Control No.?</h3>
                                <input type="hidden" name="transaction_id" id="transaction_id">
                                <br><br>
                                <div class="align-right">
                                    <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                    <button type="submit" class="btn btn-link waves-effect"> YES </button>
                                    <br>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                        </div>
                    </div>
                </div>
            </div>
            {{-- End Delete Control No--}}
        @endif
    @endif
    {{--Upload Memo--}}
    @if ($transaction->freeFlow && $transaction->upload_location == NULL)
        <div class="modal fade" id="uploadMemo" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="defaultModalLabel">Upload File</h4>
                    </div>
                    <div class="modal-body">
                        <form action="/memo/upload" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="row clearfix">
                                <div class="col-sm-3">
                                    <div class="form-group">
                                        <div>
                                            <input type="file" name="file" id="file" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="transaction_id" id="transaction_id">
                            <br><br>
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect"> UPLOAD </button>
                                <br>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                    </div>
                </div>
            </div>
        </div>
    @endif
    {{--End Upload Memo--}}
    {{--Delete Memo--}}
    @if ($transaction->freeFlow && $transaction->upload_location != NULL)
        <div class="modal fade" id="deleteMemo" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="defaultModalLabel">ARE YOU SURE YOU WANT TO DELETE THE FILE?</h4>
                    </div>
                    <div class="modal-body">
                        <form action="/memo/delete" method="POST" enctype="multipart/form-data">
                            @csrf
                            <input type="hidden" name="transaction_id" id="transaction_id">
                            <br><br>
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect"> DELETE FILE </button>
                                <br>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                    </div>
                </div>
            </div>
        </div>
    @endif
    {{--End Delete Memo--}}
@endsection

@section('js_files')
    <!-- Select Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-select/js/bootstrap-select.js')}}"></script>
    <script src="{{asset('/js/pages/ui/tooltips-popovers.js')}}"></script>
    <script>
        function print(){
            var transaction_id = document.getElementById('transaction_id').value
            var orientation = document.getElementById('orientation').value
            
            /* window.location = "/print_barcode/"+transaction_id+"/"+orientation; */
            window.open("/print_barcode/"+transaction_id+"/"+orientation, '_blank').focus();
        }
        $('#deleteTransaction').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var id = button.data('id')
            var particulars = button.data('particulars')
            var modal = $(this)

            modal.find('.modal-body #particulars').text(particulars);
            modal.find('.modal-body #id').val(id);
        })

        $('#updateTransaction').on('show.bs.modal', function (event) {
            // var button = $(event.relatedTarget)
            // var id = button.data('id')
            // var particulars = button.data('particulars')
            // var modal = $(this)

            // modal.find('.modal-body #particulars').val(particulars);
            // modal.find('.modal-body #id').val(id);
        })

        $('#completeTransaction').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var id = button.data('id')
            var particulars = button.data('particulars')
            var modal = $(this)

            modal.find('.modal-body #particulars').text(particulars);
            modal.find('.modal-body #id').val(id);
        })

        $('#deleteCF').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var barcode = button.data('barcode')
            var office_id = button.data('office_id')
            var modal = $(this)

            modal.find('.modal-body #barcode').val(barcode);
            modal.find('.modal-body #office_id').val(office_id);
        })

        $('#deleteStatus').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var barcode = button.data('barcode')
            var office_id = button.data('office_id')
            var office = button.data('office')
            var flow = button.data('flow')
            var modal = $(this)
            
            modal.find('.modal-body #barcode_value').val(barcode);
            modal.find('.modal-body #office_id').val(office_id);
            modal.find('.modal-body #flow').val(flow);
            modal.find('.modal-body #office').text(office);
        })

        $('#addActionModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var barcode = button.data('barcode')
            var flow = button.data('flow')
            var action = button.data('action')
            var modal = $(this)

            modal.find('.modal-body #barcode').val(barcode);
            modal.find('.modal-body #flow').val(flow);
            modal.find('.modal-body #action').text(action);
        })

        $('#addControlNo').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var transaction_id = button.data('transaction_id')
            var modal = $(this)

            modal.find('.modal-body #transaction_id').val(transaction_id);
        })

        $('#updateControlNo').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var transaction_id = button.data('transaction_id')
            var modal = $(this)

            modal.find('.modal-body #transaction_id').val(transaction_id);
        })

        $('#deleteControlNo').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var transaction_id = button.data('transaction_id')
            var modal = $(this)

            modal.find('.modal-body #transaction_id').val(transaction_id);
        })

        $('#uploadMemo').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var transaction_id = button.data('transaction_id')
            var modal = $(this)

            modal.find('.modal-body #transaction_id').val(transaction_id);
        })

        $('#deleteMemo').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var transaction_id = button.data('transaction_id')
            var modal = $(this)

            modal.find('.modal-body #transaction_id').val(transaction_id);
        })
    </script>
@endsection