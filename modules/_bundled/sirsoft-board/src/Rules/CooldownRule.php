<?php

namespace Modules\Sirsoft\Board\Rules;

use App\Extension\Cache\ModuleCacheDriver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 쿨다운 검증 규칙
 *
 * 게시글 또는 댓글 작성 시 지정된 시간(초) 내 연속 작성을 제한합니다.
 * 회원은 user_id, 비회원은 IP 주소로 식별합니다.
 *
 * 쿨다운 자리는 검증 시점에 원자적으로 선점한다(`add`) — 「있는지 보고(has) 저장은 나중(Service)」이던 1.2.2 까지는 동시에 들어온
 * 요청이 모두 검증을 통과해 쿨다운을 켠 게시판에 글이 여러 개 써졌다(#161 F7). 작성에 성공하면 Service 가 같은 키를 덮어써 쿨다운이
 * 이어지고, 다른 검증 실패·생성 실패로 끝난 요청은 응답 뒤 자리를 반납한다(422 뒤 쿨다운에 갇히지 않게). 캐시가 오류를 내면 검증을
 * 통과시킨다 — 도배 방지 장치가 캐시 장애로 글쓰기 전체를 막는 쪽이 더 나쁘다(종전 has() 도 장애 시 통과였다).
 */
class CooldownRule implements ValidationRule
{
    /**
     * 이번 요청이 선점한 쿨다운 자리 — 응답 뒤 아직 자기 표지를 들고 있으면 반납한다.
     *
     * @var list<array{store: \Illuminate\Contracts\Cache\Repository, key: string, token: string}>
     */
    private static array $pendingClaims = [];

    /**
     * 반납 콜백을 등록한 앱 인스턴스 id — 수명이 긴 앱(큐 워커·Octane)에서 요청마다 콜백이 쌓이지 않게 앱당 한 번만 건다.
     */
    private static ?int $terminatingRegisteredFor = null;

    /**
     * CooldownRule 생성자
     *
     * @param  string  $type  쿨다운 유형 ('post' 또는 'comment')
     * @param  int  $cooldownSeconds  쿨다운 시간(초), 0이면 비활성화
     * @param  string  $slug  게시판 슬러그
     */
    public function __construct(
        private string $type,
        private int $cooldownSeconds,
        private string $slug
    ) {}

    /**
     * 검증 규칙을 실행합니다.
     *
     * @param  string  $attribute  검증할 속성명
     * @param  mixed  $value  검증할 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 쿨다운이 0이면 비활성화
        if ($this->cooldownSeconds <= 0) {
            return;
        }

        $identifier = Auth::id() ?? request()->ip();
        $cacheKey = "{$this->type}_cooldown_{$this->slug}_{$identifier}";

        // 모듈 컨트롤러와 동일한 ModuleCacheDriver 사용 → 동일 키 namespace 보장
        // (`g7:module.sirsoft-board:` 접두사). Validation Rule 은 컨테이너 DI 가 안 되므로
        // 인스턴스 생성. 동일 모듈 내 다중 호출 시에도 stateless 라 안전.
        $cache = new ModuleCacheDriver('sirsoft-board', config('cache.default', 'array'));

        try {
            // audit:allow no-cache-facade-direct reason: CacheInterface 에는 원자적 선점(add)이 없다 — 같은 스토어(getStore)와
            // 같은 키(resolveKey)로 add 만 직접 부른다. 기록·조회는 종전대로 ModuleCacheDriver 가 맡는다.
            $store = Cache::store($cache->getStore());
            $key = $cache->resolveKey($cacheKey);
            $token = 'claim:'.Str::uuid()->toString();
            $claimed = $store->add($key, $token, $this->cooldownSeconds);
        } catch (Throwable $e) {
            Log::error('[sirsoft-board] 쿨다운 캐시 오류 — 이번 요청은 쿨다운 검사 없이 통과합니다', [
                'type' => $this->type,
                'slug' => $this->slug,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($claimed) {
            // 이 요청이 자리를 잡았다 — 작성에 성공하면 Service 가 값을 덮어써 남고, 아니면 응답 뒤 반납한다
            self::$pendingClaims[] = ['store' => $store, 'key' => $key, 'token' => $token];
            self::registerReleaseOnTerminate();

            return;
        }

        $formattedTime = $this->formatDuration($this->cooldownSeconds);

        // 타입별 전용 메시지가 있으면 사용, 없으면 공통 메시지
        $typeKey = "sirsoft-board::validation.cooldown_required_{$this->type}";
        $typeMessage = __($typeKey, ['time' => $formattedTime]);

        $fail($typeMessage !== $typeKey
            ? $typeMessage
            : __('sirsoft-board::validation.cooldown_required', ['time' => $formattedTime])
        );
    }

    /**
     * 응답 뒤 선점한 자리를 반납하는 콜백을 앱마다 한 번만 등록합니다.
     *
     * @return void 반환값 없음
     */
    private static function registerReleaseOnTerminate(): void
    {
        $app = app();
        if (self::$terminatingRegisteredFor === spl_object_id($app)) {
            return;
        }
        self::$terminatingRegisteredFor = spl_object_id($app);

        $app->terminating(static function (): void {
            $claims = self::$pendingClaims;
            self::$pendingClaims = [];

            foreach ($claims as $claim) {
                try {
                    if ($claim['store']->get($claim['key']) === $claim['token']) {
                        $claim['store']->forget($claim['key']);
                    }
                } catch (Throwable) {
                    // 반납 실패는 쿨다운 시간이 지나면 저절로 풀린다
                }
            }
        });
    }

    /**
     * 초를 사람이 읽기 쉬운 시간 형식으로 변환합니다.
     *
     * 시간·분·초를 단위마다 따로 읽어 공백으로 잇는다(「1시간 30분」, 「1 hour 30 minutes」).
     * en 은 단위 수가 1 이면 단수를 써야 하므로 trans_choice 로 고른다 — 종전에는 복수형 고정이라
     * 기본 쿨다운 60초가 「1 minutes」로 나왔다(7.1.0 실측).
     *
     * @param  int  $seconds  초 단위 시간
     * @return string 변환된 시간 문자열
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds >= 3600) {
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);

            return $this->joinUnits(['hours' => $hours, 'minutes' => $minutes]);
        }

        if ($seconds >= 60) {
            return $this->joinUnits(['minutes' => intdiv($seconds, 60), 'seconds' => $seconds % 60]);
        }

        return $this->joinUnits(['seconds' => $seconds]);
    }

    /**
     * 단위별 수를 문구로 읽어 공백으로 잇습니다. 첫 단위가 아닌 0 은 건너뜁니다.
     *
     * @param  array<string, int>  $units  단위 키(hours|minutes|seconds) => 수, 큰 단위부터
     * @return string 이은 문구
     */
    private function joinUnits(array $units): string
    {
        $parts = [];
        $first = true;

        foreach ($units as $unit => $count) {
            if ($first || $count > 0) {
                $parts[] = trans_choice("sirsoft-board::validation.cooldown_duration.{$unit}", $count, [$unit => $count]);
            }
            $first = false;
        }

        return implode(' ', $parts);
    }
}
