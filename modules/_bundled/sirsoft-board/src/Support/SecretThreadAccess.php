<?php

namespace Modules\Sirsoft\Board\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Enums\SecretReplyVisibility;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\SecretViewTokenService;

/**
 * 비밀글 스레드 경로 판정
 *
 * 답변 트리에서 요청자가 「앵커」로 삼을 수 있는 글을 통해 다른 글의 본문을 여는지 판정합니다.
 *
 * - 앵커(X): 살아 있고(삭제되지 않음), 요청자가 X 의 회원 작성자이거나 요청자 토큰에 X 의 grant 가
 *   있는 글. 비밀 여부와 무관하다(공개 질문 + 비밀 답변, 나중에 비밀로 바뀐 원글).
 * - 경로 규칙: P 의 조상 중 앵커가 있으면 P 를 연다(앵커의 하위 전체). 게시판 설정이 `thread_path`
 *   이면 P 의 자손 중 앵커가 있어도 연다(자기 글의 조상 경로). 형제 가지는 조상도 자손도 아니므로
 *   자동으로 막힌다.
 * - 구조 순회는 삭제된 글도 지나간다(구조는 사실이므로). 앵커 자격만 살아 있는 글에 준다.
 *
 * 성능: 요청자에게 회원 ID 도 grant 도 없으면 쿼리 없이 false. 노드는 요청 속성에 게시판별로 담아
 * 상세 화면의 답변 트리(이미 메모리에 있음)를 다시 읽지 않고, 목록은 prime() 이 레벨당 1쿼리로 채운다.
 * 오염 데이터(순환·과도한 깊이)는 순회 상한과 방문 집합으로 막고 false(fail-closed)로 끝낸다.
 */
class SecretThreadAccess
{
    /**
     * 조상·자손 순회 레벨 상한
     */
    public const MAX_WALK = 32;

    /**
     * 스레드 비밀번호 후보(비회원 조상) 상한
     */
    public const MAX_PASSWORD_CANDIDATES = 10;

    /**
     * @param  SecretViewTokenService  $tokens  열람 토큰 서비스
     * @param  PostRepositoryInterface  $posts  게시글 저장소
     * @param  BoardRepositoryInterface  $boards  게시판 저장소
     */
    public function __construct(
        private SecretViewTokenService $tokens,
        private PostRepositoryInterface $posts,
        private BoardRepositoryInterface $boards,
    ) {}

    /**
     * 요청자가 스레드 경로로 이 글의 본문을 여는지 판정합니다.
     *
     * @param  Post  $post  대상 게시글
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  게시판 슬러그
     * @return bool 경로로 열리면 true
     */
    public function reachesThroughThread(Post $post, Request $request, string $slug): bool
    {
        $mode = $this->modeFor($post, $slug, $request);
        if ($mode === SecretReplyVisibility::PerPost || ! $post->id || ! $post->board_id) {
            return false;
        }

        // 메모리에 있는 트리를 먼저 등록한다(쿼리 없음) — 경로가 열리지 않는 요청이라도 이어지는
        // 스레드 비밀번호 후보 판정(thread_password_available)이 같은 행을 다시 읽지 않게 한다.
        $this->registerLoaded($post, $request);

        $uid = $this->currentUserId();
        $grants = $this->tokens->grantedPostIds($request, $slug);
        if ($uid === null && $grants === []) {
            return false;
        }

        $boardId = (int) $post->board_id;
        $postId = (int) $post->id;
        $memoKey = ($uid ?? 'guest').'|'.md5(implode(',', $grants)).'|'.$mode->value.'|'.$boardId.':'.$postId;
        $verdicts = $request->attributes->get(BoardPermissionCacheKeys::SECRET_THREAD_VERDICTS, []);
        if (array_key_exists($memoKey, $verdicts)) {
            return $verdicts[$memoKey];
        }

        $verdict = $this->decide($boardId, $postId, $uid, $grants, $mode, $request);

        $verdicts = $request->attributes->get(BoardPermissionCacheKeys::SECRET_THREAD_VERDICTS, []);
        $verdicts[$memoKey] = $verdict;
        $request->attributes->set(BoardPermissionCacheKeys::SECRET_THREAD_VERDICTS, $verdicts);

        return $verdict;
    }

