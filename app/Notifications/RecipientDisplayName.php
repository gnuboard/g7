<?php

namespace App\Notifications;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameResolver;
use App\Models\User;

/**
 * 알림에 싣는 회원 이름을 표시 이름으로 계산하는 한 곳.
 *
 * | 쓰임 | 영역 |
 * | --- | --- |
 * | 받는 사람 이름(`{recipient_name}`, 메일의 받는 사람 이름) | `core:notification:{채널}` (설정이 없으면 `core:notification`, 그 위는 사이트 기본) |
 * | 받는 사람이 아닌 다른 회원 이름(글쓴이, 댓글 작성자 등) | `core:notification` |
 * | 발송 이력에 남기는 받는 사람 이름 | `core:admin` (이력은 관리자 화면의 기록물이다) |
 *
 * 회원이 아닌 수신자(비회원 주문 알림의 임시 수신자 등)는 그 객체의 `name` 을 그대로 쓴다.
 *
 * @since 7.2.0
 */
class RecipientDisplayName
{
    /**
     * @param  DisplayNameResolver  $resolver  표시 이름 계산기
     * @param  DisplayNameAreaRegistry  $areas  영역 등록소
     */
    public function __construct(
        protected DisplayNameResolver $resolver,
        protected DisplayNameAreaRegistry $areas,
    ) {}

    /**
     * 채널의 표시 이름 영역 키를 반환합니다.
     *
     * 등록되지 않은 채널(목록에 없는 확장 채널, 채널을 모르는 경로)은 알림 공통 영역으로 계산한다.
     *
     * @param  string|null  $channel  채널 식별자 (예: mail, database)
     * @return string 영역 키
     */
    public function areaForChannel(?string $channel): string
    {
        if ($channel === null || $channel === '') {
            return DisplayNameAreaRegistry::AREA_NOTIFICATION;
        }

        $area = DisplayNameAreaRegistry::notificationChannelArea($channel);

        return $this->areas->has($area) ? $area : DisplayNameAreaRegistry::AREA_NOTIFICATION;
    }

    /**
     * 받는 사람의 이름을 그 채널의 표시 이름으로 반환합니다.
     *
     * @param  object  $notifiable  수신자 (User, 비회원 수신자, 그 밖의 notifiable)
     * @param  string|null  $channel  채널 식별자
     * @return string|null 이름 (없으면 null)
     */
    public function forChannel(object $notifiable, ?string $channel): ?string
    {
        if ($notifiable instanceof User) {
            return $this->resolver->resolve($notifiable, $this->areaForChannel($channel));
        }

        return $this->plainName($notifiable);
    }

    /**
     * 받는 사람이 아닌 다른 회원의 이름을 알림 공통 영역의 표시 이름으로 반환합니다.
     *
     * 자료 추출 리스너가 글쓴이, 댓글 작성자처럼 받는 사람과 다른 회원의 이름을 알림 데이터에 실을 때 쓴다.
     * 알림 데이터는 발송 시점에 한 번 만들어 모든 채널이 함께 쓰므로 채널별 영역이 아니라 공통 영역이다.
     *
     * @param  User|null  $user  회원
     * @param  string|null  $guestName  회원이 아닐 때 쓸 이름 (비회원 글쓴이 등)
     * @return string|null 이름 (없으면 null)
     */
    public function ofMember(?User $user, ?string $guestName = null): ?string
    {
        return $this->resolver->resolve($user, DisplayNameAreaRegistry::AREA_NOTIFICATION, ['guest_name' => $guestName]);
    }

    /**
     * 발송 이력에 남길 받는 사람 이름을 반환합니다 (그 시점의 관리자 영역 표시 이름).
     *
     * @param  object  $notifiable  수신자
     * @return string|null 이름 (없으면 null)
     */
    public function forLog(object $notifiable): ?string
    {
        if ($notifiable instanceof User) {
            return $this->resolver->resolve($notifiable, DisplayNameAreaRegistry::AREA_ADMIN);
        }

        return $this->plainName($notifiable);
    }

    /**
     * 회원 수신자 여럿의 표시 이름 계산에 필요한 값을 미리 읽어 둡니다 (수신자 묶음마다 한 번).
     *
     * @param  iterable<int, object>  $recipients  수신자 목록 (회원이 아닌 수신자는 건너뛴다)
     * @param  string|null  $channel  채널 식별자
     * @return void 반환값 없음
     */
    public function preload(iterable $recipients, ?string $channel): void
    {
        $users = [];
        foreach ($recipients as $recipient) {
            if ($recipient instanceof User) {
                $users[] = $recipient;
            }
        }

        if ($users !== []) {
            $this->resolver->preload($users, $this->areaForChannel($channel));
        }
    }

    /**
     * 회원이 아닌 수신자의 이름을 읽습니다.
     *
     * @param  object  $notifiable  수신자
     * @return string|null 이름 (없거나 글자가 아니면 null)
     */
    protected function plainName(object $notifiable): ?string
    {
        $name = $notifiable->name ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
