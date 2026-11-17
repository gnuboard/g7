<?php

namespace Plugins\Sirsoft\VerificationKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;

/**
 * 본인확인 로그 보관주기 파기 직전에 이 플러그인의 challenge 매핑을 명시 삭제한다.
 *
 * 코어 `IdentityLogService::purge()` 는 보관주기가 지난 `identity_verification_logs` 를 배치로 지우며, 배치마다
 * 지우기 직전에 `core.identity_logs.before_purge` 를 그 배치의 로그 ID 배열 하나로 발화한다. 코어는 이 플러그인의
 * 테이블을 모르므로, 그 로그를 참조하는 `inicis_challenge_mappings` 행은 이 리스너가 지운다.
 *
 * 매핑 테이블의 외래키 `cascadeOnDelete()` 는 안전망일 뿐 삭제 경로가 아니다 — 삭제는 이 리스너가 맡는다.
 * 로그 행이 지워지기 전에 끝나야 하므로 동기로 실행한다.
 */
class CleanInicisChallengeMappingsOnLogPurge implements HookListenerInterface
{
    /**
     * @param  InicisChallengeMappingRepositoryInterface  $mappingRepository  challenge 매핑 Repository
     */
    public function __construct(
        protected readonly InicisChallengeMappingRepositoryInterface $mappingRepository,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>> 훅 이름 → 구독 설정(method·priority·sync) 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.identity_logs.before_purge' => [
                'method' => 'handle',
                'priority' => 10,
                'sync' => true,
            ],
        ];
    }

    /**
     * 훅 진입점.
     *
     * @param  mixed  ...$args  [$logIds] — 이번 배치에서 지울 로그 ID(UUID 문자열) 배열
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        $logIds = $args[0] ?? null;
        if (! is_array($logIds)) {
            return;
        }

        $challengeIds = array_values(array_filter(
            array_map(static fn ($id) => is_scalar($id) ? (string) $id : '', $logIds),
            static fn (string $id) => $id !== '',
        ));

        if ($challengeIds === []) {
            return;
        }

        $this->mappingRepository->deleteByChallengeIds($challengeIds);
    }
}
