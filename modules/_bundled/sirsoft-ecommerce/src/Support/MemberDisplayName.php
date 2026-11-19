<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use App\DisplayName\DisplayNameQuery;
use App\DisplayName\DisplayNameResolver;
use App\DisplayName\MemberNamePresenter;
use App\Models\User;
use App\Search\KeywordSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * 이커머스가 회원 이름을 응답과 기록에 실을 때 쓰는 한 곳.
 *
 * 회원 이름은 `users.name` 을 직접 읽지 않고 코어 표시 이름 계산기에게 묻는다. 이 클래스는 이커머스의 영역 키와,
 * 「요청이 관리자 컨트롤러에 도달했는지」로 영역을 가르는 규칙을 한 곳에 모은다.
 *
 * | 영역 | 쓰는 자리 | 기본값 |
 * | --- | --- | --- |
 * | `sirsoft-ecommerce:review` | 사용자 화면의 상품 후기 작성자 | 사이트 기본을 따름 |
 * | `sirsoft-ecommerce:inquiry` | 사용자 화면의 상품 문의 작성자 | 가린 이름 고정 |
 * | `sirsoft-ecommerce:admin` | 이커머스 관리자 화면의 회원 이름, 새로 남기는 기록 | 관리자 화면을 따름 |
 *
 * 기능별 설정은 인스턴스(게시판마다 다른 값 같은 것)가 아니라 영역 설정 자체(코어 `member.display_name_areas`)에
 * 저장한다. 그래서 인스턴스 판정과 사용처 목록을 따로 두지 않는다. 닉네임 항목 잠금과 추가 항목 삭제 차단은
 * 코어가 영역 설정을 직접 보고 판정한다.
 *
 * 실명은 이름 키에 싣지 않는다. 관리자 컨트롤러에 도달한 요청이고 보는 사람에게 회원 조회 권한이 있으며,
 * 탈퇴 회원이 아니고 실명이 표시 이름과 다를 때만 별도 키로 싣는다(조건에 맞지 않으면 키 자체가 없다).
 *
 * @since 1.4.0
 */
class MemberDisplayName
{
    /** 영역 키: 사용자 화면의 상품 후기 작성자 */
    public const AREA_REVIEW = 'sirsoft-ecommerce:review';

    /** 영역 키: 사용자 화면의 상품 문의 작성자 */
    public const AREA_INQUIRY = 'sirsoft-ecommerce:inquiry';

    /** 영역 키: 이커머스 관리자 화면 */
    public const AREA_ADMIN = 'sirsoft-ecommerce:admin';

    /** 영역 키: 사이트 기본 (코어) */
    public const AREA_SITE = 'core:site';

    /** 영역 키: 알림 본문에 들어가는 (받는 사람이 아닌) 회원 이름 */
    public const AREA_NOTIFICATION = 'core:notification';

    /** 설정 화면이 다루는 기능 키 => 영역 키 */
    public const FEATURE_AREAS = [
        'review' => self::AREA_REVIEW,
        'inquiry' => self::AREA_INQUIRY,
    ];

    /**
     * 요청이 관리자 컨트롤러에 도달했는지 반환합니다.
     *
     * 판정 근거는 라우트가 고른 컨트롤러다(이메일 노출 게이트 `ExposesMemberContact` 와 같은 근거).
     * 헤더나 쿼리 같은 클라이언트 입력으로 판정하지 않는다.
     *
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @return bool 관리자 컨트롤러에 도달했으면 true
     */
    public static function isAdminRequest(?Request $request = null): bool
    {
        return app(MemberNamePresenter::class)->isAdminRequest($request);
    }

    /**
     * 이 요청에서 쓸 영역 키를 반환합니다.
     *
     * 같은 Resource 가 사용자 화면과 관리자 화면에 함께 쓰일 때, 관리자 컨트롤러에 도달한 요청은 관리자 영역으로,
     * 그 밖은 넘겨준 사용자 화면 영역으로 계산한다.
     *
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @param  string  $userArea  사용자 화면에서 쓸 영역 키
     * @return string 영역 키
     */
    public static function areaFor(?Request $request, string $userArea): string
    {
        return self::isAdminRequest($request) ? self::AREA_ADMIN : $userArea;
    }

