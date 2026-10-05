<?php

namespace App\Http\Resources;

use App\Enums\UserStatus;
use App\Http\Resources\Traits\ExposesMemberContact;
use Illuminate\Http\Request;

/**
 * 관리자 회원 알림함 행 리소스
 *
 * “누가 무엇을 받았고 읽었는가” 가 행의 주어다. 제목·본문·링크는 Laravel 표준
 * notifications 테이블의 `data` JSON 안에 있다(GenericNotification::toArray 가 넣는다).
 *
 * 본문은 목록용으로 잘라 싣는다 — 전문을 실으면 한 쪽 응답이 알림 수에 비례해 무거워지고,
 * 목록에서 필요한 것은 "무슨 알림인지 알아볼 만큼" 이다.
 */
class MemberNotificationResource extends BaseApiResource
{
    use ExposesMemberContact;

    /**
     * 알림 종류 → 표시 라벨 매핑 (컬렉션이 한 번만 조회해 주입한다).
     *
     * @var array<string, string>
     */
    private array $typeLabelMap = [];

    /**
     * 종류 라벨 맵을 주입합니다.
     *
     * 행마다 정의 저장소를 조회하면 목록 한 쪽이 행 수만큼 조회를 부른다 —
     * 컬렉션이 한 번 읽어 모든 행에 나눠 준다.
     *
     * @param  array<string, string>  $map  종류 → 라벨 매핑
     * @return $this 같은 리소스(이어 호출용)
     */
    public function withTypeLabelMap(array $map): static
    {
        $this->typeLabelMap = $map;

        return $this;
    }

    /**
     * 행 권한 매핑 — 행 메뉴 「삭제」는 행의 abilities.can_delete 로 켜고 끈다(DataGrid disabledField).
     *
     * @return array<string, string> ability 키 → 권한 식별자
     */
    protected function abilityMap(): array
    {
        return [
            'can_delete' => 'core.member-notifications.delete',
        ];
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 알림함 행
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : (json_decode((string) $this->data, true) ?? []);
        $member = $this->resolveMember($request);

        // 어떤 알림인지는 `data.type`(알림 정의 키)에 있다 — `type` 열은 모든 행이 같은 알림 클래스명이다.
        // 회원 화면의 알림 목록(UserNotificationResource)과 같은 순서로 고른다.
        $type = $data['type'] ?? $this->type;

        return [
            'id' => $this->id,
            'member' => $member,
            'type' => $type,
            'type_label' => $this->typeLabelMap[$type] ?? $type,
            'subject' => $data['subject'] ?? null,
            'body_excerpt' => $this->excerpt($data['body'] ?? null),
            'url' => $data['click_url'] ?? null,
            'read_at' => $this->formatDateTimeStringForUser($this->read_at),
            'is_read' => $this->read_at !== null,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 수신 회원 블록을 구성합니다 (탈퇴 회원은 이름 익명화).
     *
     * 다형 관계라 회원이 이미 지워졌을 수 있다 — 그때는 null 로 두고 화면이 "삭제된 회원"
     * 으로 그린다(행 자체를 감추면 정리 대상을 못 찾는다).
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed>|null 회원 정보
     */
    private function resolveMember(Request $request): ?array
    {
        $member = $this->relationLoaded('notifiable') ? $this->notifiable : null;

        if ($member === null) {
            return null;
        }

        $isWithdrawn = UserStatus::tryFrom((string) $member->status) === UserStatus::Withdrawn;

        return [
            'uuid' => $member->uuid,
            'name' => $isWithdrawn ? __('user.withdrawn_user') : $member->name,
            'email' => $this->canExposeMemberEmail($request, (int) $member->id) ? $member->email : null,
            'is_withdrawn' => $isWithdrawn,
        ];
    }

    /**
     * 본문을 목록용으로 잘라 돌려줍니다.
     *
     * @param  string|null  $body  본문
     * @return string|null 요약 본문
     */
    private function excerpt(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');

        return mb_strlen($plain) > 120 ? mb_substr($plain, 0, 120).'…' : $plain;
    }
}
