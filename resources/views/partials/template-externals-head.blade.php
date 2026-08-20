@php
    $templateExternals = $templateExternals ?? [];
    $resourceHints = \App\Support\TemplateExternals::resourceHints($templateExternals);
    $headLinks = \App\Support\TemplateExternals::headLinks($templateExternals);
    $headScripts = \App\Support\TemplateExternals::scriptsForPosition($templateExternals, 'head');
@endphp
@foreach($resourceHints as $external)
        <link{!! \App\Support\TemplateExternals::renderAttributes(\App\Support\TemplateExternals::linkAttributes($external)) !!}>
@endforeach
@foreach($headLinks as $external)
        <link{!! \App\Support\TemplateExternals::renderAttributes(\App\Support\TemplateExternals::linkAttributes($external)) !!}>
@endforeach
@foreach($headScripts as $external)
        <script{!! \App\Support\TemplateExternals::renderAttributes(\App\Support\TemplateExternals::scriptAttributes($external)) !!}></script>
@endforeach
