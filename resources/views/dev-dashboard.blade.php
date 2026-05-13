<?php
// 모듈/템플릿/플러그인 폴더 스캔 (활성 + _bundled 항목 통합, 중복 제거)
function scanExtensions($path) {
    $extensions = [];
    if (is_dir($path)) {
        // 1) 활성 디렉토리 (루트에 직접 설치된 확장)
        $dirs = array_filter(glob($path . '/*'), 'is_dir');
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (str_starts_with($name, '_')) {
                continue;
            }
            $extensions[$name] = $name;
        }
        // 2) _bundled 디렉토리 (Git 번들 확장)
        $bundledPath = $path . '/_bundled';
        if (is_dir($bundledPath)) {
            $bundledDirs = array_filter(glob($bundledPath . '/*'), 'is_dir');
            foreach ($bundledDirs as $dir) {
                $name = basename($dir);
                if (!isset($extensions[$name])) {
                    $extensions[$name] = $name;
                }
            }
        }
    }
    sort($extensions);
    return array_values($extensions);
}

$modules = scanExtensions(base_path('modules'));
$templates = scanExtensions(base_path('templates'));
$plugins = scanExtensions(base_path('plugins'));

// 언어팩은 활성 디렉토리(lang-packs/{identifier}) 와 번들(lang-packs/_bundled/{identifier})
// 양쪽 모두 식별자 기반이므로 동일 헬퍼 사용 가능
$languagePacks = scanExtensions(base_path('lang-packs'));

