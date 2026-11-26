<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', '그누보드7') }} - {{ __('maintenance.title') }}</title>
        {{-- 파비콘 — 번들 계층만(DB·API 를 보지 않는다) --}}
        {!! g7_favicon_tags_static() !!}
        {{-- 스타일은 정적 파일(g7-standalone.css)에 있다 — 엄격한 스타일 정책에서 뷰 안 스타일 블록이 막힌다(#163) --}}
        @include('partials.core-styles', ['sheet' => 'standalone'])
    </head>
    <body>
        <div class="maintenance-container">
            <div class="maintenance-card">
                {{-- Gear icon (inline SVG - no external dependency) --}}
                <div class="maintenance-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                </div>

                <h1 class="maintenance-title">{{ __('maintenance.title') }}</h1>
                <p class="maintenance-message">{{ __('maintenance.message') }}</p>
                <p class="maintenance-description">{{ __('maintenance.description') }}</p>

                {{-- 관리자 화면 코어 업데이트가 진행·되돌리기·복구 중일 때만 — 브라우저를 닫은 관리자가 다른 기기에서 이어 간다.
                     확인되면 점검 우회 쿠키만 받고, 업데이트 탭에서 평소처럼 로그인한다. --}}
                @if ($coreUpdateResumeAccess ?? false)
                    <form class="maintenance-admin-access" method="POST" action="{{ $coreUpdateAccessPath }}">
                        <p class="maintenance-admin-access-title">{{ __('maintenance.admin_access.title') }}</p>
                        <p class="maintenance-admin-access-hint">{{ __('maintenance.admin_access.hint') }}</p>
                        @if (($coreUpdateAccessResult ?? null) === 'failed')
                            <p class="maintenance-admin-access-error" role="alert">{{ __('maintenance.admin_access.failed') }}</p>
                        @elseif (($coreUpdateAccessResult ?? null) === 'throttled')
                            <p class="maintenance-admin-access-error" role="alert">{{ __('maintenance.admin_access.throttled') }}</p>
                        @endif
                        <label class="maintenance-admin-access-label">
                            <span>{{ __('maintenance.admin_access.email') }}</span>
                            <input type="email" name="email" autocomplete="username" required>
                        </label>
                        <label class="maintenance-admin-access-label">
                            <span>{{ __('maintenance.admin_access.password') }}</span>
                            <input type="password" name="password" autocomplete="current-password" required>
                        </label>
                        <button type="submit" class="maintenance-admin-access-submit">{{ __('maintenance.admin_access.submit') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </body>
</html>
