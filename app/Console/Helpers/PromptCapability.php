<?php

namespace App\Console\Helpers;

/**
 * 「이 실행이 프롬프트 응답을 받을 수 있는가」 판정의 시험용 주입 지점.
 *
 * `HasUnifiedConfirm::canPromptForAnswer()` 는 단위 테스트에서 늘 「받을 수 있음」을 돌려준다(테스트는 입력을 주입한다).
 * 그래서 「콘솔 없는 실행」(cron·CI·원격 명령) 갈래를 테스트로 잴 수 없었다. 테스트가 `fake(false)` 로 판정을 고정하고
 * `reset()` 으로 되돌린다. 운영 코드는 이 값을 쓰지 않는다(기본 null = 실제 판정).
 *
 * @since 7.2.0
 */
final class PromptCapability
{
    /**
     * 고정된 판정 (null 이면 실제 판정)
     */
    private static ?bool $override = null;

    /**
     * 판정을 고정합니다 — 테스트 전용.
     *
     * @param  bool  $canPrompt  응답을 받을 수 있으면 true
     * @return void 반환값 없음
     */
    public static function fake(bool $canPrompt): void
    {
        self::$override = $canPrompt;
    }

    /**
     * 고정을 풉니다.
     *
     * @return void 반환값 없음
     */
    public static function reset(): void
    {
        self::$override = null;
    }

    /**
     * 고정된 판정을 돌려줍니다.
     *
     * @return bool|null 고정값 (없으면 null)
     */
    public static function override(): ?bool
    {
        return self::$override;
    }
}
