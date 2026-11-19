<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', '그누보드7') }} - {{ __('database_credential.title') }}</title>
        {{-- 파비콘 — 번들 계층만(DB·API 를 보지 않는다, #434) --}}
        {!! g7_favicon_tags_static() !!}
        {{-- 스타일은 정적 파일(g7-standalone.css)에 있다 — 엄격한 스타일 정책에서 뷰 안 스타일 블록이 막힌다(#163) --}}
        @include('partials.core-styles', ['sheet' => 'standalone'])
    </head>
    <body>
        <div class="credential-container">
            <div class="credential-card">
                {{-- Warning triangle icon (inline SVG - no external dependency) --}}
                <div class="credential-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                </div>

                <h1 class="credential-title">{{ __('database_credential.title') }}</h1>
                <p class="credential-message">{{ __('database_credential.message') }}</p>
                <p class="credential-description">{{ __('database_credential.description') }}</p>

                {{-- 원인 구분 안내. 현재 설정된 계정명 자체는 출력하지 않는다 --}}
                {{-- (미인증 공개 페이지이므로 DB 계정명 노출은 정보 유출). --}}
                <p class="credential-reason">
                    {{ __('database_credential.'.($reason ?? 'blocked').'_reason') }}
                </p>

                <p class="credential-recovery">{{ __('database_credential.recovery_guide') }}</p>
            </div>
        </div>
    </body>
</html>
