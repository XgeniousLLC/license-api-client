@if(session()->has('msg'))
    <div class="alert alert-{{session('type')}}">
        {!! strip_tags(session('msg')) !!}
    </div>
@endif