    /**
     * 목록 직렬화 전에 비밀글들의 스레드 노드를 한꺼번에 채웁니다.
     *
     * 게시판별로 묶어 조상 사슬을 레벨당 1쿼리, 회원의 자손 탐색을 레벨당 1쿼리로 채운다 —
     * 이후 글마다 부르는 reachesThroughThread() 는 쿼리 없이 판정된다. 페이지 크기와 무관하게
     * 쿼리 수는 순회 깊이에만 비례한다.
     *
     * @param  iterable<mixed>  $posts  게시글(또는 게시글을 감싼 리소스) 목록
     * @param  Request  $request  HTTP 요청
     * @return void 반환값 없음
     */
    public function prime(iterable $posts, Request $request): void
    {
        $uid = $this->currentUserId();
        $byBoard = [];

        foreach ($posts as $item) {
            $post = $item instanceof Post ? $item : ($item->resource ?? null);
            if (! $post instanceof Post || ! $post->is_secret || ! $post->id || ! $post->board_id) {
                continue;
            }
            $byBoard[(int) $post->board_id][] = $post;
        }

        foreach ($byBoard as $boardId => $boardPosts) {
            $slug = $this->slugFor($boardPosts[0], $request);
            if ($slug === null) {
                continue;
            }

            $mode = $this->modeFor($boardPosts[0], $slug, $request);
            $grants = $this->tokens->grantedPostIds($request, $slug);
            if ($mode === SecretReplyVisibility::PerPost || ($uid === null && $grants === [])) {
                continue;
            }

            $ids = [];
            foreach ($boardPosts as $post) {
                $this->registerLoaded($post, $request);
                $ids[] = (int) $post->id;
            }

            // 조상 사슬 (목록 글 + grant 글)
            $this->ensureAncestors($boardId, array_merge($ids, $grants), $request);

            // 회원의 자손 앵커 탐색 — 답변을 쓴 적이 있을 때만
            if ($mode->opensAncestors() && $uid !== null && $this->userHasReply($boardId, $uid, $request)) {
                $this->ensureSubtrees($boardId, $ids, $request);
            }
        }
    }

    /**
     * 가까운 조상부터 스레드 비밀번호 후보(비회원·비밀번호 있음·살아 있는 글) ID 를 반환합니다.
     *
     * 비밀 여부와 무관하다 — 비회원이 쓴 공개 질문도 비밀번호가 있으면 후보다.
     * 게시판 설정이 `per_post` 이면 스레드 비밀번호를 쓰지 않으므로 빈 배열이다.
     *
     * @param  Post  $post  대상 게시글
     * @param  Request  $request  HTTP 요청
     * @return array<int, int> 조상 ID 목록 (가까운 순, 최대 MAX_PASSWORD_CANDIDATES)
     */
    public function guestPasswordAncestorIds(Post $post, Request $request): array
    {
        $slug = $this->slugFor($post, $request);
        if ($slug === null || ! $post->parent_id || ! $post->board_id
            || $this->modeFor($post, $slug, $request) === SecretReplyVisibility::PerPost) {
            return [];
        }

        $this->registerLoaded($post, $request);
        $boardId = (int) $post->board_id;
        $ancestors = $this->ancestorNodes($boardId, (int) $post->id, $request);

        // 메모리 모델에서 등록한 노드는 비밀번호 컬럼이 없을 수 있다 — 비회원 글만 다시 읽어 채운다
        $unknown = array_column(array_filter(
            $ancestors,
            fn (array $node) => $node['has_password'] === null && $node['user_id'] === null
        ), 'id');
        if ($unknown !== []) {
            $this->storeNodes($boardId, $this->posts->findThreadNodes($boardId, $unknown), $request);
            $ancestors = $this->ancestorNodes($boardId, (int) $post->id, $request);
        }

        $candidates = [];
        foreach ($ancestors as $node) {
            if ($node['alive'] && $node['user_id'] === null && $node['has_password']) {
                $candidates[] = $node['id'];
                if (count($candidates) >= self::MAX_PASSWORD_CANDIDATES) {
                    break;
                }
            }
        }

        return $candidates;
    }

