<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\ValidLayoutExtensionStructure;
use Tests\TestCase;

/**
 * `ValidLayoutExtensionStructure` 의 overlay 기여 판정 회귀.
 *
 * overlay fragment 는 `injections` 없이 `modals` 만으로도 성립한다 — 런타임이 그렇게 동작하고
 * ([LayoutExtensionService::255-259](app/Services/LayoutExtensionService.php#L255)),
 * 번들 결제 플러그인 2종이 실제로 그 형태로 출하 중이다.
 */
final class ValidLayoutExtensionStructureTest extends TestCase
{
    /**
     * 규칙을 돌려 실패 메시지를 모읍니다.
     *
     * @param  array<string, mixed>  $value  확장 정의
     * @return array<int, string> 실패 메시지 목록
     */
    private function failures(array $value): array
    {
        $messages = [];

        (new ValidLayoutExtensionStructure)->validate(
            'content',
            $value,
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        return $messages;
    }

    /**
     * modals 만 기여하는 overlay fragment 는 유효하다.
     *
     * @scenario compile_unit=validator,source_health=valid
     *
     * @effects overlay_fragment_valid_with_modals_only
     */
    public function test_overlay_with_only_modals_is_valid(): void
    {
        $this->assertSame([], $this->failures([
            'target_layout' => 'checkout',
            'modals' => [['id' => 'pay_error', 'components' => []]],
        ]));
    }

    /**
     * 아무것도 기여하지 않는 overlay fragment 는 거부한다 (대조군 — 규칙이 무력화되지 않았음).
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects overlay_fragment_rejected_when_contributes_nothing
     */
    public function test_overlay_contributing_nothing_is_rejected(): void
    {
        $this->assertNotSame([], $this->failures([
            'target_layout' => 'checkout',
        ]));
    }

    /**
     * injections 가 배열이 아니면 종전대로 거부한다 (대조군).
     *
     * @scenario compile_unit=validator,source_health=invalid
     *
     * @effects overlay_fragment_rejected_when_injections_not_array
     */
    public function test_overlay_with_non_array_injections_is_rejected(): void
    {
        $this->assertNotSame([], $this->failures([
            'target_layout' => 'checkout',
            'injections' => 'nope',
        ]));
    }
}
