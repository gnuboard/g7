<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}{{ $titleSuffix }}</title>
    {!! $generatorTag ?? '' !!}
    <meta name="description" content="{{ $description }}">
    @if($keywords)
    <meta name="keywords" content="{{ $keywords }}">
    @endif
    <link rel="canonical" href="{{ $canonicalUrl }}">
    {!! $hreflangTags !!}
    <meta property="og:locale" content="{{ $locale }}">

    {{-- 코어 설정: 사이트 소유권 확인 --}}
    @if($googleVerification)
    <meta name="google-site-verification" content="{{ $googleVerification }}">
    @endif
    @if($naverVerification)
    <meta name="naver-site-verification" content="{{ $naverVerification }}">
    @endif

    {{-- Open Graph --}}
    {!! $ogTags !!}

    {{-- Twitter Card --}}
    {!! $twitterTags ?? '' !!}

    {{-- CSS (템플릿 CSS만 — 코어 app.css 는 사용자 화면에서 미참조하는 Laravel 기본 빌드 파일이라 미포함) --}}
    @foreach($stylesheets as $stylesheet)
    <link rel="stylesheet" href="{{ $stylesheet }}">
    @endforeach

    {{-- 구조화된 데이터 (JSON-LD) --}}
    @if($jsonLd)
    <script type="application/ld+json">{!! $jsonLd !!}</script>
    @endif

    {{-- 코어 설정: Google Analytics --}}
    @if($googleAnalyticsId)
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $googleAnalyticsId }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{{ $googleAnalyticsId }}');
    </script>
    @endif

    {{-- 확장 슬롯: 커스텀 메타 태그, 스크립트, 스타일 (core.seo.filter_view_data 훅으로 주입) --}}
    {!! $extraHeadTags ?? '' !!}
</head>
<body>
    <div id="app">{!! $bodyHtml !!}</div>
    {{-- 확장 슬롯: 추적 스크립트, 위젯 (core.seo.filter_view_data 훅으로 주입) --}}
    {!! $extraBodyEnd ?? '' !!}
</body>
</html>
