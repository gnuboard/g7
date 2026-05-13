<?php

/**
 * G7 DevTools 라우트
 *
 * 디버깅 데이터 덤프 및 로그 전송 엔드포인트
 * 디버그 모드가 활성화된 경우에만 동작
 */

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DevTools Helper Functions
|--------------------------------------------------------------------------
|
| 섹션별 분할 전송 처리를 위한 헬퍼 함수들
|
*/

if (! function_exists('handleSectionalDump')) {
    /**
     * 섹션별 청크 분할 전송 처리
     */
    function handleSectionalDump(Request $request, string $debugDir, string $sessionsDir): JsonResponse
    {
        $sessionId = $request->input('sessionId');
        $sectionName = $request->input('sectionName');
        $chunkData = $request->input('chunkData');
        $chunkIndex = (int) $request->input('chunkIndex', 0);
        $totalChunks = (int) $request->input('totalChunks', 1);
        $isLastChunk = $request->boolean('isLastChunk');
        $isLastSection = $request->boolean('isLastSection');
        $timestamp = $request->input('timestamp');
        $saveHistory = $request->boolean('saveHistory');
        $totalSections = $request->input('totalSections', 1);

        // 세션 디렉토리 생성
        $sessionDir = $sessionsDir.'/'.$sessionId;
        if (! File::isDirectory($sessionDir)) {
            File::makeDirectory($sessionDir, 0755, true);
        }

        // 청크를 임시 파일에 저장
        $chunkFile = $sessionDir.'/'.$sectionName.'.chunk.'.$chunkIndex;
        File::put($chunkFile, $chunkData);

        // 해당 섹션의 마지막 청크이면 모든 청크를 병합
        if ($isLastChunk) {
            $mergedData = '';
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $sessionDir.'/'.$sectionName.'.chunk.'.$i;
                if (File::exists($chunkPath)) {
                    $mergedData .= File::get($chunkPath);
                    File::delete($chunkPath);
                }
            }

            // 병합된 JSON을 섹션 파일로 저장
            $sectionFile = $sessionDir.'/'.$sectionName.'.json';
            File::put($sectionFile, $mergedData);
        }

        // 전체 덤프의 마지막 섹션/청크이면 최종 파일 생성
        if ($isLastSection) {
            mergeSectionsToFinalFiles($sessionDir, $debugDir, $saveHistory, $timestamp);

            // 세션 디렉토리 정리
            File::deleteDirectory($sessionDir);

            // 오래된 세션 정리 (10분 이상 된 세션)
            cleanupOldSessions($sessionsDir);
        }

        return response()->json([
            'status' => 'success',
            'sessionId' => $sessionId,
            'sectionName' => $sectionName,
            'chunkIndex' => $chunkIndex,
            'totalChunks' => $totalChunks,
            'isLastSection' => $isLastSection,
        ]);
    }
}