// AJAX 요청 처리: GET 방식으로 처리
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax_action'];

    // Artisan 명령어 실행
    if ($action === 'artisan') {
        $command = $_GET['command'] ?? '';
        try {
            $parts = preg_split('/\s+/', trim($command));
            $cmdName = array_shift($parts);

            $arguments = [];
            foreach ($parts as $part) {
                // 빈 문자열 스킵 (연속 공백 처리)
                if (empty($part)) {
                    continue;
                }

                if (str_starts_with($part, '--')) {
                    $optPart = substr($part, 2);
                    if (str_contains($optPart, '=')) {
                        [$optName, $optValue] = explode('=', $optPart, 2);
                        $arguments['--' . $optName] = $optValue;
                    } else {
                        $arguments['--' . $optPart] = true;
                    }
                } else {
                    if (!isset($arguments['identifier'])) {
                        $arguments['identifier'] = $part;
                    }
                }
            }

            $exitCode = \Illuminate\Support\Facades\Artisan::call($cmdName, $arguments);
            $output = \Illuminate\Support\Facades\Artisan::output();

            // 출력 메시지 정리 (전체 출력 유지)
            $cleanOutput = trim($output);

            // 출력이 비어있으면 기본 메시지
            if (empty($cleanOutput)) {
                $cleanOutput = $exitCode === 0 ? '명령이 성공적으로 실행되었습니다.' : '명령 실행 중 오류가 발생했습니다.';
            }

            // 성공/실패 여부 표시
            if ($exitCode === 0) {
                $cleanOutput = "✅ 완료\n\n" . $cleanOutput;
            } else {
                $cleanOutput = "❌ 실패 (exitCode: {$exitCode})\n\n" . $cleanOutput;
            }

            echo json_encode([
                'success' => $exitCode === 0,
                'output' => $cleanOutput,
                'code' => $exitCode
            ]);
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'output' => '❌ ' . $e->getMessage(),
                'code' => 1
            ]);
        }
        exit;
    }

    // 템플릿 삭제 (Manager 직접 사용 - 확인 프롬프트 우회)
    if ($action === 'template_uninstall') {
        $identifier = $_GET['identifier'] ?? '';
        try {
            $manager = app(\App\Extension\TemplateManager::class);
            $manager->loadTemplates();
            $result = $manager->uninstallTemplate($identifier);
            echo json_encode([
                'success' => $result,
                'output' => $result ? "✅ 템플릿 '{$identifier}' 삭제 완료" : "❌ 삭제 실패",
                'code' => $result ? 0 : 1
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'output' => '❌ ' . $e->getMessage(), 'code' => 1]);
        }
        exit;
    }

    // 모듈 삭제 (Manager 직접 사용)
    if ($action === 'module_uninstall') {
        $identifier = $_GET['identifier'] ?? '';
        try {
            $manager = app(\App\Extension\ModuleManager::class);
            $manager->loadModules();
            $result = $manager->uninstallModule($identifier);
            echo json_encode([
                'success' => $result,
                'output' => $result ? "✅ 모듈 '{$identifier}' 삭제 완료" : "❌ 삭제 실패",
                'code' => $result ? 0 : 1
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'output' => '❌ ' . $e->getMessage(), 'code' => 1]);
        }
        exit;
    }

    // 플러그인 삭제 (Manager 직접 사용)
    if ($action === 'plugin_uninstall') {
        $identifier = $_GET['identifier'] ?? '';
        try {
            $manager = app(\App\Extension\PluginManager::class);
            $manager->loadPlugins();
            $result = $manager->uninstallPlugin($identifier);
            echo json_encode([
                'success' => $result,
                'output' => $result ? "✅ 플러그인 '{$identifier}' 삭제 완료" : "❌ 삭제 실패",
                'code' => $result ? 0 : 1
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'output' => '❌ ' . $e->getMessage(), 'code' => 1]);
        }
        exit;
    }

    // 템플릿 빌드 (npm run build)
    if ($action === 'template_build') {
        $identifier = $_GET['identifier'] ?? '';
        try {
            $templatePath = base_path('templates/' . $identifier);

            if (!is_dir($templatePath)) {
                echo json_encode([
                    'success' => false,
                    'output' => "❌ 템플릿 '{$identifier}' 디렉토리를 찾을 수 없습니다.",
                    'code' => 1
                ]);
                exit;
            }

            // package.json 존재 확인
            if (!file_exists($templatePath . '/package.json')) {
                echo json_encode([
                    'success' => false,
                    'output' => "❌ package.json 파일이 없습니다.",
                    'code' => 1
                ]);
                exit;
            }

            // Windows 환경에서 npm run build 실행
            $command = "cd " . escapeshellarg($templatePath) . " && npm run build 2>&1";
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $command = "cd /d " . escapeshellarg($templatePath) . " && npm run build 2>&1";
            }

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            // ANSI 색상 코드 제거 및 로그 필터링
            $filteredOutput = [];
            foreach ($output as $line) {
                // ANSI 색상 코드 제거
                $cleanLine = preg_replace('/\x1b\[[0-9;]*m/', '', $line);

                // 필터링할 패턴 (상세 진행 로그 제외)
                $skipPatterns = [
                    '/^transforming\.\.\./',           // transforming...
                    '/^✓ \d+ modules transformed/',    // ✓ 194 modules transformed
                    '/^rendering chunks\.\.\./',       // rendering chunks...
                    '/^computing gzip size\.\.\./',    // computing gzip size...
                    '/^You are using Node\.js/',       // Node.js 버전 경고
                    '/^vite v[\d\.]+ building/',       // vite 버전 정보
                ];

                $shouldSkip = false;
                foreach ($skipPatterns as $pattern) {
                    if (preg_match($pattern, $cleanLine)) {
                        $shouldSkip = true;
                        break;
                    }
                }

                if (!$shouldSkip && trim($cleanLine) !== '') {
                    $filteredOutput[] = $cleanLine;
                }
            }

            $outputText = implode("\n", $filteredOutput);

            // 성공/실패 여부 표시
            if ($returnCode === 0) {
                $outputText = "✅ 빌드 완료\n\n" . $outputText;
            } else {
                $outputText = "❌ 빌드 실패\n\n" . $outputText;
            }

            echo json_encode([
                'success' => $returnCode === 0,
                'output' => $outputText,
                'code' => $returnCode
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'output' => '❌ ' . $e->getMessage(), 'code' => 1]);
        }
        exit;
    }

    // 인스톨러 초기화
    if ($action === 'reset') {
        $results = [];
        $deleteVendor = isset($_GET['delete_vendor']) && $_GET['delete_vendor'] === '1';

    // DB 테이블 전체 삭제 (.env 삭제 전에 실행해야 DB 연결 유지)
    $dropTables = isset($_GET['drop_tables']) && $_GET['drop_tables'] === '1';
    if ($dropTables) {
        try {
            $tables = \Illuminate\Support\Facades\DB::select('SHOW TABLES');

            if (empty($tables)) {
                $results[] = ['name' => 'DB 테이블', 'status' => 'notfound'];
            } else {
                // SHOW TABLES 결과의 첫 번째 컬럼명을 동적으로 가져옴
                $columnKey = array_key_first((array) $tables[0]);
                $tableNames = array_map(fn($t) => ((array) $t)[$columnKey], $tables);
                \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS = 0');
                $droppedCount = 0;
                foreach ($tableNames as $tableName) {
                    \Illuminate\Support\Facades\DB::statement("DROP TABLE IF EXISTS `{$tableName}`");
                    $droppedCount++;
                }
                \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS = 1');
                $results[] = [
                    'name' => "DB 테이블 ({$droppedCount}개 삭제)",
                    'status' => 'deleted'
                ];
            }
        } catch (\Exception $e) {
            $results[] = ['name' => 'DB 테이블 (' . $e->getMessage() . ')', 'status' => 'failed'];
        }
    }

    // 삭제 대상 파일 목록 (표시명 => 경로)
    $filesToDelete = [
        '.env' => base_path('.env'),
        'storage/app/g7_installed' => storage_path('app/g7_installed'),
        'storage/installer-state.json' => storage_path('installer-state.json')
    ];

    foreach ($filesToDelete as $displayName => $file) {
        if (file_exists($file)) {
            if (@unlink($file)) {
                $results[] = ['name' => $displayName, 'status' => 'deleted'];
            } else {
                $results[] = ['name' => $displayName, 'status' => 'failed'];
            }
        } else {
            $results[] = ['name' => $displayName, 'status' => 'notfound'];
        }
    }

    if ($deleteVendor) {
        $vendorPath = base_path('vendor');
        if (is_dir($vendorPath)) {
            // .gitkeep 보존: vendor 내부 항목만 개별 삭제
            $vendorItems = array_diff(scandir($vendorPath), ['.', '..', '.gitkeep']);
            $deletedCount = 0;
            $failedCount = 0;
            foreach ($vendorItems as $item) {
                $itemPath = $vendorPath . DIRECTORY_SEPARATOR . $item;
                $escapedItem = escapeshellarg($itemPath);
                if (is_dir($itemPath)) {
                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                        exec("rmdir /s /q $escapedItem 2>nul", $output, $returnCode);
                    } else {
                        exec("rm -rf $escapedItem", $output, $returnCode);
                    }
                    is_dir($itemPath) ? $failedCount++ : $deletedCount++;
                } else {
                    @unlink($itemPath) ? $deletedCount++ : $failedCount++;
                }
            }
            $results[] = [
                'name' => 'vendor/ (.gitkeep 보존)',
                'status' => $failedCount === 0 ? 'deleted' : 'failed'
            ];
        } else {
            $results[] = ['name' => 'vendor/', 'status' => 'notfound'];
        }
    }

    // 확장 설치 디렉토리 삭제 (_bundled, _pending 보존)
    $deleteExtensions = isset($_GET['delete_extensions']) && $_GET['delete_extensions'] === '1';
    if ($deleteExtensions) {
        $extensionTypes = ['modules', 'plugins', 'templates'];
        $protectedDirs = ['_bundled', '_pending'];
        foreach ($extensionTypes as $type) {
            $basePath = base_path($type);
            if (!is_dir($basePath)) {
                $results[] = ['name' => "$type/", 'status' => 'notfound'];
                continue;
            }
            $dirs = array_filter(glob($basePath . '/*'), 'is_dir');
            $deletedDirs = [];
            $failedDirs = [];
            foreach ($dirs as $dir) {
                $dirName = basename($dir);
                if (in_array($dirName, $protectedDirs)) {
                    continue; // _bundled, _pending 보호
                }
                $escapedDir = escapeshellarg($dir);
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    exec("rmdir /s /q $escapedDir 2>nul", $output, $returnCode);
                } else {
                    exec("rm -rf $escapedDir", $output, $returnCode);
                }
                is_dir($dir) ? $failedDirs[] = $dirName : $deletedDirs[] = $dirName;
            }
            if (empty($deletedDirs) && empty($failedDirs)) {
                $results[] = ['name' => "$type/", 'status' => 'notfound'];
            } else {
                $status = empty($failedDirs) ? 'deleted' : 'failed';
                $detail = implode(', ', $deletedDirs);
                $results[] = ['name' => "$type/ ($detail)", 'status' => $status];
            }
        }
    }

        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', '그누보드7') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .card-hover { transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .card-hover:hover { transform: translateY(-8px); box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3); }
        .card-icon { transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .card-hover:hover .card-icon { transform: scale(1.15) rotate(-5deg); }
        .selectable-item { transition: all 0.2s; border: 2px solid transparent; }
        .selectable-item:has(input:checked) { border-color: var(--select-color, #10b981); background: var(--select-bg, rgba(16, 185, 129, 0.1)); }
        .selectable-item:has(input:checked) .item-text { color: var(--select-color, #10b981); font-weight: 500; }
        .selectable-item:has(input:checked) .select-icon { color: var(--select-color, #10b981); opacity: 1; }
    </style>
</head>
<body class="min-h-screen bg-slate-900 flex flex-col">
    <!-- 메인 콘텐츠 -->
    <main class="flex-1 flex items-center justify-center px-6 pt-2 pb-4">
    <div class="w-full max-w-6xl text-center">

        <!-- 로고 및 타이틀 -->
        <div class="mb-6 flex items-center justify-center gap-4">
            <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-cyan-400 rounded-xl flex items-center justify-center shadow-xl shrink-0">
                <span class="text-3xl font-bold text-white">G7</span>
            </div>
            <div class="text-left">
                <h1 class="text-2xl font-light text-slate-50">{{ config('app.name', '그누보드7') }}</h1>
                <p class="text-sm text-slate-400">개발 환경 대시보드</p>
            </div>
        </div>

        <!-- 진행 상황 패널 -->
        <div id="progressPanel" class="hidden mb-4 bg-slate-800/80 rounded-xl p-4 border border-slate-700/30 text-left">
            <div class="flex items-center justify-between mb-3">
                <div class="text-sm font-medium text-white" id="progressTitle">재설치 진행 중...</div>
                <button onclick="closeProgress()" class="text-slate-400 hover:text-white text-xs">✕ 닫기</button>
            </div>
            <div id="progressContent" class="space-y-2 text-xs max-h-96 overflow-y-auto"></div>
        </div>

        <!-- Artisan 커맨드 패널 (아코디언) -->
        <div class="mb-6 bg-gradient-to-br from-slate-800/50 to-slate-800/30 backdrop-blur-lg rounded-2xl border border-slate-700/40 shadow-xl relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-emerald-500/0 to-teal-500/0 transition-all duration-300"></div>
            <div class="relative z-10">
                <!-- 아코디언 헤더 -->
                <button type="button" onclick="toggleCommandPanel()" class="w-full flex items-center gap-3 p-4 text-left hover:bg-slate-700/20 transition-colors rounded-2xl">
                    <div class="text-2xl shrink-0">⚡</div>
                    <div class="flex-1">
                        <div class="text-base font-semibold text-slate-100">Artisan 커맨드</div>
                        <p class="text-xs text-slate-400">모듈/템플릿 관리, 빌드, 캐시 초기화</p>
                    </div>
                    <svg id="commandPanelArrow" class="w-5 h-5 text-slate-400 transition-transform duration-200 rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <!-- 아코디언 콘텐츠 (기본 열림) -->
                <div id="commandPanelContent" class="border-t border-slate-700/30 p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <!-- 모듈 관리 -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">📦</span>
                                <span class="text-xs font-medium text-slate-200">모듈 관리</span>
                            </div>
                            <div class="space-y-2">
                                <div class="flex gap-2">
                                    <select id="moduleSelect" class="flex-1 px-3 py-2 bg-slate-800 text-slate-200 text-sm rounded border border-slate-600 focus:border-emerald-500 outline-none">
                                        <option value="">모듈 선택</option>
                                        @foreach ($modules as $module)
                                        <option value="{{ $module }}">{{ $module }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button onclick="runCommand('module:list')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>목록 조회</span>
                                        <span class="text-[10px] opacity-60">(module:list)</span>
                                    </button>
                                    <button onclick="runModuleCommand('install')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>설치</span>
                                        <span class="text-[10px] opacity-60">(install)</span>
                                    </button>
                                    <button onclick="runModuleCommand('activate')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>활성화</span>
                                        <span class="text-[10px] opacity-60">(activate)</span>
                                    </button>
                                    <button onclick="runModuleCommand('deactivate')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-orange-600 hover:bg-orange-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>비활성화</span>
                                        <span class="text-[10px] opacity-60">(deactivate)</span>
                                    </button>
                                    <button onclick="runModuleCommand('uninstall')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>제거</span>
                                        <span class="text-[10px] opacity-60">(uninstall)</span>
                                    </button>
                                    <button onclick="runModuleCommand('build')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-cyan-600 hover:bg-cyan-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>빌드</span>
                                        <span class="text-[10px] opacity-60">(build)</span>
                                    </button>
                                    <button onclick="runModuleCommand('refresh-layout')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>레이아웃 갱신</span>
                                        <span class="text-[10px] opacity-60">(refresh-layout)</span>
                                    </button>
                                    <button onclick="runCommand('module:cache-clear')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>캐시 초기화</span>
                                        <span class="text-[10px] opacity-60">(cache-clear)</span>
                                    </button>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-2 pt-2 border-t border-slate-700/20">
                                    <button onclick="runCommand('module:check-updates')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>업데이트 확인</span>
                                        <span class="text-[10px] opacity-60">(check-updates)</span>
                                    </button>
                                    <button onclick="runExtensionCommand('module', 'update')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>업데이트</span>
                                        <span class="text-[10px] opacity-60">(update)</span>
                                    </button>
                                    <button onclick="runExtensionCommand('module', 'update', '--force')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-sky-700 hover:bg-sky-800 text-white text-xs font-medium rounded transition-colors">
                                        <span>강제 업데이트</span>
                                        <span class="text-[10px] opacity-60">(update --force)</span>
                                    </button>
                                    <button onclick="runModuleCommand('seed')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-lime-600 hover:bg-lime-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>시더 실행</span>
                                        <span class="text-[10px] opacity-60">(seed)</span>
                                    </button>
                                    <button onclick="runModuleCommand('composer-install')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-violet-600 hover:bg-violet-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>Composer 설치</span>
                                        <span class="text-[10px] opacity-60">(composer-install)</span>
                                    </button>
                                    <button onclick="runModuleCommand('vendor-bundle')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-fuchsia-600 hover:bg-fuchsia-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>Vendor 번들</span>
                                        <span class="text-[10px] opacity-60">(vendor-bundle)</span>
                                    </button>
                                    <button onclick="runModuleCommand('vendor-verify')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-fuchsia-700 hover:bg-fuchsia-800 text-white text-xs font-medium rounded transition-colors">
                                        <span>Vendor 검증</span>
                                        <span class="text-[10px] opacity-60">(vendor-verify)</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- 템플릿 관리 -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">🎨</span>
                                <span class="text-xs font-medium text-slate-200">템플릿 관리</span>
                            </div>
                            <div class="space-y-2">
                                <div class="flex gap-2">
                                    <select id="templateSelect" class="flex-1 px-3 py-2 bg-slate-800 text-slate-200 text-sm rounded border border-slate-600 focus:border-emerald-500 outline-none">
                                        <option value="">템플릿 선택</option>
                                        @foreach ($templates as $template)
                                        <option value="{{ $template }}">{{ $template }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button onclick="runCommand('template:list')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>목록 조회</span>
                                        <span class="text-[10px] opacity-60">(template:list)</span>
                                    </button>
                                    <button onclick="runTemplateCommand('install')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>설치</span>
                                        <span class="text-[10px] opacity-60">(install)</span>
                                    </button>
                                    <button onclick="runTemplateCommand('activate')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>활성화</span>
                                        <span class="text-[10px] opacity-60">(activate)</span>
                                    </button>
                                    <button onclick="runTemplateCommand('deactivate')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-orange-600 hover:bg-orange-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>비활성화</span>
                                        <span class="text-[10px] opacity-60">(deactivate)</span>
                                    </button>
                                    <button onclick="runTemplateCommand('uninstall')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>제거</span>
                                        <span class="text-[10px] opacity-60">(uninstall)</span>
                                    </button>
                                    <button onclick="runTemplateCommand('build')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-cyan-600 hover:bg-cyan-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>빌드</span>
                                        <span class="text-[10px] opacity-60">(build)</span>
                                    </button>
                                    <button onclick="runTemplateCommand('refresh-layout')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>레이아웃 갱신</span>
                                        <span class="text-[10px] opacity-60">(refresh-layout)</span>
                                    </button>
                                    <button onclick="runCommand('template:cache-clear')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>캐시 초기화</span>
                                        <span class="text-[10px] opacity-60">(cache-clear)</span>
                                    </button>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-2 pt-2 border-t border-slate-700/20">
                                    <button onclick="runCommand('template:check-updates')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>업데이트 확인</span>
                                        <span class="text-[10px] opacity-60">(check-updates)</span>
                                    </button>
                                    <button onclick="runExtensionCommand('template', 'update', '--layout-strategy=overwrite')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>업데이트 (덮어쓰기)</span>
                                        <span class="text-[10px] opacity-60">(update --overwrite)</span>
                                    </button>
                                    <button onclick="runExtensionCommand('template', 'update', '--layout-strategy=keep')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>업데이트 (유지)</span>
                                        <span class="text-[10px] opacity-60">(update --keep)</span>
                                    </button>
                                    <button onclick="runExtensionCommand('template', 'update', '--layout-strategy=overwrite --force')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-sky-700 hover:bg-sky-800 text-white text-xs font-medium rounded transition-colors">
                                        <span>강제 업데이트</span>
                                        <span class="text-[10px] opacity-60">(update --force)</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- 플러그인 관리 -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">🔌</span>
                                <span class="text-xs font-medium text-slate-200">플러그인 관리</span>
                            </div>
                            <div class="space-y-2">
                                <div class="flex gap-2">
                                    <select id="pluginSelect" class="flex-1 px-3 py-2 bg-slate-800 text-slate-200 text-sm rounded border border-slate-600 focus:border-emerald-500 outline-none">
                                        <option value="">플러그인 선택</option>
                                        @foreach ($plugins as $plugin)
                                        <option value="{{ $plugin }}">{{ $plugin }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button onclick="runCommand('plugin:list')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>목록 조회</span>
                                        <span class="text-[10px] opacity-60">(plugin:list)</span>
                                    </button>
                                    <button onclick="runPluginCommand('install')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>설치</span>
                                        <span class="text-[10px] opacity-60">(install)</span>
                                    </button>
                                    <button onclick="runPluginCommand('activate')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>활성화</span>
                                        <span class="text-[10px] opacity-60">(activate)</span>
                                    </button>
                                    <button onclick="runPluginCommand('deactivate')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-orange-600 hover:bg-orange-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>비활성화</span>
                                        <span class="text-[10px] opacity-60">(deactivate)</span>
                                    </button>
                                    <button onclick="runPluginCommand('uninstall')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>제거</span>
                                        <span class="text-[10px] opacity-60">(uninstall)</span>
                                    </button>
                                    <button onclick="runPluginCommand('build')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-cyan-600 hover:bg-cyan-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>빌드</span>
                                        <span class="text-[10px] opacity-60">(build)</span>
                                    </button>
                                    <button onclick="runPluginCommand('refresh-layout')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>레이아웃 갱신</span>
                                        <span class="text-[10px] opacity-60">(refresh-layout)</span>
                                    </button>
                                    <button onclick="runCommand('plugin:cache-clear')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>캐시 초기화</span>
                                        <span class="text-[10px] opacity-60">(cache-clear)</span>
                                    </button>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-2 pt-2 border-t border-slate-700/20">
                                    <button onclick="runCommand('plugin:check-updates')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>업데이트 확인</span>
                                        <span class="text-[10px] opacity-60">(check-updates)</span>
                                    </button>
                                    <button onclick="runExtensionCommand('plugin', 'update')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>업데이트</span>
                                        <span class="text-[10px] opacity-60">(update)</span>
                                    </button>
                                    <button onclick="runExtensionCommand('plugin', 'update', '--force')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-sky-700 hover:bg-sky-800 text-white text-xs font-medium rounded transition-colors">
                                        <span>강제 업데이트</span>
                                        <span class="text-[10px] opacity-60">(update --force)</span>
                                    </button>
                                    <button onclick="runPluginCommand('seed')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-lime-600 hover:bg-lime-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>시더 실행</span>
                                        <span class="text-[10px] opacity-60">(seed)</span>
                                    </button>
                                    <button onclick="runPluginCommand('composer-install')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-violet-600 hover:bg-violet-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>Composer 설치</span>
                                        <span class="text-[10px] opacity-60">(composer-install)</span>
                                    </button>
                                    <button onclick="runPluginCommand('vendor-bundle')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-fuchsia-600 hover:bg-fuchsia-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>Vendor 번들</span>
                                        <span class="text-[10px] opacity-60">(vendor-bundle)</span>
                                    </button>
                                    <button onclick="runPluginCommand('vendor-verify')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-fuchsia-700 hover:bg-fuchsia-800 text-white text-xs font-medium rounded transition-colors">
                                        <span>Vendor 검증</span>
                                        <span class="text-[10px] opacity-60">(vendor-verify)</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- 언어팩 관리 -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">🌐</span>
                                <span class="text-xs font-medium text-slate-200">언어팩 관리</span>
                            </div>
                            <div class="space-y-2">
                                <div class="flex gap-2">
                                    <select id="languagePackSelect" class="flex-1 px-3 py-2 bg-slate-800 text-slate-200 text-sm rounded border border-slate-600 focus:border-emerald-500 outline-none">
                                        <option value="">언어팩 선택</option>
                                        @foreach ($languagePacks as $languagePack)
                                        <option value="{{ $languagePack }}">{{ $languagePack }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button onclick="runCommand('language-pack:list')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>목록 조회</span>
                                        <span class="text-[10px] opacity-60">(language-pack:list)</span>
                                    </button>
                                    <button onclick="runLanguagePackCommand('install')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>설치</span>
                                        <span class="text-[10px] opacity-60">(install)</span>
                                    </button>
                                    <button onclick="runLanguagePackCommand('activate')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>활성화</span>
                                        <span class="text-[10px] opacity-60">(activate)</span>
                                    </button>
                                    <button onclick="runLanguagePackCommand('deactivate')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-orange-600 hover:bg-orange-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>비활성화</span>
                                        <span class="text-[10px] opacity-60">(deactivate)</span>
                                    </button>
                                    <button onclick="runLanguagePackCommand('uninstall')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>제거</span>
                                        <span class="text-[10px] opacity-60">(uninstall)</span>
                                    </button>
                                    <button onclick="runCommand('language-pack:cache-clear')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>캐시 초기화</span>
                                        <span class="text-[10px] opacity-60">(cache-clear)</span>
                                    </button>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-2 pt-2 border-t border-slate-700/20">
                                    <button onclick="runCommand('language-pack:check-updates')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>업데이트 확인</span>
                                        <span class="text-[10px] opacity-60">(check-updates)</span>
                                    </button>
                                    <button onclick="runLanguagePackCommand('update')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-xs font-medium rounded transition-colors">
                                        <span>업데이트</span>
                                        <span class="text-[10px] opacity-60">(update)</span>
                                    </button>
                                    <button onclick="runLanguagePackCommand('update', '--force')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-sky-700 hover:bg-sky-800 text-white text-xs font-medium rounded transition-colors">
                                        <span>강제 업데이트</span>
                                        <span class="text-[10px] opacity-60">(update --force)</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- 코어 빌드 -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">🔨</span>
                                <span class="text-xs font-medium text-slate-200">코어 빌드</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button onclick="runCommand('core:build')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-cyan-600 hover:bg-cyan-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>템플릿 엔진 빌드</span>
                                    <span class="text-[10px] opacity-60">(core:build)</span>
                                </button>
                                <button onclick="runCommand('core:build --full')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-cyan-700 hover:bg-cyan-800 text-white text-xs font-medium rounded transition-colors">
                                    <span>전체 빌드</span>
                                    <span class="text-[10px] opacity-60">(core:build --full)</span>
                                </button>
                            </div>
                        </div>

                        <!-- 확장 공통 -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">🔗</span>
                                <span class="text-xs font-medium text-slate-200">확장 공통</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button onclick="runCommand('extension:composer-install')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-violet-600 hover:bg-violet-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>Composer 전체 설치</span>
                                    <span class="text-[10px] opacity-60">(extension:composer-install)</span>
                                </button>
                                <button onclick="runCommand('extension:update-autoload')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-violet-600 hover:bg-violet-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>오토로드 갱신</span>
                                    <span class="text-[10px] opacity-60">(extension:update-autoload)</span>
                                </button>
                                <button onclick="runCommand('extension:clear-version-cache')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>버전 캐시 초기화</span>
                                    <span class="text-[10px] opacity-60">(extension:clear-version-cache)</span>
                                </button>
                            </div>
                        </div>

                        <!-- 캐시 & DB -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">🧹</span>
                                <span class="text-xs font-medium text-slate-200">캐시 & DB</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button onclick="runCommand('cache:clear')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>캐시 초기화</span>
                                    <span class="text-[10px] opacity-60">(cache:clear)</span>
                                </button>
                                <button onclick="runCommand('config:clear')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>설정 캐시</span>
                                    <span class="text-[10px] opacity-60">(config:clear)</span>
                                </button>
                                <button onclick="runCommand('route:clear')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>라우트 캐시</span>
                                    <span class="text-[10px] opacity-60">(route:clear)</span>
                                </button>
                                <button onclick="runCommand('view:clear')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>뷰 캐시</span>
                                    <span class="text-[10px] opacity-60">(view:clear)</span>
                                </button>
                                <button onclick="runCommand('optimize:clear')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-700 hover:bg-amber-800 text-white text-xs font-medium rounded transition-colors">
                                    <span>전체 최적화</span>
                                    <span class="text-[10px] opacity-60">(optimize:clear)</span>
                                </button>
                                <button onclick="runCommand('migrate:fresh --seed')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>DB 초기화</span>
                                    <span class="text-[10px] opacity-60">(migrate:fresh --seed)</span>
                                </button>
                            </div>
                        </div>

                        <!-- 코어 업데이트 -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">🔄</span>
                                <span class="text-xs font-medium text-slate-200">코어 업데이트</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button onclick="runCommand('core:check-updates')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>업데이트 확인</span>
                                    <span class="text-[10px] opacity-60">(core:check-updates)</span>
                                </button>
                                <button onclick="if(confirm('코어 업데이트는 DB 마이그레이션과 파일 갱신을 수반합니다. 백업 후 진행하셨나요?')) runCommand('core:update --force')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>코어 업데이트</span>
                                    <span class="text-[10px] opacity-60">(core:update --force) ⚠️</span>
                                </button>
                                <button onclick="runCommand('core:vendor-verify')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-fuchsia-600 hover:bg-fuchsia-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>코어 Vendor 검증</span>
                                    <span class="text-[10px] opacity-60">(core:vendor-verify)</span>
                                </button>
                                <button onclick="runCommand('vendor-bundle:verify-all')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-fuchsia-700 hover:bg-fuchsia-800 text-white text-xs font-medium rounded transition-colors">
                                    <span>전체 Vendor 검증</span>
                                    <span class="text-[10px] opacity-60">(vendor-bundle:verify-all)</span>
                                </button>
                            </div>
                        </div>

                        <!-- SEO -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">🔍</span>
                                <span class="text-xs font-medium text-slate-200">SEO</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button onclick="runCommand('seo:warmup')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>SEO 워밍업</span>
                                    <span class="text-[10px] opacity-60">(seo:warmup)</span>
                                </button>
                                <button onclick="runCommand('seo:clear')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>SEO 캐시 삭제</span>
                                    <span class="text-[10px] opacity-60">(seo:clear)</span>
                                </button>
                                <button onclick="runCommand('seo:stats')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>SEO 통계</span>
                                    <span class="text-[10px] opacity-60">(seo:stats)</span>
                                </button>
                                <button onclick="runCommand('seo:generate-sitemap --sync')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>사이트맵 생성</span>
                                    <span class="text-[10px] opacity-60">(seo:generate-sitemap)</span>
                                </button>
                            </div>
                        </div>

                        <!-- 유지보수 -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">🛠️</span>
                                <span class="text-xs font-medium text-slate-200">유지보수</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button onclick="runCommand('notification:cleanup')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>알림 정리</span>
                                    <span class="text-[10px] opacity-60">(notification:cleanup)</span>
                                </button>
                                <button onclick="runCommand('layout-previews:cleanup')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>레이아웃 미리보기 정리</span>
                                    <span class="text-[10px] opacity-60">(layout-previews:cleanup)</span>
                                </button>
                                <button onclick="runCommand('geoip:update')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>GeoIP DB 갱신</span>
                                    <span class="text-[10px] opacity-60">(geoip:update)</span>
                                </button>
                                <button onclick="runCommand('dashboard:broadcast-resources')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-cyan-600 hover:bg-cyan-700 text-white text-xs font-medium rounded transition-colors">
                                    <span>대시보드 리소스 브로드캐스트</span>
                                    <span class="text-[10px] opacity-60">(dashboard:broadcast-resources)</span>
                                </button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
            <!-- 왼쪽: 페이지 이동 -->
            <div class="space-y-4">
                <h2 class="text-sm font-medium text-slate-500 uppercase tracking-wider text-left">페이지 이동</h2>

                <a href="{{ url('/admin') }}" class="card-hover block bg-gradient-to-br from-slate-800/50 to-slate-800/30 backdrop-blur-lg rounded-2xl p-5 border border-slate-700/40 shadow-xl relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-br from-blue-500/0 to-cyan-500/0 group-hover:from-blue-500/10 group-hover:to-cyan-500/5 transition-all duration-300"></div>
                    <div class="relative z-10 flex items-center gap-4">
                        <div class="card-icon text-4xl shrink-0">⚙️</div>
                        <div class="text-left">
                            <div class="text-lg font-semibold text-slate-100 group-hover:text-blue-200 transition-colors">관리자 페이지</div>
                            <p class="text-sm text-slate-400 mt-0.5">시스템 관리 및 설정</p>
                        </div>
                    </div>
                </a>

                <a href="{{ url('/install') }}" class="card-hover block bg-gradient-to-br from-slate-800/50 to-slate-800/30 backdrop-blur-lg rounded-2xl p-5 border border-slate-700/40 shadow-xl relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-br from-purple-500/0 to-pink-500/0 group-hover:from-purple-500/10 group-hover:to-pink-500/5 transition-all duration-300"></div>
                    <div class="relative z-10 flex items-center gap-4">
                        <div class="card-icon text-4xl shrink-0">📥</div>
                        <div class="text-left">
                            <div class="text-lg font-semibold text-slate-100 group-hover:text-purple-200 transition-colors">설치 페이지</div>
                            <p class="text-sm text-slate-400 mt-0.5">프로젝트 설치 및 구성</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- 오른쪽: 개발 도구 -->
            <div class="space-y-4">
                <h2 class="text-sm font-medium text-slate-500 uppercase tracking-wider text-left">개발 도구</h2>

                <!-- 인스톨러 초기화 -->
                <div class="card-hover bg-gradient-to-br from-slate-800/50 to-slate-800/30 backdrop-blur-lg rounded-2xl p-4 border border-slate-700/40 shadow-xl relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-br from-red-500/0 to-orange-500/0 group-hover:from-red-500/5 group-hover:to-orange-500/5 transition-all duration-300"></div>
                    <div class="relative z-10">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="text-2xl shrink-0">🔄</div>
                        <div class="text-left flex-1">
                            <div class="text-base font-semibold text-slate-100">인스톨러 초기화</div>
                            <p class="text-xs text-slate-400">.env, storage 파일 삭제하여 설치 상태 초기화</p>
                        </div>
                        <button type="button" onclick="startReset()" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded-lg transition-colors shrink-0">
                            초기화
                        </button>
                    </div>
                    <div class="space-y-2">
                        <div class="flex items-center gap-2 text-xs text-slate-400 bg-slate-900/30 px-3 py-2 rounded-lg">
                            <span>vendor 폴더 삭제</span>
                            <label class="relative cursor-pointer ml-auto">
                                <input type="checkbox" id="deleteVendor" class="sr-only peer">
                                <div class="w-9 h-5 bg-slate-700 rounded-full peer peer-checked:bg-red-600 transition-colors"></div>
                                <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-4"></div>
                            </label>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-slate-400 bg-slate-900/30 px-3 py-2 rounded-lg">
                            <span>확장 설치 디렉토리 삭제</span>
                            <span class="text-[10px] text-slate-500">(_bundled, _pending 보존)</span>
                            <label class="relative cursor-pointer ml-auto">
                                <input type="checkbox" id="deleteExtensions" class="sr-only peer">
                                <div class="w-9 h-5 bg-slate-700 rounded-full peer peer-checked:bg-red-600 transition-colors"></div>
                                <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-4"></div>
                            </label>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-slate-400 bg-slate-900/30 px-3 py-2 rounded-lg">
                            <span>DB 테이블 전체 삭제</span>
                            <span class="text-[10px] text-slate-500">(migrations 포함)</span>
                            <label class="relative cursor-pointer ml-auto">
                                <input type="checkbox" id="dropTables" class="sr-only peer">
                                <div class="w-9 h-5 bg-slate-700 rounded-full peer peer-checked:bg-red-600 transition-colors"></div>
                                <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-4"></div>
                            </label>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 컴포넌트 미리보기 (아코디언) - 전체 너비 -->
        <div class="bg-gradient-to-br from-slate-800/50 to-slate-800/30 backdrop-blur-lg rounded-2xl border border-slate-700/40 shadow-xl relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-blue-500/0 to-cyan-500/0 transition-all duration-300"></div>
            <div class="relative z-10">
                <!-- 아코디언 헤더 -->
                <button type="button" onclick="toggleComponentPreview()" class="w-full flex items-center gap-3 p-4 text-left hover:bg-slate-700/20 transition-colors rounded-2xl">
                    <div class="text-2xl shrink-0">🧪</div>
                    <div class="flex-1">
                        <div class="text-base font-semibold text-slate-100">컴포넌트 미리보기</div>
                        <p class="text-xs text-slate-400">개발 중인 UI 컴포넌트 테스트</p>
                    </div>
                    <svg id="componentPreviewArrow" class="w-5 h-5 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <!-- 아코디언 콘텐츠 (기본 닫힘) - 2단 그리드 -->
                <div id="componentPreviewContent" class="hidden border-t border-slate-700/30">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 p-4">

                        <!-- Toggle 컴포넌트 -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">🔘</span>
                                <span class="text-sm font-medium text-slate-200">Toggle</span>
                                <span class="text-[10px] text-slate-500 bg-slate-800 px-2 py-0.5 rounded">Flowbite 스타일</span>
                            </div>

                            <!-- Props 설명 -->
                            <div class="bg-slate-800/30 rounded-lg p-2 mb-3 text-[10px] text-slate-400">
                                <div class="grid grid-cols-2 gap-x-3 gap-y-1">
                                    <div><span class="text-blue-400">checked/value</span>: 체크 상태</div>
                                    <div><span class="text-blue-400">onChange</span>: 변경 콜백</div>
                                    <div><span class="text-blue-400">disabled</span>: 비활성화</div>
                                    <div><span class="text-blue-400">size</span>: sm | md | lg</div>
                                </div>
                            </div>

                            <div class="space-y-3" id="toggleDemo">
                                <!-- 기본 토글 (크기별) -->
                                <div class="bg-slate-800/50 rounded-lg p-3 border border-slate-700/30">
                                    <label class="block text-xs font-medium text-blue-400 mb-2">
                                        📏 크기별 토글
                                    </label>
                                    <div class="space-y-3">
                                        <!-- Small -->
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs text-slate-300">Small (sm)</span>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" class="toggle-small sr-only peer">
                                                <div class="relative w-9 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 peer-checked:bg-blue-600 dark:peer-checked:bg-blue-500 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 dark:after:border-gray-600 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-full peer-checked:after:border-white transition-colors"></div>
                                            </label>
                                        </div>
                                        <!-- Medium -->
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs text-slate-300">Medium (md) - 기본</span>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" class="toggle-medium sr-only peer" checked>
                                                <div class="relative w-11 h-6 bg-gray-200 dark:bg-gray-700 rounded-full peer peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 peer-checked:bg-blue-600 dark:peer-checked:bg-blue-500 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 dark:after:border-gray-600 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full peer-checked:after:border-white transition-colors"></div>
                                            </label>
                                        </div>
                                        <!-- Large -->
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs text-slate-300">Large (lg)</span>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" class="toggle-large sr-only peer">
                                                <div class="relative w-14 h-7 bg-gray-200 dark:bg-gray-700 rounded-full peer peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 peer-checked:bg-blue-600 dark:peer-checked:bg-blue-500 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 dark:after:border-gray-600 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:after:translate-x-full peer-checked:after:border-white transition-colors"></div>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- 라벨과 설명이 있는 토글 -->
                                <div class="bg-slate-800/50 rounded-lg p-3 border border-slate-700/30">
                                    <label class="block text-xs font-medium text-emerald-400 mb-2">
                                        📝 라벨 & 설명
                                    </label>
                                    <div class="flex items-center">
                                        <div class="flex-1 mr-4">
                                            <label for="toggle-notifications" class="block text-sm font-medium text-gray-900 dark:text-white cursor-pointer">
                                                알림 활성화
                                            </label>
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                이 옵션을 활성화하면 시스템 알림을 받습니다
                                            </p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" id="toggle-notifications" class="toggle-notifications sr-only peer" checked>
                                            <div class="relative w-11 h-6 bg-gray-200 dark:bg-gray-700 rounded-full peer peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 peer-checked:bg-blue-600 dark:peer-checked:bg-blue-500 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 dark:after:border-gray-600 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full peer-checked:after:border-white transition-colors"></div>
                                        </label>
                                    </div>
                                </div>

                                <!-- Disabled 상태 -->
                                <div class="bg-slate-800/50 rounded-lg p-3 border border-slate-700/30">
                                    <label class="block text-xs font-medium text-slate-500 mb-2">
                                        🚫 비활성화 상태
                                    </label>
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs text-slate-500">Disabled Toggle</span>
                                        <label class="relative inline-flex items-center cursor-not-allowed opacity-50">
                                            <input type="checkbox" class="sr-only peer" disabled>
                                            <div class="relative w-11 h-6 bg-gray-200 dark:bg-gray-700 rounded-full peer peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 peer-checked:bg-blue-600 dark:peer-checked:bg-blue-500 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 dark:after:border-gray-600 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full peer-checked:after:border-white transition-colors"></div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <p class="text-[10px] text-slate-500 mt-3">💡 라이트/다크 모드 모두 지원 | onChange 이벤트 객체 전달</p>
                        </div>
                        <!-- /Toggle 컴포넌트 -->

                        <!-- TagInput 컴포넌트 -->
                        <div class="bg-slate-900/40 rounded-xl p-4 border border-slate-700/30">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">🏷️</span>
                                <span class="text-sm font-medium text-slate-200">TagInput</span>
                                <span class="text-[10px] text-slate-500 bg-slate-800 px-2 py-0.5 rounded">react-select 기반</span>
                            </div>

                            <!-- Props 설명 -->
                            <div class="bg-slate-800/30 rounded-lg p-2 mb-3 text-[10px] text-slate-400">
                                <div class="grid grid-cols-2 gap-x-3 gap-y-1">
                                    <div><span class="text-emerald-400">creatable</span>: 새 항목 추가 가능</div>
                                    <div><span class="text-emerald-400">maxItems</span>: 최대 선택 개수</div>
                                    <div><span class="text-emerald-400">onBeforeRemove</span>: 삭제 전 확인</div>
                                    <div><span class="text-emerald-400">onCreateOption</span>: 생성 콜백</div>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <!-- 분류 (Creatable) -->
                                <div class="bg-slate-800/50 rounded-lg p-3 border border-slate-700/30">
                                    <label class="block text-xs font-medium text-emerald-400 mb-2">
                                        📁 분류 <span class="text-slate-500 font-normal">creatable={true}</span>
                                    </label>
                                    <div class="tag-input-container" data-creatable="true" data-name="categories">
                                        <div class="flex flex-wrap gap-1.5 p-2 bg-slate-800 rounded-lg border border-slate-600 focus-within:border-emerald-500 transition-colors min-h-[42px]">
                                            <span class="tag-chip bg-emerald-600/30 text-emerald-300 px-2 py-1 rounded text-xs flex items-center gap-1">
                                                A/S <span class="text-emerald-400/60 text-[10px]">(5)</span>
                                                <button type="button" class="tag-remove hover:text-red-400 ml-0.5" data-value="A/S" data-count="5">×</button>
                                            </span>
                                            <span class="tag-chip bg-emerald-600/30 text-emerald-300 px-2 py-1 rounded text-xs flex items-center gap-1">
                                                반품
                                                <button type="button" class="tag-remove hover:text-red-400 ml-0.5" data-value="반품" data-count="0">×</button>
                                            </span>
                                            <input type="text" class="tag-input flex-1 min-w-[120px] bg-transparent text-slate-200 text-xs outline-none placeholder-slate-500" placeholder="검색...">
                                        </div>
                                        <div class="tag-dropdown hidden mt-1 bg-slate-800 border border-slate-600 rounded-lg shadow-xl max-h-48 overflow-y-auto">
                                            <!-- 동적으로 채워짐 -->
                                        </div>
                                    </div>
                                    <p class="text-[10px] text-slate-500 mt-1.5">💡 count > 0이면 삭제 차단 (onBeforeRemove)</p>
                                </div>

                                <!-- 역할 (Select Only) -->
                                <div class="bg-slate-800/50 rounded-lg p-3 border border-slate-700/30">
                                    <label class="block text-xs font-medium text-purple-400 mb-2">
                                        👥 역할 <span class="text-slate-500 font-normal">creatable={false}</span>
                                    </label>
                                    <div class="tag-input-container" data-creatable="false" data-name="roles">
                                        <div class="flex flex-wrap gap-1.5 p-2 bg-slate-800 rounded-lg border border-slate-600 focus-within:border-purple-500 transition-colors min-h-[42px]">
                                            <span class="tag-chip bg-purple-600/30 text-purple-300 px-2 py-1 rounded text-xs flex items-center gap-1">
                                                회원
                                                <button type="button" class="tag-remove hover:text-red-400 ml-0.5" data-value="1">×</button>
                                            </span>
                                            <span class="tag-chip bg-purple-600/30 text-purple-300 px-2 py-1 rounded text-xs flex items-center gap-1">
                                                콘텐츠 관리자
                                                <button type="button" class="tag-remove hover:text-red-400 ml-0.5" data-value="2">×</button>
                                            </span>
                                            <input type="text" class="tag-input flex-1 min-w-[120px] bg-transparent text-slate-200 text-xs outline-none placeholder-slate-500" placeholder="검색...">
                                        </div>
                                        <div class="tag-dropdown hidden mt-1 bg-slate-800 border border-slate-600 rounded-lg shadow-xl max-h-48 overflow-y-auto">
                                            <!-- 동적으로 채워짐 -->
                                        </div>
                                    </div>
                                    <p class="text-[10px] text-slate-500 mt-1.5">💡 체크박스 다중 선택 | 새 항목 추가 불가</p>
                                </div>
                            </div>
                        </div>
                        <!-- /TagInput 컴포넌트 -->

                        <!-- 추가 컴포넌트는 여기에 추가 -->

                    </div>
                </div>
            </div>
        </div>

    </div>
    </main>

    <!-- 하단 푸터 -->
    <footer class="bg-slate-800/30 border-t border-slate-700/30 px-6 py-4">
        <div class="max-w-6xl mx-auto">
            <div class="flex flex-wrap items-center justify-center gap-2 text-xs mb-3">
                <span class="inline-flex items-center gap-1.5 bg-slate-900/50 px-3 py-1 rounded-full">
                    <span class="text-blue-400">🐘</span>
                    <span class="text-slate-300">PHP {{ phpversion() }}</span>
                </span>
                <span class="inline-flex items-center gap-1.5 bg-slate-900/50 px-3 py-1 rounded-full">
                    <span class="text-red-400">🔺</span>
                    <span class="text-slate-300">Laravel {{ app()->version() }}</span>
                </span>
                <span class="inline-flex items-center gap-1.5 bg-slate-900/50 px-3 py-1 rounded-full">
                    <span class="text-orange-400">🌐</span>
                    <span class="text-slate-300">{{ php_sapi_name() === 'cli' ? 'CLI' : (isset($_SERVER['SERVER_SOFTWARE']) ? explode('/', $_SERVER['SERVER_SOFTWARE'])[0] : 'Unknown') }}</span>
                </span>
                <span class="inline-flex items-center gap-1.5 bg-slate-900/50 px-3 py-1 rounded-full">
                    <span class="text-cyan-400">🗄️</span>
                    <span class="text-slate-300">{{ env('DB_WRITE_DATABASE', env('DB_DATABASE', 'N/A')) }}</span>
                </span>
                <span class="inline-flex items-center gap-1.5 bg-slate-900/50 px-3 py-1 rounded-full {{ env('APP_ENV') === 'production' ? 'text-red-400' : 'text-yellow-400' }}">
                    <span>🏷️</span>
                    <span>{{ env('APP_ENV', 'local') }}</span>
                </span>
                <span class="inline-flex items-center gap-1.5 bg-slate-900/50 px-3 py-1 rounded-full {{ env('APP_DEBUG') ? 'text-green-400' : 'text-slate-500' }}">
                    <span>🐛</span>
                    <span>Debug {{ env('APP_DEBUG') ? 'ON' : 'OFF' }}</span>
                </span>
            </div>
            <div class="text-center text-slate-500 text-xs">
                © {{ date('Y') }} {{ config('app.name', '그누보드7') }}
            </div>
        </div>
    </footer>

    <script>
        async function startReset() {
            const deleteVendor = document.getElementById('deleteVendor').checked;
            const deleteExtensions = document.getElementById('deleteExtensions').checked;
            const dropTables = document.getElementById('dropTables').checked;
            let message = '⚠️ 인스톨러 초기화\n\n';
            message += '다음 항목이 삭제됩니다:\n';
            message += '  • .env\n';
            message += '  • storage/app/g7_installed\n';
            message += '  • storage/installer-state.json\n';
            if (deleteVendor) {
                message += '  • vendor/ (.gitkeep 보존)\n';
            }
            if (deleteExtensions) {
                message += '  • modules/ 설치 디렉토리 (_bundled, _pending 보존)\n';
                message += '  • plugins/ 설치 디렉토리 (_bundled, _pending 보존)\n';
                message += '  • templates/ 설치 디렉토리 (_bundled, _pending 보존)\n';
            }
            if (dropTables) {
                message += '  • DB 테이블 전체 삭제 (migrations 포함)\n';
            }
            message += '\n계속하시겠습니까?';

            if (!confirm(message)) return;

            const panel = document.getElementById('progressPanel');
            const content = document.getElementById('progressContent');
            document.getElementById('progressTitle').textContent = '🔄 인스톨러 초기화';
            content.innerHTML = '<div class="text-slate-400 text-[11px]">⏳ 초기화 진행 중...</div>';
            panel.classList.remove('hidden');

            try {
                const url = `${window.location.pathname}?ajax_action=reset&delete_vendor=${deleteVendor ? '1' : '0'}&delete_extensions=${deleteExtensions ? '1' : '0'}&drop_tables=${dropTables ? '1' : '0'}`;
                const response = await fetch(url);
                const data = await response.json();

                // 결과 표시
                let html = '<div class="space-y-1">';
                html += '<div class="font-medium text-slate-200 text-[11px] mb-2">📁 파일 초기화 결과:</div>';

                const statusIcons = { deleted: '✅', failed: '❌', notfound: '⏭️' };
                const statusTexts = { deleted: '삭제됨', failed: '삭제 실패', notfound: '없음' };
                const statusColors = { deleted: 'text-emerald-400', failed: 'text-red-400', notfound: 'text-slate-500' };

                data.results.forEach(item => {
                    html += `<div class="flex items-center gap-2 ${statusColors[item.status]} text-[11px]">
                        <span>${statusIcons[item.status]}</span>
                        <span>${statusTexts[item.status]}:</span>
                        <code class="bg-slate-900/50 px-1.5 py-0.5 rounded text-cyan-300 font-mono">${item.name}</code>
                    </div>`;
                });

                html += '</div>';

                // 완료 메시지 및 버튼
                html += `<div class="mt-3 pt-3 border-t border-slate-700/50">
                    <div class="text-emerald-400 text-[11px] font-medium mb-2">🎉 초기화 완료!</div>
                    <a href="/install" class="inline-flex items-center gap-1.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium px-3 py-1.5 rounded-lg transition-colors">
                        <span>📥</span> 설치 페이지로 이동
                    </a>
                </div>`;

                content.innerHTML = html;
            } catch (error) {
                content.innerHTML = `<div class="text-red-400 text-[11px]">❌ 오류 발생: ${error.message}</div>`;
            }
        }

        function closeProgress() {
            document.getElementById('progressPanel').classList.add('hidden');
        }

        // 컴포넌트 미리보기 아코디언 토글
        function toggleComponentPreview() {
            const content = document.getElementById('componentPreviewContent');
            const arrow = document.getElementById('componentPreviewArrow');

            if (content.classList.contains('hidden')) {
                content.classList.remove('hidden');
                arrow.classList.add('rotate-180');
            } else {
                content.classList.add('hidden');
                arrow.classList.remove('rotate-180');
            }
        }

        async function runArtisanCommand(command, stepName) {
            try {
                const url = `${window.location.pathname}?ajax_action=artisan&command=${encodeURIComponent(command)}`;

                // 타임아웃 설정 (60초)
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 60000);

                const response = await fetch(url, { signal: controller.signal });
                clearTimeout(timeoutId);

                // HTTP 상태 코드 검증
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const result = await response.json();
                return { ...result, stepName };
            } catch (error) {
                if (error.name === 'AbortError') {
                    return { success: false, output: '⏱️ 타임아웃 (60초 초과)', stepName };
                }
                return { success: false, output: '❌ ' + error.message, stepName };
            }
        }

        // Manager 직접 호출 (uninstall용)
        async function runUninstall(type, identifier, stepName) {
            try {
                const actionMap = { template: 'template_uninstall', module: 'module_uninstall', plugin: 'plugin_uninstall' };
                const action = actionMap[type] || 'module_uninstall';
                const url = `${window.location.pathname}?ajax_action=${action}&identifier=${encodeURIComponent(identifier)}`;

                // 타임아웃 설정 (30초)
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 30000);

                const response = await fetch(url, { signal: controller.signal });
                clearTimeout(timeoutId);

                // HTTP 상태 코드 검증
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const result = await response.json();
                return { ...result, stepName };
            } catch (error) {
                if (error.name === 'AbortError') {
                    return { success: false, output: '⏱️ 타임아웃 (30초 초과)', stepName };
                }
                return { success: false, output: '❌ ' + error.message, stepName };
            }
        }

        function addProgressStep(stepName, status, output) {
            const content = document.getElementById('progressContent');
            const statusIcon = status === 'running' ? '⏳' : (status === 'success' ? '✅' : (status === 'warning' ? '⚠️' : '❌'));
            const statusColor = status === 'running' ? 'text-amber-400' : (status === 'success' ? 'text-emerald-400' : (status === 'warning' ? 'text-yellow-400' : 'text-red-400'));

            const stepId = 'step-' + stepName.replace(/\s+/g, '-');
            let stepEl = document.getElementById(stepId);

            if (!stepEl) {
                stepEl = document.createElement('div');
                stepEl.id = stepId;
                stepEl.className = 'bg-slate-900/50 rounded p-2';
                content.appendChild(stepEl);
            }

            stepEl.innerHTML = `
                <div class="flex items-center gap-1.5 ${statusColor} font-medium text-[11px]">
                    <span>${statusIcon}</span> ${stepName}
                </div>
                ${output ? `<pre class="text-[10px] text-slate-400 mt-1 whitespace-pre-wrap leading-relaxed">${output}</pre>` : ''}
            `;

            content.scrollTop = content.scrollHeight;
        }

        async function startReactivate() {
            const selected = document.querySelector('input[name="extension_identifier"]:checked');
            if (!selected) {
                alert('재활성화할 항목을 선택해주세요.');
                return;
            }

            const identifier = selected.value;
            const type = selected.dataset.type;
            const typeName = { module: '모듈', template: '템플릿', plugin: '플러그인' }[type] || type;

            if (!confirm(`"${identifier}" ${typeName}을(를) 재활성화하시겠습니까?\n\n(deactivate  → activate → 캐시 초기화)`)) return;

            const panel = document.getElementById('progressPanel');
            const content = document.getElementById('progressContent');
            document.getElementById('progressTitle').textContent = `⚡ ${identifier} ${typeName} 재활성화`;
            content.innerHTML = '';
            panel.classList.remove('hidden');

            let allSuccess = true;
            let failedStep = null;

            const steps = [
                { name: '1. 비활성화 (deactivate )', cmd: `${type}:deactivate  ${identifier}` },
                { name: '2. 활성화 (activate)', cmd: `${type}:activate ${identifier}` },
                { name: '3. 캐시 초기화', cmd: 'cache:clear' },
            ];

            for (const step of steps) {
                addProgressStep(step.name, 'running', '처리 중...');

                const result = await runArtisanCommand(step.cmd, step.name);

                // 비활성화 단계는 실패해도 계속 진행 (이미 비활성화 상태일 수 있음)
                if (step.name === '1. 비활성화 (deactivate )') {
                    addProgressStep(step.name, 'success',
                        result.success ? result.output : '⏭️ 이미 비활성화 상태 (스킵)');
                } else {
                    addProgressStep(step.name, result.success ? 'success' : 'error', result.output);

                    if (!result.success) {
                        allSuccess = false;
                        failedStep = step.name;
                        break;
                    }
                }

                // 캐시 초기화 추가 명령어
                if (step.name === '3. 캐시 초기화') {
                    await runArtisanCommand('config:clear', '');
                    await runArtisanCommand('route:clear', '');
                    await runArtisanCommand('view:clear', '');
                }
            }

            // 최종 결과
            const finalEl = document.createElement('div');
            finalEl.className = allSuccess
                ? 'mt-2 p-2 bg-emerald-900/30 border border-emerald-700/50 rounded text-emerald-300 text-[11px]'
                : 'mt-2 p-2 bg-red-900/30 border border-red-700/50 rounded text-red-300 text-[11px]';
            finalEl.textContent = allSuccess
                ? `🎉 ${identifier} ${typeName} 재활성화 완료!`
                : `❌ "${failedStep}" 단계에서 오류 발생으로 중단됨`;
            content.appendChild(finalEl);
        }

        async function startReinstall() {
            const selected = document.querySelector('input[name="extension_identifier"]:checked');
            if (!selected) {
                alert('재설치할 항목을 선택해주세요.');
                return;
            }

            const identifier = selected.value;
            const type = selected.dataset.type;
            const typeName = { module: '모듈', template: '템플릿', plugin: '플러그인' }[type] || type;

            if (!confirm(`"${identifier}" ${typeName}을(를) 재설치하시겠습니까?`)) return;

            const panel = document.getElementById('progressPanel');
            const content = document.getElementById('progressContent');
            document.getElementById('progressTitle').textContent = `📦 ${identifier} ${typeName} 재설치`;
            content.innerHTML = '';
            panel.classList.remove('hidden');

            let allSuccess = true;
            let failedStep = null;

            // 1. 기존 제거 (Manager 직접 호출)
            const uninstallStep = '1. 기존 제거 (uninstall)';
            addProgressStep(uninstallStep, 'running', '처리 중...');
            const uninstallResult = await runUninstall(type, identifier, uninstallStep);

            // uninstall 결과 상세 표시
            if (uninstallResult.success) {
                addProgressStep(uninstallStep, 'success', '제거 완료');
            } else {
                // 출력 메시지에서 "not installed" 등의 문구가 있으면 스킵으로 처리
                const isNotInstalled = uninstallResult.output &&
                    (uninstallResult.output.includes('not installed') ||
                     uninstallResult.output.includes('설치되지 않음'));

                if (isNotInstalled) {
                    addProgressStep(uninstallStep, 'success', '⏭️ 이미 제거 상태 (스킵)');
                } else {
                    // 실제 에러는 경고로 표시하되 계속 진행
                    addProgressStep(uninstallStep, 'warning', `⚠️ 제거 중 오류 (계속 진행): ${uninstallResult.output || '알 수 없는 오류'}`);
                }
            }
            // uninstall은 실패해도 계속 진행 (미설치 상태일 수 있음)

            // 2~4. 나머지 단계
            const steps = [
                { name: '2. 새로 설치 (install)', cmd: `${type}:install ${identifier}` },
                { name: '3. 활성화 (activate)', cmd: `${type}:activate ${identifier}` },
                { name: '4. 캐시 초기화', cmd: 'cache:clear' },
            ];

            for (const step of steps) {
                addProgressStep(step.name, 'running', '처리 중...');

                const result = await runArtisanCommand(step.cmd, step.name);
                addProgressStep(step.name, result.success ? 'success' : 'error', result.output);

                if (!result.success) {
                    allSuccess = false;
                    failedStep = step.name;
                    break;
                }

                // 캐시 초기화 추가 명령어
                if (step.name === '4. 캐시 초기화') {
                    await runArtisanCommand('config:clear', '');
                    await runArtisanCommand('route:clear', '');
                    await runArtisanCommand('view:clear', '');
                }
            }

            // 최종 결과 (모든 단계 완료 후 표시)
            setTimeout(() => {
                const finalEl = document.createElement('div');
                finalEl.className = allSuccess
                    ? 'mt-2 p-2 bg-emerald-900/30 border border-emerald-700/50 rounded text-emerald-300 text-[11px]'
                    : 'mt-2 p-2 bg-red-900/30 border border-red-700/50 rounded text-red-300 text-[11px]';
                finalEl.textContent = allSuccess
                    ? `🎉 ${identifier} ${typeName} 재설치 완료!`
                    : `❌ "${failedStep}" 단계에서 오류 발생으로 중단됨`;
                content.appendChild(finalEl);
            }, 100);
        }


        // ==========================================
        // TagInput 컴포넌트 미리보기 (데모용)
        // ==========================================

        // 샘플 데이터
        const tagInputData = {
            categories: {
                options: [
                    { value: 'A/S', label: 'A/S', count: 5 },
                    { value: '반품', label: '반품', count: 0 },
                    { value: '교환', label: '교환', count: 3 },
                    { value: '배송', label: '배송', count: 12 },
                    { value: '결제', label: '결제', count: 0 },
                    { value: '테스트', label: '테스트', count: 0 },
                ],
                selected: ['A/S', '반품'],
                color: 'emerald'
            },
            roles: {
                options: [
                    { value: '1', label: '회원' },
                    { value: '2', label: '콘텐츠 관리자' },
                    { value: '3', label: '마케팅 담당자' },
                    { value: '4', label: '운영자' },
                    { value: '5', label: '편집자' },
                    { value: '6', label: '최고관리자' },
                ],
                selected: ['1', '2'],
                color: 'purple'
            }
        };

        // 드롭다운 렌더링
        function renderDropdown(container, searchText = '') {
            const name = container.dataset.name;
            const creatable = container.dataset.creatable === 'true';
            const data = tagInputData[name];
            const dropdown = container.querySelector('.tag-dropdown');
            const color = data.color;

            let filtered = data.options.filter(opt =>
                opt.label.toLowerCase().includes(searchText.toLowerCase())
            );

            let html = '';

            if (creatable) {
                // 분류용: 클릭으로 선택 + 새로 추가 옵션
                filtered.forEach(opt => {
                    const isSelected = data.selected.includes(opt.value);
                    const countText = opt.count !== undefined ? ` <span class="text-slate-500">(${opt.count})</span>` : '';
                    html += `
                        <div class="tag-option flex items-center gap-2 px-3 py-2 hover:bg-slate-700/50 cursor-pointer ${isSelected ? 'bg-' + color + '-900/30' : ''}"
                             data-value="${opt.value}" data-label="${opt.label}" data-count="${opt.count || 0}">
                            <span class="w-4 h-4 rounded border ${isSelected ? 'bg-' + color + '-500 border-' + color + '-500' : 'border-slate-500'} flex items-center justify-center text-white text-[10px]">
                                ${isSelected ? '✓' : ''}
                            </span>
                            <span class="text-slate-200 text-xs flex-1">${opt.label}${countText}</span>
                        </div>
                    `;
                });

                // 새로 추가 옵션 (검색어가 있고, 기존 옵션에 없을 때)
                if (searchText && !data.options.some(opt => opt.label.toLowerCase() === searchText.toLowerCase())) {
                    html += `
                        <div class="tag-option-create flex items-center gap-2 px-3 py-2 hover:bg-emerald-900/30 cursor-pointer border-t border-slate-700"
                             data-value="${searchText}" data-label="${searchText}">
                            <span class="text-emerald-400 text-xs">+</span>
                            <span class="text-emerald-300 text-xs">"${searchText}" 추가</span>
                        </div>
                    `;
                }
            } else {
                // 역할용: 체크박스 다중 선택
                filtered.forEach(opt => {
                    const isSelected = data.selected.includes(opt.value);
                    html += `
                        <div class="tag-option flex items-center gap-2 px-3 py-2 hover:bg-slate-700/50 cursor-pointer"
                             data-value="${opt.value}" data-label="${opt.label}">
                            <input type="checkbox" ${isSelected ? 'checked' : ''} class="w-4 h-4 rounded border-slate-500 bg-slate-700 text-purple-500 focus:ring-purple-500">
                            <span class="text-slate-200 text-xs">${opt.label}</span>
                        </div>
                    `;
                });
            }

            if (!html) {
                html = '<div class="px-3 py-2 text-slate-500 text-xs">검색 결과가 없습니다</div>';
            }

            dropdown.innerHTML = html;
            dropdown.classList.remove('hidden');

            // 이벤트 바인딩
            dropdown.querySelectorAll('.tag-option, .tag-option-create').forEach(el => {
                el.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const value = el.dataset.value;
                    const label = el.dataset.label;
                    const count = el.dataset.count || 0;
                    const isCreate = el.classList.contains('tag-option-create');

                    if (isCreate) {
                        // 새 옵션 추가
                        data.options.push({ value, label, count: 0 });
                    }

                    // 토글
                    const idx = data.selected.indexOf(value);
                    if (idx > -1) {
                        data.selected.splice(idx, 1);
                    } else {
                        data.selected.push(value);
                    }

                    // 새 항목 추가 시 입력창 초기화
                    if (isCreate) {
                        container.querySelector('.tag-input').value = '';
                    }

                    renderTags(container);
                    renderDropdown(container, container.querySelector('.tag-input').value);
                });
            });
        }

        // 태그 칩 렌더링
        function renderTags(container) {
            const name = container.dataset.name;
            const data = tagInputData[name];
            const color = data.color;
            const wrapper = container.querySelector('.flex.flex-wrap');
            const input = wrapper.querySelector('.tag-input');

            // 기존 태그 제거
            wrapper.querySelectorAll('.tag-chip').forEach(el => el.remove());

            // 선택된 태그 추가
            data.selected.forEach(value => {
                const opt = data.options.find(o => o.value === value);
                if (!opt) return;

                const chip = document.createElement('span');
                chip.className = `tag-chip bg-${color}-600/30 text-${color}-300 px-2 py-1 rounded text-xs flex items-center gap-1`;

                let countHtml = '';
                if (opt.count !== undefined && opt.count > 0) {
                    countHtml = `<span class="text-${color}-400/60 text-[10px]">(${opt.count})</span>`;
                }

                chip.innerHTML = `
                    ${opt.label} ${countHtml}
                    <button type="button" class="tag-remove hover:text-red-400 ml-0.5" data-value="${value}" data-count="${opt.count || 0}">×</button>
                `;

                wrapper.insertBefore(chip, input);
            });

            // 태그 제거 버튼 이벤트
            wrapper.querySelectorAll('.tag-remove').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const value = btn.dataset.value;
                    const count = parseInt(btn.dataset.count || 0);

                    // 분류이고 게시글이 사용 중이면 삭제 불가
                    if (name === 'categories' && count > 0) {
                        alert(`"${value}" 분류는 현재 ${count}개의 게시글에서 사용 중입니다.\n\n게시글에서 사용 중인 분류는 제거할 수 없습니다.`);
                        return;
                    }

                    const idx = data.selected.indexOf(value);
                    if (idx > -1) {
                        data.selected.splice(idx, 1);
                        renderTags(container);
                        renderDropdown(container, container.querySelector('.tag-input').value);
                    }
                });
            });
        }

        // 초기화
        document.querySelectorAll('.tag-input-container').forEach(container => {
            const input = container.querySelector('.tag-input');
            const dropdown = container.querySelector('.tag-dropdown');

            // 포커스 시 드롭다운 표시
            input.addEventListener('focus', () => {
                renderDropdown(container, input.value);
            });

            // 입력 시 필터링
            input.addEventListener('input', () => {
                renderDropdown(container, input.value);
            });

            // 외부 클릭 시 드롭다운 숨김
            document.addEventListener('click', (e) => {
                if (!container.contains(e.target)) {
                    dropdown.classList.add('hidden');
                }
            });

            // 초기 태그 렌더링
            renderTags(container);
        });

        // ==========================================
        // 템플릿 선택 감지 및 빌드 버튼 표시/숨김
        // ==========================================
        document.querySelectorAll('input[name="extension_identifier"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                const buildButton = document.getElementById('buildButton');
                const selectedType = e.target.dataset.type;

                if (selectedType === 'template') {
                    buildButton.classList.remove('hidden');
                    buildButton.classList.add('flex');
                } else {
                    buildButton.classList.add('hidden');
                    buildButton.classList.remove('flex');
                }
            });
        });

        // 페이지 로드 시 초기 상태 설정 (첫 번째 템플릿이 선택되어 있으면 빌드 버튼 표시)
        window.addEventListener('DOMContentLoaded', () => {
            const selected = document.querySelector('input[name="extension_identifier"]:checked');
            if (selected && selected.dataset.type === 'template') {
                const buildButton = document.getElementById('buildButton');
                buildButton.classList.remove('hidden');
                buildButton.classList.add('flex');
            }
        });

        // ==========================================
        // 템플릿 빌드 함수
        // ==========================================
        async function startBuild() {
            const selected = document.querySelector('input[name="extension_identifier"]:checked');
            if (!selected || selected.dataset.type !== 'template') {
                alert('템플릿을 선택해주세요.');
                return;
            }

            const identifier = selected.value;

            if (!confirm(`"${identifier}" 템플릿을 빌드하시겠습니까?\n\n(npm run build 실행)`)) return;

            const panel = document.getElementById('progressPanel');
            const content = document.getElementById('progressContent');
            document.getElementById('progressTitle').textContent = `🔨 ${identifier} 템플릿 빌드`;
            content.innerHTML = '';
            panel.classList.remove('hidden');

            addProgressStep('빌드 시작', 'running', `cd templates/${identifier} && npm run build`);

            try {
                const url = `${window.location.pathname}?ajax_action=template_build&identifier=${encodeURIComponent(identifier)}`;

                // 타임아웃 설정 (120초 - 빌드는 시간이 오래 걸릴 수 있음)
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 120000);

                const response = await fetch(url, { signal: controller.signal });
                clearTimeout(timeoutId);

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const result = await response.json();

                addProgressStep('빌드 완료', result.success ? 'success' : 'error', result.output);

                // 최종 결과
                const finalEl = document.createElement('div');
                finalEl.className = result.success
                    ? 'mt-2 p-2 bg-emerald-900/30 border border-emerald-700/50 rounded text-emerald-300 text-[11px]'
                    : 'mt-2 p-2 bg-red-900/30 border border-red-700/50 rounded text-red-300 text-[11px]';
                finalEl.textContent = result.success
                    ? `🎉 ${identifier} 템플릿 빌드 완료!`
                    : `❌ 빌드 실패`;
                content.appendChild(finalEl);
            } catch (error) {
                if (error.name === 'AbortError') {
                    addProgressStep('빌드 실패', 'error', '⏱️ 타임아웃 (120초 초과)');
                } else {
                    addProgressStep('빌드 실패', 'error', '❌ ' + error.message);
                }
            }
        }

        // ==========================================
        // 캐시 초기화 함수
        // ==========================================
        async function startCacheClear() {
            if (!confirm('모든 캐시를 초기화하시겠습니까?\n\n(cache:clear, config:clear, route:clear, view:clear)')) return;

            const panel = document.getElementById('progressPanel');
            const content = document.getElementById('progressContent');
            document.getElementById('progressTitle').textContent = '🧹 캐시 초기화';
            content.innerHTML = '';
            panel.classList.remove('hidden');

            const cacheCommands = [
                { name: 'cache:clear', label: 'Application Cache' },
                { name: 'config:clear', label: 'Configuration Cache' },
                { name: 'route:clear', label: 'Route Cache' },
                { name: 'view:clear', label: 'View Cache' },
            ];

            let allSuccess = true;

            for (const { name, label } of cacheCommands) {
                addProgressStep(label, 'running', '처리 중...');

                const result = await runArtisanCommand(name, label);
                addProgressStep(label, result.success ? 'success' : 'error', result.output);

                if (!result.success) {
                    allSuccess = false;
                }
            }

            // 최종 결과
            const finalEl = document.createElement('div');
            finalEl.className = allSuccess
                ? 'mt-2 p-2 bg-emerald-900/30 border border-emerald-700/50 rounded text-emerald-300 text-[11px]'
                : 'mt-2 p-2 bg-red-900/30 border border-red-700/50 rounded text-red-300 text-[11px]';
            finalEl.textContent = allSuccess
                ? '🎉 모든 캐시가 초기화되었습니다!'
                : '⚠️ 일부 캐시 초기화에 실패했습니다.';
            content.appendChild(finalEl);
        }

        // ==========================================
        // 개별 캐시 클리어 함수
        // ==========================================
        async function clearSingleCache(command, label) {
            if (!confirm(`${label}를 초기화하시겠습니까?\n\n(${command})`)) return;

            const panel = document.getElementById('progressPanel');
            const content = document.getElementById('progressContent');
            document.getElementById('progressTitle').textContent = `🧹 ${label} 초기화`;
            content.innerHTML = '';
            panel.classList.remove('hidden');

            addProgressStep(label, 'running', '처리 중...');

            const result = await runArtisanCommand(command, label);
            addProgressStep(label, result.success ? 'success' : 'error', result.output);

            // 최종 결과
            const finalEl = document.createElement('div');
            finalEl.className = result.success
                ? 'mt-2 p-2 bg-emerald-900/30 border border-emerald-700/50 rounded text-emerald-300 text-[11px]'
                : 'mt-2 p-2 bg-red-900/30 border border-red-700/50 rounded text-red-300 text-[11px]';
            finalEl.textContent = result.success
                ? `🎉 ${label} 초기화 완료!`
                : `⚠️ ${label} 초기화에 실패했습니다.`;
            content.appendChild(finalEl);
        }

        // ==========================================
        // Artisan 커맨드 패널 토글
        // ==========================================
        function toggleCommandPanel() {
            const content = document.getElementById('commandPanelContent');
            const arrow = document.getElementById('commandPanelArrow');

            if (content.classList.contains('hidden')) {
                content.classList.remove('hidden');
                arrow.classList.add('rotate-180');
            } else {
                content.classList.add('hidden');
                arrow.classList.remove('rotate-180');
            }
        }

        // ==========================================
        // 공통 커맨드 실행 함수
        // ==========================================
        async function runCommand(command) {
            if (!confirm(`다음 명령을 실행하시겠습니까?\n\n${command}`)) return;

            const panel = document.getElementById('progressPanel');
            const content = document.getElementById('progressContent');
            document.getElementById('progressTitle').textContent = `⚡ ${command}`;
            content.innerHTML = '';
            panel.classList.remove('hidden');

            addProgressStep(command, 'running', '실행 중...');

            const result = await runArtisanCommand(command, command);
            addProgressStep(command, result.success ? 'success' : 'error', result.output);

            // 최종 결과
            const finalEl = document.createElement('div');
            finalEl.className = result.success
                ? 'mt-2 p-2 bg-emerald-900/30 border border-emerald-700/50 rounded text-emerald-300 text-[11px]'
                : 'mt-2 p-2 bg-red-900/30 border border-red-700/50 rounded text-red-300 text-[11px]';
            finalEl.textContent = result.success
                ? `🎉 ${command} 실행 완료!`
                : `❌ ${command} 실행 실패`;
            content.appendChild(finalEl);
        }

        // ==========================================
        // 모듈 커맨드 실행
        // ==========================================
        async function runModuleCommand(action) {
            const select = document.getElementById('moduleSelect');
            const identifier = select.value;

            if (!identifier) {
                alert('모듈을 선택해주세요.');
                return;
            }

            const command = `module:${action} ${identifier}`;
            await runCommand(command);
        }

        // ==========================================
        // 템플릿 커맨드 실행
        // ==========================================
        async function runTemplateCommand(action) {
            const select = document.getElementById('templateSelect');
            const identifier = select.value;

            if (!identifier) {
                alert('템플릿을 선택해주세요.');
                return;
            }

            const command = `template:${action} ${identifier}`;
            await runCommand(command);
        }

        // ==========================================
        // 플러그인 커맨드 실행
        // ==========================================
        async function runPluginCommand(action) {
            const select = document.getElementById('pluginSelect');
            const identifier = select.value;

            if (!identifier) {
                alert('플러그인을 선택해주세요.');
                return;
            }

            const command = `plugin:${action} ${identifier}`;
            await runCommand(command);
        }

        // ==========================================
        // 확장 커맨드 실행 (identifier + 플래그)
        // ==========================================
        async function runExtensionCommand(type, action, flags = '') {
            const select = document.getElementById(type + 'Select');
            const identifier = select.value;

            if (!identifier) {
                const labels = {module: '모듈', template: '템플릿', plugin: '플러그인'};
                alert((labels[type] || type) + '을(를) 선택해주세요.');
                return;
            }

            const command = `${type}:${action} ${identifier}${flags ? ' ' + flags : ''}`;
            await runCommand(command);
        }

        // ==========================================
        // 언어팩 커맨드 실행 (드롭다운 선택)
        // ==========================================
        async function runLanguagePackCommand(action, flags = '') {
            const select = document.getElementById('languagePackSelect');
            const identifier = select.value;

            if (!identifier) {
                alert('언어팩을 선택해주세요.');
                return;
            }

            const command = `language-pack:${action} ${identifier}${flags ? ' ' + flags : ''}`;
            await runCommand(command);
        }
    </script>
</body>
</html>
