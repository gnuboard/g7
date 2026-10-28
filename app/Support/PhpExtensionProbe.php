<?php

namespace App\Support;

/**
 * PHP 확장 로드 여부 조회기 (테스트 주입 지점).
 *
 * 확장 요구 검사(설치·활성화·업데이트·관리자 표시)와 선택 확장 가드(설정 백업 zip 등)가
 * `extension_loaded()` 를 직접 부르면 테스트가 확장 부재를 만들 수 없다. 운영 코드는 컨테이너에서
 * 이 클래스를 받아 쓰고, 테스트는 `app()->instance(PhpExtensionProbe::class, …)` 로 교체한다.
 */
class PhpExtensionProbe
{
    /**
     * PHP 확장이 로드돼 있는지 확인합니다.
     *
     * @param  string  $extension  확장 이름 (예: soap, zip)
     * @return bool 로드돼 있으면 true
     */
    public function loaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    /**
     * PHP 함수를 쓸 수 있는지 확인합니다.
     *
     * 확장이 로드돼 있어도 `disable_functions` 로 함수만 막힌 서버가 있다 — 실제로 부를 함수를 확인할 때 쓴다.
     *
     * @param  string  $function  함수 이름 (예: gzencode)
     * @return bool 정의돼 있으면 true
     */
    public function functionAvailable(string $function): bool
    {
        return function_exists($function);
    }

    /**
     * 주어진 확장 중 로드되지 않은 것을 반환합니다.
     *
     * @param  list<string>  $extensions  확장 이름 목록
     * @return list<string> 로드되지 않은 확장 이름 (입력 순서 유지)
     */
    public function missing(array $extensions): array
    {
        return PhpExtensionRequirements::missing($extensions, fn (string $name): bool => $this->loaded($name));
    }
}
