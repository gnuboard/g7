<?php

namespace Plugins\Sirsoft\HeroSection\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;

/**
 * 노출 위치가 등록돼 있는지 확인한다 — 위치 목록은 위치 필터(`PositionRegistry`)가 정한다.
 *
 * 등록되지 않은 위치(위치를 더한 확장을 껐을 때)의 배너는 지우지 않고 관리자에게만 보인다(D7). 그래서 두 가지 예외를 둔다:
 * 수정하는 배너에 저장돼 있던 위치(`$stored`)는 그대로 둘 수 있고, 관리자 목록(`$orHasBanners`)은 배너가 남아 있는 위치를 연다.
 */
class RegisteredPosition implements ValidationRule
{
    /**
     * RegisteredPosition 생성자
     *
     * @param  string|null  $stored  수정 대상 배너의 저장된 위치 (등록되지 않았어도 그대로 두면 통과)
     * @param  bool  $orHasBanners  배너가 남아 있는 위치도 통과시킬지 (관리자 목록 전용)
     */
    public function __construct(private ?string $stored = null, private bool $orHasBanners = false) {}

    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  위치 키
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('sirsoft-hero_section::validation.position.unregistered'));

            return;
        }

        if (app(PositionRegistry::class)->isRegistered($value) || $value === $this->stored) {
            return;
        }

        if ($this->orHasBanners && app(BannerRepositoryInterface::class)->hasBannersAt($value)) {
            return;
        }

        $fail(__('sirsoft-hero_section::validation.position.unregistered'));
    }
}
