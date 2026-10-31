<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Support\SystemActor;

/**
 * 클레임 도메인 훅을 트랜잭션 커밋 뒤에 발화하는 공용 규칙
 *
 * 클레임 서비스의 상태·접수 훅(`claim.{type}.*`)은 알림·활동 로그가 받는 **사실의 통지**다.
 * 트랜잭션 안에서 발화하면 두 가지가 어긋난다.
 *
 *  - 동기 리스너가 던지면 이미 판정이 끝난 전이까지 함께 롤백된다 — 알림 채널 하나의 장애가
 *    운영자의 승인을 되돌린다.
 *  - 반대로 뒤이은 쓰기가 실패해 롤백되면, 먼저 나간 통지는 되돌릴 수 없다 — 일어나지 않은
 *    전이의 알림이 구매자에게 도착한다.
 *
 * 그래서 발화를 `DB::afterCommit()` 에 맡긴다(#599 validate P2 #7). 열린 트랜잭션이 없으면 즉시
 * 실행되므로 트랜잭션 밖의 호출 지점은 동작이 같고, 트랜잭션 안의 호출은 **등록 순서대로** 커밋
 * 직후에 실행된다(상품 축 → 헤더 축 순서가 유지된다). 롤백되면 발화하지 않는다.
 *
 * 호출 지점은 훅 이름 리터럴을 첫 인자로 쓴 `doAction` 호출을 **그대로** 클로저 안에 둔다 —
 * 확장 문서 생성기(`HookInventory`)가 그 리터럴로 발행 지점을 찾으므로, 훅 이름을 이 트레이트의
 * 인자로 넘기면 문서에서 발행 지점이 사라진다.
 *
 * 금전 복원처럼 **호출자 트랜잭션과 함께 되돌아가야 하는** 훅(`sync => true` 계약)은 이
 * 규칙의 대상이 아니다 — 그 훅은 이 트레이트를 거치지 않는다.
 */
trait FiresClaimHooksAfterCommit
{
    /**
     * 훅 발화 클로저를 현재 트랜잭션의 커밋 뒤에 실행합니다. (트랜잭션 밖이면 즉시)
     *
     * 클로저가 쓰는 값은 등록 시점에 캡처된다 — 커밋 뒤에 다시 읽지 않는다. 작업자도 같다 —
     * 자동 전이(`SystemActor`) 안에서 미룬 발화는 커밋 뒤에도 시스템으로 발화한다(S1-D16).
     *
     * @param  Closure  $fire  `HookManager::doAction(...)` 을 부르는 클로저
     * @return void 반환값 없음
     */
    protected function fireAfterCommit(Closure $fire): void
    {
        DB::afterCommit(SystemActor::wrap($fire));
    }
}
