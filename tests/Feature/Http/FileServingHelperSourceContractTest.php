<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * 파일 서빙 통로가 **전부** 코어 헬퍼 단일 지점을 지나는지 잠그는 소스 계약 테스트 (DB 미사용).
 *
 * `X-Content-Type-Options: nosniff`·ETag·304·`Cache-Control` 같은 응답 계약은
 * `BaseApiController::fileResponse()` / `streamedFileResponse()` 두 지점이 소유한다. 그 계약은
 * **헬퍼를 지나는 통로에만** 성립한다 — 컨트롤러가 스토리지 스트림이나 `response()->file()`
 * 을 그대로 돌려주면 그 통로만 헤더가 빠지고, 오류도 로그도 없이 응답 헤더 차이로만 드러난다.
 * 15차 G3 라운드에서 sirsoft-page 첨부 **다운로드**가 그 형태였다(미리보기는 헬퍼를 지났다).
 *
 * 모집단은 코어 + 번들 확장 전체의 컨트롤러다 — 한 확장 안에만 두면 그 밖의 동형 결함이
 * red 가 되지 않는다.
 *
 * @scenario response_kind=streamed, conditional_request=none
 */
class FileServingHelperSourceContractTest extends TestCase
{
    /**
     * 파일 응답을 **직접 만드는** 호출 — 컨트롤러에는 있어서는 안 된다.
     *
     * @var array<string>
     */
    private const DIRECT_FILE_RESPONSE_PATTERNS = [
        '/response\(\)\s*->\s*(file|download|stream|streamDownload)\s*\(/',
        '/new\s+\\\\?(Symfony\\\\Component\\\\HttpFoundation\\\\)?BinaryFileResponse\s*\(/',
        '/new\s+\\\\?(Symfony\\\\Component\\\\HttpFoundation\\\\)?StreamedResponse\s*\(/',
    ];

    /**
     * 파일 서빙 **본문 신호** — 메서드가 파일·자산을 내보내고 있음을 반환 타입과 무관하게 드러내는 패턴.
     *
     * 모집단을 반환 타입 문자열(`StreamedResponse|BinaryFileResponse`)에서만 도출하면, 반환 타입을
     * `Response` 로 넓힌(또는 생략한) 서빙 메서드가 모집단에서 빠져 **검사 자체를 받지 않는다** —
     * `AdminTemplateAssetController::serveEditorCss()` 가 그 형태였다(반환 타입 없음 · `response($css)`
     * 직접 생성). 그래서 본문 신호도 함께 본다 (16차 G3 라운드 E-4·A7):
     *
     *  - 스토리지 스트림·다운로드 생성 (`Storage::…->response(`·`->download(`)
     *  - 서비스가 스트림 응답을 돌려주는 관용 이름 (`->preview(`·`->serve(`·`->streamFile(`·`->getFileInfo(`·
     *    `->getDownloadInfo(`·`->getServableResponse(`·`->indexInfo(`·`->childInfo(`)
     *  - 문자열 본문에 `Content-Type` 을 직접 세워 돌려주는 자산 응답 — **두 형태 다** 본다:
     *    체이닝(`response($x…)->header('Content-Type'`)과 **헤더 배열 인자**
     *    (`response($x, 200, ['Content-Type' => …])`). 종전에는 앞의 형태만 봐서
     *    `AssetProbeController::probe()` 가 모집단 밖이었고, 회차 20 이 같은 축에서 고친
     *    형태(빈 번들)도 애초에 탐지 대상이 아니었다 (22차 G3 라운드 B2)
     *  - 서비스가 내려주는 다운로드(`->download(`) — 종전 이름 목록에 없어, 반환 타입을
     *    넓힌 다운로드 메서드가 본문 신호만으로는 잡히지 않았다 (22차 G3 라운드 B3)
     *
     * @var array<string>
     */
    private const FILE_SERVING_BODY_PATTERNS = [
        '/Storage::[^;]*->\s*(response|download)\s*\(/',
        '/->\s*(preview|serve|streamFile|download|getFileInfo|getDownloadInfo|getServableResponse|indexInfo|childInfo)\s*\(/',
        '/response\s*\(\s*\$\w+[^;]*->\s*header\s*\(\s*[\'"]Content-Type[\'"]/',
        '/response\s*\([^;]*[\'"]Content-Type[\'"]\s*=>/',
    ];