    /**
     * 회원의 표시 이름을 반환합니다.
     *
     * @param  User|null  $user  회원 (없으면 `$guestName` 을 그대로 쓴다)
     * @param  string  $area  영역 키
     * @param  string|null  $guestName  회원이 없을 때 보일 이름 (비회원, 이관 후기, 회원 행이 지워진 뒤 남은 이름)
     * @return string|null 표시 이름 (보일 값이 없으면 null)
     */
    public static function name(?User $user, string $area = self::AREA_ADMIN, ?string $guestName = null): ?string
    {
        return app(DisplayNameResolver::class)->resolve($user, $area, $user === null ? ['guest_name' => $guestName] : []);
    }

    /**
     * 회원 여럿의 표시 이름을 한 번에 반환합니다.
     *
     * 추가 항목을 표시 이름으로 쓰는 영역이면 값을 쿼리 한 번으로 읽는다.
     *
     * @param  iterable<int, User|null>  $users  회원 목록
     * @param  string  $area  영역 키
     * @return array<int|string, string|null> 회원 ID => 표시 이름
     */
    public static function names(iterable $users, string $area = self::AREA_ADMIN): array
    {
        return app(DisplayNameResolver::class)->resolveMany($users, $area);
    }

    /**
     * 실명 보조로 실을 값을 반환합니다.
     *
     * 다음을 모두 만족할 때만 실명을 돌려준다: 요청이 관리자 컨트롤러에 도달했다, 보는 사람에게 회원 조회 권한
     * (`core.users.read`)이 있다, 탈퇴한 회원이 아니다, 실명이 비어 있지 않고 같은 응답의 표시 이름과 다르다.
     *
     * @param  User|null  $user  회원
     * @param  string|null  $displayed  같은 응답에 싣는 표시 이름
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @return string|null 실명 (실을 수 없으면 null)
     */
    public static function realName(?User $user, ?string $displayed, ?Request $request = null): ?string
    {
        return app(MemberNamePresenter::class)->realName($user, $displayed, $request);
    }

    /**
     * 응답에 실을 이름 묶음을 반환합니다.
     *
     * 이름 키에는 표시 이름을, 실명을 실을 수 있을 때만 실명 키를 더한다. 실명 키의 이름은 이름 키가 `name` 이면
     * `real_name`, 그 밖에는 이름 키의 `_name` 을 `_real_name` 으로 바꾼 것이다(`user_name` → `user_real_name`).
     *
     * @param  User|null  $user  회원
     * @param  Request|null  $request  HTTP 요청
     * @param  string  $area  영역 키
     * @param  string  $nameKey  이름 키 (기본 `name`)
     * @param  string|null  $guestName  회원이 없을 때 보일 이름
     * @return array<string, string|null> `[이름 키 => 표시 이름]` 또는 여기에 실명 키가 더해진 배열
     */
    public static function fields(?User $user, ?Request $request, string $area = self::AREA_ADMIN, string $nameKey = 'name', ?string $guestName = null): array
    {
        $shown = self::name($user, $area, $guestName);
        $fields = [$nameKey => $shown];

        $real = self::realName($user, $shown, $request);
        if ($real !== null) {
            $fields[self::realNameKey($nameKey)] = $real;
        }

        return $fields;
    }

    /**
     * 등록자나 처리자처럼 관리자 화면에서 보이는 회원의 이름 묶음을 반환합니다.
     *
     * 관리자 컨트롤러에 도달한 요청은 이커머스 관리자 영역으로, 그 밖의 요청(같은 Resource 를 사용자 화면이 쓰는 경우)은
     * 사이트 기본 영역으로 계산한다. 실명 키는 관리자 요청에서만 붙는다.
     *
     * @param  User|null  $user  회원
     * @param  Request|null  $request  HTTP 요청
     * @param  string  $nameKey  이름 키 (기본 `name`)
     * @return array<string, string|null> 이름 묶음
     */
    public static function staffFields(?User $user, ?Request $request, string $nameKey = 'name'): array
    {
        return self::fields($user, $request, self::areaFor($request, self::AREA_SITE), $nameKey);
    }

