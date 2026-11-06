{{--
    The default theme's frame for every website page (App\Http\Controllers\Site\WebsiteController): the head (title,
    description, the theme's style sheet, the brand's colours as CSS variables named after their roles), the header
    (logo or name, the pages, sign in, sign up when sign-ups are open), the page, and the footer (legal pages, the
    support address). A child theme replaces this file, or only a section, by copying it (App\Kit\Website\Theme); the
    optional `theme::partials.head` lets it add to the head alone.

    Every text the operator wrote is escaped ({{ }}, App\Kit\Website\RichText).
--}}
@php($fill = fn (?string $text) => \App\Kit\Website\RichText::fill((string) $text, $vars))
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', $brand['name'])</title>
<meta name="description" content="{{ $fill($content['metaDescription']) }}">
<link rel="stylesheet" href="{{ asset('theme/default.css') }}">
<style>:root{@foreach ($colors as $role => $value)--kit-{{ $role }}:{{ $value }};@endforeach}</style>
@includeIf('theme::partials.head')
</head>
<body>
<a class="skip" href="#main">{{ __('website.skip') }}</a>
<header class="site-header">
  <div class="wrap header-row">
    <a class="brand" href="{{ url('/') }}">
      @if ($brand['logoUrl'])
        <img src="{{ $brand['logoUrl'] }}" alt="{{ $brand['name'] }}">
      @else
        {{ $brand['name'] }}
      @endif
    </a>
    <nav class="site-nav" aria-label="{{ __('website.nav.label') }}">
      <a href="{{ url('features') }}">{{ __('website.nav.features') }}</a>
      <a href="{{ url('pricing') }}">{{ __('website.nav.pricing') }}</a>
      <a href="{{ url('contact') }}">{{ __('website.nav.contact') }}</a>
      <a href="{{ url('console') }}">{{ __('website.nav.sign_in') }}</a>
      @if ($signUp)
        <a class="button" href="{{ url('signup') }}">{{ __('website.nav.sign_up') }}</a>
      @endif
    </nav>
  </div>
</header>
<main id="main">
@yield('main')
</main>
<footer class="site-footer">
  <div class="wrap footer-row">
    <span>{{ __('website.rights', ['year' => now()->year, 'brand' => $brand['name']]) }}</span>
    <nav aria-label="{{ __('website.nav.legal') }}">
      @foreach ($legalPages as $legal)
        <a href="{{ url("legal/{$legal}") }}">{{ __("website.legal_pages.{$legal}") }}</a>
      @endforeach
      @if ($vars['email'] !== '')
        <a href="mailto:{{ $vars['email'] }}">{{ $vars['email'] }}</a>
      @endif
    </nav>
  </div>
</footer>
</body>
</html>
