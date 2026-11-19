<?php

namespace Plugins\Sirsoft\HeroSection\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Support\Positions\Placement;

/**
 * 순서 변경 요청이 그 자리(위치 + 대상)의 배너 전체(빠짐·중복·다른 자리 없음)인지 확인한다.
 *
 * 일부만 보내면 나머지 배너의 순서 값이 겹쳐 사용자 화면 순서가 꼬이므로 거부한다.
 */
class CompletePositionOrder implements ValidationRule
{
    /**
     * CompletePositionOrder 생성자
     *
     * @param  Placement|null  $placement  자리 (검증 전 값으로 만든 것 — 못 만들면 null, 위치·대상 규칙이 따로 막는다)
     */
    public function __construct(private ?Placement $placement) {}

    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  검증 값 (배너 ID 배열)
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $this->placement === null) {
            return;
        }

        $sent = array_map('intval', $value);
        $expected = app(BannerRepositoryInterface::class)->idsForPlacement($this->placement);

        sort($sent);
        sort($expected);

        if ($sent !== $expected) {
            $fail(__('sirsoft-hero_section::validation.order.incomplete'));
        }
    }
}
