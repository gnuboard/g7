<?php

namespace Modules\Sirsoft\Board\DisplayName;

use App\DisplayName\DisplayNameArea;
use App\DisplayName\DisplayNameKindRegistry;
use App\DisplayName\DisplayNameQuery;
use App\DisplayName\DisplayNameResolver;
use App\DisplayName\MemberNamePresenter;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 게시판의 작성자 표시 이름 창구.
 *
 * 게시판 모듈은 회원 이름을 응답에 실을 때 `users.name` 을 직접 읽지 않고 이 클래스를 거친다.
 *
 * | 자리 | 영역 | 게시판별 설정 |
 * | --- | --- | --- |
 * | 사용자 화면(글, 댓글, 최근글, 인기글, 검색, 스크랩, 반응 목록) | `sirsoft-board:posts` | 적용 |
 * | 관리자 화면(게시글, 댓글, 신고, 스크랩, 반응 관리, 대시보드) | `sirsoft-board:admin` | 적용하지 않음 |
 *
 * 실명은 관리자 화면 요청이고 회원 조회 권한이 있을 때만 별도 키 `real_name` 으로 싣는다({@see self::realName()}).
 *
 * @since 1.3.0
 */
class BoardDisplayNames
{
    use ChecksBoardPermission {
        isAdminRequest as protected adminRequestOf;
    }

    /** 사용자 화면의 게시판 영역 */
    public const AREA_POSTS = 'sirsoft-board:posts';

    /** 관리자 화면의 게시판 영역 */
    public const AREA_ADMIN = 'sirsoft-board:admin';

    /**
     * 게시판 ID 와 슬러그로 찾는 표시 이름 설정 메모 (null 이면 아직 읽지 않음).
     *
     * @var array{by_id: array<int, array<string, mixed>>, by_slug: array<string, array<string, mixed>>, rows: array<int, array<string, mixed>>}|null
     */
    protected ?array $settings = null;

    /**
     * @param  DisplayNameResolver  $resolver  표시 이름 계산기
     * @param  DisplayNameQuery  $query  표시 이름 검색, 정렬 헬퍼
     * @param  DisplayNameKindRegistry  $kinds  표시 이름 종류 등록소
     * @param  BoardRepositoryInterface  $boards  게시판 저장소
     * @param  MemberNamePresenter  $presenter  코어 회원 이름 표현기 (실명 보조 판정)
     */
    public function __construct(
        protected DisplayNameResolver $resolver,
        protected DisplayNameQuery $query,
        protected DisplayNameKindRegistry $kinds,
        protected BoardRepositoryInterface $boards,
        protected MemberNamePresenter $presenter,
    ) {}

    /**
     * 요청이 그리는 화면의 영역 키를 반환합니다.
     *
     * 판정 근거는 요청이 도달한 컨트롤러가 관리자 컨트롤러인지 하나다(클라이언트 입력으로 바꿀 수 없다).
     * 판정 규칙은 게시판 리소스와 같은 {@see ChecksBoardPermission::isAdminRequest()} 한 곳이다.
     *
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @return string 영역 키
     */
    public function areaFor(?Request $request = null): string
    {
        return $this->isAdminRequest($request) ? self::AREA_ADMIN : self::AREA_POSTS;
    }

    /**
     * 관리자 컨트롤러에 도달한 요청인지 반환합니다.
     *
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @return bool 관리자 화면 요청이면 true
     */
    public function isAdminRequest(?Request $request = null): bool
    {
        return $this->adminRequestOf($request ?? request());
    }

    /**
     * 계산기에 넘길 호출 문맥을 만듭니다.
     *
     * @param  Board|int|string|null  $board  게시판 (모델, ID, 슬러그)
     * @param  string|null  $guestName  비회원이 입력한 이름
     * @return array<string, mixed> 호출 문맥
     */
    public function context(Board|int|string|null $board = null, ?string $guestName = null): array
    {
        $context = [];
        if ($board !== null) {
            $context['board'] = $board;
        }
        if ($guestName !== null) {
            $context['guest_name'] = $guestName;
        }

        return $context;
    }

