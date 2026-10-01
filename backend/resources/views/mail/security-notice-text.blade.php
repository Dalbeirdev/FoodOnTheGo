{{ config('app.name') }}

@foreach ($sections as $i => $section)
@if ($i > 0)
----------------------------------------

{!! $section['subject'] !!}

@endif
@foreach ($section['lines'] as $line)
{!! $line !!}

@endforeach
@if ($url !== null)
{!! $section['action'] !!}:
{!! $url !!}

@endif
@foreach ($section['after'] as $line)
{!! $line !!}

@endforeach
@endforeach
