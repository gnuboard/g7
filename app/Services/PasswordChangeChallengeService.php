<?php

namespace App\Services;

use App\Auth\DTO\PasswordChangeRequirement;
use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * 로그인 비밀번호 변경 단계 식별자(challenge) 서비스
 *
 * 비밀번호·2단계 인증을 통과했지만 비밀번호를 바꿔야 하는 회원에게 토큰 대신 1회용 식별자를 준다.
 * 식별자와 새 비밀번호를 함께 보내야 로그인이 끝난다(2FA challenge 와 같은 개념).
 *
 * - 캐시(`CacheInterface`)에 10분 보관, 회원당 1개(재발급 시 이전 것 무효), 완료 시 삭제.
 * - 발급 경로(admin/user)와 발급 시점의 비밀번호 해시 지문에 묶는다 — 다른 경로로 쓰거나, 그 사이
 *   비밀번호가 바뀌었으면(재설정 등) 무효다.
 * - 다중 서버는 공유 캐시를 전제한다(`onOneServer` 예약과 같은 전제 — 새 전제 없음).
 *
 * @since 7.1.0
 */
class PasswordChangeChallengeService
{
    /**
     * 식별자 유효 시간(초)
     */
    public const TTL_SECONDS = 600;

    /**
     * 식별자 캐시 키 접두사
     */
    private const KEY_PREFIX = 'auth.password_change.';

    /**
     * 회원별 현재 식별자 캐시 키 접두사
     */
    private const USER_KEY_PREFIX = 'auth.password_change.user.';

    /**
     * @param  CacheInterface  $cache  코어 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 식별자를 발급합니다 (회원의 이전 식별자는 무효화).
     *
     * @param  User  $user  비밀번호를 바꿔야 하는 회원
     * @param  string  $guard  발급 경로 (admin | user)
     * @param  PasswordChangeRequirement  $requirement  변경 요구
     * @return string|null 식별자 (캐시에 쓰지 못하면 null — 호출자가 503 으로 알린다)
     */
    public function issue(User $user, string $guard, PasswordChangeRequirement $requirement): ?string
    {
        $userKey = self::USER_KEY_PREFIX.$user->getKey();
        $previous = $this->cache->get($userKey);

        if (is_string($previous) && $previous !== '') {
            $this->cache->forget(self::KEY_PREFIX.$previous);
        }

        $id = (string) Str::uuid();
        $payload = [
            'user_id' => (int) $user->getKey(),
            'guard' => $guard,
            'reason' => $requirement->reason->value,
            'can_postpone' => $requirement->canPostpone,
            'password_fingerprint' => $this->fingerprint($user),
            'issued_at' => now()->toIso8601String(),
        ];

        if (! $this->cache->put(self::KEY_PREFIX.$id, $payload, self::TTL_SECONDS)) {
            return null;
        }

        $this->cache->put($userKey, $id, self::TTL_SECONDS);

        HookManager::doAction('core.auth.password_change_required', $user, [
            'reason' => $requirement->reason->value,
            'guard' => $guard,
        ]);

        return $id;
    }

    /**
     * 식별자의 내용을 읽습니다 (소비하지 않음).
     *
     * @param  string  $id  식별자
     * @return array{user_id: int, guard: string, reason: string, can_postpone: bool, password_fingerprint: string, issued_at: string}|null 내용 (없거나 만료면 null)
     */
    public function peek(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $payload = $this->cache->get(self::KEY_PREFIX.$id);

        return is_array($payload) && isset($payload['user_id'], $payload['guard']) ? $payload : null;
    }

    /**
     * 식별자를 소비(삭제)합니다.
     *
     * @param  string  $id  식별자
     * @return void
     */
    public function consume(string $id): void
    {
        $payload = $this->peek($id);
        $this->cache->forget(self::KEY_PREFIX.$id);

        if ($payload !== null) {
            $userKey = self::USER_KEY_PREFIX.$payload['user_id'];

            if ($this->cache->get($userKey) === $id) {
                $this->cache->forget($userKey);
            }
        }
    }

    /**
     * 식별자가 지금 이 회원·경로에 유효한지 확인합니다 (비밀번호 지문까지).
     *
     * @param  array<string, mixed>  $payload  `peek()` 결과
     * @param  User  $user  식별자가 가리키는 회원
     * @param  string  $guard  요청 경로 (admin | user)
     * @return bool 유효하면 true
     */
    public function matches(array $payload, User $user, string $guard): bool
    {
        return ($payload['guard'] ?? null) === $guard
            && (int) ($payload['user_id'] ?? 0) === (int) $user->getKey()
            && hash_equals((string) ($payload['password_fingerprint'] ?? ''), $this->fingerprint($user));
    }

    /**
     * 식별자 원자 처리용 잠금 키를 반환합니다.
     *
     * @param  string  $id  식별자
     * @return string 잠금 키
     */
    public function lockKey(string $id): string
    {
        return self::KEY_PREFIX.'lock.'.$id;
    }

    /**
     * 회원의 현재 비밀번호 해시 지문을 계산합니다.
     *
     * @param  User  $user  회원
     * @return string sha256 지문
     */
    private function fingerprint(User $user): string
    {
        return hash('sha256', (string) $user->getRawOriginal('password'));
    }
}
