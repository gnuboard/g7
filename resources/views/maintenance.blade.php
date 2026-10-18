<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', '그누보드7') }} - {{ __('maintenance.title') }}</title>
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
            .maintenance-container {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                background-color: #f9fafb;
                padding: 1rem;
            }
            .maintenance-card {
                max-width: 32rem;
                width: 100%;
                background-color: #ffffff;
                border-radius: 0.75rem;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
                padding: 3rem 2rem;
                text-align: center;
            }
            .maintenance-icon {
                margin-bottom: 1.5rem;
            }
            .maintenance-icon svg {
                width: 5rem;
                height: 5rem;
                color: #3b82f6;
                animation: spin 3s linear infinite;
            }
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            .maintenance-title {
                font-size: 1.75rem;
                font-weight: 700;
                color: #111827;
                margin-bottom: 0.75rem;
            }
            .maintenance-message {
                font-size: 1.05rem;
                color: #4b5563;
                margin-bottom: 0.5rem;
                line-height: 1.625;
            }
            .maintenance-description {
                font-size: 0.875rem;
                color: #6b7280;
                line-height: 1.625;
            }
            @media (prefers-color-scheme: dark) {
                .maintenance-container {
                    background-color: #111827;
                }
                .maintenance-card {
                    background-color: #1f2937;
                    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
                }
                .maintenance-icon svg {
                    color: #60a5fa;
                }
                .maintenance-title {
                    color: #ffffff;
                }
                .maintenance-message {
                    color: #9ca3af;
                }
                .maintenance-description {
                    color: #6b7280;
                }
            }
        </style>
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
            </div>
        </div>
    </body>
</html>
