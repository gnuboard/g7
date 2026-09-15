<?php

namespace Modules\Sirsoft\Ecommerce\Traits;

use App\Helpers\PermissionHelper;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 서비스 계층 스코프 게이트 재적용
 *
 * `PermissionMiddleware` 는 라우트 파라미터가 **Model 로 resolve 될 때만** 스코프를 검사하고,
 * 아니면 "목록 엔드포인트" 로 보아 건너뛴다. 이 모듈은 두 형태가 모두 존재한다:
 *
 * 1. **정적 경로** — `bulk-*`, `reorder` 처럼 리소스 파라미터 자체가 없는 라우트
 * 2. **파라미터명 불일치** — 권한은 `resource_route_key` 로 `shippingPolicy`/`coupon` 을
 *    가리키는데 라우트는 `{id}` 로 선언돼 바인딩이 일어나지 않는 경우. 이쪽은 **상세 경로까지**
 *    무가드가 된다.
 *
 * 두 경우 모두 미들웨어가 아무 것도 하지 않으므로 서비스가 같은 판정을 재적용한다. 판정은
 * `PermissionHelper::checkScopeAccess` 단일 SSoT 에 위임한다 — 여기서 재구현하면 스코프
 * 유형 하나가 빠지는 식으로 경로별 강도가 갈린다.
 */
trait ReappliesPermissionScope
{
    /**
     * 단일 대상이 액터의 스코프 안에 있는지 검사합니다.
     *
     * @param  Model  $model  대상 모델
     * @param  string  $permission  스코프 대상 권한 식별자
     *
     * @throws AccessDeniedHttpException 스코프 밖인 경우
     */
    protected function assertWithinScope(Model $model, string $permission): void
    {
        if (! PermissionHelper::checkScopeAccess($model, $permission)) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }
    }

    /**
     * 일괄 대상 전체가 액터의 스코프 안에 있는지 검사합니다.
     *
     * 걸러내지 않고 **전량 거부**한다. 일괄 작업은 호출자가 지정한 집합에 대한 한 번의
     * 의사표시라, 일부만 조용히 빠지면 호출자는 전부 반영됐다고 믿는다(응답의 처리 건수만
     * 다를 뿐 어느 것이 빠졌는지 알 수 없다).
     *
     * @param  iterable<Model>  $models  대상 모델들
     * @param  string  $permission  스코프 대상 권한 식별자
     *
     * @throws AccessDeniedHttpException 스코프 밖 대상이 하나라도 있는 경우
     */
    protected function assertAllWithinScope(iterable $models, string $permission): void
    {
        foreach ($models as $model) {
            $this->assertWithinScope($model, $permission);
        }
    }
}
