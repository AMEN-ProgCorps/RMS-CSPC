<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Copy Furnished</title>

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
    <table cellborder="1">
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
    
    <h3>Transactions with CF to {!!Auth::user()->assigned_to['description']!!}</h3>
    <p>From: {!!$from!!}</p>
    <p>To: {!!$to!!}</p>
    <br>
    <table class="result-table">
        <tr>
            <th class="align-center" style="width:3%">#</th>
            <th class="align-center" style="width:10%">From</th>
            <th class="align-center" style="width:15%">Date Received</th>
            <th class="align-center" style="width:15%">Received By</th>
            <th class="align-center">Particulars</th>
            <th class="align-center" style="width: 10%">Barcode Value</th>
        </tr>
        @if (count($cfs)>0)
            @foreach ($cfs as $i => $cf)
                <tr>
                    <td>{!!$i+1!!}</td>
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
                    @php
                        $count = substr_count( $cf->transaction->description, "\n" );
                        if($count > 4){
                            $newline = substr_count( $cf->transaction->description, "\n" ) + 2;
                        }else{
                            $newline = 4;
                        }
                    @endphp
                    <td>                        
                        <textarea style="height:{{ $newline }}em;">{{$cf->transaction->description}}
                        </textarea>
                    </td>
                    <td>{!!$cf->barcode_value!!}</td>
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