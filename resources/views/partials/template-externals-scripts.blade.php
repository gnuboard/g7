@php
    $templateExternals = $templateExternals ?? [];
    $position = $position ?? 'before-template';
    $scripts = \App\Support\TemplateExternals::scriptsForPosition($templateExternals, $position);
@endphp
@foreach($scripts as $external)
        <script{!! \App\Support\TemplateExternals::renderAttributes(\App\Support\TemplateExternals::scriptAttributes($external)) !!}></script>
@endforeach
