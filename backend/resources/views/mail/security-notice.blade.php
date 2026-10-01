{{-- E-mail to a restaurant or admin user, once per language (see WritesStaffNotice). No image, no tracking; a link only when the message exists to deliver one ($url). --}}
<!DOCTYPE html>
<html lang="{{ $sections[0]['locale'] }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6fa;color:#1b1f2a;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;">
<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;padding:24px;">
<p style="margin:0 0 16px;font-weight:bold;font-size:18px;">{{ config('app.name') }}</p>
@foreach ($sections as $i => $section)
<div lang="{{ $section['locale'] }}" @if ($i > 0) style="border-top:1px solid #e4e7ec;margin-top:18px;padding-top:18px;" @endif>
@if ($i > 0)
<p style="margin:0 0 14px;font-weight:bold;">{{ $section['subject'] }}</p>
@endif
@foreach ($section['lines'] as $line)
<p style="margin:0 0 14px;">{{ $line }}</p>
@endforeach
@if ($url !== null)
<p style="margin:0 0 14px;"><a href="{{ $url }}" style="display:inline-block;background:#c2380f;color:#ffffff;text-decoration:none;font-weight:bold;border-radius:10px;padding:12px 18px;">{{ $section['action'] }}</a></p>
@endif
@foreach ($section['after'] as $line)
<p style="margin:0 0 14px;">{{ $line }}</p>
@endforeach
</div>
@endforeach
@if ($url !== null)
<p style="margin:18px 0 0;font-size:13px;color:#475467;overflow-wrap:anywhere;word-break:break-all;">{{ $url }}</p>
@endif
</div>
</body>
</html>