    /**
     * 작성자 표시 이름을 반환합니다.
     *
     * 회원이 없으면(비회원 글, 계정이 지워진 회원의 글) 저장된 작성자 이름을 그대로 보인다.
     * 탈퇴한 회원은 종류와 무관하게 「탈퇴한 사용자」 문구다.
     *
     * @param  User|null  $user  작성자 회원 (비회원이면 null)
     * @param  string|null  $guestName  글에 저장된 작성자 이름 (비회원)
     * @param  string  $area  영역 키
     * @param  Board|int|string|null  $board  게시판 (사용자 화면 영역에서 게시판별 설정을 적용한다)
     * @return string|null 표시 이름 (보일 값이 없으면 null)
     */
    public function name(?User $user, ?string $guestName = null, string $area = self::AREA_POSTS, Board|int|string|null $board = null): ?string
    {
        return $this->resolver->resolve($user, $area, $this->context($area === self::AREA_POSTS ? $board : null, $guestName));
    }

    /**
     * 글이나 댓글 모델의 작성자 표시 이름을 반환합니다.
     *
     * 회원 관계(`user`)가 실려 있지 않으면 읽지 않는다(목록에서 N+1 을 만들지 않기 위해서다). 그때는 저장된 작성자 이름만 쓴다.
     * 리소스로 감싼 모델도 받는다(컬렉션이 만든 리소스는 모델을 한 겹 더 감싸고 있다).
     *
     * @param  mixed  $content  글 또는 댓글 모델, 또는 그것을 감싼 리소스 (`user_id`, `author_name`, `user` 관계)
     * @param  string  $area  영역 키
     * @param  Board|int|string|null  $board  게시판 (생략하면 모델의 `board_id`)
     * @return string|null 표시 이름 (모델이 아니면 null)
     */
    public function authorOf(mixed $content, string $area = self::AREA_POSTS, Board|int|string|null $board = null): ?string
    {
        $content = $this->unwrap($content);
        if (! $content instanceof Model) {
            return null;
        }

        $user = $content->getAttribute('user_id') && $content->relationLoaded('user') ? $content->getRelation('user') : null;

        return $this->name(
            $user instanceof User ? $user : null,
            $content->getAttribute('author_name'),
            $area,
            $board ?? $this->boardOf($content),
        );
    }

    /**
     * 리소스로 감싼 값을 모델까지 벗깁니다.
     *
     * @param  mixed  $value  모델 또는 리소스
     * @return mixed 가장 안쪽 값
     */
    protected function unwrap(mixed $value): mixed
    {
        while ($value instanceof JsonResource) {
            $value = $value->resource;
        }

        return $value;
    }

    /**
     * 이 회원이 이 게시판에서 보이는 종류 키를 반환합니다 (글쓰기 화면의 안내 줄).
     *
     * @param  User|null  $user  회원
     * @param  Board|int|string|null  $board  게시판
     * @return string 종류 키
     */
    public function effectiveKind(?User $user, Board|int|string|null $board = null): string
    {
        return $this->resolver->effectiveKind($user, self::AREA_POSTS, $this->context($board));
    }

    /**
     * 종류의 화면용 이름을 반환합니다.
     *
     * @param  string|null  $kind  종류 키
     * @return string|null 종류 이름 (지금 없는 종류면 null)
     */
    public function kindLabel(?string $kind): ?string
    {
        return $this->kinds->get($kind)?->label();
    }

    /**
     * 실명 보조 줄에 실을 값을 반환합니다.
     *
     * 판정은 코어 회원 이름 표현기가 한다. 관리자 화면 요청이고 요청자가 회원 조회 권한을 가졌을 때만, 그리고 실명이 표시 이름과
     * 다르고 비어 있지 않을 때만 값을 준다. 탈퇴한 회원의 이름은 탈퇴 처리가 바꿔 둔 값이라 싣지 않는다.
     *
     * @param  User|null  $user  회원
     * @param  string|null  $displayName  같은 응답에 실은 표시 이름
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @return string|null 실명 (싣지 않으면 null)
     */
    public function realName(?User $user, ?string $displayName, ?Request $request = null): ?string
    {
        return $this->presenter->realName($user, $displayName, $request);
    }

