<?php

namespace App\Support\DevTools;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * G7 DevTools 상태 덤프 기록기.
 *
 * 브라우저가 전송한 디버깅 데이터를 `storage/debug-dump/` 아래 섹션별 파일로 저장한다.
 * 전송 모드는 세 가지이며 저장 결과(파일명·이력 규칙)는 모두 동일하다.
 *
 *   - bulk      소용량: 모든 섹션이 단일 요청의 `sections` 배열에 담겨 온다
 *   - sectional 대용량: 섹션 → 청크 단위로 쪼개 여러 요청에 나눠 온다
 *   - legacy    하위 호환: 섹션명이 요청의 최상위 키로 온다
 *
 * 왜 라우트 파일이 아니라 클래스인가:
 *   원래 이 로직은 `routes/devtools.php` 안의 전역 함수 5개였다. `route:cache` 가 걸리면
 *   `RouteServiceProvider::boot()` 이 `loadCachedRoutes()` 로 분기해 **라우트 파일 자체가
 *   로드되지 않는다**. 클로저는 `SerializableClosure` 로 복원되지만 전역 함수는 오토로드
 *   대상이 아니라 정의되지 않아, 핸들러가 호출하는 즉시 `Call to undefined function` 500 이
 *   된다. 클래스로 옮겨야 오토로더가 캐시 여부와 무관하게 심볼을 찾아준다.
 *
 * 왜 `app/Http/Controllers/` 가 아닌가:
 *   코어 컨트롤러는 API 레퍼런스 문서(`docs/backend/api/**`) 동반이 강제된다. 이 엔드포인트는
 *   디버그 모드 전용 개발 도구라 공개 API 표면이 아니므로, 문서 의무가 붙는 위치를 피해
 *   `app/Support/` 하위 도메인 디렉토리에 둔다 (`ApiDoc/`, `Query/`, `Routing/` 과 동일 관례).
 *
 * 응답 스키마는 MCP 도구와 브라우저 클라이언트가 의존하므로 변경하지 않는다.
 */
class DebugDumpWriter
{
    /**
     * 섹션명 → 저장 파일명 매핑 (SSoT).
     *
     * 세 전송 모드가 모두 이 표 하나만 본다. 과거에는 같은 29행이 세 곳에 복제되어 있어
     * 섹션 하나를 추가할 때 세 곳을 모두 고쳐야 했다.
     *
     * @var array<string, string>
     */
    public const SECTION_FILES = [
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

    /** 이력 파일(`state-{timestamp}.json`)을 남기는 섹션 */
    private const HISTORY_SECTION = 'state';

    /** 오래된 세션 디렉토리 정리 기준 (초) */
    private const SESSION_TTL_SECONDS = 600;

    /** JSON 저장 공통 플래그 */
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;

    /**
     * 섹션별 청크 분할 전송을 처리합니다 (대용량 데이터).
     *
     * @param  Request  $request  덤프 요청
     * @param  string  $debugDir  최종 저장 디렉토리
     * @param  string  $sessionsDir  청크 임시 디렉토리
     * @return JsonResponse 처리 결과
     */
    public static function handleSectional(Request $request, string $debugDir, string $sessionsDir): JsonResponse
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

        // 세션 디렉토리 생성
        $sessionDir = $sessionsDir.'/'.$sessionId;
        if (! File::isDirectory($sessionDir)) {
            File::makeDirectory($sessionDir, 0755, true);
        }

        // 청크를 임시 파일에 저장
        File::put($sessionDir.'/'.$sectionName.'.chunk.'.$chunkIndex, $chunkData);

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

            File::put($sessionDir.'/'.$sectionName.'.json', $mergedData);
        }

