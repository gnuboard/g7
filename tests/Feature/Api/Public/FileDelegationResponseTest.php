<?php

namespace Tests\Feature\Api\Public;

use App\Http\Controllers\Api\Base\BaseApiController;
use App\Http\Middleware\GzipEncodeResponse;
use App\Http\Responses\DelegatedFileResponse;
use App\Support\FileServing\FileDelegation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * 웹서버 파일 위임 — `BaseApiController::fileResponse()` 가 내는 `DelegatedFileResponse` 의 계약 (DB 미사용).
 *
 * 위임은 운영자 설정(`core.file_serving.delegation`)으로만 켜진다. 요청 헤더 `X-Sendfile-Type`·`X-Accel-Mapping` 은
 * 위조할 수 있으므로 보지 않는다. 켜져 있으면 본문 대신 위임 헤더를 세우고, ETag·304·Cache-Control·nosniff·
 * Content-Disposition 은 위임과 무관하게 헬퍼가 정한 그대로다. 신뢰 플래그는 프로세스 전역 static 이라 응답 하나의
 * `prepare()` 가 끝나면 원래 값으로 돌아와야 한다.
 *
 * @scenario-manifest tests/scenarios/file-serving-delegation.yaml
 */
class FileDelegationResponseTest extends TestCase
{
    /**
     * 이 테스트가 만든 픽스처 경로 (정리 대상).
     *
     * @var array<int, string>
     */
    private array $fixtures = [];

    /**
     * 위임 설정을 기본(none·기본 접두사)으로 되돌려 두고 시작한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.file_serving.delegation' => FileDelegation::MODE_NONE,
            'core.file_serving.accel_prefix' => FileDelegation::DEFAULT_ACCEL_PREFIX,
        ]);
    }

    /**
     * 픽스처 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            @unlink($path);
        }

        $dir = storage_path('framework/testing/g7-file-delegation');
        if (is_dir($dir) && (glob($dir.'/*') ?: []) === []) {
            @rmdir($dir);
        }

        parent::tearDown();
    }

    /**
     * 설치 위치 안(storage/framework/testing)에 픽스처 파일을 만든다 — nginx 매핑이 덮는 자리다.
     *
     * @param  string  $name  파일 이름
     * @param  string  $content  내용
     * @return string 파일 절대 경로
     */
    private function fixtureInsideBase(string $name, string $content): string
    {
        $dir = storage_path('framework/testing/g7-file-delegation');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $content);

