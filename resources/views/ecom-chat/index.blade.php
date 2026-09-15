@extends('layouts.app')
@section('title', 'Chat E-commerce')
@section('heading', 'Chat E-commerce')
@section('content')
<div>
    @foreach($conversations as $c)
        <a href="{{ route('ecom-chat.show', $c) }}">{{ $c->buyer_name ?? 'Pembeli' }}</a>
    @endforeach
</div>
@endsection
