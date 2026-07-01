<?php

namespace Plugins\Sirsoft\VerificationKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\VerificationKginicis\Enums\InicisDuplicateField;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\IdentityDuplicateException;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;

/**
 * 가입 직전 동일인 중복 가입 차단 listener.
 *
 * 흐름 (core.auth.before_register):
 *  1. 코어 AssertIdentityVerifiedBeforeRegister (priority 10) 가 token 검증 + consume
 *  2. 본 listener (priority 20) 가 같은 token 으로 log 재조회 → metadata.di_hash/ci_hash 회수
 *     → `inicis_identity_records` 에서 동일 hash 매칭 → 발견 시 IdentityDuplicateException
 *
 * 운영자 settings:
 *  - `duplicate_block_enabled` (bool, 기본 true) — false 시 즉시 통과 (가족 휴대폰 공유/B2B)
 *  - `duplicate_field` ('di' | 'ci', 기본 'di') — 동일인 판정 키 선택
 *
 * 보안:
 *  - log.provider_id !== 'inicis' 인 경우 통과 (다른 IDV provider 책임 영역)
 *  - hash 가 NULL 인 경우 통과 (외국인 등 hash 발급 불가 케이스 — 의도된 통과)
 *  - 예외 메시지에 PII (이메일/이름) 미노출 — heads-up 방어
 *
 * @since 1.0.0-beta.1
 */
class AssertNoDuplicateInicisIdentity implements HookListenerInterface
{
    /** plugin 식별자 — g7_plugin_settings() 헬퍼 키 */
    protected const PLUGIN_IDENTIFIER = 'sirsoft-verification_kginicis';

    /**
     * @param  InicisIdentityLogQueryRepositoryInterface  $logQueryRepository  consumed_at 무관 verified log 조회
     * @param  InicisIdentityRecordRepositoryInterface  $recordRepository  inicis_identity_records 조회
     */
    public function __construct(
        protected readonly InicisIdentityLogQueryRepositoryInterface $logQueryRepository,
        protected readonly InicisIdentityRecordRepositoryInterface $recordRepository,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * priority 20 — 코어 AssertIdentityVerifiedBeforeRegister (priority 10) 직후.
     * 코어가 token 검증 + consume 완료한 뒤 본 listener 가 동일인 검증 수행.
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
     * @return void
     *
     * @throws IdentityDuplicateException 동일 DI/CI hash 매칭 시
     */
    public function handle(...$args): void
    {
        // 1. settings 로드 — duplicate_block_enabled=false 면 즉시 통과
        if (! (bool) g7_plugin_settings(self::PLUGIN_IDENTIFIER, 'duplicate_block_enabled', true)) {
            return;
        }

        $data = $args[0] ?? [];
        if (! is_array($data)) {
            return;
        }

        // 2. token 으로 verified log 조회 (consumed_at 무관)
        $token = (string) ($data['verification_token'] ?? '');
        $log = $this->logQueryRepository->findVerifiedLogForToken($token, 'signup');
        if ($log === null) {
            return;
        }

        // 3. 본 plugin 책임 영역 분기 — 다른 provider 의 verify 는 통과
        if ((string) $log->provider_id !== InicisIdentityProvider::PROVIDER_ID) {
            return;
        }

        // 4. settings duplicate_field 기준으로 hash 회수
        $duplicateField = InicisDuplicateField::tryFrom(
            (string) g7_plugin_settings(self::PLUGIN_IDENTIFIER, 'duplicate_field', 'di')
        ) ?? InicisDuplicateField::Di;

        $metadata = is_array($log->metadata) ? $log->metadata : [];
        [$hash, $hashColumn] = $this->extractHash($metadata, $duplicateField);

        // 5. hash 부재 시 통과 (외국인 등)
        if ($hash === null) {
            return;
        }

        // 6. 기존 record 매칭 검색
        $existing = $duplicateField === InicisDuplicateField::Di
            ? $this->recordRepository->findByDiHash($hash)
            : $this->recordRepository->findByCiHash($hash);

        if ($existing !== null) {
            // 7. 운영자 감사 추적용 — 어느 hash 컬럼으로 차단됐는지 로그 metadata 에 보존.
            //    admin 인증이력 화면이 metadata.matched_field 를 노출하면 DI/CI 식별 가능.
            //    실패해도 차단 흐름 자체는 진행 — 보조 추적이라 try/catch 로 격리.
            try {
                $this->logQueryRepository->appendMetadata((string) $log->id, [
                    'matched_field' => $hashColumn,
                    'matched_record_id' => (int) $existing->id,
                ]);
            } catch (\Throwable $e) {
                // metadata 기록 실패는 차단 의도에 영향 없음 — 무시
            }

            throw new IdentityDuplicateException($hashColumn);
        }
    }

    /**
     * log.metadata 에서 duplicate_field 에 해당하는 hash 와 컬럼명을 추출한다.
     *
     * @param  array<string, mixed>  $metadata
     * @param  InicisDuplicateField  $field
     * @return array{0: string|null, 1: string}  [hash, column_name]
     */
    protected function extractHash(array $metadata, InicisDuplicateField $field): array
    {
        if ($field === InicisDuplicateField::Di) {
            $hash = $metadata['di_hash'] ?? null;

            return [is_string($hash) && $hash !== '' ? $hash : null, 'di_hash'];
        }

        $hash = $metadata['ci_hash'] ?? null;

        return [is_string($hash) && $hash !== '' ? $hash : null, 'ci_hash'];
    }
}
