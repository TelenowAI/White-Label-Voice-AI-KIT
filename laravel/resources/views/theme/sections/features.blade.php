{{-- The features (content.features): on the home page, and the features page. --}}
<section class="band" aria-labelledby="features-title">
  <div class="wrap">
    <h2 id="features-title">{{ __('website.features_title') }}</h2>
    <ul class="cards">
      @foreach ($content['features'] as $feature)
        <li class="card">
          <h3>{{ \App\Kit\Website\RichText::fill($feature['title'], $vars) }}</h3>
          {{ \App\Kit\Website\RichText::html($feature['text'], $vars) }}
        </li>
      @endforeach
    </ul>
  </div>
</section>
