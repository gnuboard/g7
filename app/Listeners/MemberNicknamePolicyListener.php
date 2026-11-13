<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Jobs\RecomputeNicknameUniqueKeysJob;
use Illuminate\Support\Facades\DB;

/**
 * 닉네임 중복 금지 설정이 바뀌면 회원의 닉네임 중복 키를 다시 계산하게 하는 리스너.
 *
 * 환경설정 > 회원 저장에서 「중복 금지」 값이 실제로 바뀐 경우에만 큐 작업을 접수한다.
 * 바뀌었는지는 저장 전 값(`core.settings.before_save` 에서 떠 둔 사본)과 견줘 정한다.
 *
 * @since 7.2.0
 */
class MemberNicknamePolicyListener implements HookListenerInterface
{
    /**
     * 저장 전의 중복 금지 값 (이 저장 한 번에만 쓴다)
     */
    private static ?bool $uniqueBefore = null;

    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.settings.before_save' => ['method' => 'onBeforeSettingsSave', 'priority' => 20, 'sync' => true],
            'core.settings.after_save' => ['method' => 'onSettingsSave', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러 (사용하지 않는다 — 훅마다 메서드를 지정했다)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 저장 전의 중복 금지 값을 떠 둡니다.
     *
     * @param  mixed  ...$args  훅 인자 ($settings)
     * @return void
     */
    public function onBeforeSettingsSave(...$args): void
    {
        $settings = is_array($args[0] ?? null) ? $args[0] : [];

        self::$uniqueBefore = ($settings['_tab'] ?? null) === 'member'
            ? (bool) g7_core_settings('member.nickname_unique', false)
            : null;
    }

    /**
     * 회원 탭 저장에서 중복 금지 값이 바뀌었으면 재계산 작업을 접수합니다.
     *
     * @param  mixed  ...$args  훅 인자 ($tab, $settings, $result)
     * @return void
     */
    public function onSettingsSave(...$args): void
    {
        $before = self::$uniqueBefore;
        self::$uniqueBefore = null;

        if (($args[0] ?? null) !== 'member' || ($args[2] ?? null) === false || $before === null) {
            return;
        }

        $saved = is_array($args[1] ?? null) ? $args[1] : [];
        if (! array_key_exists('nickname_unique', $saved) || (bool) $saved['nickname_unique'] === $before) {
            return;
        }

        // 설정이 커밋된 뒤에 접수한다 — 작업은 실행 시점의 설정 값을 읽는다.
        DB::afterCommit(static fn () => RecomputeNicknameUniqueKeysJob::dispatch());
    }
}
