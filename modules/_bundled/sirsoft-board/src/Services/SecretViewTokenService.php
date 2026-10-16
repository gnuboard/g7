<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Support\BoardPermissionCacheKeys;

/**
 * 비밀글 열람 토큰 서비스
 *
 * 비밀번호를 맞힌 사실을 다음 요청으로 넘기는 브라우저 토큰 1개와, 서버가 그 토큰에 묶어 두는
 * 글별 인증(grant) 기록을 관리합니다. 두 번째 글을 인증하면 새 토큰을 만들지 않고 같은 토큰에
 * 그 글의 grant 만 더하고, grant 마다 만료 시각이 따로입니다.
 *
 * 레지스트리 키에는 토큰 원문이 아니라 해시를 씁니다 — 캐시 저장소를 열람할 수 있는 쪽이 키
 * 목록만으로 살아 있는 토큰을 얻지 못하게 합니다.
 *
 * 토큰 고정 공격 방지: 요청이 제시한 토큰에 grant 를 **더하는** 것은 서버가 발급해 아직 살아 있고,
 * 발급 당시 신원(회원 ID·비회원)이 지금 요청자와 같을 때만입니다. 그 밖에는 새로 발급합니다.
 * grant 를 **읽는** 쪽은 신원을 묻지 않습니다 — 토큰은 비밀번호를 맞힌 사실의 소지 증명이라
 * 권한 범위가 비밀번호를 아는 것과 같고, 로그인·로그아웃 경계의 정리는 화면이 맡습니다.
 */
class SecretViewTokenService
{
    /**
     * 열람 토큰을 싣는 요청 헤더 이름
     */
    public const HEADER = 'X-Board-Secret-View-Token';

    /**
     * 토큰 하나에 쌓을 수 있는 grant 상한
     */
    public const MAX_GRANTS = 50;

    /**
     * 토큰 형식 (서버가 발급하는 40자 영숫자)
     */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{40}$/';

    /**
     * @param  CacheInterface  $cache  모듈 캐시
     */
    public function __construct(private CacheInterface $cache) {}

    /**
     * 현재 요청자에게 이 글의 grant 를 주고 토큰을 돌려줍니다.
     *
     * 요청이 재사용 가능한 토큰을 제시했으면 그 토큰에 grant 를 더하고, 아니면 새 토큰을 발급합니다.
     * 같은 글을 다시 인증하면 그 grant 의 만료만 늘어납니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return array{token: string, expires_at: string} 토큰과 레지스트리 전체 만료 시각
     */
    public function grant(Request $request, string $slug, int $postId): array
    {
        $ttl = max(1, (int) g7_core_settings('cache.post_verify_token_ttl', 3600));
        $now = now()->getTimestamp();

        $token = $this->presentedToken($request);
        $registry = $token !== null ? $this->loadRegistry($token) : null;

        if ($token === null || $registry === null || $registry['uid'] !== $this->currentUserId()) {
            $token = Str::random(40);
            $registry = ['uid' => $this->currentUserId(), 'grants' => []];
        }

        $grants = array_filter($registry['grants'], fn (int $expiresAt) => $expiresAt > $now);
        $grants[self::grantKey($slug, $postId)] = $now + $ttl;

        if (count($grants) > self::MAX_GRANTS) {
            asort($grants);
            $grants = array_slice($grants, count($grants) - self::MAX_GRANTS, null, true);
        }

        $registry['grants'] = $grants;
        $registryExpiresAt = max($grants);

        $this->cache->put(self::registryKey($token), $registry, max(1, $registryExpiresAt - $now));
        $this->forgetRequestMemo($request);

        return [
            'token' => $token,
            'expires_at' => now()->setTimestamp($registryExpiresAt)->toIso8601String(),
        ];
    }

    /**
     * 요청 토큰에 이 글의 살아 있는 grant 가 있는지 판정합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return bool grant 보유 여부
     */
    public function hasGrant(Request $request, string $slug, int $postId): bool
    {
        return in_array($postId, $this->grantedPostIds($request, $slug), true);
    }

