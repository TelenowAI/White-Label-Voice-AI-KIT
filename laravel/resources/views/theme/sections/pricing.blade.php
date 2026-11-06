{{--
    The plans (App\Kit\Website\Website::plans): each with the operator's headline and note and what its price list
    fixes as a number. On the home page and the pricing page.
--}}
<section class="band alt" aria-labelledby="pricing-title">
  <div class="wrap">
    <h2 id="pricing-title">{{ __('website.pricing_title') }}</h2>
    {{ \App\Kit\Website\RichText::html($content['pricingIntro'], $vars) }}
    @if ($plans === [])
      <p>{{ __('website.pricing_none') }}</p>
    @else
      <ul class="cards plans">
        @foreach ($plans as $plan)
          <li class="card plan">
            <h3>{{ $plan['name'] }}</h3>
            @if ($plan['headline'] !== '')
              <p class="headline">{{ \App\Kit\Website\RichText::fill($plan['headline'], $vars) }}</p>
            @endif
            @if ($plan['lines'] !== [])
              <dl class="prices">
                @foreach ($plan['lines'] as $line)
                  <div><dt>{{ $line['label'] }}</dt><dd>{{ $line['amount'] }}@if ($line['per'] !== '') <small>{{ $line['per'] }}</small>@endif</dd></div>
                @endforeach
              </dl>
            @endif
            @if ($plan['increment'])
              <p class="muted">{{ __('website.increment', ['seconds' => $plan['increment']]) }}</p>
            @endif
            {{ \App\Kit\Website\RichText::html($plan['note'], $vars) }}
          </li>
        @endforeach
      </ul>
    @endif
  </div>
</section>
