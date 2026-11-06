{{-- The pricing page: the pricing section on its own. --}}
@extends('theme::layout')
@section('title', __('website.pricing_title').' — '.$brand['name'])
@section('main')
@include('theme::sections.pricing')
@endsection
