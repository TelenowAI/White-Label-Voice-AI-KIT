{{-- The contact page: the operator's text and the support address. --}}
@extends('theme::layout')
@section('title', __('website.contact_title').' — '.$brand['name'])
@section('main')
<section class="band" aria-labelledby="contact-title">
  <div class="wrap narrow">
    <h1 id="contact-title">{{ __('website.contact_title') }}</h1>
    {{ \App\Kit\Website\RichText::html($content['contactText'], $vars) }}
    @if ($vars['email'] !== '')
      <p><a class="button" href="mailto:{{ $vars['email'] }}">{{ $vars['email'] }}</a></p>
    @endif
  </div>
</section>
@endsection
