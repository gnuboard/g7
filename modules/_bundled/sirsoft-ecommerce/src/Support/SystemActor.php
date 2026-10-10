<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * 사람이 누르지 않은 전이를 **시스템**으로 실행합니다.
 *
 * 활동 로그의 작업자는 코어 `ActivityLogProcessor` 가 `user_id ??= Auth::id()` 로 채우고,
 * 큐로 가는 훅은 발화 시점의 `Auth::id()` 를 `HookContextCapture` 가 실어 간다. 자동 승인·
 * 자동 진행은 고객 요청 안에서 돌기 때문에 신청서의 `processed_by` 를 비워도 로그인한 고객이
 * 작업자로 남았다(S1-D16). 저장소의 관례는 작업자 NULL 을 화면이 시스템으로 읽는 것이다
 * (입금기한 만료 자동취소와 같다).
 *
 * 범위 안에서는 저장되지 않은 빈 사용자를 인증 사용자로 둔다 — `Auth::id()` 는 null 이고,
 * `forgetUser()` 와 달리 요청 가드가 토큰으로 고객을 다시 읽어 오지 않는다. 범위를 나가면
 * 원래 사용자로 되돌린다.
 *
 * 클레임 훅은 커밋 뒤로 미뤄 발화하므로(`FiresClaimHooksAfterCommit`) 범위 안에서 미룬 발화는
 * `wrap()` 으로 같은 범위를 다시 씌운다 — 그러지 않으면 커밋 시점에는 이미 고객으로 돌아와 있다.
 */
final class SystemActor
{
    private static int $depth = 0;

    /**
     * 콜백을 작업자 없이(시스템으로) 실행합니다.
     *
     * @template T
     *
     * @param  Closure(): T  $callback  실행할 콜백
     * @return T 콜백의 반환값
     */
    public static function run(Closure $callback): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->hasUser() ? $guard->user() : null;

        self::$depth++;
        $guard->setUser(new User);

        try {
            return $callback();
        } finally {
            self::$depth--;

            if ($previous !== null) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }

    /**
     * 큐 작업 하나를 작업자 없이(시스템으로) 처리하도록 인증 사용자를 비웁니다.
     *
     * 시스템 범위에서 발화한 훅이 큐로 가면 워커가 컨텍스트를 복원하는데, 코어는 `user_id` 가
     * 비어 있으면 인증 사용자를 그대로 둔다 — 같은 워커가 앞서 처리한 작업의 사용자가 남는다.
     * 작업이 끝나면 워커가 다음 작업에서 다시 복원하므로 되돌리지 않는다.
     *
     * @return void 반환값 없음
     */
    public static function assumeForJob(): void
    {
        Auth::guard()->setUser(new User);
    }

    /**
     * 시스템 범위 안인지 돌려줍니다.
     *
     * @return bool 범위 안이면 true
     */
    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /**
     * 지금 시스템 범위라면, 나중에 실행될 콜백에도 같은 범위를 씌웁니다.
     *
     * @param  Closure  $callback  나중에 실행될 콜백
     * @return Closure 범위를 이어받은 콜백 (범위 밖이면 그대로)
     */
    public static function wrap(Closure $callback): Closure
    {
        if (! self::active()) {
            return $callback;
        }

        return fn () => self::run($callback);
    }
}
