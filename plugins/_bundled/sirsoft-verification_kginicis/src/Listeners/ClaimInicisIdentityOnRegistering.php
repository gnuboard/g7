<?php

namespace Plugins\Sirsoft\VerificationKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use App\Services\IdentityTokenAcceptanceService;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\VerificationKginicis\Enums\InicisDuplicateField;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\IdentityDuplicateException;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityClaimRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityLogQueryRepositoryInterface;

/**
 * 가입 트랜잭션 안에서 동일인 식별값을 선점하는 listener (권위 게이트, 1.1.4 — KVE-2026-3192 동형).
 *
 * `AssertNoDuplicateInicisIdentity` 는 records 표를 보고 판정하는데, records 는 가입이 커밋된 뒤(`after_register`)에야
 * 채워진다. 같은 본인확인 결과로 두 가입이 동시에 들어오면 둘 다 빈 records 를 보고 통과했다. 이 listener 는 코어
 * `core.auth.registering`(사용자 생성 트랜잭션 안)에서 `inicis_identity_claims` 의 UNIQUE 로 해시를 선점한다 —
 * 진 쪽은 예외를 던져 사용자 생성·동의 이력까지 함께 롤백된다.
 *
 * 운영자 settings 는 `AssertNoDuplicateInicisIdentity` 와 같다(`duplicate_block_enabled`·`duplicate_field`).
 *
 * @since 1.1.4
 */
class ClaimInicisIdentityOnRegistering implements HookListenerInterface
{
    /** plugin 식별자 — g7_plugin_settings() 헬퍼 키 */
    protected const PLUGIN_IDENTIFIER = 'sirsoft-verification_kginicis';

    /**
     * @param  IdentityTokenAcceptanceService  $tokenAcceptance  같은 요청에서 인정한 본인인증 결과 조회
     * @param  InicisIdentityClaimRepositoryInterface  $claimRepository  선점표
     * @param  InicisIdentityLogQueryRepositoryInterface  $logQueryRepository  감사 metadata 기록
     */
    public function __construct(
        protected readonly IdentityTokenAcceptanceService $tokenAcceptance,
        protected readonly InicisIdentityClaimRepositoryInterface $claimRepository,
        protected readonly InicisIdentityLogQueryRepositoryInterface $logQueryRepository,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * 동기 실행 필수 — 큐로 밀리면 트랜잭션이 이미 커밋된 뒤라 롤백할 수 없다.
     *
     * @return array<string, array<string, mixed>> 훅 → 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.registering' => [
                'method' => 'handle',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * core.auth.registering 훅 핸들러.
     *
     * @param  mixed  ...$args  [0]=User 막 만든 회원, [1]=array 가입 데이터(비밀번호 제외), [2]=array 문맥
     * @return void 반환값 없음
     *
     * @throws IdentityDuplicateException 다른 회원이 이미 같은 식별값을 쥐고 있을 때 (가입 롤백)
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;
        $data = is_array($args[1] ?? null) ? $args[1] : [];

        if (! $user instanceof User) {
            return;
        }

        if (! (bool) g7_plugin_settings(self::PLUGIN_IDENTIFIER, 'duplicate_block_enabled', true)) {
            return;
        }

        $token = (string) ($data['verification_token'] ?? '');
        $log = $token !== '' ? $this->tokenAcceptance->acceptedInRequest($token, 'signup') : null;

        if ($log === null || (string) $log->provider_id !== InicisIdentityProvider::PROVIDER_ID) {
            return;
        }

        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $field = InicisDuplicateField::tryFrom(
            (string) g7_plugin_settings(self::PLUGIN_IDENTIFIER, 'duplicate_field', 'di')
        ) ?? InicisDuplicateField::Di;

        $userId = (int) $user->getKey();

        foreach (InicisDuplicateField::cases() as $candidate) {
            $hash = $metadata[$candidate->value.'_hash'] ?? null;
            if (! is_string($hash) || $hash === '') {
                continue;
            }

            if ($candidate !== $field) {
                // 판정 키가 아닌 식별값도 미리 쥐어 둔다 — 운영자가 판정 키를 바꿔도 선점이 이어진다.
                $this->claimRepository->claimSoft($candidate->value, $hash, $userId);

                continue;
            }

            if ($this->claimRepository->claim($candidate->value, $hash, $userId)) {
                continue;
            }

            $column = $candidate->value.'_hash';
            $patch = [
                'matched_field' => $column,
                'matched_user_id' => $this->claimRepository->findHolderId($candidate->value, $hash),
            ];
            $logId = (string) $log->id;

            // 감사 추적 — 이 예외가 가입 트랜잭션을 롤백하므로 기록은 롤백이 끝난 뒤에 남긴다(안에서 쓰면 함께
            // 사라진다). 보조 기록이므로 실패해도 차단은 그대로 진행한다.
            $record = function () use ($logId, $patch): void {
                try {
                    $this->logQueryRepository->appendMetadata($logId, $patch);
                } catch (\Throwable) {
                    // metadata 기록 실패는 차단 의도에 영향 없음
                }
            };
            DB::transactionLevel() > 0 ? DB::afterRollBack($record) : $record();

            throw new IdentityDuplicateException($column);
        }
    }
}
