<?php

namespace Tests\Unit\Helpers;

use App\Helpers\ResponseHelper;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * ResponseHelper 단위 테스트
 *
 * 디버그 모드에 따른 예외 정보 노출/차단을 검증합니다.
 */
class ResponseHelperTest extends TestCase
{
    /**
     * error() + \Throwable + debug=true → message에 예외 메시지 포함 + debug 키 존재
     */
    public function test_error_with_throwable_in_debug_mode_includes_debug_info(): void
    {
        config(['app.debug' => true]);

        $exception = new \RuntimeException('Test exception message');
        $response = ResponseHelper::error('messages.failed', 500, $exception);
        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Test exception message', $data['message']);
        $this->assertArrayHasKey('debug', $data);
        $this->assertEquals(\RuntimeException::class, $data['debug']['exception']);
        $this->assertEquals('Test exception message', $data['debug']['message']);
        $this->assertArrayHasKey('file', $data['debug']);
        $this->assertArrayHasKey('line', $data['debug']);
        $this->assertArrayHasKey('trace', $data['debug']);
        $this->assertLessThanOrEqual(10, count($data['debug']['trace']));
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * error() + \Throwable + debug=false → 번역 메시지만, debug 키 미존재
     */
    public function test_error_with_throwable_in_production_hides_debug_info(): void
    {
        config(['app.debug' => false]);

        $exception = new \RuntimeException('Sensitive error detail');
        $response = ResponseHelper::error('messages.failed', 500, $exception);
        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertStringNotContainsString('Sensitive error detail', $data['message']);
        $this->assertArrayNotHasKey('debug', $data);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * error() + string + 500 + debug=false → errors 키 미존재 (프로덕션 보안)
     */
    public function test_error_with_string_500_in_production_hides_errors(): void
    {
        config(['app.debug' => false]);

        $response = ResponseHelper::error('messages.failed', 500, 'Internal DB error');
        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * error() + string + 500 + debug=true → errors 키 존재
     */
    public function test_error_with_string_500_in_debug_mode_shows_errors(): void
    {
        config(['app.debug' => true]);

        $response = ResponseHelper::error('messages.failed', 500, 'Internal DB error');
        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
        $this->assertEquals('Internal DB error', $data['errors']);
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * error() + array + 422 → debug 무관하게 errors 존재 (기존 validation 동작 유지)
     */
    public function test_error_with_array_422_always_shows_errors(): void
    {
        config(['app.debug' => false]);

        $validationErrors = ['email' => ['이메일 형식이 올바르지 않습니다.']];
        $response = ResponseHelper::error('messages.validation_failed', 422, $validationErrors);
        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
        $this->assertEquals($validationErrors, $data['errors']);
        $this->assertEquals(422, $response->getStatusCode());
    }

    /**
     * error() + string + 400 + debug=false → errors 존재 (500 미만은 guard 미적용)
     */
    public function test_error_with_string_400_in_production_shows_errors(): void
    {
        config(['app.debug' => false]);

        $response = ResponseHelper::error('messages.failed', 400, 'Bad request detail');
        $data = $response->getData(true);

        $this->assertArrayHasKey('errors', $data);
        $this->assertEquals('Bad request detail', $data['errors']);
    }

    /**
     * serverError() + \Throwable + debug=true → message + debug 상세
     */
    public function test_server_error_with_throwable_in_debug_mode(): void
    {
        config(['app.debug' => true]);

        $exception = new \InvalidArgumentException('Invalid config value');
        $response = ResponseHelper::serverError('messages.error_occurred', $exception);
        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Invalid config value', $data['message']);
        $this->assertArrayHasKey('debug', $data);
        $this->assertEquals(\InvalidArgumentException::class, $data['debug']['exception']);
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * serverError() + \Throwable + debug=false → 번역 메시지만
     */
    public function test_server_error_with_throwable_in_production(): void
    {
        config(['app.debug' => false]);

        $exception = new \InvalidArgumentException('Sensitive config detail');
        $response = ResponseHelper::serverError('messages.error_occurred', $exception);
        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertStringNotContainsString('Sensitive config detail', $data['message']);
        $this->assertArrayNotHasKey('debug', $data);
        $this->assertArrayNotHasKey('error', $data);
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * moduleError() + \Throwable → error() 위임 동작 확인 (domain 전달)
     */
    public function test_module_error_with_throwable_delegates_to_error(): void
    {
        config(['app.debug' => true]);

        $exception = new \RuntimeException('Module specific error');
        $response = ResponseHelper::moduleError(
            'sirsoft-ecommerce',
            'messages.error_occurred',
            500,
            $exception
        );
        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Module specific error', $data['message']);
        $this->assertArrayHasKey('debug', $data);
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * serverError() + string + debug=true → 기존 동작 유지 (error 키에 포함)
     */
    public function test_server_error_with_string_in_debug_mode_keeps_legacy_behavior(): void
    {
        config(['app.debug' => true]);

        $response = ResponseHelper::serverError('messages.error_occurred', 'Some error string');
        $data = $response->getData(true);

        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Some error string', $data['error']);
        $this->assertArrayNotHasKey('debug', $data);
    }

    /**
     * error() + \Throwable + messageParams + debug=true → message 중복 방지 + debug 존재
     */
    public function test_error_with_throwable_and_message_params_no_duplicate(): void
    {
        config(['app.debug' => true]);

        $exception = new \RuntimeException('DB connection failed');
        $response = ResponseHelper::error(
            'user.create_failed',
            500,
            $exception,
            ['error' => $exception->getMessage()]
        );
        $data = $response->getData(true);

        // messageParams가 있으면 message에 예외 메시지를 중복 concatenate 하지 않음
        $this->assertArrayHasKey('debug', $data);
        $this->assertEquals('DB connection failed', $data['debug']['message']);
        // :error가 messageParams로 치환되고, Throwable concatenate는 생략
        $this->assertStringContainsString('DB connection failed', $data['message']);
        $this->assertSame(
            1,
            substr_count($data['message'], 'DB connection failed')
        );
    }

    /**
     * error() + null errors → errors/debug 키 모두 미존재
     */
    public function test_error_with_null_errors_has_no_extra_keys(): void
    {
        $response = ResponseHelper::error('messages.failed', 400);
        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertArrayNotHasKey('debug', $data);
    }

    /**
     * formatException trace는 최대 10프레임
     */
    public function test_debug_trace_is_limited_to_10_frames(): void
    {
        config(['app.debug' => true]);

        // 깊은 호출 스택을 만들기 위해 재귀 예외 생성
        $exception = $this->createDeepException(20);
        $response = ResponseHelper::error('messages.failed', 500, $exception);
        $data = $response->getData(true);

        $this->assertArrayHasKey('debug', $data);
        $this->assertLessThanOrEqual(10, count($data['debug']['trace']));
    }

    /**
     * 깊은 호출 스택의 예외를 생성합니다.
     *
     * @param int $depth 호출 깊이
     * @return \RuntimeException
     */
    private function createDeepException(int $depth): \RuntimeException
    {
        if ($depth <= 0) {
            return new \RuntimeException('Deep exception');
        }

        return $this->createDeepException($depth - 1);
    }

    /**
     * 회귀: ResponseHelper::trans() 가 App::getLocale() 결과를 그대로 사용한다.
     *
     * 이전 구현은 자체 화이트리스트 ['ko', 'en'] 으로 ja 를 거부하고
     * config('app.locale') fallback 으로 떨어뜨렸다. 이 회귀 테스트는
     * SetLocale 미들웨어가 이미 set 한 App::getLocale() 결과(ja 등)가
     * 다국어 응답에 반영됨을 보장한다.
     */
    public function test_trans_uses_current_app_locale_not_hardcoded_whitelist(): void
    {
        // 임시 키를 ko/ja 양쪽에 등록 (lang/ja/* 는 활성 언어팩이 폴백 등록)
        App::setLocale('ja');
        app('translator')->addLines(['testdomain.greet' => 'こんにちは'], 'ja');
        app('translator')->addLines(['testdomain.greet' => '안녕'], 'ko');

        $response = ResponseHelper::success('testdomain.greet');
        $data = $response->getData(true);

        $this->assertSame('こんにちは', $data['message']);
    }

    /**
     * 회귀: App::getLocale() 가 빌트인 ko/en 외 로케일이어도 ResponseHelper 가 그 값을 신뢰한다.
     */
    public function test_trans_respects_non_builtin_locale_when_app_locale_set(): void
    {
        App::setLocale('zh-CN');
        app('translator')->addLines(['testdomain.welcome' => '欢迎'], 'zh-CN');

        $response = ResponseHelper::success('testdomain.welcome');
        $data = $response->getData(true);

        $this->assertSame('欢迎', $data['message']);
    }
}