    /**
     * 요청자가 실명 보조 줄을 볼 수 있는지 반환합니다.
     *
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @return bool 관리자 화면 요청이고 회원 조회 권한이 있으면 true
     */
    public function canSeeRealName(?Request $request = null): bool
    {
        return $this->presenter->canExposeRealName($request);
    }

    /**
     * 실명 보조 줄을 배열에 덧붙입니다 (값이 있을 때만 키를 만든다).
     *
     * @param  array<string, mixed>  $payload  응답 배열
     * @param  User|null  $user  회원
     * @param  string|null  $displayName  같은 응답에 실은 표시 이름
     * @param  Request|null  $request  HTTP 요청
     * @param  string  $key  실명 키
     * @return array<string, mixed> 실명이 더해진 응답 배열
     */
    public function withRealName(array $payload, ?User $user, ?string $displayName, ?Request $request = null, string $key = 'real_name'): array
    {
        $real = $this->realName($user, $displayName, $request);
        if ($real !== null) {
            $payload[$key] = $real;
        }

        return $payload;
    }

    /**
     * 목록에 실릴 회원들의 표시 이름용 값을 미리 읽어 둡니다 (추가 항목 종류가 쓰일 때의 N+1 방지).
     *
     * @param  iterable<int, mixed>  $items  회원 또는 `user` 관계를 실은 모델 목록
     * @param  string  $area  영역 키
     * @param  Board|int|string|null  $board  게시판 (여러 게시판이 섞인 목록이면 null)
     * @return void 반환값 없음
     */
    public function preload(iterable $items, string $area = self::AREA_POSTS, Board|int|string|null $board = null): void
    {
        $users = [];
        foreach ($items as $item) {
            $item = $this->unwrap($item);
            $user = $item instanceof User
                ? $item
                : ($item instanceof Model && $item->relationLoaded('user') ? $item->getRelation('user') : null);
            if ($user instanceof User) {
                $users[$user->getKey()] = $user;
            }
        }
        if ($users === []) {
            return;
        }

        // 여러 게시판이 섞인 목록은 게시판마다 종류가 다를 수 있어 등록된 추가 항목 종류 전부에 대비한다.
        if ($area === self::AREA_POSTS && $board === null) {
            $this->resolver->preload($users);

            return;
        }

        $this->resolver->preload($users, $area, $this->context($area === self::AREA_POSTS ? $board : null));
    }

    /**
     * 사용자 화면의 작성자 검색 조건을 겁니다 (그 게시판에서 보이는 종류의 원본 값만 찾는다).
     *
     * @param  Builder  $query  글 또는 댓글 쿼리
     * @param  string  $keyword  검색어
     * @param  Board|int|string|null  $board  게시판
     * @param  array<string, mixed>  $options  `guest_column`, `user_key`, `boolean`, `relation`
     * @return Builder 조건을 건 쿼리
     */
    public function applySearch(Builder $query, string $keyword, Board|int|string|null $board = null, array $options = []): Builder
    {
        return $this->query->applySearch($query, $keyword, self::AREA_POSTS, ['context' => $this->context($board)] + $options);
    }

    /**
     * 관리자 화면의 작성자 검색 조건을 겁니다 (이름과 닉네임을 모두 찾는다).
     *
     * @param  Builder  $query  작성자 관계를 가진 모델의 쿼리
     * @param  string  $keyword  검색어
     * @param  array<string, mixed>  $options  `guest_column`, `user_key`, `boolean`, `relation`
     * @return Builder 조건을 건 쿼리
     */
    public function applyAdminSearch(Builder $query, string $keyword, array $options = []): Builder
    {
        return $this->query->applyAdminSearch($query, $keyword, $options);
    }

    /**
     * 회원 쿼리에 관리자 화면 검색 조건(이름 또는 닉네임)을 겁니다.
     *
     * @param  Builder  $users  회원 쿼리
     * @param  string  $keyword  검색어
     * @return Builder 조건을 건 쿼리
     */
    public function constrainUsersForAdmin(Builder $users, string $keyword): Builder
    {
        return $this->query->constrainUsersForAdmin($users, $keyword);
    }

