<?php

namespace Tests\Unit\Extension;

use App\Extension\Traits\ValidatesLayoutFiles;
use Tests\TestCase;

/**
 * 레이아웃 검증 실패 문구 테스트 (#712 W1 · F1a).
 *
 * 7.1.0 실측에서 세 결함이 함께 드러났다 — 템플릿 실패 첫 줄이 원시 키(`templates.errors.layout_validation_validation_failed`),
 * 모듈 실패 문구 끝에 치환되지 않은 `:error`, 플러그인 실패가 "모듈" 로 표기되고 `plugins.errors.layout_validation_markup_error`
 * 키가 없었다. 실패 목록은 요약 문구 뒤에 줄바꿈으로 붙으므로 요약 문구는 `:error` 를 받지 않는다.
 *
 * @see ValidatesLayoutFiles::formatLayoutValidationError()
 */
class LayoutValidationMessageTest extends TestCase
{
    private object $harness;

    /**
     * 테스트 준비 — 문구 포맷 대역을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = new class
        {
            use ValidatesLayoutFiles;

            /**
             * 검증 실패 문구를 만듭니다.
             *
             * @param  string  $sourceType  소스 타입
             * @param  string  $errorType  오류 종류
             * @param  array<string, mixed>  $params  치환 값
             * @return string 문구
             */
            public function format(string $sourceType, string $errorType, array $params): string
            {
                return $this->formatLayoutValidationError($sourceType, $errorType, $params);
            }
        };
    }

    /**
     * 세 소스 타입 모두 요약 문구에 원시 키·미치환 자리표시자가 없다.
     *
     * @scenario case=validation_message_summary
     *
     * @effects validation_failed_summary_has_no_raw_key_or_placeholder
     *
     * @return void 반환값 없음
     */
    public function test_validation_failed_summary_has_no_raw_key_or_placeholder(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $this->app->setLocale($locale);

            foreach (['template', 'module', 'plugin'] as $type) {
                $message = $this->harness->format($type, 'validation_failed', ['identifier' => 'probe', 'count' => 2]);

                $this->assertStringNotContainsString('.errors.', $message, "[{$locale}/{$type}] 원시 키: {$message}");
                $this->assertDoesNotMatchRegularExpression('/:[a-z_]+/', $message, "[{$locale}/{$type}] 미치환 자리표시자: {$message}");
                $this->assertStringContainsString('probe', $message, "[{$locale}/{$type}] 식별자가 빠졌다");
                $this->assertStringContainsString('2', $message, "[{$locale}/{$type}] 오류 수가 빠졌다");
            }
        }
    }

    /**
     * 플러그인 실패는 플러그인으로 표기되고, 모듈 문구를 빌려 쓰지 않는다.
     *
     * @scenario case=validation_message_plugin_label
     *
     * @effects plugin_validation_failure_is_labelled_plugin
     *
     * @return void 반환값 없음
     */
    public function test_plugin_failure_is_labelled_plugin(): void
    {
        $this->app->setLocale('ko');
        $this->assertStringContainsString('플러그인', $this->harness->format('plugin', 'validation_failed', ['identifier' => 'probe', 'count' => 1]));
        $this->assertStringNotContainsString('모듈', $this->harness->format('plugin', 'validation_failed', ['identifier' => 'probe', 'count' => 1]));

        $this->app->setLocale('en');
        $this->assertStringContainsStringIgnoringCase('plugin', $this->harness->format('plugin', 'validation_failed', ['identifier' => 'probe', 'count' => 1]));
    }

    /**
     * 각 소스 타입의 개별 오류 키(마크업 컴파일 포함)가 모두 정의돼 있다.
     *
     * @scenario case=validation_message_kinds
     *
     * @effects every_layout_error_kind_has_a_translation_per_source_type
     *
     * @return void 반환값 없음
     */
    public function test_every_error_kind_is_translated(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $this->app->setLocale($locale);

            foreach (['template', 'module', 'plugin'] as $type) {
                foreach (['json_error', 'missing_name', 'partial_error', 'markup_error', 'validation_failed'] as $kind) {
                    $message = $this->harness->format($type, $kind, ['file' => 'f.json', 'error' => 'boom', 'partial' => 'p.json', 'identifier' => 'probe', 'count' => 1]);

                    $this->assertStringNotContainsString('layout_validation_', $message, "[{$locale}/{$type}/{$kind}] 키가 정의돼 있지 않다");
                }
            }
        }
    }
}