if (! function_exists('mergeSectionsToFinalFiles')) {
    /**
     * 섹션 파일들을 최종 파일로 병합
     */
    function mergeSectionsToFinalFiles(string $sessionDir, string $debugDir, bool $saveHistory, $timestamp): void
    {
        // 섹션명 -> 파일명 매핑
        $sectionToFile = [
            'state' => 'state-latest.json',
            'actions' => 'actions-latest.json',
            'cache' => 'cache-latest.json',
            'lifecycle' => 'lifecycle-latest.json',
            'network' => 'network-latest.json',
            'expressions' => 'expressions-latest.json',
            'forms' => 'form-latest.json',
            'performance' => 'performance-latest.json',
            'conditionals' => 'conditionals-latest.json',
            'dataSources' => 'datasources-latest.json',
            'handlers' => 'handlers-latest.json',
            'componentEvents' => 'component-events-latest.json',
            'stateRendering' => 'state-rendering-latest.json',
            'stateHierarchy' => 'state-hierarchy-latest.json',
            'contextFlow' => 'context-flow-latest.json',
            'styleValidation' => 'style-validation-latest.json',
            'authDebug' => 'auth-debug-latest.json',
            'logs' => 'logs-latest.json',
            'layout' => 'layout-latest.json',
            'changeDetection' => 'change-detection-latest.json',
            'sequenceTracking' => 'sequence-latest.json',
            'staleClosureTracking' => 'stale-closure-latest.json',
            'cacheDecisionTracking' => 'cache-decisions-latest.json',
            'dataPathTransformTracking' => 'data-path-transform-latest.json',
            'nestedContextTracking' => 'nested-context-latest.json',
            'formBindingValidationTracking' => 'form-binding-validation-latest.json',
            'computedDependencyTracking' => 'computed-dependency-latest.json',
            'modalStateScopeTracking' => 'modal-state-scope-latest.json',
            'namedActionTracking' => 'named-action-tracking-latest.json',
        ];

        $timestampStr = $timestamp ? date('Ymd_His', $timestamp / 1000) : now()->format('Ymd_His');

        // 각 섹션 파일을 최종 위치로 이동
        foreach (File::files($sessionDir) as $file) {
            $sectionName = pathinfo($file, PATHINFO_FILENAME);

            if (isset($sectionToFile[$sectionName])) {
                $targetFile = $debugDir.'/'.$sectionToFile[$sectionName];
                File::copy($file, $targetFile);

                // 이력 파일 저장 (state 섹션만)
                if ($saveHistory && $sectionName === 'state') {
                    File::copy($file, $debugDir."/state-{$timestampStr}.json");
                }
            }
        }
    }
}

if (! function_exists('cleanupOldSessions')) {
    /**
     * 오래된 세션 디렉토리 정리 (10분 이상)
     */
    function cleanupOldSessions(string $sessionsDir): void
    {
        if (! File::isDirectory($sessionsDir)) {
            return;
        }

        $cutoffTime = time() - (10 * 60); // 10분 전

        foreach (File::directories($sessionsDir) as $sessionDir) {
            if (File::lastModified($sessionDir) < $cutoffTime) {
                File::deleteDirectory($sessionDir);
            }
        }
    }
}

