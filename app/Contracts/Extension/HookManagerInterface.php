<?php

namespace App\Contracts\Extension;

interface HookManagerInterface
{
    /**
     * Hook 이벤트를 발생시켜 등록된 콜백들을 실행합니다.
     *
     * @param string $hookName Hook 이름
     * @param mixed ...$args Hook에 전달할 인수들
     * @return void
     */
    public static function doAction(string $hookName, ...$args): void;

    /**
     * Filter를 적용하여 데이터를 변환합니다.
     *
     * @param string $filterName Filter 이름
     * @param mixed $value 변환할 원본 값
     * @param mixed ...$args Filter에 전달할 추가 인수들
     * @return mixed 변환된 값
     */
    public static function applyFilters(string $filterName, $value, ...$args);

    /**
     * Hook 이벤트에 콜백 함수를 등록합니다.
     *
     * @param string $hookName Hook 이름
     * @param callable $callback 실행할 콜백 함수
     * @param int $priority 실행 우선순위 (기본값: 10)
     * @return void
     */
    public static function addAction(string $hookName, callable $callback, int $priority = 10): void;

    /**
     * Filter에 콜백 함수를 등록합니다.
     *
     * @param string $filterName Filter 이름
     * @param callable $callback 실행할 콜백 함수
     * @param int $priority 실행 우선순위 (기본값: 10)
     * @return void
     */
    public static function addFilter(string $filterName, callable $callback, int $priority = 10): void;

    /**
     * Hook 이벤트에서 콜백 함수를 제거합니다.
     *
     * @param string $hookName Hook 이름
     * @param callable $callback 제거할 콜백 함수
     * @return void
     */
    public static function removeAction(string $hookName, callable $callback): void;

    /**
     * Filter에서 콜백 함수를 제거합니다.
     *
     * @param string $filterName Filter 이름
     * @param callable $callback 제거할 콜백 함수
     * @return void
     */
    public static function removeFilter(string $filterName, callable $callback): void;

    /**
     * 등록된 모든 Hook 목록을 조회합니다.
     *
     * @return array Hook 목록 배열
     */
    public static function getHooks(): array;

    /**
     * 등록된 모든 Filter 목록을 조회합니다.
     *
     * @return array Filter 목록 배열
     */
    public static function getFilters(): array;
}
