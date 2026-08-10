<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Enums\KcpDuplicateField;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\IdentityDuplicateException;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpDuplicateIdentityChecker;

/**
 * 가입 직전 동일인 중복 가입 차단 listener (권위 게이트).
 *
 * 흐름 (core.auth.before_register):
 *  1. 코어 AssertIdentityVerifiedBeforeRegister (priority 10) 가 토큰 검증 + consume
 *  2. 본 listener (priority 20) 가 같은 토큰으로 로그를 재조회 → metadata 의 di_hash/ci_hash 회수
 *     → 동일 hash record 가 있으면 IdentityDuplicateException
 *
 * 인증 시점(KcpIdentityProvider::verify)에도 동일 판정을 수행해 사용자에게 조기 안내하지만,
 * 실제 가입을 막는 최종 게이트는 본 listener 다. 두 지점은 KcpDuplicateIdentityChecker 를
 * 공유하므로 판정 규칙이 어긋나지 않는다.
 *
 * 운영자 settings:
 *  - `duplicate_block_enabled` (bool, 기본 true) — false 면 즉시 통과 (가족 휴대폰 공유 / B2B)
 *  - `duplicate_field` ('di' | 'ci', 기본 'di') — 동일인 판정 키 선택
 *
 * @since 1.0.0
 */
class AssertNoDuplicateKcpIdentity implements HookListenerInterface
{
    /** plugin 식별자 — g7_plugin_settings() 헬퍼 키 */
    protected const PLUGIN_IDENTIFIER = 'sirsoft-verification_nhnkcp';

    /**
     * @param  KcpIdentityLogQueryRepositoryInterface  $logQueryRepository  consumed_at 무관 verified 로그 조회
     * @param  KcpDuplicateIdentityChecker  $duplicateChecker  동일인 판정 Service
     */
    public function __construct(
        protected readonly KcpIdentityLogQueryRepositoryInterface $logQueryRepository,
        protected readonly KcpDuplicateIdentityChecker $duplicateChecker,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * priority 20 — 코어 AssertIdentityVerifiedBeforeRegister (priority 10) 직후.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.before_register' => [
                'method' => 'handle',
                'priority' => 20,
                'sync' => true, // 인라인 가드 — 실패 시 가입 즉시 중단
            ],
        ];
    }

    /**
     * core.auth.before_register 훅 핸들러.
     *
     * @param  mixed  ...$args  [0]=array $data 가입 요청 데이터
     *
     * @throws IdentityDuplicateException 동일 DI/CI hash 매칭 시
     */
    public function handle(...$args): void
    {
        if (! (bool) g7_plugin_settings(self::PLUGIN_IDENTIFIER, 'duplicate_block_enabled', true)) {
            return;
        }

        $data = $args[0] ?? [];
        if (! is_array($data)) {
            return;
        }

        $token = (string) ($data['verification_token'] ?? '');
        $log = $this->logQueryRepository->findVerifiedLogForToken($token, 'signup');
        if ($log === null) {
            return;
        }

        // 본 plugin 책임 영역 분기 — 다른 provider 의 verify 는 통과시킨다.
        if ((string) $log->provider_id !== KcpIdentityProvider::PROVIDER_ID) {
            return;
        }

        $duplicateField = KcpDuplicateField::tryFrom(
            (string) g7_plugin_settings(self::PLUGIN_IDENTIFIER, 'duplicate_field', 'di')
        ) ?? KcpDuplicateField::Di;

        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $hash = $this->extractHash($metadata, $duplicateField);

        // hash 부재 시 통과 (식별값 미발급 케이스 — 의도된 통과)
        if ($hash === null) {
            return;
        }

        $conflictColumn = $this->duplicateChecker->findConflictColumn($duplicateField, $hash);

        if ($conflictColumn === null) {
            return;
        }

        // 운영자 감사 추적용 — 어느 기준으로 차단됐는지 로그 metadata 에 보존한다.
        // 보조 추적이므로 실패해도 차단 흐름은 그대로 진행한다.
        try {
            $this->logQueryRepository->appendMetadata((string) $log->id, [
                'matched_field' => $conflictColumn,
                'matched_user_id' => $this->duplicateChecker->findConflictUserId($duplicateField, $hash),
            ]);
        } catch (\Throwable $e) {
            // metadata 기록 실패는 차단 의도에 영향 없음 — 무시
        }

        throw new IdentityDuplicateException($conflictColumn);
    }

    /**
     * log.metadata 에서 duplicate_field 에 해당하는 hash 를 추출한다.
     *
     * @param  array<string, mixed>  $metadata  로그 metadata
     * @param  KcpDuplicateField  $field  판정 기준
     * @return string|null hash 또는 null
     */
    protected function extractHash(array $metadata, KcpDuplicateField $field): ?string
    {
        $key = $field === KcpDuplicateField::Di ? 'di_hash' : 'ci_hash';
        $hash = $metadata[$key] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }
}