    /**
     * 이름 키에 대응하는 실명 키 이름을 반환합니다.
     *
     * @param  string  $nameKey  이름 키 (예: `name`, `user_name`, `granted_by_name`)
     * @return string 실명 키 (예: `real_name`, `user_real_name`, `granted_by_real_name`)
     */
    public static function realNameKey(string $nameKey): string
    {
        if ($nameKey === 'name') {
            return 'real_name';
        }

        return str_ends_with($nameKey, '_name')
            ? substr($nameKey, 0, -strlen('_name')).'_real_name'
            : $nameKey.'_real_name';
    }

    /**
     * 회원 여럿의 표시 이름 계산에 필요한 값을 미리 읽어 둡니다 (목록에서 행 수만큼 쿼리가 늘지 않게).
     *
     * 추가 항목을 표시 이름으로 쓰는 영역에서만 쿼리가 한 번 나간다. 그 밖에는 아무것도 읽지 않는다.
     *
     * @param  iterable<int, User|null>  $users  회원 목록
     * @param  string  $area  영역 키
     * @return void 반환값 없음
     */
    public static function preload(iterable $users, string $area = self::AREA_ADMIN): void
    {
        app(DisplayNameResolver::class)->preload($users, $area);
    }

    /**
     * 모델 목록의 읽어 둔 회원 관계를 모아 미리 읽어 둡니다.
     *
     * 이미 읽어 둔(eager load 한) 관계만 모은다. 여기서 지연 로딩을 일으키지 않는다.
     *
     * @param  iterable<int, mixed>  $models  모델 목록 (리소스로 감싼 것도 된다)
     * @param  array<int, string>  $relations  회원 관계 이름 목록 (예: `['user']`, `['user', 'grantedByUser']`)
     * @param  string  $area  영역 키
     * @param  Request|null  $request  HTTP 요청
     * @return void 반환값 없음
     */
    public static function preloadRelations(iterable $models, array $relations, string $area = self::AREA_ADMIN, ?Request $request = null): void
    {
        app(MemberNamePresenter::class)->preloadRelations($models, $relations, $request, $area);
    }

    /**
     * {@see self::staffFields()} 로 이름을 싣는 목록의 회원 관계를 미리 읽어 둡니다.
     *
     * 영역은 `staffFields()` 와 같은 규칙으로 고른다(관리자 요청은 이커머스 관리자 영역, 그 밖은 사이트 기본).
     *
     * @param  iterable<int, mixed>  $models  모델 목록
     * @param  array<int, string>  $relations  회원 관계 이름 목록
     * @param  Request|null  $request  HTTP 요청
     * @return void 반환값 없음
     */
    public static function preloadStaffRelations(iterable $models, array $relations, ?Request $request = null): void
    {
        self::preloadRelations($models, $relations, self::areaFor($request, self::AREA_SITE), $request);
    }

    /**
     * 관리자 화면의 회원 검색 조건을 회원 쿼리에 겁니다 (이름 또는 닉네임).
     *
     * 종전에 이메일도 함께 찾던 화면은 `$withEmail` 을 켜 이메일 조건을 유지한다.
     *
     * @param  Builder  $users  회원 쿼리 (`whereHas('user', …)` 의 안쪽 쿼리)
     * @param  string  $keyword  검색어 (`%` 를 붙이지 않은 원문)
     * @param  bool  $withEmail  이메일도 함께 찾을지
     * @return Builder 조건을 건 쿼리
     */
    public static function constrainUsersForAdmin(Builder $users, string $keyword, bool $withEmail = false): Builder
    {
        $helper = app(DisplayNameQuery::class);

        if (! $withEmail) {
            return $helper->constrainUsersForAdmin($users, $keyword);
        }

        return $users->where(function (Builder $where) use ($helper, $keyword) {
            $helper->constrainUsersForAdmin($where, $keyword);
            $where->orWhere($where->qualifyColumn('email'), 'like', '%'.KeywordSearch::escapeLikeWildcards($keyword).'%');
        });
    }
}
