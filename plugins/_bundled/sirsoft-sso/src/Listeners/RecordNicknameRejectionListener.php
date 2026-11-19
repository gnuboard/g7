<?php

namespace Plugins\Sirsoft\Sso\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Sso\Services\NicknameRejectionLedger;

/**
 * 코어가 닉네임 정책(중복 금지, 금지어)에 걸려 버린 닉네임의 사유를 장부에 적습니다.
 *
 * 회사 계정의 속성 동기화와 자동 생성은 사람이 화면을 보지 않으므로, 걸린 닉네임만 빼고 나머지를 저장하게 한다
 * (`nickname_policy => skip_field`). 코어는 버린 사실을 액션 `core.user.nickname_rejected` 로만 알린다 —
 * 이 리스너가 사유를 장부에 적고, 동기화 서비스가 꺼내 자기 기록(활동 로그 `sso.attributes_synced`)에 싣는다.
 *
 * @since 1.0.0
 */
class RecordNicknameRejectionListener implements HookListenerInterface
{
    /**
     * @param  NicknameRejectionLedger  $ledger  버린 닉네임 사유 장부
     */
    public function __construct(private readonly NicknameRejectionLedger $ledger) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.nickname_rejected' => ['method' => 'onRejected', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 버린 닉네임의 사유를 장부에 적습니다.
     *
     * @param  mixed  ...$args  훅 인자 (User|null $user, string $nickname, string $reason, array $context)
     * @return void
     */
    public function onRejected(...$args): void
    {
        $user = $args[0] ?? null;
        $reason = $args[2] ?? null;

        if ($user instanceof User && is_string($reason) && $reason !== '') {
            $this->ledger->record((int) $user->getKey(), $reason);
        }
    }
}