if (! function_exists('handleBulkDump')) {
    /**
     * 일괄 전송 처리 (소용량 데이터용)
     *
     * 모든 섹션이 단일 요청에 포함됨
     */
    function handleBulkDump(Request $request, string $debugDir): JsonResponse
    {
        $sections = $request->input('sections', []);
        $timestamp = $request->input('timestamp');
        $saveHistory = $request->boolean('saveHistory');

        $timestampStr = $timestamp ? date('Ymd_His', $timestamp / 1000) : now()->format('Ymd_His');

        // 섹션명 -> 파일명 매핑
        $sectionToFile = [
            'state' => 'state-latest.json',
            'actions' => 'actions-latest.json',
            'cache' => 'cache-latest.json',
            'lifecycle' => 'lifecycle-latest.json',
            'network' => 'network-latest.json',
            'expressions' => 'expressions-latest.json',
            'forms' => 'form-latest.json',
            'performance' => 'performance-latest.json',
            'conditionals' => 'conditionals-latest.json',
            'dataSources' => 'datasources-latest.json',
            'handlers' => 'handlers-latest.json',
            'componentEvents' => 'component-events-latest.json',
            'stateRendering' => 'state-rendering-latest.json',
            'stateHierarchy' => 'state-hierarchy-latest.json',
            'contextFlow' => 'context-flow-latest.json',
            'styleValidation' => 'style-validation-latest.json',
            'authDebug' => 'auth-debug-latest.json',
            'logs' => 'logs-latest.json',
            'layout' => 'layout-latest.json',
            'changeDetection' => 'change-detection-latest.json',
            'sequenceTracking' => 'sequence-latest.json',
            'staleClosureTracking' => 'stale-closure-latest.json',
            'cacheDecisionTracking' => 'cache-decisions-latest.json',
            'dataPathTransformTracking' => 'data-path-transform-latest.json',
            'nestedContextTracking' => 'nested-context-latest.json',
            'formBindingValidationTracking' => 'form-binding-validation-latest.json',
            'computedDependencyTracking' => 'computed-dependency-latest.json',
            'modalStateScopeTracking' => 'modal-state-scope-latest.json',
            'namedActionTracking' => 'named-action-tracking-latest.json',
        ];

        $savedSections = [];

        foreach ($sections as $sectionName => $sectionData) {
            if (isset($sectionToFile[$sectionName])) {
                $json = json_encode($sectionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $targetFile = $debugDir.'/'.$sectionToFile[$sectionName];
                File::put($targetFile, $json);
                $savedSections[] = $sectionName;

                // 이력 파일 저장 (state 섹션만)
                if ($saveHistory && $sectionName === 'state') {
                    File::put($debugDir."/state-{$timestampStr}.json", $json);
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'mode' => 'bulk',
            'savedSections' => $savedSections,
            'timestamp' => $timestampStr,
        ]);
    }
}

if (! function_exists('handleLegacyDump')) {
    /**
     * 레거시 전체 전송 처리 (하위 호환성)
     */
    function handleLegacyDump(Request $request, string $debugDir): JsonResponse
    {
        $timestamp = now()->format('Ymd_His');

        // 상태 데이터 저장
        if ($request->has('state')) {
            $stateData = $request->input('state');
            $stateJson = json_encode($stateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            File::put($debugDir.'/state-latest.json', $stateJson);

            if ($request->boolean('saveHistory')) {
                File::put($debugDir."/state-{$timestamp}.json", $stateJson);
            }
        }

        // 액션 이력 저장
        if ($request->has('actions')) {
            $actionsData = $request->input('actions');
            $actionsJson = json_encode($actionsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/actions-latest.json', $actionsJson);
        }

        // 캐시 통계 저장
        if ($request->has('cache')) {
            $cacheData = $request->input('cache');
            $cacheJson = json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/cache-latest.json', $cacheJson);
        }

        // 라이프사이클 정보 저장
        if ($request->has('lifecycle')) {
            $lifecycleData = $request->input('lifecycle');
            $lifecycleJson = json_encode($lifecycleData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/lifecycle-latest.json', $lifecycleJson);
        }

        // 네트워크 정보 저장
        if ($request->has('network')) {
            $networkData = $request->input('network');
            $networkJson = json_encode($networkData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/network-latest.json', $networkJson);
        }

        // 표현식 정보 저장
        if ($request->has('expressions')) {
            $expressionsData = $request->input('expressions');
            $expressionsJson = json_encode($expressionsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/expressions-latest.json', $expressionsJson);
        }

        // 폼 정보 저장
        if ($request->has('forms')) {
            $formsData = $request->input('forms');
            $formsJson = json_encode($formsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/form-latest.json', $formsJson);
        }

        // 성능 정보 저장
        if ($request->has('performance')) {
            $performanceData = $request->input('performance');
            $performanceJson = json_encode($performanceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/performance-latest.json', $performanceJson);
        }

        // 조건부 렌더링 정보 저장
        if ($request->has('conditionals')) {
            $conditionalsData = $request->input('conditionals');
            $conditionalsJson = json_encode($conditionalsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/conditionals-latest.json', $conditionalsJson);
        }

        // 데이터소스 정보 저장
        if ($request->has('dataSources')) {
            $dataSourcesData = $request->input('dataSources');
            $dataSourcesJson = json_encode($dataSourcesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/datasources-latest.json', $dataSourcesJson);
        }

        // 핸들러 정보 저장
        if ($request->has('handlers')) {
            $handlersData = $request->input('handlers');
            $handlersJson = json_encode($handlersData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/handlers-latest.json', $handlersJson);
        }

        // 컴포넌트 이벤트 정보 저장
        if ($request->has('componentEvents')) {
            $componentEventsData = $request->input('componentEvents');
            $componentEventsJson = json_encode($componentEventsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/component-events-latest.json', $componentEventsJson);
        }

        // 상태-렌더링 정보 저장
        if ($request->has('stateRendering')) {
            $stateRenderingData = $request->input('stateRendering');
            $stateRenderingJson = json_encode($stateRenderingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/state-rendering-latest.json', $stateRenderingJson);
        }

        // 상태 계층 정보 저장
        if ($request->has('stateHierarchy')) {
            $stateHierarchyData = $request->input('stateHierarchy');
            $stateHierarchyJson = json_encode($stateHierarchyData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/state-hierarchy-latest.json', $stateHierarchyJson);
        }

        // 컨텍스트 플로우 정보 저장
        if ($request->has('contextFlow')) {
            $contextFlowData = $request->input('contextFlow');
            $contextFlowJson = json_encode($contextFlowData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/context-flow-latest.json', $contextFlowJson);
        }

        // 스타일 검증 정보 저장
        if ($request->has('styleValidation')) {
            $styleValidationData = $request->input('styleValidation');
            $styleValidationJson = json_encode($styleValidationData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/style-validation-latest.json', $styleValidationJson);
        }

        // 인증 디버깅 정보 저장
        if ($request->has('authDebug')) {
            $authDebugData = $request->input('authDebug');
            $authDebugJson = json_encode($authDebugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/auth-debug-latest.json', $authDebugJson);
        }

        // 로그 정보 저장
        if ($request->has('logs')) {
            $logsData = $request->input('logs');
            $logsJson = json_encode($logsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/logs-latest.json', $logsJson);
        }

        // 레이아웃 정보 저장
        if ($request->has('layout')) {
            $layoutData = $request->input('layout');
            $layoutJson = json_encode($layoutData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/layout-latest.json', $layoutJson);
        }

        // 변경 감지 정보 저장
        if ($request->has('changeDetection')) {
            $changeDetectionData = $request->input('changeDetection');
            $changeDetectionJson = json_encode($changeDetectionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/change-detection-latest.json', $changeDetectionJson);
        }

        // Sequence 추적 정보 저장
        if ($request->has('sequenceTracking')) {
            $sequenceTrackingData = $request->input('sequenceTracking');
            $sequenceTrackingJson = json_encode($sequenceTrackingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/sequence-latest.json', $sequenceTrackingJson);
        }

        // Stale Closure 추적 정보 저장
        if ($request->has('staleClosureTracking')) {
            $staleClosureTrackingData = $request->input('staleClosureTracking');
            $staleClosureTrackingJson = json_encode($staleClosureTrackingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/stale-closure-latest.json', $staleClosureTrackingJson);
        }

        // 캐시 결정 추적 정보 저장
        if ($request->has('cacheDecisionTracking')) {
            $cacheDecisionTrackingData = $request->input('cacheDecisionTracking');
            $cacheDecisionTrackingJson = json_encode($cacheDecisionTrackingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/cache-decisions-latest.json', $cacheDecisionTrackingJson);
        }

        // 데이터 경로 변환 추적 정보 저장
        if ($request->has('dataPathTransformTracking')) {
            $dataPathTransformTrackingData = $request->input('dataPathTransformTracking');
            $dataPathTransformTrackingJson = json_encode($dataPathTransformTrackingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/data-path-transform-latest.json', $dataPathTransformTrackingJson);
        }

        // Nested Context 추적 정보 저장
        if ($request->has('nestedContextTracking')) {
            $nestedContextTrackingData = $request->input('nestedContextTracking');
            $nestedContextTrackingJson = json_encode($nestedContextTrackingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/nested-context-latest.json', $nestedContextTrackingJson);
        }

        // Form 바인딩 검증 추적 정보 저장
        if ($request->has('formBindingValidationTracking')) {
            $formBindingValidationTrackingData = $request->input('formBindingValidationTracking');
            $formBindingValidationTrackingJson = json_encode($formBindingValidationTrackingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/form-binding-validation-latest.json', $formBindingValidationTrackingJson);
        }

        // Computed 의존성 추적 정보 저장
        if ($request->has('computedDependencyTracking')) {
            $computedDependencyTrackingData = $request->input('computedDependencyTracking');
            $computedDependencyTrackingJson = json_encode($computedDependencyTrackingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/computed-dependency-latest.json', $computedDependencyTrackingJson);
        }

        // 모달 상태 스코프 추적 정보 저장
        if ($request->has('modalStateScopeTracking')) {
            $modalStateScopeTrackingData = $request->input('modalStateScopeTracking');
            $modalStateScopeTrackingJson = json_encode($modalStateScopeTrackingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/modal-state-scope-latest.json', $modalStateScopeTrackingJson);
        }

        // Named Action 추적 정보 저장
        if ($request->has('namedActionTracking')) {
            $namedActionTrackingData = $request->input('namedActionTracking');
            $namedActionTrackingJson = json_encode($namedActionTrackingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($debugDir.'/named-action-tracking-latest.json', $namedActionTrackingJson);
        }

        return response()->json([
            'status' => 'success',
            'path' => $debugDir.'/state-latest.json',
            'timestamp' => $timestamp,
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| DevTools Routes
|--------------------------------------------------------------------------
|
| G7 DevTools 시스템을 위한 라우트입니다.
| 상태 덤프, 로그 전송 등 디버깅 관련 엔드포인트를 제공합니다.
|
*/

Route::prefix('_boost/g7-debug')->middleware('api')->group(function () {
    /**
     * 상태 덤프 엔드포인트
     *
     * 브라우저에서 전송한 디버깅 데이터를 파일로 저장합니다.
     * 섹션별 분할 전송을 지원합니다.
     */
    Route::post('dump-state', function (Request $request): JsonResponse {
        // 디버그 모드 확인 (환경설정 또는 .env)
        $debugMode = config('app.debug') || \App\Models\Setting::getValue('debug.mode', false);

        if (! $debugMode) {
            return response()->json([
                'status' => 'error',
                'message' => '디버그 모드가 비활성화되어 있습니다.',
            ], 403);
        }

        // 테스트 요청 처리
        if ($request->boolean('test')) {
            return response()->json([
                'status' => 'success',
                'message' => '연결 성공',
            ]);
        }

        $debugDir = storage_path('debug-dump');
        $sessionsDir = $debugDir.'/sessions';

        // 디렉토리 생성
        if (! File::isDirectory($debugDir)) {
            File::makeDirectory($debugDir, 0755, true);
        }

        // 일괄 전송 처리 (소용량 데이터)
        if ($request->boolean('bulk') && $request->has('sections')) {
            return handleBulkDump($request, $debugDir);
        }

        // 섹션별 분할 전송 처리 (대용량 데이터)
        if ($request->has('sessionId') && $request->has('sectionName')) {
            return handleSectionalDump($request, $debugDir, $sessionsDir);
        }

        // 레거시 전체 전송 처리 (하위 호환성)
        return handleLegacyDump($request, $debugDir);
    })->name('devtools.dump-state');

    /**
     * 로그 전송 엔드포인트
     *
     * 디버그 로그, 에러, 프로파일 데이터를 저장합니다.
     */
    Route::post('log', function (Request $request): JsonResponse {
        // 디버그 모드 확인
        $debugMode = config('app.debug') || \App\Models\Setting::getValue('debug.mode', false);

        if (! $debugMode) {
            return response()->json([
                'status' => 'error',
                'message' => '디버그 모드가 비활성화되어 있습니다.',
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
                'message' => '상태 덤프 파일이 없습니다. 브라우저에서 G7DevTools.server.dumpState()를 실행하세요.',
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
                'message' => '액션 이력 파일이 없습니다.',
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
                'message' => '캐시 통계 파일이 없습니다.',
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
                'message' => '변경 감지 데이터가 없습니다. 브라우저에서 G7DevTools.server.dumpState()를 실행하세요.',
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
            'message' => '디버그 데이터가 삭제되었습니다.',
        ]);
    })->name('devtools.clear');
});
