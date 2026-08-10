<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', '그누보드7') }} - {{ __('database_credential.title') }}</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }
            .credential-container {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                background-color: #f9fafb;
                padding: 1rem;
            }
            .credential-card {
                max-width: 36rem;
                width: 100%;
                background-color: #ffffff;
                border-radius: 0.75rem;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
                padding: 3rem 2rem;
                text-align: center;
            }
            .credential-icon {
                margin-bottom: 1.5rem;
            }
            .credential-icon svg {
                width: 4.5rem;
                height: 4.5rem;
                color: #f59e0b;
            }
            .credential-title {
                font-size: 1.75rem;
                font-weight: 700;
                color: #111827;
                margin-bottom: 0.75rem;
            }
            .credential-message {
                font-size: 1.05rem;
                color: #4b5563;
                margin-bottom: 0.5rem;
                line-height: 1.625;
            }
            .credential-description {
                font-size: 0.875rem;
                color: #6b7280;
                line-height: 1.625;
                margin-bottom: 1.5rem;
            }
            .credential-reason {
                text-align: left;
                background-color: #fffbeb;
                border: 1px solid #fde68a;
                border-radius: 0.5rem;
                padding: 1rem 1.25rem;
                font-size: 0.9rem;
                color: #92400e;
                line-height: 1.625;
                margin-bottom: 1rem;
            }
            .credential-recovery {
                text-align: left;
                background-color: #f3f4f6;
                border-radius: 0.5rem;
                padding: 1rem 1.25rem;
                font-size: 0.875rem;
                color: #374151;
                line-height: 1.625;
            }
            @media (prefers-color-scheme: dark) {
                .credential-container {
                    background-color: #111827;
                }
                .credential-card {
                    background-color: #1f2937;
                    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
                }
                .credential-icon svg {
                    color: #fbbf24;
                }
                .credential-title {
                    color: #ffffff;
                }
                .credential-message {
                    color: #9ca3af;
                }
                .credential-description {
                    color: #6b7280;
                }
                .credential-reason {
                    background-color: #78350f;
                    border-color: #92400e;
                    color: #fde68a;
                }
                .credential-recovery {
                    background-color: #374151;
                    color: #d1d5db;
                }
            }
        </style>
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
