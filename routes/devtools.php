<?php

/**
 * G7 DevTools 라우트
 *
 * 디버깅 데이터 덤프 및 로그 전송 엔드포인트
 * 디버그 모드가 활성화된 경우에만 동작
 *
 * 라우트 캐시 안전성:
 *   `route:cache` 가 걸리면 이 파일은 **로드되지 않는다** — `RouteServiceProvider::boot()` 이
 *   `loadCachedRoutes()` 로 분기하고 클로저만 `SerializableClosure` 로 복원된다. 따라서 이
 *   파일 안에 전역 함수나 파일 스코프 변수를 정의하고 핸들러에서 참조하면 캐시 상태에서
 *   `Call to undefined function` 500 이 된다. 실제 로직은 전부 오토로드되는 클래스
 *   (`App\Support\DevTools\*`) 에 두고, 여기서는 위임만 한다.
 *
 *   반면 클래스 호출은 `use` 별칭이든 FQCN 이든 안전하다. `SerializableClosure` 는 **직렬화
 *   시점**(= `route:cache` 실행 중, 이 파일이 로드되어 있는 시점) 에 소스를 파싱해 import 를
 *   해석하고 완전 수식명으로 치환한 코드를 굽는다. 역직렬화 때는 해석할 import 가 남아 있지
 *   않다. `DevtoolsRouteCacheTest` 가 캐시된 클로저로 이 위임들을 실제 호출해 검증한다.
 */

use App\Support\DevTools\BrowserLogWriter;
use App\Support\DevTools\DebugDumpWriter;
use App\Support\DevTools\DebugGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Browser Logs (boost.browser-logs)
|--------------------------------------------------------------------------
|
| 이 라우트는 원래 `Laravel\Boost\BoostServiceProvider::boot()` 이 등록한다.
| 그런데 `Router::setCompiledRoutes()` 는 `booted` 콜백에서 라우트 컬렉션을 통째로
| 교체하므로, 라우트 캐시가 있으면 프로바이더 boot() 에서 등록한 라우트는 조건 충족
| 여부와 무관하게 전부 폐기된다. 그 결과 `routes/web.php` 의 SPA catch-all(GET 전용)만
| 남아 POST 가 405 로 튕기고, `BrowserLogger` 는 `Route::has('boost.browser-logs')` 가
| false 라 폴백 리터럴 URL 을 굽는다 — fetch 실패는 `.catch` 로 삼켜져 오류 한 줄 없이
| 브라우저 로그 수집만 사라진다.
|
| 그래서 G7 가 이 URI 를 직접 소유해 캐시에 함께 구워지게 한다. "boost 가 등록하는데 왜
| 중복으로 두었나" 라는 오해로 제거하지 말 것. 캐시가 없을 때는 boost 도 같은 URI 를
| 등록하지만 `RouteCollection::addToCollections()` 가 method+URI 키로 덮어쓰므로 물리적
| 라우트는 1개만 남는다.
|
| URI 는 `_boost/browser-logs` 고정이다 — `BrowserLogger` 의 폴백 리터럴 및 이미 배포된
| 인젝션 스크립트와 일치해야 한다. CSRF 면제는 불필요하다: `bootstrap/app.php` 가 이 파일을
| `api` 그룹으로 감싸므로 `VerifyCsrfToken` 이 애초에 걸리지 않는다.
|
*/

Route::post('_boost/browser-logs', function (Request $request): JsonResponse {
    if (! DebugGate::isEnabled()) {
        return response()->json([
            'status' => 'error',
            'message' => __('devtools.debug_disabled'),
        ], 403);
    }

    return BrowserLogWriter::handle($request);
})->name('boost.browser-logs');

