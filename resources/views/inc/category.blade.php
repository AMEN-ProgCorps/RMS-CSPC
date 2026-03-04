<!--Display Children-->
@if (Auth::user()->office == 1){{--For Admin--}}
    <li>
        @if(!$category->parent_id)
            {!! $category->code !!}) {!! $category->description !!}
            <a href="#" data-toggle="modal" data-target="#addModal" data-parent_id="{!!$category->id!!}"
                data-parent_offices='{{ $category->offices }}'>
                <i style="font-size:17px" class="font-underline col-blue"><sup>Add</sup></i>
            </a>
        @else
            <a href="/records/categories/{!!$category->id!!}">{!! $category->code !!}) {!! $category->description !!}</a>
        @endif
        <a href="/categories/{!!$category->id!!}/edit">
            <i style="font-size:17px" class="font-underline col-blue"><sup>Update</sup></i>
        </a>
        <a href="#" data-toggle="modal" data-target="#deleteModal" data-id="{!!$category->id!!}" 
            data-code="{!!$category->code!!}" data-description="{!!$category->description!!}">
            <i style="font-size:17px" class="font-underline col-blue"><sup>Delete</sup></i>
        </a>
    </li>
    @if (count($category->childRecursive) > 0)
        <ul>
            @foreach($category->childRecursive->sortBy('code', SORT_NATURAL, false)->values() as $category)
                @include('inc.category', $category)
            @endforeach
        </ul>
    @endif
@else {{--For Regular User--}}
    <li>
        @if(!$category->parent_id)
            {!! $category->code !!}) {!! $category->description !!}
        @else
            <a href="/records/categories/{!!$category->id!!}">{!! $category->code !!}) {!! $category->description !!}</a>
        @endif
    </li>
    @if (count($category->child) > 0)
        <ul>
            @foreach($category->child->sortBy('code', SORT_NATURAL, false)->values() as $category)
                @include('inc.category', $category)
            @endforeach
        </ul>
        <br>
    @endif
@endif