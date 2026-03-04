@if ($errors->any() or session('success') or session('error'))    
    <div class="container-fluid">
        @if(count($errors)>0)
            @foreach ($errors->all() as $error)
                <div class="alert bg-red alert-danger alert-dismissible" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    {{$error}}
                </div>
            @endforeach
        @endif
        @if(session('success'))
            <div class="alert bg-light-blue alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                {{session('success')}}
            </div>
        @endif
        @if(session('error'))
            <div class="alert bg-red alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                {{session('error')}}
            </div>
        @endif
    </div>
@endif