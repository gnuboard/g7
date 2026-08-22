<?php

namespace App\Extension\Traits;

/**
 * 확장이 수명주기 훅에서 실패 사유를 알리는 통로.
 *
 * `install()` / `activate()` / `deactivate()` / `uninstall()` 은 bool 만 돌려주므로,
 * 확장이 "왜" 거부했는지가 호출자에게 전달되지 않는다. 그 결과 관리자 화면에는
 * 원인 자리가 빈 실패 문구만 남는다.
 *
 * 확장은 `failWith()` 로 사유를 남기며 false 를 돌려주고, 코어(Manager)는
 * `getLifecycleFailureReason()` 으로 그 사유를 읽어 응답에 싣는다.
 * 사유를 남기지 않은 확장은 null 이며, 이 경우 코어가 일반 문구로 대체한다.
 *
 * 사유는 이미 번역된 문장이어야 한다 — 확장의 언어 파일 키는 코어가 해석할 수 없다.
 */
trait ReportsLifecycleFailure
{
    /** @var string|null 마지막 수명주기 실패 사유 (번역된 문장) */
    protected ?string $lifecycleFailureReason = null;

    /**
     * 마지막 수명주기 훅이 남긴 실패 사유를 반환합니다.
     *
     * @return string|null 실패 사유. 남기지 않았으면 null
     */
    public function getLifecycleFailureReason(): ?string
    {
        return $this->lifecycleFailureReason;
    }

    /**
     * 실패 사유를 남기고 false 를 반환합니다.
     *
     * 수명주기 훅에서 `return $this->failWith(__('...'));` 형태로 사용합니다.
     *
     * @param  string  $reason  운영자에게 보일 실패 사유 (번역된 문장)
     * @return false 언제나 false
     */
    protected function failWith(string $reason): bool
    {
        $this->lifecycleFailureReason = $reason;

        return false;
    }

    /**
     * 직전 실패 사유를 지웁니다.
     *
     * 같은 확장 인스턴스로 수명주기 훅을 다시 부르기 전에 코어가 호출합니다.
     * 지우지 않으면 이전 실패의 사유가 다음 성공/실패에 그대로 따라붙는다.
     */
    public function clearLifecycleFailureReason(): void
    {
        $this->lifecycleFailureReason = null;
    }
}
