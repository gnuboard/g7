<?php

namespace App\DisplayName;

use App\Enums\UserStatus;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 응답에 회원 이름을 싣는 한 곳 — 표시 이름과 실명 보조(`real_name`)를 함께 만든다.
 *
 * 규칙:
 *
 * 1. 이름 키(`name`, `author_name` 등)에는 그 자리(영역)의 표시 이름만 싣는다.
 * 2. 실명은 별도 키 `real_name` 으로만 싣는다. 관리자 컨트롤러에 도달한 요청이고, 보는 사람에게 회원 조회 권한이 있고,
 *    실명이 표시 이름과 다르고 비어 있지 않을 때만이다. 조건에 맞지 않으면 키 자체를 싣지 않는다.
 * 3. 회원 조회 권한의 범위가 「본인」이나 「소유 역할」로 좁혀진 관리자는 자기 자신의 실명만 본다(행마다 역할을 조회하지 않는다).
 * 4. 탈퇴한 회원의 실명은 싣지 않는다(탈퇴 처리가 이름을 바꿔 둔 값이라 실명이 아니다).
 *
 * 영역을 주지 않으면 요청이 관리자 컨트롤러에 도달했는지로 고른다(관리자 화면 `core:admin`, 그 밖 `core:site`).
 *
 * @since 7.2.0
 */
class MemberNamePresenter
{
    /** 실명 보조를 볼 수 있는 권한 (회원 조회) */
    public const REAL_NAME_PERMISSION = 'core.users.read';

    /** 요청 속성 키: 이 요청에서 실명 보조를 볼 수 있는 범위 판정 결과 */
    protected const REQUEST_GATE_KEY = 'g7.display_name.real_name_gate';

    /**
     * @param  DisplayNameResolver  $resolver  표시 이름 계산기
     */
    public function __construct(
        protected DisplayNameResolver $resolver,
    ) {}

    /**
     * 요청이 관리자 컨트롤러에 도달했는지 반환합니다.
     *
     * 판정 근거는 라우트가 고른 컨트롤러 하나다. 헤더나 쿼리 같은 클라이언트 입력으로 판정하지 않는다.
     *
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @return bool 관리자 컨트롤러에 도달했으면 true
     */
    public function isAdminRequest(?Request $request = null): bool
    {
        $request ??= request();

        return $request->route()?->getController() instanceof AdminBaseController;
    }

    /**
     * 이 요청에서 쓸 코어 영역 키를 반환합니다.
     *
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @return string 관리자 화면이면 `core:admin`, 아니면 `core:site`
     */
    public function areaFor(?Request $request = null): string
    {
        return $this->isAdminRequest($request) ? DisplayNameAreaRegistry::AREA_ADMIN : DisplayNameAreaRegistry::AREA_SITE;
    }

    /**
     * 회원 여럿의 표시 이름 계산에 필요한 값을 미리 읽어 둡니다 (목록에서 N+1 방지).
     *
     * @param  iterable<int, User|null>  $users  회원 목록
     * @param  Request|null  $request  HTTP 요청 (영역을 주지 않았을 때 영역 판정에 쓴다)
     * @param  string|null  $area  영역 키 (생략하면 요청으로 판정)
     * @param  array<string, mixed>  $context  호출 문맥
     * @return void 반환값 없음
     */
    public function preload(iterable $users, ?Request $request = null, ?string $area = null, array $context = []): void
    {
        $this->resolver->preload($users, $area ?? $this->areaFor($request), $context);
    }

    /**
     * 모델 여럿의 회원 관계를 모아 미리 읽어 둡니다.
     *
     * 이미 읽어 둔(eager load 한) 관계만 모은다. 읽지 않은 관계는 건드리지 않는다(여기서 지연 로딩을 일으키지 않는다).
     *
     * @param  iterable<int, mixed>  $models  모델 목록 (리소스로 감싼 것도 된다)
     * @param  array<int, string>  $relations  회원 관계 이름 목록 (예: `['user']`, `['senderUser', 'recipientUser']`)
     * @param  Request|null  $request  HTTP 요청
     * @param  string|null  $area  영역 키 (생략하면 요청으로 판정)
     * @param  array<string, mixed>  $context  호출 문맥
     * @return void 반환값 없음
     */
    public function preloadRelations(iterable $models, array $relations, ?Request $request = null, ?string $area = null, array $context = []): void
    {
        $users = [];
        foreach ($models as $model) {
            while (is_object($model) && ! $model instanceof Model && isset($model->resource)) {
                $model = $model->resource;
            }
            if (! $model instanceof Model) {
                continue;
            }
            foreach ($relations as $relation) {
                $related = $model->relationLoaded($relation) ? $model->getRelation($relation) : null;
                if ($related instanceof User) {
                    $users[$related->getKey()] = $related;
                }
            }
        }

        if ($users !== []) {
            $this->preload($users, $request, $area, $context);
        }
    }

