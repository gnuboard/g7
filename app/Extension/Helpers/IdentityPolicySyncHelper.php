<?php

namespace App\Extension\Helpers;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Extension\Helpers\Concerns\RecordsSyncDeletion;
use App\Listeners\Identity\EnforceIdentityPolicyListener;
use App\Models\IdentityPolicy;
use Illuminate\Support\Facades\Log;

/**
 * 본인인증 정책 동기화 Helper.
 *
 * 선언형 정의 시스템 — 알림의 NotificationSyncHelper 동형 패턴.
 * config/core.php.identity_policies 블록 + {벤더}IdentityPolicySeeder 가 이 Helper 를 호출한다.
 *
 * 운영자가 S1d UI 에서 수정한 필드는 user_overrides JSON 에 기록되며,
 * 재동기화 시 해당 필드는 갱신 대상에서 제외된다 (HasUserOverrides 공통 API).
 *
 * @since 7.0.0-beta.4
 */
class IdentityPolicySyncHelper
{
    use RecordsSyncDeletion;

    /**
     * @param  IdentityPolicyRepositoryInterface  $repository  정책 데이터 접근 Repository
     */
    public function __construct(
        protected IdentityPolicyRepositoryInterface $repository,
    ) {}

    /**
     * 정책을 동기화합니다 (user_overrides 보존 upsert).
     *
     * 신규: 생성
     * 기존: user_overrides 에 없는 필드만 업데이트
     *
     * @param  array<string, mixed>  $data  정책 데이터 (key/scope/target/purpose 등)
     * @return IdentityPolicy 동기화된 정책
     */
    public function syncPolicy(array $data): IdentityPolicy
    {
        $policy = IdentityPolicy::syncOrCreateFromUpgrade(
            ['key' => $data['key']],
            [
                'scope' => $data['scope'] ?? 'route',
                'target' => $data['target'] ?? $data['key'],
                'purpose' => $data['purpose'] ?? 'sensitive_action',
                'provider_id' => $data['provider_id'] ?? null,
                'grace_minutes' => (int) ($data['grace_minutes'] ?? 0),
                'enabled' => (bool) ($data['enabled'] ?? true),
                'priority' => (int) ($data['priority'] ?? 100),
                'conditions' => $data['conditions'] ?? null,
                'source_type' => $data['source_type'] ?? 'core',
                'source_identifier' => $data['source_identifier'] ?? 'core',
                'applies_to' => $data['applies_to'] ?? 'both',
                'fail_mode' => $data['fail_mode'] ?? 'block',
            ]
        );

        // scope=hook 정책이 새로 적재되면 그 target 에 enforce 구독을 멱등 (재)바인딩한다.
        // 코어 리스너 자동발견은 부팅 전반부에 1회만 일어나 그 시점에 없던 모듈 hook target 을
        // 놓치므로, 정책이 DB 에 적재되는 이 시점에 보충한다(이미 바인딩된 target 은 멱등 스킵).
        if (($policy->scope instanceof \BackedEnum ? $policy->scope->value : $policy->scope) === 'hook') {
            EnforceIdentityPolicyListener::syncDynamicHookSubscriptions();
        }

        return $policy;
    }

    /**
     * seed/정의에 없는 stale 정책을 삭제합니다 (완전 동기화 원칙).
     *
     * 운영자가 S1d 에서 직접 생성한 정책(source_type='admin')은 영향받지 않습니다.
     *
     * @param  string  $sourceType  확장 타입 (core|module|plugin)
     * @param  string  $sourceIdentifier  확장 식별자
     * @param  array<int, string>  $currentKeys  현재 유효한 정책 key 목록
     * @return int 삭제된 정책 수
     */
    public function cleanupStalePolicies(
        string $sourceType,
        string $sourceIdentifier,
        array $currentKeys,
    ): int {
        // 검수 서버(stage)는 공유 DB 의 선언 항목을 지우지 않고 대장에 미룬다 — 운영 서버가 extension:apply-deferred-cleanup 으로 같은 규칙대로 지운다
        if (DeferredDeclarativeCleanup::shouldDefer()) {
            return DeferredDeclarativeCleanup::defer($this, __FUNCTION__, func_get_args());
        }

        $targets = $this->repository->findStale($sourceType, $sourceIdentifier, $currentKeys);
        foreach ($targets as $policy) {
            // 운영자가 고친 정책이면 지우기 직전 기록
            if ($this->hasOperatorOverrides($policy)) {
                $this->recordSyncDeletion('identity_policy', (string) $policy->key, $sourceType, $sourceIdentifier, ['policy' => $policy->toArray()]);
            }

            // per-model delete — deleted 이벤트로 라우트 스코프 캐시가 flush 된다(bulk delete 와 차이).
            $policy->delete();
        }

        $count = $targets->count();
        if ($count > 0) {
            Log::info('IdentityPolicySyncHelper: stale 정책 정리', [
                'source_type' => $sourceType,
                'source_identifier' => $sourceIdentifier,
                'deleted_count' => $count,
                'deleted_keys' => $targets->pluck('key')->all(),
            ]);
        }

        return $count;
    }

    /**
     * 지워질 stale 정책을 미리 계산합니다 — 쓰기 없음, 실제 정리와 같은 조회식.
     *
     * @param  string  $sourceType  출처 종류
     * @param  string  $sourceIdentifier  출처 식별자
     * @param  array<int, string>  $currentKeys  새 판이 선언한 정책 키
     * @return list<array{type: string, key: string, has_operator_overrides: bool}> 삭제 예정
     */
    public function planCleanup(string $sourceType, string $sourceIdentifier, array $currentKeys): array
    {
        return $this->repository->findStale($sourceType, $sourceIdentifier, $currentKeys)
            ->map(fn ($policy) => ['type' => 'identity_policy', 'key' => (string) $policy->key, 'has_operator_overrides' => $this->hasOperatorOverrides($policy)])
            ->values()
            ->all();
    }
}
