@php
    $templateExternals = $templateExternals ?? [];
    $resourceHints = \App\Support\TemplateExternals::resourceHints($templateExternals);
    $headLinks = \App\Support\TemplateExternals::headLinks($templateExternals);
    $headScripts = \App\Support\TemplateExternals::scriptsForPosition($templateExternals, 'head');
@endphp
{{-- 셸 <head> 스크립트 (자산 실패 신호 + 자산 URL 자가 복구) — 아래 externals <link> 와
     템플릿 CSS <link> 보다 먼저 실행되어야 한다(그 태그들의 error 를 캡처 리스너로 받는다).

     strict CSP(`script-src 'self'`)에서도 동작하도록 실행 코드는 정적 파일
     `public/assets/core/js/g7-shell-head.js` 에 두고, 서버 값은 비실행 JSON 데이터 블록으로만
     넘긴다(#158). 인라인 `<script>`·on* 속성을 다시 넣지 않는다. --}}
        <script type="application/json" id="g7-shell-head-data">@json(['cacheVersion' => (int) ($extensionCacheVersion ?? 0)])</script>
        <script src="{{ asset('assets/core/js/g7-shell-head.js') }}?v={{ file_exists(public_path('assets/core/js/g7-shell-head.js')) ? filemtime(public_path('assets/core/js/g7-shell-head.js')) : 0 }}"></script>
@foreach($resourceHints as $external)
        <link{!! \App\Support\TemplateExternals::renderAttributes(\App\Support\TemplateExternals::linkAttributes($external)) !!}>
@endforeach
@foreach($headLinks as $external)
        <link{!! \App\Support\TemplateExternals::renderAttributes(\App\Support\TemplateExternals::linkAttributes($external)) !!}>
@endforeach
@foreach($headScripts as $external)
        <script{!! \App\Support\TemplateExternals::renderAttributes(\App\Support\TemplateExternals::scriptAttributes($external)) !!}></script>
@endforeach
