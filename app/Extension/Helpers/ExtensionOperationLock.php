<?php

namespace App\Extension\Helpers;

use App\Exceptions\ExtensionOperationInProgressException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 같은 확장의 업데이트를 한 번에 하나만 수행하게 하는 원자적 잠금
 *
 * 상태 가드(ExtensionStatusGuard)는 status=updating 을 세운 뒤에야 막는다. 그 전 구간(업데이트 확인·백업)에
 * 들어온 두 번째 요청은 함께 통과해 업데이트가 두 번 수행됐다(#138 실측 ② — 버튼 더블클릭). Manager 진입점에서
 * 잠그므로 CLI·관리자 화면·일괄 업데이트가 같은 잠금을 공유한다.
 *
 * 잡은 잠금은 이 객체가 사라질 때 풀린다 — 호출하는 메서드가 지역 변수로 들고 있으면 정상 반환이든 예외든
 * 메서드를 빠져나가는 순간 해제된다.
 */
final class ExtensionOperationLock
{
    /**
     * 잠금 유지 시간(초). 프로세스가 죽어 해제하지 못해도 이 시간 뒤 풀린다 — 그 뒤의 재진입은 상태 가드가 막는다.
     */
    public const LOCK_SECONDS = 600;

    /**
     * 잡은 잠금을 들고 있는 핸들을 만듭니다 — 이 객체가 사라질 때 잠금이 풀립니다.
     *
     * @param  Lock|null  $lock  잡은 잠금 (저장소 장애로 잠금 없이 진행하면 null)
     */
    private function __construct(private ?Lock $lock) {}

    /**
     * 잠금 키를 반환합니다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return string 잠금 키
     */
    public static function key(string $type, string $identifier): string
    {
        return "extension-update:{$type}:{$identifier}";
    }

    /**
     * 잠금을 잡습니다. 돌려받은 객체를 지역 변수로 들고 있는 동안 잠금이 유지됩니다.
     *
     * 캐시 저장소가 잠금을 제공하지 못하면(장애) 잠금 없이 진행한다 — 업데이트 자체를 막지 않고, 재진입은 상태
     * 가드가 계속 막는다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return self 잠금 보유 객체
     *
     * @throws ExtensionOperationInProgressException 같은 확장의 업데이트가 이미 진행 중일 때
     */
    public static function acquire(string $type, string $identifier): self
    {
        try {
            $lock = Cache::lock(self::key($type, $identifier), self::LOCK_SECONDS);
            $acquired = $lock->get();
        } catch (Throwable $e) {
            Log::warning('확장 업데이트 잠금을 잡지 못해 잠금 없이 진행합니다', [
                'type' => $type,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return new self(null);
        }

        if (! $acquired) {
            throw new ExtensionOperationInProgressException($type, $identifier);
        }

        return new self($lock);
    }

    /**
     * 잠금을 풉니다. 여러 번 불러도 한 번만 풉니다.
     *
     * @return void 반환값 없음
     */
    public function release(): void
    {
        $lock = $this->lock;
        $this->lock = null;

        try {
            $lock?->release();
        } catch (Throwable $e) {
            Log::warning('확장 업데이트 잠금을 풀지 못했습니다 — 유지 시간이 지나면 풀립니다', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 객체가 사라질 때(메서드를 빠져나갈 때) 잠금을 풉니다.
     */
    public function __destruct()
    {
        $this->release();
    }
}