    /**
     * 작성자순 정렬 식을 반환합니다 (조인 없이 쓰는 상관 서브쿼리 식).
     *
     * 글 목록은 본 테이블의 컬럼을 한정하지 않은 조건이 많아 users 를 조인하면 `status`, `id` 같은 컬럼이 모호해진다.
     * 그래서 조인 대신 「그 글의 회원 한 명에서 화면에 보이는 값」을 구하는 상관 서브쿼리로 식을 만든다.
     * 회원이 없는 행(비회원 글, 계정이 지워진 회원의 글)은 저장된 작성자 이름으로 자리를 잡는다.
     * 식 자체를 비교할 수 있어 이전 글, 다음 글 조회에도 같은 식을 쓴다.
     *
     * 인덱스를 타지 못하는 정렬이다. 글이 많은 게시판에서는 그 게시판의 글 전체를 훑는다.
     *
     * @param  Builder  $query  글 쿼리 (접두와 인용 규칙을 얻는다)
     * @param  string  $area  영역 키
     * @param  Board|int|string|null  $board  게시판
     * @param  string  $userKey  회원 외래키 컬럼 (예: `board_posts.user_id`)
     * @param  string  $guestColumn  저장된 작성자 이름 컬럼 (예: `board_posts.author_name`)
     * @return Expression 정렬 식
     */
    public function sortExpression(Builder $query, string $area, Board|int|string|null $board, string $userKey, string $guestColumn): Expression
    {
        $base = $query->getQuery();
        $grammar = $base->getGrammar();
        $alias = DisplayNameQuery::USERS_ALIAS;

        $kindExpression = $this->query->sortExpression($query, $area, [
            'users_alias' => $alias,
            'context' => $this->context($area === self::AREA_POSTS ? $board : null),
        ]);

        $sub = DB::connection($base->getConnection()->getName())
            ->table((new User)->getTable().' as '.$alias)
            ->selectRaw((string) $kindExpression->getValue($grammar))
            ->whereColumn($alias.'.id', $userKey)
            ->limit(1);

        return DB::raw('COALESCE(('.$sub->toSql().'), '.$grammar->wrap($guestColumn).')');
    }

    /**
     * 게시판 설정 폼이 그릴 표시 이름 선택지를 반환합니다.
     *
     * @return array{kinds: array<int, array{value: string, label: string, requires_nickname: bool}>, inherited: array{mode: string, kind: string|null, kind_label: string|null}} 종류 목록과 「따름」일 때 적용되는 값
     */
    public function formOptions(): array
    {
        $kinds = [];
        foreach ($this->kinds->all() as $key => $kind) {
            $kinds[] = [
                'value' => (string) $key,
                'label' => $kind->label(),
                'requires_nickname' => $kind->requiresNickname(),
            ];
        }

        // 게시판 설정이 없을 때 이 게시판에 적용되는 값 (회원 설정의 게시판 영역 → 사이트 기본)
        $inherited = $this->resolver->areaRule(self::AREA_POSTS);

        return [
            'kinds' => $kinds,
            'inherited' => [
                'mode' => $inherited['mode'],
                'kind' => $inherited['kind'],
                'kind_label' => $this->kindLabel($inherited['kind']),
            ],
        ];
    }

    /**
     * 게시판의 표시 이름 설정을 폼 값 모양으로 반환합니다.
     *
     * @param  Board  $board  게시판
     * @return array{mode: string, kind: string|null} 방식(inherit|fixed|member)과 종류
     */
    public function formValue(Board $board): array
    {
        $setting = self::normalize($board->getAttribute('display_name_setting'));

        return $setting ?? ['mode' => DisplayNameArea::MODE_INHERIT, 'kind' => null];
    }

