<?php

namespace App\Services;

use App\Contracts\Notifications\GuestRecipientInterface;
use App\Models\User;

/**
 * 알림 수신자 권한 판정 서비스
 *
 * "이 수신자에게 관리자 화면 주소를 줘도 되는가" 처럼, 이미 결정된 한 수신자를 놓고
 * 권한 보유 여부만 묻는 지점이 쓰는 협력자입니다.
 *
 * **수신자 선정과는 다른 축입니다.** 수신자 선정은 역할 기준이고
 * {@see NotificationRecipientResolver} 가 소유합니다. 이 클래스는 선정이 끝난 뒤
 * 개인화(치환) 단계에서만 쓰입니다.
 *
 * 싱글턴으로 바인딩되어 요청 수명 동안 판정을 메모합니다 — 수신자가 많은 알림
 * (예: 관리자 역할 전원)에서 같은 권한을 사람 수만큼 재조회하지 않게 합니다.
 */
class NotificationRecipientCapability
{
    /**
     * 판정 캐시.
     *
     * @var array<string, array<int|string, bool>> [permission][userId] => bool
     */
    private array $memo = [];

    /**
     * 수신자가 해당 권한을 가졌는지 판정합니다.
     *
     * 게스트(비회원)는 언제나 false 입니다 — 관리자 화면에 접근할 계정 자체가 없습니다.
     *
     * @param  object  $notifiable  수신자 (User 또는 GuestNotifiable)
     * @param  string  $permission  권한 identifier
     * @return bool 권한 보유 여부
     */
    public function can(object $notifiable, string $permission): bool
    {
        if ($notifiable instanceof GuestRecipientInterface && $notifiable->isGuest()) {
            return false;
        }

        if (! $notifiable instanceof User) {
            return false;
        }

        $userId = $notifiable->getKey();
        if ($userId === null) {
            return false;
        }

        if (isset($this->memo[$permission][$userId])) {
            return $this->memo[$permission][$userId];
        }

        return $this->memo[$permission][$userId] = $notifiable->hasPermission($permission);
    }

    /**
     * 메모된 판정을 비웁니다 (테스트·장기 실행 워커용).
     *
     * @return void
     */
    public function flush(): void
    {
        $this->memo = [];
    }
}