        return $this->fixtures[] = $path;
    }

    /**
     * 설치 위치 밖(시스템 임시 디렉터리)에 픽스처 파일을 만든다 — nginx 매핑이 덮지 못하는 자리다.
     *
     * @param  string  $content  내용
     * @return string 파일 절대 경로
     */
    private function fixtureOutsideBase(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'g7_delegation_');
        file_put_contents($path, $content);

        return $this->fixtures[] = $path;
    }

    /**
     * 헬퍼를 노출하는 테스트 전용 컨트롤러를 만든다.
     *
     * @return object `serve(...)` 로 `fileResponse()` 를 그대로 부르는 객체
     */
    private function controller(): object
    {
        return new class extends BaseApiController
        {
            /**
             * 로컬 파일을 주어진 MIME 과 max-age 3600 으로 `fileResponse()` 에 넘겨 결과를 돌려준다.
             *
             * @param  string  $path  서빙할 로컬 파일 경로
             * @param  string  $mimeType  MIME 유형 문자열
             * @return BinaryFileResponse|Response 파일 응답 또는 304 응답
             */
            public function serve(string $path, string $mimeType): BinaryFileResponse|Response
            {
                return $this->fileResponse($path, $mimeType, 3600);
            }
        };
    }

    /**
     * 주어진 메서드·헤더의 요청을 컨테이너에 바인딩한다 (헬퍼는 `request()` 를 읽는다).
     *
     * @param  array<string, string>  $headers  요청 헤더
     * @param  string  $method  HTTP 메서드
     * @return Request 바인딩한 요청
     */
    private function bindRequest(array $headers = [], string $method = 'GET'): Request
    {
        $request = Request::create('/api/delegation-fixture', $method);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $this->app->instance('request', $request);

        return $request;
    }

    /**
     * 헬퍼로 응답을 만들고 라우터처럼 `prepare()` 한다.
     *
     * @param  string  $path  파일 경로
     * @param  string  $mimeType  MIME 유형
     * @param  array<string, string>  $headers  요청 헤더
     * @param  string  $method  HTTP 메서드
     * @return array{0: BinaryFileResponse|Response, 1: Request} 준비된 응답과 요청
     */
    private function serve(string $path, string $mimeType, array $headers = [], string $method = 'GET'): array
    {
        $request = $this->bindRequest($headers, $method);
        $response = $this->controller()->serve($path, $mimeType);
        $response->prepare($request);

        return [$response, $request];
    }

    /**
     * 응답이 실제로 내보낼 본문을 잡는다.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response  응답
     * @return string 출력된 본문
     */
    private function sentBody(\Symfony\Component\HttpFoundation\Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * 기대 `X-Accel-Redirect` 값 — 접두사 + 설치 위치 기준 상대 경로(구분자 `/`), Symfony 와 같은 `rawurlencode`.
     *
     * @param  string  $prefix  접두사
     * @param  string  $path  파일 경로
     * @return string 기대 헤더 값
     */
    private function expectedAccelRedirect(string $prefix, string $path): string
    {
        $relative = substr((string) realpath($path), strlen(FileDelegation::basePath()) + 1);

        return rawurlencode($prefix.str_replace('\\', '/', $relative));
    }

    /**
     * ① 위임이 꺼져 있으면 요청이 `X-Sendfile-Type` 을 보내도 위임하지 않는다 — 본문은 PHP 가 보낸다.
     *
     * @scenario case=forged_header_off
     *
     * @effects forged_sendfile_request_header_is_ignored
     *
     * @return void 반환값 없음
     */
    public function test_forged_request_header_is_ignored_when_delegation_is_off(): void
    {
        $path = $this->fixtureInsideBase('off.css', 'body{color:red}');

        [$response] = $this->serve($path, 'text/css', [
            'X-Sendfile-Type' => 'X-Sendfile',
            'X-Accel-Mapping' => '/=/leak/',
        ]);

        $this->assertInstanceOf(DelegatedFileResponse::class, $response);
        $this->assertFalse($response->headers->has('X-Sendfile'), '요청 헤더로 위임이 켜지면 방문자가 웹서버에 임의 경로를 넘길 수 있다');
        $this->assertFalse($response->headers->has('X-Accel-Redirect'));
        $this->assertFalse($response->headers->has(FileDelegation::DIAGNOSTIC_HEADER));
        $this->assertSame('body{color:red}', $this->sentBody($response));
    }

    /**
     * ② `x-sendfile` — 실경로를 `X-Sendfile` 로 내고 본문은 비운다. 캐시·보안 헤더는 그대로, 압축 제외를 선언한다.
     *
     * @scenario case=x_sendfile_helper
     *
     * @effects delegated_response_carries_header_without_body, delegated_response_keeps_cache_and_security_headers
     *
     * @return void 반환값 없음
     */
    public function test_x_sendfile_sets_real_path_and_sends_no_body(): void
    {
        config(['core.file_serving.delegation' => FileDelegation::MODE_X_SENDFILE]);
        $path = $this->fixtureInsideBase('bundle.css', str_repeat('.a{color:red}', 200));

        [$response, $request] = $this->serve($path, 'text/css');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(realpath($path), $response->headers->get('X-Sendfile'));
        $this->assertSame('applied', $response->headers->get(FileDelegation::DIAGNOSTIC_HEADER));
        $this->assertSame('', $this->sentBody($response), '위임 응답의 본문은 웹서버가 보낸다');

        $this->assertStringStartsWith('text/css', (string) $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', (string) $response->headers->get('ETag'));
        $this->assertNotNull($response->headers->get('Last-Modified'));
        $this->assertNotNull($response->headers->get('Expires'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'), '테스트(비운영) 환경의 헬퍼 정책이 그대로여야 한다');
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertTrue($request->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE), '위임 응답은 앱 gzip 에서 빠져야 한다');
    }

    /**
     * ③ 조건부 요청이 맞으면 304 — 위임 헤더 없이 헬퍼의 304 가 나간다.
     *
     * @scenario case=conditional_304
     *
     * @effects conditional_request_is_answered_before_delegation
     *
     * @return void 반환값 없음
     */
    public function test_matching_if_none_match_returns_304_without_delegation_header(): void
    {
        config(['core.file_serving.delegation' => FileDelegation::MODE_X_SENDFILE]);
        $path = $this->fixtureInsideBase('cached.css', 'body{}');

        [$full] = $this->serve($path, 'text/css');
        [$notModified] = $this->serve($path, 'text/css', ['If-None-Match' => (string) $full->headers->get('ETag')]);

        $this->assertSame(304, $notModified->getStatusCode());
        $this->assertFalse($notModified->headers->has('X-Sendfile'));
        $this->assertFalse($notModified->headers->has(FileDelegation::DIAGNOSTIC_HEADER));
    }

    /**
     * ④ Range 요청에도 앱은 200 전량 헤더만 낸다 — 구간 처리는 웹서버 몫이다.
     *
     * @scenario case=range_delegated
     *
     * @effects range_is_left_to_the_web_server
     *
     * @return void 반환값 없음
     */
    public function test_range_request_is_left_to_the_web_server(): void
    {
        config(['core.file_serving.delegation' => FileDelegation::MODE_X_SENDFILE]);
        $path = $this->fixtureInsideBase('video.mp4', str_repeat('v', 100));

        [$response] = $this->serve($path, 'video/mp4', ['Range' => 'bytes=0-3']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Content-Range'));
        $this->assertSame(realpath($path), $response->headers->get('X-Sendfile'));
        $this->assertSame('', $this->sentBody($response));
    }

    /**
     * ⑤ `x-accel-redirect` — 앱이 만든 매핑으로 internal URI 를 낸다. 요청이 보낸 매핑은 덮어쓴다.
     *
     * @scenario case=x_accel_mapping
     *
     * @effects accel_redirect_uri_is_built_from_app_mapping, forged_sendfile_request_header_is_ignored
     *
     * @return void 반환값 없음
     */
    public function test_x_accel_redirect_uses_the_app_mapping_and_prefix(): void
    {
        config(['core.file_serving.delegation' => FileDelegation::MODE_X_ACCEL_REDIRECT]);
        $path = $this->fixtureInsideBase('icon.png', 'png-bytes');

        [$response] = $this->serve($path, 'image/png', [
            'X-Sendfile-Type' => 'X-Sendfile',
            'X-Accel-Mapping' => '/=/leak/',
        ]);

        $this->assertSame($this->expectedAccelRedirect('/_g7_sendfile/', $path), $response->headers->get('X-Accel-Redirect'));
        $this->assertFalse($response->headers->has('X-Sendfile'), '방식은 설정이 정한다 — 요청이 보낸 X-Sendfile-Type 은 무시된다');
        $this->assertSame('applied', $response->headers->get(FileDelegation::DIAGNOSTIC_HEADER));
        $this->assertSame('', $this->sentBody($response));

        // 운영자가 정한 접두사를 그대로 쓴다
        config(['core.file_serving.accel_prefix' => '/protected-files/']);
        [$custom] = $this->serve($path, 'image/png');

        $this->assertSame($this->expectedAccelRedirect('/protected-files/', $path), $custom->headers->get('X-Accel-Redirect'));
    }

    /**
     * ⑥ 실경로가 매핑 밖이면 위임하지 않는다 — PHP 가 본문을 보내고(Range 도 종전대로) 진단 헤더가 `skipped`.
     *
     * @scenario case=outside_mapping
     *
     * @effects unmapped_path_falls_back_to_php_with_skipped_diagnostic
     *
     * @return void 반환값 없음
     */
    public function test_path_outside_the_mapping_falls_back_to_php(): void
    {
        config(['core.file_serving.delegation' => FileDelegation::MODE_X_ACCEL_REDIRECT]);
        $path = $this->fixtureOutsideBase('outside-content');

        $this->assertStringStartsNotWith(FileDelegation::basePath(), (string) realpath($path), '픽스처가 설치 위치 밖이어야 이 케이스가 성립한다');

        [$full] = $this->serve($path, 'text/plain');

        $this->assertFalse($full->headers->has('X-Accel-Redirect'));
        $this->assertSame('skipped', $full->headers->get(FileDelegation::DIAGNOSTIC_HEADER));
        $this->assertSame('outside-content', $this->sentBody($full));

        [$partial] = $this->serve($path, 'text/plain', ['Range' => 'bytes=0-6']);

        $this->assertSame(206, $partial->getStatusCode(), '위임하지 못한 응답은 Range 를 PHP 가 종전대로 처리해야 한다');
        $this->assertSame('outside', $this->sentBody($partial));
    }

    /**
     * ⑥-2 보내기 전에 위임 결과(꺼짐·적용·매핑 밖)를 미리 알 수 있다 — 2단계 프로브가 「PHP 가 보낸 본문」을 웹서버 전송으로
     * 오판하지 않게 쓴다. 미리 보기는 응답 자신의 헤더를 바꾸지 않는다.
     *
     * @scenario case=outside_mapping
     *
     * @effects probe_reports_php_delivery_instead_of_success
     *
     * @return void 반환값 없음
     */
    public function test_delegation_outcome_is_known_before_sending(): void
    {
        $inside = $this->fixtureInsideBase('outcome.txt', 'inside');
        $outside = $this->fixtureOutsideBase('outside');
        $request = $this->bindRequest();

        $off = $this->controller()->serve($inside, 'text/plain');
        $this->assertSame(DelegatedFileResponse::OUTCOME_INACTIVE, $off->delegationOutcome($request));

        config(['core.file_serving.delegation' => FileDelegation::MODE_X_ACCEL_REDIRECT]);

        $applied = $this->controller()->serve($inside, 'text/plain');
        $this->assertSame(DelegatedFileResponse::OUTCOME_APPLIED, $applied->delegationOutcome($request));
        $this->assertFalse($applied->headers->has('X-Accel-Redirect'), '미리 보기가 응답 헤더를 바꿨다');
        $this->assertFalse($applied->headers->has(FileDelegation::DIAGNOSTIC_HEADER));

        $skipped = $this->controller()->serve($outside, 'text/plain');
        $this->assertSame(DelegatedFileResponse::OUTCOME_SKIPPED, $skipped->delegationOutcome($request));
        $this->assertFalse((new ReflectionProperty(BinaryFileResponse::class, 'trustXSendfileTypeHeader'))->getValue(), '신뢰 플래그가 새었다');
    }

    /**
     * ⑦ 신뢰 플래그는 응답 하나의 준비 동안만 켜진다 — 연속 두 응답 뒤에도 꺼져 있고, 일반 파일 응답은 위조 헤더를 믿지 않는다.
     *
     * @scenario case=trust_flag_restore
     *
     * @effects trust_flag_never_leaks_past_prepare
     *
     * @return void 반환값 없음
     */
    public function test_trust_flag_is_restored_after_each_prepare(): void
    {
        config(['core.file_serving.delegation' => FileDelegation::MODE_X_SENDFILE]);
        $path = $this->fixtureInsideBase('twice.js', 'console.log(1);');

        $this->serve($path, 'text/javascript');
        $this->serve($path, 'text/javascript');

        $flag = new ReflectionProperty(BinaryFileResponse::class, 'trustXSendfileTypeHeader');
        $this->assertFalse($flag->getValue(), '전역 신뢰 플래그가 남으면 이후 모든 파일 응답이 요청 헤더를 믿는다');

        $plain = new BinaryFileResponse($path);
        $forged = Request::create('/plain', 'GET');
        $forged->headers->set('X-Sendfile-Type', 'X-Sendfile');
        $plain->prepare($forged);

        $this->assertFalse($plain->headers->has('X-Sendfile'));
    }

    /**
     * ⑧ HEAD 는 헤더만 — 위임 헤더와 길이는 GET 과 같고 본문은 없다.
     *
     * @scenario case=head_only
     *
     * @effects delegated_response_carries_header_without_body
     *
     * @return void 반환값 없음
     */
    public function test_head_request_carries_headers_only(): void
    {
        config(['core.file_serving.delegation' => FileDelegation::MODE_X_SENDFILE]);
        $path = $this->fixtureInsideBase('head.css', 'body{margin:0}');

        [$head] = $this->serve($path, 'text/css', [], 'HEAD');

        $this->assertSame(200, $head->getStatusCode());
        $this->assertSame(realpath($path), $head->headers->get('X-Sendfile'));
        $this->assertSame((string) filesize($path), (string) $head->headers->get('Content-Length'));
        $this->assertSame('', $this->sentBody($head));
    }

    /**
     * 모르는 모드 값은 `none` 으로 해석한다 — 오타가 위임을 켜지 않는다.
     *
     * @scenario case=unknown_mode
     *
     * @effects unknown_mode_is_treated_as_none
     *
     * @return void 반환값 없음
     */
    public function test_unknown_mode_value_is_treated_as_none(): void
    {
        config(['core.file_serving.delegation' => 'X-SendFile-typo']);

        $this->assertSame(FileDelegation::MODE_NONE, FileDelegation::mode());
        $this->assertFalse(FileDelegation::active());
        $this->assertNull(FileDelegation::sendfileType());

        config(['core.file_serving.delegation' => 'X-Sendfile']);
        $this->assertSame(FileDelegation::MODE_X_SENDFILE, FileDelegation::mode(), '대소문자는 가리지 않는다');

        config(['core.file_serving.accel_prefix' => 'no-leading-slash']);
        $this->assertSame(FileDelegation::DEFAULT_ACCEL_PREFIX, FileDelegation::accelPrefix(), '형식이 틀린 접두사는 기본값으로 돌아간다');

        config(['core.file_serving.accel_prefix' => '/files']);
        $this->assertSame('/files/', FileDelegation::accelPrefix(), '끝 / 는 보정한다');
    }
}
