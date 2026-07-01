{{--
  seo-preview.blade.php — 편집기 봇 미리보기 전용.

  검색엔진 탭·레이아웃·모듈/코어 SEO 환경설정이 "설정/정의한 것의 산출물" 만 렌더한다.
  운영 seo.blade.php 와 달리 다음은 SEO 설정 산출물이 아니므로 미포함:
   - body 컴포넌트 마크업($bodyHtml) — 페이지 본문(검색엔진 탭과 무관)
   - CSS($cssPath/$stylesheets) — 에셋
   - 시스템 기본 메타(charset/viewport/generatorTag) — SEO 설정과 무관
   - Google Analytics 스크립트 — 추적(검색 노출 산출물 아님)
   - extraHeadTags/extraBodyEnd — 훅 주입 비-SEO 슬롯

  포함하는 SEO 산출물:
   - title/titleSuffix(검색 제목), description/keywords(검색 설명)
   - canonical/hreflang(렌더러 자동 — alternate)
   - og:locale + ogTags(소셜 공유), twitterTags(트위터 카드)
   - 사이트 소유권 확인 메타(google/naver-site-verification — 검색엔진 SEO 설정)
   - JSON-LD 구조화 데이터
--}}
<head>
    <title>{{ $title }}{{ $titleSuffix }}</title>
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

    {{-- 구조화된 데이터 (JSON-LD) --}}
    @if($jsonLd)
    <script type="application/ld+json">{!! $jsonLd !!}</script>
    @endif
</head>