    /**
     * 요청 토큰이 이 게시판에서 grant 를 가진 글 ID 목록을 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @return array<int, int> 게시글 ID 목록 (오름차순)
     */
    public function grantedPostIds(Request $request, string $slug): array
    {
        $memo = $request->attributes->get(BoardPermissionCacheKeys::SECRET_VIEW_TOKEN, []);

        if (! array_key_exists('grants', $memo)) {
            $token = $this->presentedToken($request);
            $registry = $token !== null ? $this->loadRegistry($token) : null;
            $memo['grants'] = $registry['grants'] ?? [];
            $request->attributes->set(BoardPermissionCacheKeys::SECRET_VIEW_TOKEN, $memo);
        }

        $now = now()->getTimestamp();
        $prefix = $slug.':';
        $ids = [];

        foreach ($memo['grants'] as $key => $expiresAt) {
            if ($expiresAt > $now && str_starts_with((string) $key, $prefix)) {
                $ids[] = (int) substr((string) $key, strlen($prefix));
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * 토큰 원문이 이 글의 살아 있는 grant 를 가졌는지 판정합니다 (요청 밖 호출용).
     *
     * @param  string|null  $token  토큰 원문
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return bool grant 보유 여부
     */
    public function tokenHasGrant(?string $token, string $slug, int $postId): bool
    {
        if (! self::isWellFormed($token)) {
            return false;
        }

        $expiresAt = $this->loadRegistry($token)['grants'][self::grantKey($slug, $postId)] ?? 0;

        return $expiresAt > now()->getTimestamp();
    }

    /**
     * 요청이 제시한 토큰을 반환합니다.
     *
     * 같은 요청 안에서 막 발급한 토큰(useForRequest 로 건 override)을 헤더보다 먼저 봅니다.
     * 형식이 맞지 않으면 없는 것으로 봅니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 토큰
     */
    public function presentedToken(Request $request): ?string
    {
        $memo = $request->attributes->get(BoardPermissionCacheKeys::SECRET_VIEW_TOKEN, []);
        $token = $memo['override'] ?? $request->header(self::HEADER);

        return self::isWellFormed($token) ? $token : null;
    }

    /**
     * 막 발급한 토큰을 이 응답을 만드는 요청 인스턴스들에 겁니다.
     *
     * FormRequest 는 `Request::createFrom` 으로 attributes 를 복제하므로, 컨트롤러가 받은 요청과
     * 전역 `request()` 가 다른 인스턴스입니다. 리소스는 어느 쪽으로도 판정할 수 있어 둘 다에
     * 걸어야 같은 응답의 답변·원글·댓글이 새 grant 로 판정됩니다.
     *
     * @param  string  $token  토큰
     * @param  Request  ...$requests  요청 인스턴스들
     * @return void 반환값 없음
     */
    public function useForRequest(string $token, Request ...$requests): void
    {
        foreach ($requests as $request) {
            $request->attributes->set(BoardPermissionCacheKeys::SECRET_VIEW_TOKEN, ['override' => $token]);
        }
    }

    /**
     * 토큰 레지스트리를 읽습니다 (만료된 grant 는 뺀다).
     *
     * @param  string  $token  토큰
     * @return array{uid: int|null, grants: array<string, int>}|null 레지스트리
     */
    private function loadRegistry(string $token): ?array
    {
        $registry = $this->cache->get(self::registryKey($token));

        if (! is_array($registry) || ! is_array($registry['grants'] ?? null)) {
            return null;
        }

        $uid = isset($registry['uid']) ? (int) $registry['uid'] : null;

        $now = now()->getTimestamp();
        $grants = array_filter(
            array_map('intval', $registry['grants']),
            fn (int $expiresAt) => $expiresAt > $now
        );

        if ($grants === []) {
            return null;
        }

        return ['uid' => $uid, 'grants' => $grants];
    }

    /**
     * 요청 단위 grant 메모를 지웁니다 (override 는 유지).
     *
     * @param  Request  $request  HTTP 요청
     * @return void 반환값 없음
     */
    private function forgetRequestMemo(Request $request): void
    {
        $memo = $request->attributes->get(BoardPermissionCacheKeys::SECRET_VIEW_TOKEN, []);
        unset($memo['grants']);
        $request->attributes->set(BoardPermissionCacheKeys::SECRET_VIEW_TOKEN, $memo);
    }

    /**
     * 현재 요청자의 회원 ID 를 반환합니다.
     *
     * @return int|null 회원 ID (비회원이면 null)
     */
    private function currentUserId(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }

    /**
     * 토큰 형식이 맞는지 판정합니다.
     *
     * @param  mixed  $token  토큰 후보
     * @return bool 형식 일치 여부
     */
    private static function isWellFormed(mixed $token): bool
    {
        return is_string($token) && preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /**
     * 레지스트리 캐시 키를 만듭니다 (토큰 원문 대신 해시).
     *
     * @param  string  $token  토큰
     * @return string 캐시 키
     */
    private static function registryKey(string $token): string
    {
        return 'board_secret_view_token_'.hash('sha256', $token);
    }

    /**
     * grant 키를 만듭니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return string grant 키
     */
    private static function grantKey(string $slug, int $postId): string
    {
        return $slug.':'.$postId;
    }
}
