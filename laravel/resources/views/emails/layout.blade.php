{{--
    Every email the kit sends, in the operator's brand (App\Kit\Email\EmailTemplate fills it): the logo or name, the
    kind's lines, its button for the link (the link also written out, for clients that hide buttons), the lines after
    it, and the support address, in the brand's colours. Inline styles only, as email clients drop style sheets.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:{{ $colors['surface'] }};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:{{ $colors['text'] }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $colors['surface'] }};padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:{{ $colors['background'] }};border-radius:8px;padding:28px;">
<tr><td style="padding-bottom:20px;border-bottom:3px solid {{ $colors['primary'] }};">
@if ($logo)
<img src="{{ $logo }}" alt="{{ $brand }}" style="max-height:40px;max-width:200px;">
@else
<strong style="font-size:18px;">{{ $brand }}</strong>
@endif
</td></tr>
<tr><td style="padding-top:20px;font-size:15px;line-height:1.55;">
@foreach ($lines as $line)
<p style="margin:0 0 14px;">{{ $line }}</p>
@endforeach
@if ($action)
<p style="margin:22px 0;"><a href="{{ $url }}" style="display:inline-block;background:{{ $colors['primary'] }};color:{{ $colors['primary-contrast'] }};text-decoration:none;padding:11px 20px;border-radius:6px;font-weight:600;">{{ $action }}</a></p>
<p style="margin:0 0 14px;font-size:13px;color:{{ $colors['muted'] }};word-break:break-all;">{{ $url }}</p>
@endif
@foreach ($after as $line)
<p style="margin:0 0 14px;color:{{ $colors['muted'] }};">{{ $line }}</p>
@endforeach
</td></tr>
<tr><td style="padding-top:16px;border-top:1px solid {{ $colors['surface'] }};font-size:12px;color:{{ $colors['muted'] }};">
{{ $brand }}@if ($support) · {{ $support }}@endif
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