    /**
     * 조상 중 스레드 비밀번호 후보가 있는지 판정합니다.
     *
     * @param  Post  $post  대상 게시글
     * @param  Request  $request  HTTP 요청
     * @return bool 후보 존재 여부
     */
    public function hasGuestPasswordAncestor(Post $post, Request $request): bool
    {
        return $this->guestPasswordAncestorIds($post, $request) !== [];
    }

    /**
     * 이 글의 비밀 조상들을 판정용 게시글 모델로 반환합니다 (canWriteReply 용).
     *
     * 반환 모델은 노드 요약(ID·부모·작성자·비밀 여부·삭제 여부)만 담은 읽기 전용 인스턴스다.
     * 사슬을 끝까지 따라가지 못하면(순환·상한 초과) 판정할 수 없으므로 null 을 돌려
     * 호출부가 거부하게 한다.
     *
     * @param  Post  $post  대상 게시글 (답변을 달 부모)
     * @param  Request  $request  HTTP 요청
     * @return array<int, Post>|null 비밀 조상 목록 (가까운 순), 판정 불가면 null
     */
    public function secretAncestors(Post $post, Request $request): ?array
    {
        if (! $post->parent_id) {
            return [];
        }
        if (! $post->board_id) {
            return null;
        }

        $this->registerLoaded($post, $request);
        $boardId = (int) $post->board_id;
        $nodes = $this->ancestorNodes($boardId, (int) $post->id, $request, $complete);
        if (! $complete) {
            return null;
        }

        $board = $post->relationLoaded('board') ? $post->board : null;
        $result = [];
        foreach ($nodes as $node) {
            if (! $node['is_secret']) {
                continue;
            }

            $ancestor = (new Post)->newFromBuilder([
                'id' => $node['id'],
                'board_id' => $boardId,
                'parent_id' => $node['parent_id'],
                'user_id' => $node['user_id'],
                'is_secret' => true,
                'deleted_at' => $node['alive'] ? null : now(),
            ]);
            if ($board !== null) {
                $ancestor->setRelation('board', $board);
            }
            $result[] = $ancestor;
        }

        return $result;
    }

    /**
     * 게시판의 비밀 답변 열람 범위를 반환합니다.
     *
     * @param  Post  $post  대상 게시글
     * @param  string  $slug  게시판 슬러그
     * @param  Request  $request  HTTP 요청
     * @return SecretReplyVisibility 열람 범위
     */
    public function modeFor(Post $post, string $slug, Request $request): SecretReplyVisibility
    {
        $board = $post->relationLoaded('board') ? $post->board : null;

        if ($board instanceof Board && $board->slug === $slug) {
            // 관계로 쥔 게시판을 슬러그 메모에도 남겨, 관계가 없는 트리 안 답변이 게시판을 다시 읽지 않게 한다
            $memo = $request->attributes->get(BoardPermissionCacheKeys::BOARD_MODEL, []);
            if (! array_key_exists($slug, $memo)) {
                $memo[$slug] = $board;
                $request->attributes->set(BoardPermissionCacheKeys::BOARD_MODEL, $memo);
            }
        } else {
            $board = $this->boardBySlug($slug, $request);
        }

        $value = $board?->secret_reply_visibility;

        if ($value instanceof SecretReplyVisibility) {
            return $value;
        }

        return SecretReplyVisibility::tryFrom((string) $value) ?? SecretReplyVisibility::ThreadPath;
    }

