<?php

namespace Plugins\Sirsoft\VerificationKginicis\Repositories;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;

/**
 * InicisIdentityLogQueryRepositoryInterface 구현체.
 *
 * 본 plugin 의 ServiceProvider register() 에서 인터페이스 바인딩으로 등록된다.
 * Listener 는 인터페이스만 의존하고 구체 클래스에 의존하지 않는다.
 *
 * @since 1.0.0-beta.1
 */
class InicisIdentityLogQueryRepository implements InicisIdentityLogQueryRepositoryInterface
{
    /**
     * verified 상태의 IDV 로그를 token + purpose 로 조회한다 (consumed_at 무관).
     *
     * 코어 findVerifiedForToken 과 달리 consumed_at IS NULL 필터를 두지 않는다 —
     * 코어 priority 10 listener 가 consume 한 후 본 plugin priority 20 listener 가
     * 같은 토큰으로 metadata.di_hash/ci_hash 를 회수해야 하기 때문.
     *
     * @param  string  $token  verification_token
     * @param  string  $purpose  IDV purpose
     * @return IdentityVerificationLog|null  매칭 로그 또는 null
     */
    public function findVerifiedLogForToken(string $token, string $purpose): ?IdentityVerificationLog
    {
        if ($token === '') {
            return null;
        }

        return IdentityVerificationLog::query()
            ->where('verification_token', $token)
            ->where('purpose', $purpose)
            ->where('status', IdentityVerificationStatus::Verified->value)
            ->first();
    }

    /**
     * 탈퇴/삭제 사용자의 본 plugin 발행 로그를 익명화한다.
     *
     * user_id 를 NULL 로 비우는 동시에, 로그 metadata 에 남은 PII성 식별자 해시
     * (di_hash / ci_hash) 를 함께 파기한다. CI/DI 해시는 동일인 추적에 쓰일 수 있어
     * 탈퇴자에게도 잔존하면 안 되며(PIPC 파기 의무), 감사 추적 필드
     * (matched_field / duplicate_field_used 등) 는 보존한다.
     *
     * @param  int  $userId  탈퇴/삭제 사용자 id
     * @return int  익명화된 row 수
     */
    public function anonymizeUserId(int $userId): int
    {
        $logs = IdentityVerificationLog::query()
            ->where('user_id', $userId)
            ->where('provider_id', InicisIdentityProvider::PROVIDER_ID)
            ->get();

        foreach ($logs as $log) {
            $metadata = is_array($log->metadata) ? $log->metadata : [];
            unset($metadata['di_hash'], $metadata['ci_hash']);

            $log->user_id = null;
            $log->metadata = $metadata !== [] ? $metadata : null;
            $log->save();
        }

        return $logs->count();
    }

    /**
     * 로그의 metadata JSON 에 추가 키를 병합한다 (기존 키 보존).
     *
     * @param  string  $logId  대상 로그 id
     * @param  array<string, mixed>  $patch  병합할 metadata 키
     * @return bool  업데이트 성공 여부
     */
    public function appendMetadata(string $logId, array $patch): bool
    {
        if ($logId === '' || $patch === []) {
            return false;
        }

        $log = IdentityVerificationLog::query()->find($logId);
        if ($log === null) {
            return false;
        }

        $current = is_array($log->metadata) ? $log->metadata : [];
        $log->metadata = array_merge($current, $patch);

        return (bool) $log->save();
    }
}
