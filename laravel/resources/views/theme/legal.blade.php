{{-- A legal page (config('kit.website.legal_pages')): its name, then the operator's text, `# ` lines as headings. --}}
@extends('theme::layout')
@section('title', __("website.legal_pages.{$page}").' — '.$brand['name'])
@section('main')
<article class="band legal">
  <div class="wrap narrow">
    <h1>{{ __("website.legal_pages.{$page}") }}</h1>
    {{ \App\Kit\Website\RichText::html($content['legal'][$page] ?? '', $vars) }}
  </div>
</article>
@endsection