    /**
     * 경로 규칙의 실제 판정을 수행합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  대상 게시글 ID
     * @param  int|null  $uid  요청자 회원 ID
     * @param  array<int, int>  $grants  요청자 grant 글 ID
     * @param  SecretReplyVisibility  $mode  열람 범위
     * @param  Request  $request  HTTP 요청
     * @return bool 경로로 열리면 true
     */
    private function decide(int $boardId, int $postId, ?int $uid, array $grants, SecretReplyVisibility $mode, Request $request): bool
    {
        $grantSet = array_fill_keys($grants, true);

        // 1) 조상 중 앵커 → 앵커의 하위 전체를 연다
        foreach ($this->ancestorNodes($boardId, $postId, $request) as $node) {
            if ($this->isAnchor($node, $uid, $grantSet)) {
                return true;
            }
        }

        if (! $mode->opensAncestors()) {
            return false;
        }

        // 2) 자손 중 앵커 → 그 앵커의 조상 경로를 연다
        //    2-a) grant 글들의 조상 사슬에 대상 글이 있는가
        if ($grants !== []) {
            $this->ensureAncestors($boardId, $grants, $request);
            foreach ($grants as $grantId) {
                $grantNode = $this->node($boardId, $grantId, $request);
                if ($grantNode === null || ! $grantNode['alive']) {
                    continue;
                }
                foreach ($this->ancestorNodes($boardId, $grantId, $request) as $ancestor) {
                    if ($ancestor['id'] === $postId) {
                        return true;
                    }
                }
            }
        }

        //    2-b) 회원이면 대상 글의 서브트리에서 내 살아 있는 글을 찾는다 (답변을 쓴 적이 있을 때만)
        if ($uid !== null && $this->userHasReply($boardId, $uid, $request)) {
            $this->ensureSubtrees($boardId, [$postId], $request);
            foreach ($this->descendantNodes($boardId, $postId, $request) as $node) {
                if ($node['alive'] && $node['user_id'] === $uid) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 노드가 요청자의 앵커인지 판정합니다.
     *
     * @param  array<string, mixed>  $node  노드 요약
     * @param  int|null  $uid  요청자 회원 ID
     * @param  array<int, bool>  $grantSet  grant 글 ID 집합
     * @return bool 앵커 여부
     */
    private function isAnchor(array $node, ?int $uid, array $grantSet): bool
    {
        if (! $node['alive']) {
            return false;
        }

        return ($uid !== null && $node['user_id'] === $uid) || isset($grantSet[$node['id']]);
    }

    /**
     * 글의 조상 노드를 가까운 순으로 반환합니다 (필요한 레벨만 조회).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  게시글 ID
     * @param  Request  $request  HTTP 요청
     * @param  bool|null  $complete  사슬을 끝(루트)까지 따라갔는지 (출력)
     * @return array<int, array<string, mixed>> 조상 노드 목록
     */
    private function ancestorNodes(int $boardId, int $postId, Request $request, ?bool &$complete = null): array
    {
        $this->ensureAncestors($boardId, [$postId], $request);

        $result = [];
        $visited = [$postId => true];
        $current = $this->node($boardId, $postId, $request);
        $complete = false;

        for ($step = 0; $step < self::MAX_WALK; $step++) {
            $parentId = $current['parent_id'] ?? null;
            if ($current === null) {
                return $result;
            }
            if ($parentId === null) {
                $complete = true;

                return $result;
            }
            if (isset($visited[$parentId])) {
                return $result;
            }
            $visited[$parentId] = true;

            $current = $this->node($boardId, $parentId, $request);
            if ($current === null) {
                return $result;
            }
            $result[] = $current;
        }

        return $result;
    }

    /**
     * 글의 자손 노드를 반환합니다 (이미 채워진 자식 목록만 따라감).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  게시글 ID
     * @param  Request  $request  HTTP 요청
     * @return array<int, array<string, mixed>> 자손 노드 목록
     */
    private function descendantNodes(int $boardId, int $postId, Request $request): array
    {
        $result = [];
        $visited = [$postId => true];
        $frontier = [$postId];

        for ($level = 0; $level < self::MAX_WALK && $frontier !== []; $level++) {
            $next = [];
            foreach ($frontier as $id) {
                foreach ($this->node($boardId, $id, $request)['kids'] ?? [] as $kidId) {
                    if (isset($visited[$kidId])) {
                        continue;
                    }
                    $visited[$kidId] = true;
                    $kid = $this->node($boardId, $kidId, $request);
                    if ($kid !== null) {
                        $result[] = $kid;
                        $next[] = $kidId;
                    }
                }
            }
            $frontier = $next;
        }

        return $result;
    }

    /**
     * 주어진 글들의 조상 사슬을 레벨당 1쿼리로 노드 캐시에 채웁니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  array<int, int>  $ids  시작 게시글 ID 목록
     * @param  Request  $request  HTTP 요청
     * @return void 반환값 없음
     */
    private function ensureAncestors(int $boardId, array $ids, Request $request): void
    {
        $frontier = array_values(array_unique(array_map('intval', $ids)));
        $visited = [];

        for ($level = 0; $level <= self::MAX_WALK && $frontier !== []; $level++) {
            $missing = array_values(array_filter($frontier, fn (int $id) => $this->node($boardId, $id, $request) === null && ! $this->isKnownMissing($boardId, $id, $request)));
            if ($missing !== []) {
                $this->storeNodes($boardId, $this->posts->findThreadNodes($boardId, $missing), $request, $missing);
            }

            $next = [];
            foreach ($frontier as $id) {
                $visited[$id] = true;
                $parentId = $this->node($boardId, $id, $request)['parent_id'] ?? null;
                if ($parentId !== null && ! isset($visited[$parentId])) {
                    $next[$parentId] = $parentId;
                }
            }
            $frontier = array_values($next);
        }
    }

    /**
     * 주어진 글들의 서브트리 자식 목록을 레벨당 1쿼리로 채웁니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  array<int, int>  $ids  시작 게시글 ID 목록
     * @param  Request  $request  HTTP 요청
     * @return void 반환값 없음
     */
    private function ensureSubtrees(int $boardId, array $ids, Request $request): void
    {
        $frontier = array_values(array_unique(array_map('intval', $ids)));
        $visited = [];

        for ($level = 0; $level < self::MAX_WALK && $frontier !== []; $level++) {
            $unknown = [];
            foreach ($frontier as $id) {
                $visited[$id] = true;
                $node = $this->node($boardId, $id, $request);
                if ($node === null || ! is_array($node['kids'] ?? null)) {
                    $unknown[] = $id;
                }
            }

            if ($unknown !== []) {
                $children = $this->posts->findThreadChildNodes($boardId, $unknown);
                $kidsByParent = array_fill_keys($unknown, []);
                foreach ($children as $child) {
                    $kidsByParent[$child['parent_id']][] = $child['id'];
                }
                $this->storeNodes($boardId, $children, $request);
                $this->storeKids($boardId, $kidsByParent, $request);
            }

            $next = [];
            foreach ($frontier as $id) {
                foreach ($this->node($boardId, $id, $request)['kids'] ?? [] as $kidId) {
                    if (! isset($visited[$kidId])) {
                        $next[$kidId] = $kidId;
                    }
                }
            }
            $frontier = array_values($next);
        }
    }

    /**
     * 메모리에 이미 있는 글과 그 관계(parent·replies 트리)를 노드 캐시에 등록합니다.
     *
     * 상세 화면은 답변 트리 전체와 부모 글을 이미 읽었으므로, 트리 안 글의 조상 사슬은 같은 행을
     * 다시 읽지 않는다. 다만 자식 목록은 기록하지 않는다 — 상세 트리는 관리 권한이 없으면 삭제된
     * 답변을 빼고 읽어, 삭제된 글 아래의 살아 있는 답변(앵커 후보)이 트리에서 빠져 있다.
     *
     * @param  Post  $post  게시글
     * @param  Request  $request  HTTP 요청
     * @return void 반환값 없음
     */
    private function registerLoaded(Post $post, Request $request): void
    {
        if (! $post->board_id || ! $post->id) {
            return;
        }

        $boardId = (int) $post->board_id;
        $nodes = [];

        $collect = function (Post $item, int $depth) use (&$collect, &$nodes, $boardId): void {
            if ((int) $item->board_id !== $boardId || $depth > self::MAX_WALK || isset($nodes[(int) $item->id])) {
                return;
            }
            $nodes[(int) $item->id] = $this->nodeFromModel($item);

            if ($item->relationLoaded('replies') && $item->replies instanceof EloquentCollection) {
                foreach ($item->replies as $reply) {
                    if ($reply instanceof Post) {
                        $collect($reply, $depth + 1);
                    }
                }
            }
        };

        $collect($post, 0);

        if ($post->relationLoaded('parent') && $post->parent instanceof Post && ! isset($nodes[(int) $post->parent->id])) {
            $nodes[(int) $post->parent->id] = $this->nodeFromModel($post->parent);
        }

        $this->storeNodes($boardId, array_values($nodes), $request);
    }

    /**
     * 게시글 모델에서 노드 요약을 만듭니다.
     *
     * 상세 조회는 password 컬럼을 싣고 있으므로 비밀번호 존재 여부를 모델에서 읽는다.
     * 컬럼이 선택되지 않은 모델이면 비밀번호 없음으로 보지 않도록 has_password 를 null 로 남겨
     * 조회가 필요한 노드로 다룬다.
     *
     * @param  Post  $post  게시글
     * @return array<string, mixed> 노드 요약
     */
    private function nodeFromModel(Post $post): array
    {
        $attributes = $post->getAttributes();

        return [
            'id' => (int) $post->id,
            'parent_id' => $post->parent_id !== null ? (int) $post->parent_id : null,
            'user_id' => $post->user_id !== null ? (int) $post->user_id : null,
            'is_secret' => (bool) $post->is_secret,
            'alive' => $post->deleted_at === null,
            'has_password' => array_key_exists('password', $attributes) ? $attributes['password'] !== null : null,
        ];
    }

    /**
     * 노드 캐시에서 노드를 꺼냅니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $id  게시글 ID
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed>|null 노드 요약
     */
    private function node(int $boardId, int $id, Request $request): ?array
    {
        $cache = $request->attributes->get(BoardPermissionCacheKeys::SECRET_THREAD_NODES, []);
        $node = $cache[$boardId][$id] ?? null;

        return is_array($node) ? $node : null;
    }

    /**
     * 조회했지만 없던(다른 게시판·영구 삭제) 글인지 확인합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $id  게시글 ID
     * @param  Request  $request  HTTP 요청
     * @return bool 없는 글로 기록됐으면 true
     */
    private function isKnownMissing(int $boardId, int $id, Request $request): bool
    {
        $cache = $request->attributes->get(BoardPermissionCacheKeys::SECRET_THREAD_NODES, []);

        return ($cache[$boardId][$id] ?? null) === false;
    }

    /**
     * 노드들을 캐시에 넣습니다.
     *
     * 이미 있는 노드의 자식 목록은 보존하고, 비밀번호 여부를 모르는 노드는 조회 결과로 채운다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  array<int, array<string, mixed>>  $nodes  노드 요약 목록
     * @param  Request  $request  HTTP 요청
     * @param  array<int, int>  $requested  조회를 요청한 ID (결과에 없으면 없는 글로 기록)
     * @return void 반환값 없음
     */
    private function storeNodes(int $boardId, array $nodes, Request $request, array $requested = []): void
    {
        $cache = $request->attributes->get(BoardPermissionCacheKeys::SECRET_THREAD_NODES, []);

        foreach ($nodes as $node) {
            $existing = $cache[$boardId][$node['id']] ?? null;
            if (is_array($existing)) {
                $node['kids'] = $existing['kids'] ?? ($node['kids'] ?? null);
                if ($node['has_password'] === null) {
                    $node['has_password'] = $existing['has_password'];
                }
            }
            $cache[$boardId][$node['id']] = $node;
        }

        foreach ($requested as $id) {
            if (! isset($cache[$boardId][$id])) {
                $cache[$boardId][$id] = false;
            }
        }

        $request->attributes->set(BoardPermissionCacheKeys::SECRET_THREAD_NODES, $cache);
    }

    /**
     * 노드들의 자식 목록을 기록합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  array<int, array<int, int>>  $kidsByParent  부모 ID ⇒ 자식 ID 목록
     * @param  Request  $request  HTTP 요청
     * @return void 반환값 없음
     */
    private function storeKids(int $boardId, array $kidsByParent, Request $request): void
    {
        if ($kidsByParent === []) {
            return;
        }

        $cache = $request->attributes->get(BoardPermissionCacheKeys::SECRET_THREAD_NODES, []);
        foreach ($kidsByParent as $parentId => $kidIds) {
            if (is_array($cache[$boardId][$parentId] ?? null)) {
                $cache[$boardId][$parentId]['kids'] = array_values(array_unique($kidIds));
            }
        }
        $request->attributes->set(BoardPermissionCacheKeys::SECRET_THREAD_NODES, $cache);
    }

    /**
     * 회원이 이 게시판에 답변을 쓴 적이 있는지 (요청 단위 메모).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $uid  회원 ID
     * @param  Request  $request  HTTP 요청
     * @return bool 답변 존재 여부
     */
    private function userHasReply(int $boardId, int $uid, Request $request): bool
    {
        $memo = $request->attributes->get(BoardPermissionCacheKeys::SECRET_THREAD_MEMBER_REPLY, []);
        if (! isset($memo[$boardId][$uid])) {
            $memo[$boardId][$uid] = $this->posts->userHasReplyInBoard($boardId, $uid);
            $request->attributes->set(BoardPermissionCacheKeys::SECRET_THREAD_MEMBER_REPLY, $memo);
        }

        return $memo[$boardId][$uid];
    }

    /**
     * 게시글의 게시판 슬러그를 해석합니다 (라우트 슬러그 우선, 로드된 board 관계 다음).
     *
     * @param  Post  $post  게시글
     * @param  Request  $request  HTTP 요청
     * @return string|null 슬러그
     */
    private function slugFor(Post $post, Request $request): ?string
    {
        $slug = $request->route('slug');
        if (is_string($slug) && $slug !== '') {
            return $slug;
        }

        return $post->relationLoaded('board') ? $post->board?->slug : null;
    }

    /**
     * 슬러그로 게시판 모델을 읽습니다 (요청 단위 메모, 게시판 권한 판정과 같은 키를 공유).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Request  $request  HTTP 요청
     * @return Board|null 게시판
     */
    private function boardBySlug(string $slug, Request $request): ?Board
    {
        $memo = $request->attributes->get(BoardPermissionCacheKeys::BOARD_MODEL, []);
        if (! array_key_exists($slug, $memo)) {
            $memo[$slug] = $this->boards->findBySlug($slug) ?? false;
            $request->attributes->set(BoardPermissionCacheKeys::BOARD_MODEL, $memo);
        }

        return $memo[$slug] ?: null;
    }

    /**
     * 현재 요청자의 회원 ID 를 반환합니다.
     *
     * @return int|null 회원 ID
     */
    private function currentUserId(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }
}