    /**
     * 회원의 표시 이름을 반환합니다.
     *
     * @param  User|null  $user  회원
     * @param  Request|null  $request  HTTP 요청 (영역을 주지 않았을 때 영역 판정에 쓴다)
     * @param  string|null  $area  영역 키 (생략하면 요청으로 판정)
     * @param  array<string, mixed>  $context  호출 문맥
     * @return string|null 표시 이름 (회원이 없거나 보일 값이 없으면 null)
     */
    public function name(?User $user, ?Request $request = null, ?string $area = null, array $context = []): ?string
    {
        if ($user === null) {
            return null;
        }

        return $this->resolver->resolve($user, $area ?? $this->areaFor($request), $context);
    }

    /**
     * 응답에 실을 이름 묶음을 반환합니다.
     *
     * `name` 은 표시 이름이고, 실명 보조를 볼 수 있을 때만 `real_name` 이 더해진다.
     *
     * @param  User|null  $user  회원
     * @param  Request|null  $request  HTTP 요청
     * @param  string|null  $area  영역 키 (생략하면 요청으로 판정)
     * @param  array<string, mixed>  $context  호출 문맥
     * @param  string  $nameKey  이름 키 (기본 `name`)
     * @return array<string, string|null> `[이름 키 => 표시 이름]` 또는 여기에 `real_name` 이 더해진 배열
     */
    public function fields(?User $user, ?Request $request = null, ?string $area = null, array $context = [], string $nameKey = 'name'): array
    {
        $shown = $this->name($user, $request, $area, $context);
        $fields = [$nameKey => $shown];

        $real = $this->realName($user, $shown, $request);
        if ($real !== null) {
            $fields['real_name'] = $real;
        }

        return $fields;
    }

    /**
     * 실명 보조로 실을 값을 반환합니다.
     *
     * @param  User|null  $user  회원
     * @param  string|null  $displayName  같은 응답에 싣는 표시 이름
     * @param  Request|null  $request  HTTP 요청
     * @return string|null 실명 (실을 수 없거나 표시 이름과 같으면 null)
     */
    public function realName(?User $user, ?string $displayName, ?Request $request = null): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = $user->getAttribute('name');
        if (! is_string($name) || trim($name) === '' || $name === $displayName) {
            return null;
        }

        $status = $user->getAttribute('status');
        $status = $status instanceof \BackedEnum ? $status->value : $status;
        if ($status === UserStatus::Withdrawn->value) {
            return null;
        }

        return $this->canExposeRealNameOf($user, $request) ? $name : null;
    }

    /**
     * 이 요청에서 실명 보조를 볼 수 있는지 반환합니다 (대상 회원과 무관한 판정).
     *
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @return bool 관리자 요청이고 회원 조회 권한이 있으면 true
     */
    public function canExposeRealName(?Request $request = null): bool
    {
        return $this->gate($request)['allowed'];
    }

    /**
     * 이 요청에서 이 회원의 실명 보조를 볼 수 있는지 반환합니다.
     *
     * @param  User  $user  대상 회원
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @return bool 볼 수 있으면 true
     */
    public function canExposeRealNameOf(User $user, ?Request $request = null): bool
    {
        $gate = $this->gate($request);
        if (! $gate['allowed']) {
            return false;
        }

        // 조회 범위가 좁혀진 관리자는 자기 자신만 본다.
        return $gate['all'] || ($gate['viewer_id'] !== null && (int) $user->getKey() === $gate['viewer_id']);
    }

    /**
     * 요청 하나의 실명 보조 판정을 계산하고 요청에 적어 둡니다 (행마다 권한을 다시 계산하지 않는다).
     *
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @return array{allowed: bool, all: bool, viewer_id: int|null} 볼 수 있는지, 전체 범위인지, 보는 사람 ID
     */
    protected function gate(?Request $request = null): array
    {
        $request ??= request();

        $cached = $request->attributes->get(self::REQUEST_GATE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $gate = ['allowed' => false, 'all' => false, 'viewer_id' => null];

        if ($this->isAdminRequest($request)) {
            $viewer = $request->user() ?? Auth::user();
            if ($viewer instanceof User && PermissionHelper::check(self::REAL_NAME_PERMISSION, $viewer)) {
                $gate = [
                    'allowed' => true,
                    'all' => PermissionHelper::getEffectiveScope(self::REAL_NAME_PERMISSION, $viewer) === null,
                    'viewer_id' => (int) $viewer->getKey(),
                ];
            }
        }

        $request->attributes->set(self::REQUEST_GATE_KEY, $gate);

        return $gate;
    }
}