    /**
     * 저장할 표시 이름 설정 모양으로 정리합니다.
     *
     * @param  mixed  $setting  요청이나 컬럼에서 온 값
     * @return array{mode: string, kind: string|null}|null 고정 또는 회원 선택 설정 (따름이거나 모양이 맞지 않으면 null)
     */
    public static function normalize(mixed $setting): ?array
    {
        if (! is_array($setting)) {
            return null;
        }

        $mode = $setting['mode'] ?? null;
        if ($mode === DisplayNameArea::MODE_MEMBER) {
            return ['mode' => DisplayNameArea::MODE_MEMBER, 'kind' => null];
        }
        if ($mode === DisplayNameArea::MODE_FIXED && is_string($setting['kind'] ?? null) && $setting['kind'] !== '') {
            return ['mode' => DisplayNameArea::MODE_FIXED, 'kind' => $setting['kind']];
        }

        return null;
    }

    /**
     * 호출 문맥이 가리키는 게시판의 표시 이름 설정을 반환합니다 (영역 선언의 `instance_resolver`).
     *
     * @param  array<string, mixed>  $context  호출 문맥 (`board`: 게시판 모델, ID, 슬러그)
     * @return array{mode: string, kind: string|null}|null 게시판 설정 (없거나 따름이면 null)
     */
    public function instanceSetting(array $context): ?array
    {
        $board = $context['board'] ?? null;

        if ($board instanceof Board && array_key_exists('display_name_setting', $board->getAttributes())) {
            return self::normalize($board->getAttribute('display_name_setting'));
        }

        $settings = $this->settings();
        $row = match (true) {
            $board instanceof Board => $settings['by_id'][(int) $board->getKey()] ?? null,
            is_int($board) || (is_string($board) && ctype_digit($board)) => $settings['by_id'][(int) $board] ?? null,
            is_string($board) && $board !== '' => $settings['by_slug'][$board] ?? null,
            default => null,
        };

        return $row === null ? null : self::normalize($row['setting']);
    }

    /**
     * 게시판들이 쓰고 있는 표시 이름 설정을 반환합니다 (영역 선언의 `used_kinds_provider`).
     *
     * 닉네임 항목 잠금 판정과 추가 항목 삭제 차단의 사용처 안내에 쓰인다.
     *
     * @return array<int, array{mode: string, kind: string|null, label: string}> 게시판별 설정
     */
    public function usages(): array
    {
        $usages = [];
        foreach ($this->settings()['rows'] as $row) {
            $setting = self::normalize($row['setting']);
            if ($setting !== null) {
                $usages[] = [...$setting, 'label' => $row['label']];
            }
        }

        return $usages;
    }

    /**
     * 읽어 둔 게시판 설정과 판정 메모를 버립니다 (게시판을 저장하거나 지운 뒤).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->settings = null;
    }

    /**
     * 표시 이름을 직접 정한 게시판의 설정을 읽습니다 (프로세스에서 한 번).
     *
     * @return array{by_id: array<int, array<string, mixed>>, by_slug: array<string, array<string, mixed>>, rows: array<int, array<string, mixed>>} 설정 메모
     */
    protected function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $memo = ['by_id' => [], 'by_slug' => [], 'rows' => []];
        foreach ($this->boards->getBoardsWithDisplayNameSetting() as $board) {
            $row = [
                'setting' => $board->getAttribute('display_name_setting'),
                'label' => (string) $board->getLocalizedName(),
            ];
            $memo['by_id'][(int) $board->getKey()] = $row;
            $memo['by_slug'][(string) $board->slug] = $row;
            $memo['rows'][] = $row;
        }

        return $this->settings = $memo;
    }

    /**
     * 글이나 댓글 모델이 속한 게시판을 반환합니다 (실려 있으면 모델, 아니면 ID).
     *
     * @param  Model  $content  글 또는 댓글
     * @return Board|int|null 게시판
     */
    protected function boardOf(Model $content): Board|int|null
    {
        if ($content->relationLoaded('board') && $content->getRelation('board') instanceof Board) {
            $board = $content->getRelation('board');

            // 컬럼 제한으로 읽은 게시판(`board:id,slug,name`)은 설정 컬럼이 없다. 그때는 ID 로 찾는다.
            return array_key_exists('display_name_setting', $board->getAttributes()) ? $board : (int) $board->getKey();
        }

        $boardId = $content->getAttribute('board_id');

        return $boardId !== null ? (int) $boardId : null;
    }
}