    /**
     * 코어 헬퍼 호출 — 이 중 하나를 지나야 응답 계약(nosniff·ETag·304)이 성립한다.
     */
    private const CORE_HELPER_CALL = '/\$this\s*->\s*(streamedFileResponseFromMeta|streamedFileResponse|fileResponse|inlineContentResponse)\s*\(/';

    /**
     * 파일 서빙 메서드 수의 하한 (회차 15 전수표 기준).
     *
     * 매직 넘버가 아니라 이름 있는 하한이다 — 모집단 도출이 깨져 메서드가 조용히 덜 잡히면
     * 아래 검사가 공허하게 초록이 되므로, 그 바닥을 여기 한 곳에서 선언한다 (17차 G3 라운드 B-16).
     *
     * 값은 **실측**이다. 종전 10 은 그 시점 실측(22)의 절반도 되지 않아, 모집단이 절반 넘게
     * 비어도 초록이었다 — 하한이 실측을 따라가지 않으면 「바닥을 선언한다」는 목적 자체가
     * 사라진다. 서빙 메서드를 정당하게 줄였다면 이 값을 함께 내리고 근거를 남긴다
     * (22차 G3 라운드 B4).
     */
    // 28 → 44: develop-7.2.0 병합으로 방문자 통계 내보내기(코어 8)·이커머스 내보내기와 클레임 이미지·
    // SSO 로그인 페이지와 메타데이터 등 서빙 메서드가 모집단에 들어왔다(병합 결과 실측).
    // 44 → 45: develop-7.2.0 3차 병합으로 NHN KCP PC 결제창 프레임 문서(`KcpFrameController::show()`)가
    // 들어왔다 — 생성 문서라 `file-serving:allow` 로 처분하되 모집단에는 남는다(병합 결과 실측).
    private const MIN_FILE_SERVING_METHODS = 45;

    /**
     * 컨트롤러 소스 파일 전수 (코어 + 번들 확장).
     *
     * @return array<string> 중복을 걷어낸 컨트롤러 파일 절대 경로 목록
     */
    private function controllerFiles(): array
    {
        $patterns = [
            base_path('app/Http/Controllers/**/*.php'),
            base_path('app/Http/Controllers/*.php'),
            base_path('modules/_bundled/*/src/Http/Controllers/**/*.php'),
            base_path('modules/_bundled/*/src/Http/Controllers/*.php'),
            base_path('plugins/_bundled/*/src/Http/Controllers/**/*.php'),
            base_path('plugins/_bundled/*/src/Http/Controllers/*.php'),
            // 확장은 `src/Http/Controllers` 를 강제받지 않는다 — 결제 플러그인 4종은
            // `src/Controllers` 를 쓰고, 그 44개가 통째로 이 계약 밖이었다. 그 안에는
            // 헬퍼를 지나지 않고 손조립한 응답이 실재했다 (23차 G3 라운드 B23-6).
            // 모집단을 **디렉토리 이름 한 벌**로 고정하지 않는다 — 다음 확장이 또 다른
            // 이름을 쓰면 같은 사각이 다시 생긴다.
            base_path('modules/_bundled/*/src/Controllers/**/*.php'),
            base_path('modules/_bundled/*/src/Controllers/*.php'),
            base_path('plugins/_bundled/*/src/Controllers/**/*.php'),
            base_path('plugins/_bundled/*/src/Controllers/*.php'),
        ];

        $files = [];
        foreach ($patterns as $pattern) {
            foreach ($this->recursiveGlob($pattern) as $file) {
                // 헬퍼 **소유자**는 모집단이 아니다 — 그 두 지점이 파일 응답을 만드는 자리다.
                if (str_ends_with(str_replace('\\', '/', $file), 'app/Http/Controllers/Api/Base/BaseApiController.php')) {
                    continue;
                }

                $files[$file] = true;
            }
        }

        return array_keys($files);
    }

