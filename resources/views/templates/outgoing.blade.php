<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Outgoing Transactions</title>

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
    
    <h3>Outgoing Transactions from the {!!Auth::user()->assigned_to['description']!!}</h3>
    <p>From: {!!$from!!}</p>
    <p>To: {!!$to!!}</p>
    <br>
    <table class="result-table">
        <tr>
            <th class="align-center" style="width: 3%">#</th>
            <th class="align-center">Particulars</th>
            <th class="align-center" style="width: 10%">Forwarded To</th>
            {{-- <th class="align-center" style="width: 15%">Date Forwarded</th> --}}
            <th class="align-center" style="width: 15%">Date Received</th>
            <th class="align-center" style="width: 15%">Received By</th>
            <th class="align-center" style="width: 10%">Barcode Value</th>
        </tr>
        @php
            $last_id = NULL;
            $index = 0
        @endphp
        @if (count($status)>0)
            @foreach ($status as $i => $s)
                <tr>
                    @php
                        if($last_id != $s->barcode_value){
                            $index++;
                            $j = $index;
                        }else{
                            $j = " ";
                        }
                        $count = substr_count( $s->transaction->description, "\n" );
                        if($count > 4){
                            $newline = substr_count( $s->transaction->description, "\n" ) + 2;
                        }else{
                            $newline = 4;
                        }
                    @endphp
                    @if ($j != " ")
                        <td>{!!$j!!}</td>
                        <td>
                            <textarea style="height:{{ $newline }}em;">{!!$s->transaction->description!!}
                            </textarea>
                        </td>
                    @else
                        <td>{!!$j!!}</td>
                        <td>
                            <textarea class="align-center" style="height: 1em">--do--</textarea>
                        </td>
                    @endif
                    <td>
                        {!!$s->office->Code!!}
                    </td>
                    {{-- <td>{!!$s->orig_date_out!!}</td> --}}
                    <td>
                        @if ($s->date_in)
                            {!!$s->date_in!!}
                        @else
                            Not Yet Received
                        @endif
                    </td>
                    <td>
                        @if ($s->received_by != NULL)
                            {!! $s->receiver->lname.', '.$s->receiver->fname.' '.$s->receiver->mname !!}
                        @endif
                    </td>
                    @if ($j != " ")
                        <td>{!!$s->barcode_value!!}</td>
                    @else
                        <td></td>
                    @endif
                    
                </tr>
                @php
                    $last_id = $s->barcode_value;
                @endphp
            @endforeach 
        @else
            <tr>
                <td>NO TRANSACTIONS</td><td></td><td></td>
            </tr>
        @endif
    </table>
</body>
</html>