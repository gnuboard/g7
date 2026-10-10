<?php

namespace App\Services;

use App\Contracts\Notifications\GuestRecipientInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use App\Notifications\GuestNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 알림 수신자 해석 서비스
 *
 * recipients JSON 규칙 배열을 해석하여
 * 최종 수신자 컬렉션(회원 User + 비회원 GuestNotifiable)을 반환합니다.
 *
 * 수신자 타입:
 * - trigger_user: 이벤트를 유발한 사용자 (주문자, 가입자 등). 회원이면 User,
 *   비회원이면 컨텍스트의 표준 키 guest_recipient 로 GuestNotifiable 생성.
 * - related_user: 관련 사용자 (문의 답변 → 문의 작성자)
 * - role: 특정 역할의 사용자들 (admin, manager 등)
 * - permission: 특정 권한을 가진 역할에 소속된 사용자들 (예: sirsoft-board.reports.manage)
 * - specific_users: 지정된 사용자 UUID 목록
 *
 * 비회원 수신자는 user_id 없이 이메일/이름/로케일만 가진 1급 Notifiable 로,
 * 회원과 동일한 발송 경로($notifiable->notify())를 공유합니다.
 */
class NotificationRecipientResolver
{
    /**
     * @param  UserRepositoryInterface  $userRepository  사용자 조회 Repository
     * @param  RoleRepositoryInterface  $roleRepository  역할 조회 Repository
     * @param  NotificationWithdrawnRecipientPolicy  $withdrawnPolicy  탈퇴·삭제 회원 수신 정책
     */
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly RoleRepositoryInterface $roleRepository,
        private readonly NotificationWithdrawnRecipientPolicy $withdrawnPolicy,
    ) {}

    /**
     * recipients 규칙 배열을 해석하여 수신자 목록을 반환합니다.
     *
     * @param  array  $rules  수신자 규칙 배열
     * @param  array  $context  컨텍스트 데이터 (trigger_user_id, guest_recipient, related_users 등)
     * @return Collection<int, object> 회원(User) + 비회원(GuestNotifiable) 혼합 가능
     */
    public function resolve(array $rules, array $context): Collection
    {
        if (empty($rules)) {
            return collect();
        }

        $recipients = collect();
        $triggerUserId = $context['trigger_user_id'] ?? null;

        foreach ($rules as $rule) {
            $type = $rule['type'] ?? null;

            // 규칙마다 별도 바구니에 담는다 — exclude_trigger_user 는 **그 규칙이 뽑은 사람에게만**
            // 적용되어야 한다. 전체 수신자에 적용하면 `[{role:admin, exclude:true},{trigger_user}]`
            // 같은 혼합 규칙에서 고객까지 지워지고, 그 사실이 어디에도 드러나지 않는다.
            $ruleRecipients = collect();

            try {
                match ($type) {
                    'trigger_user' => $this->addTriggerUser($ruleRecipients, $context),
                    'related_user' => $this->addRelatedUser($ruleRecipients, $context, $rule),
                    'role' => $this->addByRole($ruleRecipients, $rule),
                    'permission' => $this->addByPermission($ruleRecipients, $rule),
                    'specific_users' => $this->addSpecificUsers($ruleRecipients, $rule),
                    default => Log::warning('NotificationRecipientResolver: 알 수 없는 수신자 타입', [
                        'type' => $type,
                    ]),
                };
            } catch (\Throwable $e) {
                Log::error('NotificationRecipientResolver: 수신자 해석 실패', [
                    'rule_type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }

            // exclude_trigger_user 적용 (회원 유발자 기준 — 게스트는 user_id 가 없어 자연 비대상)
            if ($triggerUserId && ! empty($rule['exclude_trigger_user'])) {
                $ruleRecipients = $ruleRecipients->reject(
                    fn (object $recipient) => isset($recipient->id) && $recipient->id === $triggerUserId
                );
            }

            $recipients = $recipients->concat($ruleRecipients);
        }

        // 중복 제거: 회원(user:{id})과 비회원(guest:{hash}) 키 네임스페이스를 분리해
        // null-id 게스트가 하나로 뭉개지는 것을 방지한다.
        return $recipients->unique(fn (object $recipient) => $this->recipientDedupKey($recipient))->values();
    }

    /**
     * 수신자 중복 제거 키를 반환합니다.
     *
     * 회원은 `user:{id}`, 비회원(게스트)은 `guest:{이메일 해시}` 네임스페이스를 사용해
     * 서로 충돌하지 않게 합니다.
     *
     * @param  object  $recipient  수신자 (User 또는 GuestNotifiable)
     * @return string 중복 제거 키
     */
    private function recipientDedupKey(object $recipient): string
    {
        if ($recipient instanceof GuestRecipientInterface && $recipient->isGuest()) {
            return (string) $recipient->getKey();
        }

        return 'user:'.($recipient->id ?? spl_object_id($recipient));
    }

    /**
     * 이벤트 유발자를 수신자에 추가합니다.
     *
     * 회원이면 trigger_user_id 로 User 를, 비회원이면 컨텍스트의 표준 키
     * guest_recipient({email, name, locale})로 GuestNotifiable 을 생성해 추가합니다.
     * 템플릿의 {"type":"trigger_user"} 규칙이 회원/비회원 분기 없이 동작합니다.
     *
     * 회원이 지목된 사건에서 그 계정이 삭제되어 없으면 **아무에게도 보내지 않습니다** —
     * 게스트 재료가 함께 실려 있어도 그 주소로 대신 보내지 않습니다
     * ({@see NotificationWithdrawnRecipientPolicy::allowsGuestFallback()}).
     *
     * @param  Collection  $recipients  수신자 컬렉션
     * @param  array  $context  컨텍스트
     * @return void
     */
    private function addTriggerUser(Collection $recipients, array $context): void
    {
        if (! $this->withdrawnPolicy->allowsGuestFallback($context)) {
            $user = $this->withdrawnPolicy->findTriggerMember($context);
            if ($user) {
                $recipients->push($user);
            }

            return;
        }

        // 비회원: 회원이 지목되지 않은 사건만 표준 guest_recipient 키로 1급 게스트 수신자 생성
        $guest = $context['guest_recipient'] ?? null;
        if (is_array($guest) && ($notifiable = GuestNotifiable::fromContext($guest)) !== null) {
            $recipients->push($notifiable);
        }
    }

    /**
     * 관련 사용자를 수신자에 추가합니다.
     *
     * @param  Collection  $recipients  수신자 컬렉션
     * @param  array  $context  컨텍스트
     * @param  array  $rule  수신자 규칙 (relation 키 필요)
     * @return void
     */
    private function addRelatedUser(Collection $recipients, array $context, array $rule): void
    {
        $relation = $rule['relation'] ?? null;
        if (! $relation) {
            return;
        }

        $relatedUsers = $context['related_users'] ?? [];
        $user = $relatedUsers[$relation] ?? null;

        if ($user instanceof User) {
            $recipients->push($user);
        } elseif ($user instanceof Collection) {
            $recipients->push(...$user->all());
        }
    }

    /**
     * 역할 기반 수신자를 추가합니다.
     *
     * @param  Collection  $recipients  수신자 컬렉션
     * @param  array  $rule  수신자 규칙 (value = role identifier)
     * @return void
     */
    private function addByRole(Collection $recipients, array $rule): void
    {
        // 값은 문자열 하나일 수도, 여러 개일 수도 있다. 저장된 기존 규칙은 **문자열**이고
        // 화면이 역할 다중 선택을 열면서 배열이 섞인다 — 여기서 스칼라만 받으면 배열이 들어온
        // 순간 TypeError 가 나고, `resolve()` 의 try/catch 가 그것을 삼켜 **수신자 0명**이 된다.
        // 화면에도 응답에도 아무 흔적이 없다(로그 한 줄뿐). 그래서 읽기에서 두 모양을 모두 받는다.
        $identifiers = array_filter(
            array_map(
                static fn ($value) => is_string($value) ? trim($value) : '',
                is_array($rule['value'] ?? null) ? $rule['value'] : [$rule['value'] ?? null],
            ),
            static fn (string $identifier): bool => $identifier !== '',
        );

        if ($identifiers === []) {
            return;
        }

        $roleUsers = $this->roleRepository->getUsersByIdentifiers(array_values($identifiers));

        // 역할 사용자가 없으면 superAdmin 폴백
        if ($roleUsers->isEmpty()) {
            $superAdmin = $this->userRepository->findSuperAdmin();
            if ($superAdmin) {
                $roleUsers = collect([$superAdmin]);
            }
        }

        $recipients->push(...$roleUsers->all());
    }

    /**
     * 특정 권한 보유 사용자를 수신자에 추가합니다.
     *
     * `rule.value` 에 지정된 권한 identifier 를 가진 역할에 소속된 모든 사용자를 수집합니다.
     * role 기반 수신자와 달리 "권한을 가진 자" 기준이므로 테스트에서 권한을 제거하면
     * 자동으로 수신자 목록에서 제외됩니다.
     *
     * @param  Collection  $recipients  수신자 컬렉션
     * @param  array  $rule  수신자 규칙 (value = permission identifier)
     * @return void
     */
    private function addByPermission(Collection $recipients, array $rule): void
    {
        $permissionIdentifier = $rule['value'] ?? null;
        if (! $permissionIdentifier) {
            return;
        }

        $users = $this->userRepository->findManyByPermissionIdentifier($permissionIdentifier);
        $recipients->push(...$users->all());
    }

    /**
     * 특정 사용자 UUID 목록을 수신자에 추가합니다.
     *
     * @param  Collection  $recipients  수신자 컬렉션
     * @param  array  $rule  수신자 규칙 (value = user UUID 배열)
     * @return void
     */
    private function addSpecificUsers(Collection $recipients, array $rule): void
    {
        $userIds = $rule['value'] ?? [];
        if (empty($userIds) || ! is_array($userIds)) {
            return;
        }

        $users = $this->userRepository->findManyByUuids($userIds);
        $recipients->push(...$users->all());
    }
}
