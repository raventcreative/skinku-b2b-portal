@extends('layouts.app')
@section('title', 'Percakapan')
@section('heading', 'Percakapan')
@section('content')
<div>
    @foreach($conversation->messages as $m)
        <p>{{ $m->sender }}: {{ $m->text }}</p>
    @endforeach
</div>
@endsection
