{{-- List CFs for the Office --}}
@extends('layouts.app')

@section('css_files')
    <!-- JQuery DataTable Css -->
    <link href="{{asset('/plugins/jquery-datatable/skin/bootstrap/css/dataTables.bootstrap.css')}}" rel="stylesheet">
    <!-- Bootstrap DatePicker Css -->
    <link href="{{asset('/plugins/bootstrap-datepicker/css/bootstrap-datepicker.css')}}" rel="stylesheet" />
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="row clearfix">
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
            <div class="card">
                <div class="header">
                    <h2>
                        Copy Furnished for {!!Auth::user()->assigned_to['description']!!}
                    </h2>
                </div>
                <div class="body">
                    @if(count($cfs)>0)
                        <div class="align-right">
                            <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#printModal">
                                <i class="material-icons">print</i>
                                <span>PRINT...</span>
                            </a>
                        </div>
                    @endif
                    <div class="table-responsive">
                        <table id="transaction_table" class="table table-bordered table-striped table-hover js-basic-example dataTable">
                            <thead>
                                <tr>
                                    <th style="width: 3%">#</th>
                                    <th style="width: 13%">From</th>
                                    <th style="width: 15%">Date Received</th>
                                    <th style="width: 15%">Received By</th>
                                    <th>Particulars</th>
                                    <th style="width: 5%"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @if(count($cfs)>0)
                                    @foreach ($cfs as $index => $cf)
                                        <tr>
                                            <td>{!!$index+1!!}</td>
                                            <td>{!!$cf->transaction->office->Code!!}</td>
                                            <td>
                                                @if ($cf->date_in)
                                                    {!!$cf->date_in!!}
                                                @else
                                                    Not Yet Received
                                                @endif
                                            </td>
                                            <td>
                                                @if($cf->received_by != NULL)
                                                    {!! $cf->receiver->lname.', '.$cf->receiver->fname.' '.$cf->receiver->mname !!}
                                                @endif
                                            </td>
                                            <td>
                                                <div class="form-group">
                                                    {{-- {{ substr_count( $cf->transaction->description, "\n" )+2 }} --}}
                                                    <textarea rows="4" class="form-control no-resize" readonly>{!!$cf->transaction->description!!}</textarea>
                                                </div>
                                            </td>
                                            <td><a href="/show_transaction/{!!$cf->transaction->id!!}">View</a></td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td>NO TRANSACTIONS</td><td></td><td></td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="footer">
                    <h6>Note: Display transactions from the last two months. For transactions beyond two months use "Search Transactions".</h6>
                </div>
            </div>
        </div>
    </div>
    <!--print Modal-->
    <div class="modal fade" id="printModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">PRINT TRANSACTIONS</h4>
                </div>
                <div class="modal-body">
                    <div class="row clearfix">
                        <form target="_blank" action="/print_transactions" method="GET">
                            @csrf
                            <div class="col-xs-12">
                                <h5 class="card-inside-title">Range</h5>
                                <div class="input-daterange input-group" id="bs_datepicker_range_container">
                                    <div class="form-line">
                                        <input type="text" class="form-control" placeholder="Date start..." name="from" required>
                                    </div>
                                    <span class="input-group-addon">to</span>
                                    <div class="form-line">
                                        <input type="text" class="form-control" placeholder="Date end..." name="to" required>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xs-12">
                                <h5 class="card-inside-title">Orientation: </h5>
                                <select id="orientation" class="form-control show-tick" name="orientation" required>
                                    <option value="landscape" selected>Landscape</option>
                                    <option value="portrait">Portrait</option>
                                </select>
                                <br ><br ><br >
                            </div>
                            <div class="col-xs-12">
                                <input type="hidden" name="type" value="4">
                            </div>
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect"> PRINT </button>
                                <br>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="modal-footer">
                </div>
            </div>
        </div>
    </div>
    <!--End print Modal-->
@endsection

@section('js_files')
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

    <!-- Jquery DataTable Plugin Js -->
    <script src="{{asset('/plugins/jquery-datatable/jquery.dataTables.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/skin/bootstrap/js/dataTables.bootstrap.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/dataTables.buttons.min.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/buttons.flash.min.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/jszip.min.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/pdfmake.min.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/vfs_fonts.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/buttons.html5.min.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/buttons.print.min.js')}}"></script>

    <!-- Custom Js -->
    <script src="{{asset('/js/pages/forms/basic-form-elements.js')}}"></script>
    <script src="{{asset('/js/pages/tables/jquery-datatable.js')}}"></script>
    <script>
        //refresh every 1 minute
        setTimeout(function(){
            location = ''
        },60000)
    </script>
    <script>
        $('#printModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var barcode = button.data('barcode')
            var modal = $(this)

            //modal.find('.modal-body #particulars').text(particulars);
            modal.find('.modal-body #barcode').val(barcode);
        })
    </script>
@endsection