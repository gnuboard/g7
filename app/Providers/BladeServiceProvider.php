<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class BladeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Blade::directive('viteThemeAssets', function ($expression) {
            return "<?php 
                \$params = {$expression};
                
                // 매개변수 처리: 배열로 전달된 경우와 단순 배열로 전달된 경우 구분
                if (is_array(\$params)) {
                    // 연관 배열인지 확인 (키가 문자열인지)
                    \$isAssoc = array_keys(\$params) !== range(0, count(\$params) - 1);
                    
                    if (\$isAssoc && isset(\$params['entries'])) {
                        // 연관 배열 형태: ['entries' => [...], 'type' => '...', 'target' => '...', 'theme' => '...']
                        \$entries = \$params['entries'];
                        \$type = \$params['type'] ?? 'core';
                        \$target = \$params['target'] ?? 'admin';
                        \$themeName = \$params['theme'] ?? theme_name(\$type, \$target);
                    } else {
                        // 단순 배열 형태: [entry1, entry2, ...]
                        \$entries = \$params;
                        \$type = 'core';
                        \$target = 'admin';
                        \$themeName = theme_name(\$type, \$target);
                    }
                } else {
                    // 단일 값인 경우
                    \$entries = [\$params];
                    \$type = 'core';
                    \$target = 'admin';
                    \$themeName = theme_name(\$type, \$target);
                }
                
                // Vite 개발 서버 실행 여부 확인
                \$viteDevServerRunning = false;
                \$viteDevServerUrl = config('app.vite_dev_server_url', 'http://localhost:5173');
                
                if (app()->environment('local', 'development')) {
                    \$context = stream_context_create([
                        'http' => [
                            'timeout' => 1,
                            'ignore_errors' => true
                        ]
                    ]);
                    \$viteDevServerRunning = @file_get_contents(\$viteDevServerUrl . '/@vite/client', false, \$context) !== false;
                }
                
                if (\$viteDevServerRunning) {
                    // 개발 서버가 실행 중인 경우 - Vite 개발 서버 사용
                    echo '<script type=\"module\" src=\"' . \$viteDevServerUrl . '/@vite/client\"></script>';
                    
                    foreach (\$entries as \$entry) {
                        echo '<script type=\"module\" src=\"' . \$viteDevServerUrl . '/' . \$entry . '\"></script>';
                    }
                } else {
                    // 프로덕션 또는 개발 서버 미실행 - 빌드된 파일 사용
                    \$buildPath = resource_path('themes/' . \$type . '/' . \$target . '/' . \$themeName . '/build');
                    \$manifestPath = \$buildPath . '/manifest.json';
                    
                    if (file_exists(\$manifestPath) && is_array(\$entries)) {
                        \$manifest = json_decode(file_get_contents(\$manifestPath), true);
                        
                        foreach (\$entries as \$entry) {
                            if (isset(\$manifest[\$entry])) {
                                \$entryData = \$manifest[\$entry];
                                
                                // JavaScript 파일 로드
                                if (isset(\$entryData['file'])) {
                                    \$jsFileName = basename(\$entryData['file']);
                                    \$jsFileName = preg_replace('/^(.+?)-[^.]+\.js$/', '\$1.js', \$jsFileName);
                                    
                                    \$routeParams = [
                                        'themeName' => \$themeName, 
                                        'path' => \$jsFileName,
                                        'type' => \$type,
                                        'target' => \$target
                                    ];
                                    
                                    // 모듈명이 있는 경우 추가
                                    \$moduleName = \$params['module_name'] ?? null;
                                    if (\$moduleName) {
                                        \$routeParams['module_name'] = \$moduleName;
                                    }
                                    
                                    echo '<script type=\"module\" src=\"' . route('api.public.themes.serve-asset', \$routeParams) . '\"></script>';
                                }
                                
                                // CSS 파일 로드
                                if (isset(\$entryData['css'])) {
                                    foreach (\$entryData['css'] as \$cssFile) {
                                        \$cssFileName = basename(\$cssFile);
                                        \$cssFileName = preg_replace('/^(.+?)-[^.]+\.css$/', '\$1.css', \$cssFileName);
                                        
                                        \$routeParams = [
                                            'themeName' => \$themeName, 
                                            'path' => \$cssFileName,
                                            'type' => \$type,
                                            'target' => \$target
                                        ];
                                        
                                        // 모듈명이 있는 경우 추가
                                        \$moduleName = \$params['module_name'] ?? null;
                                        if (\$moduleName) {
                                            \$routeParams['module_name'] = \$moduleName;
                                        }
                                        
                                        echo '<link rel=\"stylesheet\" href=\"' . route('api.public.themes.serve-asset', \$routeParams) . '\">';
                                    }
                                }
                            }
                        }
                    }
                }
            ?>";
        });
    }
}
