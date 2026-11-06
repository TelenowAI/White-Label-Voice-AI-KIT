{{-- The features page: the features section on its own. --}}
@extends('theme::layout')
@section('title', __('website.features_title').' — '.$brand['name'])
@section('main')
@include('theme::sections.features')
@endsection
