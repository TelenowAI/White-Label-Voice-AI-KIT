{{-- The call to action at the end of the home page (content.ctaTitle, ctaText): to sign up when sign-ups are open, else to write. --}}
<section class="band cta" aria-labelledby="cta-title">
  <div class="wrap">
    <h2 id="cta-title">{{ \App\Kit\Website\RichText::fill($content['ctaTitle'], $vars) }}</h2>
    {{ \App\Kit\Website\RichText::html($content['ctaText'], $vars) }}
    <p class="actions">
      @if ($signUp)
        <a class="button" href="{{ url('signup') }}">{{ __('website.nav.sign_up') }}</a>
      @else
        <a class="button" href="{{ url('contact') }}">{{ __('website.nav.contact') }}</a>
      @endif
    </p>
  </div>
</section>