    /**
     * `**` 를 지원하는 glob (PHP `glob()` 은 재귀를 모른다).
     *
     * @param  string  $pattern  `**` 를 포함할 수 있는 glob 패턴
     * @return array<string> 패턴에 맞는 파일 경로 목록
     */
    private function recursiveGlob(string $pattern): array
    {
        if (! str_contains($pattern, '**')) {
            return glob($pattern) ?: [];
        }

        [$head, $tail] = explode('**', $pattern, 2);
        $tail = ltrim($tail, '/\\');

        $results = [];
        foreach (glob(rtrim($head, '/\\').'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $results = array_merge(
                $results,
                $this->recursiveGlob($dir.'/'.$tail),
                $this->recursiveGlob($dir.'/**/'.$tail)
            );
        }

        return $results;
    }

    /**
     * 파일을 내보내는 메서드 본문을 뽑습니다 (`이름 => 본문`).
     *
     * 모집단은 두 축의 **합집합**이다 — 반환 타입이 파일 응답을 선언했거나, 본문에 파일 서빙
     * 신호(`FILE_SERVING_BODY_PATTERNS`)가 있거나. 반환 타입만 보면 타입을 넓힌 사본이 빠지고,
     * 본문 신호만 보면 위임만 하는 메서드(`return $this->serveX()`)가 빠진다.
     *
     * @param  string  $source  컨트롤러 파일 소스 문자열
     * @return array<string, string> 파일 서빙 메서드 이름별 본문
     */
    private function fileServingMethodBodies(string $source): array
    {
        $bodies = [];

        foreach ($this->methodBodies($source) as $method => [$returnType, $body]) {
            // 반환 타입이 **JSON 뿐**인 메서드는 파일을 내보낼 수 없다 — 파일 응답을 돌려주면
            // 런타임 `TypeError` 다. 그 안의 `->preview(` 같은 이름은 JSON 미리보기(SEO OG 등)다.
            // 반환 타입이 없거나 `Response` 처럼 넓은 형태는 여전히 본문 신호로 잡는다.
            if (preg_match('/^\??JsonResponse$/', $returnType)) {
                continue;
            }

            $declaresFileResponse = preg_match('/StreamedResponse|BinaryFileResponse/', $returnType) === 1;

            if ($declaresFileResponse || $this->carriesServingSignal($body)) {
                $bodies[$method] = $body;
            }
        }

        return $bodies;
    }