        // 전체 덤프의 마지막 섹션이면 최종 파일 생성 후 세션 정리
        if ($isLastSection) {
            self::mergeSectionsToFinalFiles($sessionDir, $debugDir, $saveHistory, $timestamp);

            File::deleteDirectory($sessionDir);
            self::cleanupOldSessions($sessionsDir);
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

    /**
     * 일괄 전송을 처리합니다 (소용량 데이터).
     *
     * @param  Request  $request  덤프 요청
     * @param  string  $debugDir  최종 저장 디렉토리
     * @return JsonResponse 처리 결과
     */
    public static function handleBulk(Request $request, string $debugDir): JsonResponse
    {
        $sections = $request->input('sections', []);
        $saveHistory = $request->boolean('saveHistory');
        $timestampStr = self::formatTimestamp($request->input('timestamp'));

        $savedSections = [];

        foreach ((array) $sections as $sectionName => $sectionData) {
            if (! isset(self::SECTION_FILES[$sectionName])) {
                continue;
            }

            $json = json_encode($sectionData, self::JSON_FLAGS);
            File::put($debugDir.'/'.self::SECTION_FILES[$sectionName], $json);
            $savedSections[] = $sectionName;

            if ($saveHistory && $sectionName === self::HISTORY_SECTION) {
                File::put($debugDir."/state-{$timestampStr}.json", $json);
            }
        }

        return response()->json([
            'status' => 'success',
            'mode' => 'bulk',
            'savedSections' => $savedSections,
            'timestamp' => $timestampStr,
        ]);
    }

    /**
     * 레거시 전체 전송을 처리합니다 (하위 호환성 — 섹션명이 요청 최상위 키).
     *
     * @param  Request  $request  덤프 요청
     * @param  string  $debugDir  최종 저장 디렉토리
     * @return JsonResponse 처리 결과
     */
    public static function handleLegacy(Request $request, string $debugDir): JsonResponse
    {
        $timestamp = now()->format('Ymd_His');
        $saveHistory = $request->boolean('saveHistory');

        foreach (self::SECTION_FILES as $sectionName => $fileName) {
            if (! $request->has($sectionName)) {
                continue;
            }

            $json = json_encode($request->input($sectionName), self::JSON_FLAGS);
            File::put($debugDir.'/'.$fileName, $json);

            if ($saveHistory && $sectionName === self::HISTORY_SECTION) {
                File::put($debugDir."/state-{$timestamp}.json", $json);
            }
        }

        return response()->json([
            'status' => 'success',
            'path' => $debugDir.'/state-latest.json',
            'timestamp' => $timestamp,
        ]);
    }

    /**
     * 세션 디렉토리의 섹션 파일들을 최종 저장 위치로 옮깁니다.
     *
     * @param  string  $sessionDir  세션 임시 디렉토리
     * @param  string  $debugDir  최종 저장 디렉토리
     * @param  bool  $saveHistory  이력 파일 저장 여부
     * @param  mixed  $timestamp  클라이언트가 보낸 밀리초 타임스탬프
     * @return void
     */
    private static function mergeSectionsToFinalFiles(
        string $sessionDir,
        string $debugDir,
        bool $saveHistory,
        mixed $timestamp
    ): void {
        $timestampStr = self::formatTimestamp($timestamp);

        foreach (File::files($sessionDir) as $file) {
            $sectionName = pathinfo($file, PATHINFO_FILENAME);

            if (! isset(self::SECTION_FILES[$sectionName])) {
                continue;
            }

            File::copy($file, $debugDir.'/'.self::SECTION_FILES[$sectionName]);

            if ($saveHistory && $sectionName === self::HISTORY_SECTION) {
                File::copy($file, $debugDir."/state-{$timestampStr}.json");
            }
        }
    }

    /**
     * 오래된 세션 디렉토리를 정리합니다.
     *
     * @param  string  $sessionsDir  세션 루트 디렉토리
     * @return void
     */
    private static function cleanupOldSessions(string $sessionsDir): void
    {
        if (! File::isDirectory($sessionsDir)) {
            return;
        }

        $cutoffTime = time() - self::SESSION_TTL_SECONDS;

        foreach (File::directories($sessionsDir) as $sessionDir) {
            if (File::lastModified($sessionDir) < $cutoffTime) {
                File::deleteDirectory($sessionDir);
            }
        }
    }

    /**
     * 클라이언트 타임스탬프(밀리초)를 이력 파일명용 문자열로 변환합니다.
     *
     * `date()` 의 두 번째 파라미터는 int 이므로 `$timestamp / 1000` 의 float 결과를 그대로
     * 넘기면 PHP 8.1+ 에서 암묵 형변환 deprecation 이 발생한다 — 명시적으로 캐스팅한다.
     *
     * @param  mixed  $timestamp  밀리초 타임스탬프 (없으면 현재 시각 사용)
     * @return string `Ymd_His` 형식 문자열
     */
    private static function formatTimestamp(mixed $timestamp): string
    {
        if (! is_numeric($timestamp)) {
            return now()->format('Ymd_His');
        }

        return date('Ymd_His', (int) ($timestamp / 1000));
    }
}
