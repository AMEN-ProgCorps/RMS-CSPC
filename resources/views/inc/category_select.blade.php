@if(!$category->parent_id)
    <optgroup label="{!!$category->code!!}) {!!$category->description!!}">
@else
    <option value="{{$category->id}}">{!!$category->code!!}) {!!$category->description!!}</option>       
@endif
@if (count($category->childRecursive) > 0)
    @foreach($category->child->sortBy('code', SORT_NATURAL, false)->values() as $category)
        @include('inc.category_select', $category)
    @endforeach
    </optgroup>
@endif