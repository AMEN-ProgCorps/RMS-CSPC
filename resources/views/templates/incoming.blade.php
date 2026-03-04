<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Incoming Transactions</title>

    <style>
       .result-table {
            font-family: arial, sans-serif;
            font-size: 11px;
            border-collapse: collapse;
            width: 100%;
        }

        .result-table td, 
        .result-table th {
        border: 1px solid #dddddd;
        padding: 5px;
        margin: 0;
        }

        .align-center{
            text-align: center
        }

        .align-left{
            text-align: left
        }

        p, h3 { 
            margin: 1; 
            font-size: 14px;
        }

        .line {
            border-color: #84affe;
            background: #84affe;
            height: 1px; 
        }

        textarea {
            border-color:white;
        }
    </style>
</head>
<body>
    <table>
        <tr>
            <th><img src="{!!$school->logo!!}" alt="{!!$school->code!!} LOGO" height="70" width="70"/></th>
            <th class="align-left">
                <p>Republic of the Philippines</p>
                <p>{!!$school->name!!}</p>
                <p>{!!$school->address!!}</p>
            </th>
        </tr>
    </table>
    <hr class="line" color="blue">
    
    <h3>Incoming Transactions for the {!!Auth::user()->assigned_to['description']!!}</h3>
    <p>From: {!!$from!!}</p>
    <p>To: {!!$to!!}</p>
    <br>
    <table class="result-table">
        <tr>
            <th class="align-center" style="width: 3%">#</th>
            <th class="align-center" style="width: 10%">From</th>
            <th class="align-center" style="width: 13%">Date Received</th>
            <th class="align-center" style="width: 13%">Received By</th>
            <th class="align-center">Particulars</th>
            <th style="width: 20%" class="align-center">Notes</th>
            <th class="align-center" style="width: 10%">Barcode Value</th>
        </tr>
        @if (count($status)>0)
            @foreach ($status as $i => $s)
                <tr>
                    <td>{!!$i+1!!}</td>
                    <td>{!!$s->originating_office_details->Code!!}</td>
                    <td>
                        @if ($s->date_in)
                            {!!$s->date_in!!}
                        @else
                            Not Yet Received
                        @endif
                    </td>
                    <td>
                        @if($s->received_by != NULL)
                            {!! $s->receiver->lname.', '.$s->receiver->fname.' '.$s->receiver->mname !!}
                        @endif
                    </td>
                    @php
                        $count = substr_count( $s->transaction->description, "\n" );
                        if($count > 4){
                            $newline = substr_count( $s->transaction->description, "\n" ) + 2;
                        }else{
                            $newline = 4;
                        }
                    @endphp
                    <td>                       
                        <textarea style="height:{{ $newline }}em;">{{$s->transaction->description}}
                        </textarea>
                    </td>
                    <td>
                        {!!$s->notes!!}
                    </td>
                    <td>{!!$s->barcode_value!!}</td>
                </tr>
            @endforeach 
        @else
            <tr>
                <td>NO TRANSACTIONS</td><td></td><td></td>
            </tr>
        @endif
    </table>
</body>
</html>