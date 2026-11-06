{{--
    The home page: the hero, then the operator's sections in their order, each only when on (content.sections; one
    template per section in sections/, named after its id in config('kit.website.home_sections')).
--}}
@extends('theme::layout')
@php($fill = fn (?string $text) => \App\Kit\Website\RichText::fill((string) $text, $vars))
@section('title', $brand['name'].($content['tagline'] !== '' ? ' — '.$fill($content['tagline']) : ''))
@section('main')
<section class="hero">
  <div class="wrap hero-row">
    <div class="hero-text">
      <h1>{{ $fill($content['heroTitle']) }}</h1>
      {{ \App\Kit\Website\RichText::html($content['heroText'], $vars) }}
      <p class="actions">
        @if ($signUp)
          <a class="button" href="{{ url('signup') }}">{{ __('website.nav.sign_up') }}</a>
        @endif
        <a class="button secondary" href="{{ url('pricing') }}">{{ __('website.see_pricing') }}</a>
      </p>
    </div>
    @if ($content['heroImage'])
      <img class="hero-image" src="{{ $content['heroImage'] }}" alt="">
    @endif
  </div>
</section>
@foreach ($content['sections'] as $section)
  @if ($section['on'])
    @include("theme::sections.{$section['id']}")
  @endif
@endforeach
@endsection