/*
|--------------------------------------------------------------------------
| DevTools Routes
|--------------------------------------------------------------------------
|
| G7 DevTools 시스템을 위한 라우트입니다.
| 상태 덤프, 로그 전송 등 디버깅 관련 엔드포인트를 제공합니다.
|
| 이 그룹은 자체 미들웨어를 선언하지 않는다 — `bootstrap/app.php` 가 이 파일 전체를
| `api` 그룹으로 감싸므로 `->middleware('api')` 를 덧붙이면 같은 그룹이 두 번 지정된다
| (`Router::uniqueMiddleware()` 가 중복을 제거해 동작은 같지만, 이 그룹만 별도 미들웨어가
| 필요하다는 오해를 남긴다). 위 `_boost/browser-logs` 라우트도 같은 규율을 따른다.
|
*/

Route::prefix('_boost/g7-debug')->group(function () {
    /**
     * 상태 덤프 엔드포인트
     *
     * 브라우저에서 전송한 디버깅 데이터를 파일로 저장합니다.
     * 섹션별 분할 전송을 지원합니다.
     */
    Route::post('dump-state', function (Request $request): JsonResponse {
        if (! DebugGate::isEnabled()) {
            return response()->json([
                'status' => 'error',
                'message' => __('devtools.debug_disabled'),
            ], 403);
        }

        // 테스트 요청 처리
        if ($request->boolean('test')) {
            return response()->json([
                'status' => 'success',
                'message' => __('devtools.connection_ok'),
            ]);
        }

        $debugDir = storage_path('debug-dump');
        $sessionsDir = $debugDir.'/sessions';

        if (! File::isDirectory($debugDir)) {
            File::makeDirectory($debugDir, 0755, true);
        }

        // 일괄 전송 처리 (소용량 데이터)
        if ($request->boolean('bulk') && $request->has('sections')) {
            return DebugDumpWriter::handleBulk($request, $debugDir);
        }

        // 섹션별 분할 전송 처리 (대용량 데이터)
        if ($request->has('sessionId') && $request->has('sectionName')) {
            return DebugDumpWriter::handleSectional($request, $debugDir, $sessionsDir);
        }

        // 레거시 전체 전송 처리 (하위 호환성)
        return DebugDumpWriter::handleLegacy($request, $debugDir);
    })->name('devtools.dump-state');

    /**
     * 로그 전송 엔드포인트
     *
     * 디버그 로그, 에러, 프로파일 데이터를 저장합니다.
     */
    Route::post('log', function (Request $request): JsonResponse {
        if (! DebugGate::isEnabled()) {
            return response()->json([
                'status' => 'error',
                'message' => __('devtools.debug_disabled'),
            ], 403);
        }

        $debugDir = storage_path('debug-dump');

        if (! File::isDirectory($debugDir)) {
            File::makeDirectory($debugDir, 0755, true);
        }

        $type = $request->input('type', 'debug');
        $data = $request->input('data');
        $timestamp = $request->input('timestamp', now()->getTimestampMs());

        $logEntry = [
            'type' => $type,
            'data' => $data,
            'timestamp' => $timestamp,
            'created_at' => now()->toIso8601String(),
        ];

        // 로그 타입별 파일 저장
        $filename = match ($type) {
            'error' => 'errors-latest.json',
            'profile' => 'profile-latest.json',
            default => 'debug-latest.json',
        };

        // 기존 로그 읽기
        $logFile = $debugDir.'/'.$filename;
        $logs = [];

        if (File::exists($logFile)) {
            $existing = json_decode(File::get($logFile), true);
            if (is_array($existing)) {
                $logs = $existing;
            }
        }

        // 새 로그 추가 (최대 100개 유지)
        $logs[] = $logEntry;
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }

        File::put($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return response()->json([
            'status' => 'success',
            'logged' => true,
        ]);
    })->name('devtools.log');

    /**
     * 상태 조회 엔드포인트 (MCP 도구용)
     */
    Route::get('state', function (): JsonResponse {
        $debugDir = storage_path('debug-dump');
        $stateFile = $debugDir.'/state-latest.json';

        if (! File::exists($stateFile)) {
            return response()->json([
                'status' => 'no_data',
                'message' => __('devtools.no_state'),
            ]);
        }

        $state = json_decode(File::get($stateFile), true);

        return response()->json([
            'status' => 'success',
            'state' => $state,
            'timestamp' => File::lastModified($stateFile),
        ]);
    })->name('devtools.state');

    /**
     * 액션 이력 조회 엔드포인트 (MCP 도구용)
     */
    Route::get('actions', function (Request $request): JsonResponse {
        $debugDir = storage_path('debug-dump');
        $actionsFile = $debugDir.'/actions-latest.json';

        if (! File::exists($actionsFile)) {
            return response()->json([
                'status' => 'no_data',
                'message' => __('devtools.no_actions'),
            ]);
        }

        $actions = json_decode(File::get($actionsFile), true);
        $limit = (int) $request->input('limit', 50);
        $filter = $request->input('filter');

        // 필터 적용
        if ($filter && is_array($actions)) {
            $actions = array_filter($actions, function ($action) use ($filter) {
                return ($action['type'] ?? '') === $filter ||
                       ($action['status'] ?? '') === $filter;
            });
        }

        // 제한 적용
        if (is_array($actions)) {
            $actions = array_slice($actions, -$limit);
        }

        return response()->json([
            'status' => 'success',
            'actions' => $actions,
            'total' => count($actions ?? []),
        ]);
    })->name('devtools.actions');

    /**
     * 캐시 통계 조회 엔드포인트 (MCP 도구용)
     */
    Route::get('cache', function (): JsonResponse {
        $debugDir = storage_path('debug-dump');
        $cacheFile = $debugDir.'/cache-latest.json';

        if (! File::exists($cacheFile)) {
            return response()->json([
                'status' => 'no_data',
                'message' => __('devtools.no_cache'),
            ]);
        }

        $cache = json_decode(File::get($cacheFile), true);

        return response()->json([
            'status' => 'success',
            'cache' => $cache,
        ]);
    })->name('devtools.cache');

    /**
     * 변경 감지 정보 조회 엔드포인트 (MCP 도구용)
     */
    Route::get('change-detection', function (Request $request): JsonResponse {
        $debugDir = storage_path('debug-dump');
        $changeDetectionFile = $debugDir.'/change-detection-latest.json';

        if (! File::exists($changeDetectionFile)) {
            return response()->json([
                'status' => 'no_data',
                'message' => __('devtools.no_change_detection'),
            ]);
        }

        $changeDetection = json_decode(File::get($changeDetectionFile), true);
        $limit = (int) $request->input('limit', 20);
        $warningsOnly = $request->boolean('warningsOnly');
        $handlerName = $request->input('handlerName');

        // 경고만 필터
        if ($warningsOnly && isset($changeDetection['alerts'])) {
            $changeDetection['alerts'] = array_filter($changeDetection['alerts'], function ($alert) {
                return in_array($alert['severity'] ?? '', ['warning', 'error']);
            });
        }

        // 핸들러 이름 필터
        if ($handlerName && isset($changeDetection['executionDetails'])) {
            $changeDetection['executionDetails'] = array_filter($changeDetection['executionDetails'], function ($detail) use ($handlerName) {
                return stripos($detail['handlerName'] ?? '', $handlerName) !== false;
            });
        }

        // 제한 적용
        if (isset($changeDetection['executionDetails']) && is_array($changeDetection['executionDetails'])) {
            $changeDetection['executionDetails'] = array_slice($changeDetection['executionDetails'], -$limit);
        }

        if (isset($changeDetection['alerts']) && is_array($changeDetection['alerts'])) {
            $changeDetection['alerts'] = array_slice($changeDetection['alerts'], -$limit);
        }

        return response()->json([
            'status' => 'success',
            'changeDetection' => $changeDetection,
            'timestamp' => File::lastModified($changeDetectionFile),
        ]);
    })->name('devtools.change-detection');

    /**
     * 전체 디버그 데이터 삭제
     */
    Route::delete('clear', function (): JsonResponse {
        $debugDir = storage_path('debug-dump');

        if (File::isDirectory($debugDir)) {
            File::cleanDirectory($debugDir);
        }

        return response()->json([
            'status' => 'success',
            'message' => __('devtools.cleared'),
        ]);
    })->name('devtools.clear');
});