    /**
     * 메서드 본문이 파일 서빙 신호(`FILE_SERVING_BODY_PATTERNS`)를 하나라도 지니는지 판정합니다.
     *
     * 모집단 도출과 위임 탈출구 판정이 **같은 신호 상수**를 본다 — 종전 탈출구는
     * `download|response|preview|stream` 네 이름만 부정 판정에 썼는데, 그것은 8개 신호 목록의
     * 부분집합이라 `getFileInfo`·`indexInfo`·`childInfo` 등으로 직접 서빙하면서 `return $this->x()`
     * 로 위임하는 형태를 탈출구가 잘못 면제했다. 판정을 이 한 곳으로 접는다 (17차 G3 라운드 B-11).
     *
     * @param  string  $body  메서드 본문
     * @return bool 서빙 신호를 지니면 true
     */
    private function carriesServingSignal(string $body): bool
    {
        foreach (self::FILE_SERVING_BODY_PATTERNS as $pattern) {
            if (preg_match($pattern, $body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 소스의 모든 메서드를 `이름 => [반환 타입 문자열, 본문]` 으로 뽑습니다.
     *
     * 반환 타입이 없는 메서드도 포함한다 — 그 형태가 바로 반환 타입 기반 모집단이 놓치던 것이다.
     *
     * @param  string  $source  컨트롤러 파일 소스 문자열
     * @return array<string, array{string, string}> 메서드 이름별 [반환 타입 문자열, 본문]
     */
    private function methodBodies(string $source): array
    {
        $bodies = [];

        if (! preg_match_all(
            '/function\s+(\w+)\s*\((?:[^()]|\([^()]*\))*\)\s*(?::\s*([^{;]*?))?\s*\{/s',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return $bodies;
        }

        foreach ($matches[0] as $index => [$whole, $offset]) {
            $start = $offset + strlen($whole);
            $depth = 1;
            $length = strlen($source);
            $cursor = $start;

            while ($cursor < $length && $depth > 0) {
                $char = $source[$cursor];
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                }
                $cursor++;
            }

            $bodies[$matches[1][$index][0]] = [
                trim((string) ($matches[2][$index][0] ?? '')),
                substr($source, $start, $cursor - $start - 1),
            ];
        }

        return $bodies;
    }

    /**
     * 모집단 도출이 반환 타입에 **의존하지 않는다** — 타입을 `Response` 로 넓힌 사본도 잡힌다.
     *
     * 실제 서빙 컨트롤러 하나를 변이시켜 본다: 반환 타입에서 `StreamedResponse` 를 지워도 같은
     * 메서드가 모집단에 남아야 한다. 종전 도출(반환 타입 문자열)에서는 이 변이가 모집단을 비웠다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects file_serving_controllers_go_through_core_helper
     *
     * @return void 반환값 없음
     */
    public function test_population_survives_a_widened_return_type_mutation(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/Api/Public/PublicAttachmentController.php'));
        $this->assertArrayHasKey('download', $this->fileServingMethodBodies($source), '변이 전 원본이 모집단에 없다 — 픽스처가 잘못됐다');

        $mutated = preg_replace('/\):\s*BinaryFileResponse\|StreamedResponse\|Response\|JsonResponse/', '): Response', $source, 1, $count);
        $this->assertSame(1, $count, '변이 대상 시그니처를 찾지 못했다 — 원본이 바뀌었으면 픽스처를 갱신한다');

        $this->assertArrayHasKey('download', $this->fileServingMethodBodies((string) $mutated), '반환 타입을 넓히자 모집단에서 빠졌다 — 본문 신호 도출이 죽었다');

        // 반환 타입을 **아예 없앤** 형태도 같다 (`serveEditorCss()` 가 그 형태였다).
        $untyped = preg_replace('/\):\s*BinaryFileResponse\|StreamedResponse\|Response\|JsonResponse/', ')', $source, 1);
        $this->assertArrayHasKey('download', $this->fileServingMethodBodies((string) $untyped));
    }

    /**
     * 스트림 헬퍼 호출은 통로의 성질을 **명시**한다 — `immutable:`·`mimeType:`·`skipCompression:`.
     *
     * 셋 다 생략해도 동작하는 축이라 오류로 드러나지 않는다: `immutable` 은 요청자가 붙인
     * `?v=` 로 파생돼 사용자 업로드물이 1년 불변이 되고, `mimeType` 은 스토리지 어댑터의
     * 추정(사본)에 기대며, 압축 제외는 응답 클래스를 보고 건너뛰는 우연이 된다. 에디터
     * 이미지 통로가 `mimeType` 을 빠뜨린 채로 있었다 (16차 G3 라운드 E-2·E-3).
     *
     * 메타 헬퍼(`streamedFileResponseFromMeta()`)는 `mimeType` 을 메타에서 받으므로 나머지 둘을
     * 요구한다 — 그 둘은 기본값이 없어 생략하면 문법 오류지만, 위치 인자로 넘기는 형태
     * (`false, true`)는 의도가 드러나지 않으므로 이름 인자를 요구한다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects file_serving_controllers_go_through_core_helper
     *
     * @return void 반환값 없음
     */
    public function test_every_streamed_helper_call_names_its_serving_arguments(): void
    {
        $violations = [];

        foreach ($this->controllerFiles() as $file) {
            $source = (string) file_get_contents($file);
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            foreach ($this->helperCalls($source, 'streamedFileResponse') as $call) {
                foreach (['immutable:', 'mimeType:', 'skipCompression:'] as $argument) {
                    if (! str_contains($call, $argument)) {
                        $violations[] = "{$relative} — streamedFileResponse() 호출에 {$argument} 가 없다";
                    }
                }
            }

            foreach ($this->helperCalls($source, 'streamedFileResponseFromMeta') as $call) {
                foreach (['immutable:', 'skipCompression:'] as $argument) {
                    if (! str_contains($call, $argument)) {
                        $violations[] = "{$relative} — streamedFileResponseFromMeta() 호출에 {$argument} 가 없다";
                    }
                }
            }
        }

        $this->assertSame([], $violations, "스트림 헬퍼 호출이 통로의 성질을 명시하지 않는다:\n".implode("\n", $violations));
    }

    /**
     * `$this->{$helper}(` 호출의 인자 구간(괄호 균형)을 전부 뽑습니다.
     *
     * @param  string  $source  컨트롤러 파일 소스 문자열
     * @param  string  $helper  찾을 헬퍼 메서드 이름
     * @return array<string> 호출마다의 괄호 안 인자 문자열 목록
     */
    private function helperCalls(string $source, string $helper): array
    {
        $calls = [];
        $offset = 0;

        while (preg_match('/\$this\s*->\s*'.preg_quote($helper, '/').'\s*\(/', $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1] + strlen($match[0][0]);
            $depth = 1;
            $cursor = $start;
            $length = strlen($source);

            while ($cursor < $length && $depth > 0) {
                $char = $source[$cursor];
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }
                $cursor++;
            }

            $calls[] = substr($source, $start, $cursor - $start - 1);
            $offset = $cursor;
        }

        return $calls;
    }

    /**
     * 모집단 자체를 잰다 — 비면 아래 두 검사가 공허하게 초록이다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects file_serving_controllers_go_through_core_helper
     *
     * @return void 반환값 없음
     */
    public function test_population_covers_core_and_bundled_extension_controllers(): void
    {
        $files = $this->controllerFiles();

        $this->assertNotEmpty(array_filter($files, fn ($f) => str_contains(str_replace('\\', '/', $f), '/app/Http/Controllers/')), '코어 컨트롤러가 모집단에 없다');
        $this->assertNotEmpty(array_filter($files, fn ($f) => str_contains($f, 'sirsoft-page')), 'sirsoft-page 컨트롤러가 모집단에 없다');
        $this->assertNotEmpty(array_filter($files, fn ($f) => str_contains($f, 'sirsoft-board')), 'sirsoft-board 컨트롤러가 모집단에 없다');
        $this->assertNotEmpty(array_filter($files, fn ($f) => str_contains($f, 'sirsoft-ckeditor5')), 'sirsoft-ckeditor5 컨트롤러가 모집단에 없다');

        $servingMethods = 0;
        foreach ($files as $file) {
            $servingMethods += count($this->fileServingMethodBodies((string) file_get_contents($file)));
        }

        // 회차 15 전수표: 공개 스토리지 · 확장 자산 3(+번들 4) · 템플릿 레이아웃 첨부 · 코어 첨부 ·
        // 사이트맵 · 게시판 3 · 페이지 2 · 에디터 이미지 · 이커머스 4 — 하한이다(MIN_FILE_SERVING_METHODS).
        // 실측이 하한보다 높으면 그 여유만큼 모집단이 조용히 줄어도 통과한다 — 24차 R24-C M9
        // 실측: `src/Controllers` 4경로를 모집단에서 빼도 이 단언이 초록이었다. 하한은 실측을
        // 따라간다(줄면 red, 늘면 이 상수를 올린다).
        $this->assertSame(self::MIN_FILE_SERVING_METHODS, $servingMethods, "파일 서빙 메서드 수가 하한과 다르다 — 늘었으면 MIN_FILE_SERVING_METHODS 를 올리고, 줄었으면 모집단이 빠진 것이다 (실측 {$servingMethods})");
        $this->assertGreaterThanOrEqual(self::MIN_FILE_SERVING_METHODS, $servingMethods, '파일 서빙 메서드가 하한보다 적게 잡혔다 — 반환 타입 추출이 깨졌다');
    }

    /**
     * 컨트롤러는 파일 응답을 직접 만들지 않는다 — 헬퍼 두 지점 밖에는 `nosniff` 가 없다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects file_serving_response_declares_nosniff, file_serving_controllers_go_through_core_helper
     *
     * @return void 반환값 없음
     */
    public function test_no_controller_builds_a_file_response_directly(): void
    {
        $violations = [];

        foreach ($this->controllerFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::DIRECT_FILE_RESPONSE_PATTERNS as $pattern) {
                if (preg_match($pattern, $source)) {
                    $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).' — '.$pattern;
                }
            }
        }

        $this->assertSame([], $violations, "컨트롤러가 파일 응답을 직접 만든다 — 헬퍼 단일 지점의 응답 계약(nosniff·ETag·304)이 그 통로만 빠진다:\n".implode("\n", $violations));
    }

    /**
     * 파일 응답 타입을 돌려주는 컨트롤러 메서드는 반드시 헬퍼를 부른다.
     *
     * 스토리지가 만든 스트림을 **그대로** 돌려주면 위 직접 생성 검사에는 걸리지 않는다 —
     * `return $this->service->download($row)` 가 그 형태다 (sirsoft-page 다운로드가 그랬다).
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects file_serving_response_declares_nosniff, file_serving_controllers_go_through_core_helper
     *
     * @return void 반환값 없음
     */
    public function test_every_file_serving_controller_method_goes_through_a_core_helper(): void
    {
        $violations = [];

        foreach ($this->controllerFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach ($this->fileServingMethodBodies($source) as $method => $body) {
                if (preg_match(self::CORE_HELPER_CALL, $body)) {
                    continue;
                }

                // 다른 파일 서빙 메서드에 위임하는 형태(`return $this->serveX(...)`)는 그쪽이 잰다.
                // 단, 위임하면서 **자기도 서빙 신호를 지니면** 순수 위임이 아니므로 면제하지 않는다 —
                // 면제 판정을 모집단과 같은 신호 상수에서 도출한다 (B-11).
                if (preg_match('/return\s+\$this\s*->\s*\w+\s*\(/', $body) && ! $this->carriesServingSignal($body)) {
                    continue;
                }

                // 파일이 아니라 **생성한 문서**를 돌려주는 메서드(본인인증 중계 페이지 등)는
                // 이 계약의 대상이 아니다. 다만 그 판정은 정규식이 아니라 **코드에 남긴 선언**으로
                // 한다 — `text/html` 을 신호에서 빼는 방식은 「html 이라고 적으면 통과」라는
                // 탈출구를 만들고, 그 탈출구는 업로드물을 그 유형으로 내보내는 최악의 형태를
                // 그대로 면제한다 (22차 G3 라운드 B2).
                if (preg_match('/\/\/\s*file-serving:allow\s+\S/', $body)) {
                    continue;
                }

                $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).'::'.$method.'()';
            }
        }

        $this->assertSame([], $violations, "파일 응답을 돌려주는 메서드가 코어 헬퍼를 지나지 않는다:\n".implode("\n", $violations));
    }
}
