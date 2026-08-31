<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 화면 스크립트의 **최후 폴백 문구**가 다국어 표를 거친다.
 *
 * `window.EXTENSION_LABELS` 가 실리지 않은 경로(뷰가 그 블록을 내보내기 전의 오류 · 다른 단계에서
 * 호출되는 공용 함수)에서는 그 뒤의 `||` 폴백이 화면에 그대로 나간다. 그 자리가 영문 리터럴이면
 * 한국어 화면에 개발자 문구가 뜬다 — 같은 키가 `INSTALLER_LANG` 에 이미 실려 있어 `lang()` 으로
 * 닿는다 (13차 G3 W1-13).
 */
class InstallerJsMessageFallbackTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /** @var array<string, string> 리터럴 → 대신 써야 하는 `lang()` 키 */
    private const LITERAL_FALLBACKS = [
        "'Failed to load extensions'" => 'extension_load_failed',
        "'Save failed'" => 'save_failed',
    ];

    /**
     * @effects installer_client_messages_are_translated
     */
    public function test_last_resort_fallbacks_go_through_the_translation_table(): void
    {
        // 주석 속 문구가 판정을 흔들지 않도록 주석을 걷어낸 소스로 대조한다.
        $js = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/assets/js/installer.js');

        foreach (self::LITERAL_FALLBACKS as $literal => $key) {
            $this->assertStringNotContainsString(
                $literal,
                $js,
                $literal.' 가 최후 폴백으로 남아 있다 — 한국어 화면에 영문 개발자 문구가 나간다'
            );
            $this->assertStringContainsString(
                "lang('".$key."')",
                $js,
                $key.' 키를 `lang()` 으로 읽는 폴백이 없다'
            );
        }

        // 그 키가 두 언어 표에 실제로 있어야 `lang()` 이 키 문자열을 그대로 돌려주지 않는다.
        foreach (['ko', 'en'] as $locale) {
            $table = require dirname(__DIR__, 3).'/public/install/lang/'.$locale.'.php';

            foreach (self::LITERAL_FALLBACKS as $key) {
                $this->assertArrayHasKey($key, $table, $locale.' 표에 '.$key.' 가 없다');
            }
        }
    }
}
