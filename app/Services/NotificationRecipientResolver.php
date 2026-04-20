<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 알림 수신자 해석 서비스
 *
 * recipients JSON 규칙 배열을 해석하여
 * 최종 수신자 User 컬렉션을 반환합니다.
 *
 * 수신자 타입:
 * - trigger_user: 이벤트를 유발한 사용자 (주문자, 가입자 등)
 * - related_user: 관련 사용자 (문의 답변 → 문의 작성자)
 * - role: 특정 역할의 사용자들 (admin, manager 등)
 * - specific_users: 지정된 사용자 UUID 목록
 */
class NotificationRecipientResolver
{
    /**
     * recipients 규칙 배열을 해석하여 수신자 목록을 반환합니다.
     *
     * @param array $rules 수신자 규칙 배열
     * @param array $context 컨텍스트 데이터 (trigger_user_id, related_users 등)
     * @return Collection<int, User>
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

            try {
                match ($type) {
                    'trigger_user' => $this->addTriggerUser($recipients, $context),
                    'related_user' => $this->addRelatedUser($recipients, $context, $rule),
                    'role' => $this->addByRole($recipients, $rule),
                    'specific_users' => $this->addSpecificUsers($recipients, $rule),
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
        }

        // exclude_trigger_user 규칙 적용
        if ($triggerUserId) {
            $shouldExclude = collect($rules)->contains(fn ($rule) => ! empty($rule['exclude_trigger_user']));
            if ($shouldExclude) {
                $recipients = $recipients->filter(fn (User $user) => $user->id !== $triggerUserId);
            }
        }

        return $recipients->unique('id')->values();
    }

    /**
     * 이벤트 유발자를 수신자에 추가합니다.
     *
     * @param Collection $recipients 수신자 컬렉션
     * @param array $context 컨텍스트
     * @return void
     */
    private function addTriggerUser(Collection $recipients, array $context): void
    {
        $triggerUserId = $context['trigger_user_id'] ?? null;
        if (! $triggerUserId) {
            return;
        }

        $user = $context['trigger_user'] ?? User::find($triggerUserId);
        if ($user) {
            $recipients->push($user);
        }
    }

    /**
     * 관련 사용자를 수신자에 추가합니다.
     *
     * @param Collection $recipients 수신자 컬렉션
     * @param array $context 컨텍스트
     * @param array $rule 수신자 규칙 (relation 키 필요)
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
     * @param Collection $recipients 수신자 컬렉션
     * @param array $rule 수신자 규칙 (value = role identifier)
     * @return void
     */
    private function addByRole(Collection $recipients, array $rule): void
    {
        $roleIdentifier = $rule['value'] ?? null;
        if (! $roleIdentifier) {
            return;
        }

        $role = Role::where('identifier', $roleIdentifier)->first();
        $roleUsers = $role ? $role->users()->get() : collect();

        // 역할 사용자가 없으면 superAdmin 폴백
        if ($roleUsers->isEmpty()) {
            $superAdmin = User::superAdmins()->first();
            if ($superAdmin) {
                $roleUsers = collect([$superAdmin]);
            }
        }

        $recipients->push(...$roleUsers->all());
    }

    /**
     * 특정 사용자 UUID 목록을 수신자에 추가합니다.
     *
     * @param Collection $recipients 수신자 컬렉션
     * @param array $rule 수신자 규칙 (value = user UUID 배열)
     * @return void
     */
    private function addSpecificUsers(Collection $recipients, array $rule): void
    {
        $userIds = $rule['value'] ?? [];
        if (empty($userIds) || ! is_array($userIds)) {
            return;
        }

        $users = User::whereIn('uuid', $userIds)->get();
        $recipients->push(...$users->all());
    }
}
