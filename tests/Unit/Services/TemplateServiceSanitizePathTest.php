<?php

namespace Tests\Unit\Services;

use App\Services\TemplateService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TemplateService::sanitizePath() 경로 정제 회귀 테스트 (이슈 #486 인접 결함 ③).
 *
 * 결함: `str_replace(['../', '..\\'], '', $path)` 1회성 치환은 제거 자체가 새 패턴을
 * 만들어낸다. `....//` 는 가운데 `../` 가 제거되면서 `../` 로 복원된다.
 *
 * FormRequest 의 realpath 검사가 앞단에서 막고 있어 현재 악용은 불가하지만,
 * 다층 방어의 한 계층이 무력한 상태였으므로 교정한다.
 */
class TemplateServiceSanitizePathTest extends TestCase
{
    /**
     * sanitizePath() 를 리플렉션으로 호출합니다.
     *
     * @param  string  $path  정제할 경로
     * @return string 정제 결과
     */
    private function sanitize(string $path): string
    {
        $method = new ReflectionMethod(TemplateService::class, 'sanitizePath');
        $method->setAccessible(true);

        return $method->invoke(app(TemplateService::class), $path);
    }

    /**
     * 중첩 패턴이 탈출 시퀀스를 복원하지 않아야 한다.
     *
     * 수정 전에는 `....//` → `../` 로 복원되어 이 단언이 실패한다.
     */
    public function test_중첩_패턴이_상위_경로_시퀀스를_복원하지_않는다(): void
    {
        $payloads = [
            '....//',
            '....//....//etc/passwd',
            '....\\\\',
            '..../\\',
            '....//...././/config.json',
        ];

        foreach ($payloads as $payload) {
            $result = $this->sanitize($payload);

            $this->assertStringNotContainsString('../', $result, "상위 경로 시퀀스 잔존: {$payload} → {$result}");
            $this->assertStringNotContainsString('..\\', $result, "상위 경로 시퀀스 잔존: {$payload} → {$result}");
        }
    }

    /**
     * 단순 상위 경로 패턴은 기존대로 제거되어야 한다.
     */
    public function test_단순_상위_경로_패턴을_제거한다(): void
    {
        $this->assertStringNotContainsString('../', $this->sanitize('../../.env'));
        $this->assertStringNotContainsString('..\\', $this->sanitize('..\\..\\.env'));
    }

    /**
     * 절대 경로 선행 구분자를 제거해야 한다.
     */
    public function test_절대_경로_선행_구분자를_제거한다(): void
    {
        $this->assertSame('js/a.js', $this->sanitize('/js/a.js'));
        $this->assertSame('js/a.js', $this->sanitize('\\js/a.js'));
    }

    /**
     * 정상 경로는 변형하지 않아야 한다.
     *
     * 과잉 정제로 멀쩡한 자산 경로가 깨지면 안 된다.
     */
    public function test_정상_경로는_변형하지_않는다(): void
    {
        $this->assertSame('js/components.iife.js', $this->sanitize('js/components.iife.js'));
        $this->assertSame('css/components.css', $this->sanitize('css/components.css'));
        $this->assertSame('fonts/a.woff2', $this->sanitize('fonts/a.woff2'));
        // 파일명에 포함된 점 두 개는 경로 탈출이 아니므로 보존
        $this->assertSame('js/a..b.js', $this->sanitize('js/a..b.js'));
    }
}
