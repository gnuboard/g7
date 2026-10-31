<?php

namespace App\Analytics;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;

/**
 * 확장 필터 격리 — 확장이 던져도 코어 기본값으로 이어 갑니다.
 *
 * 판정표·등록부 필터는 수집 요청 안에서 동기로 돕니다. 여기서 난 예외가 밖으로 나가면
 * 수집 경로의 catch 가 데이터베이스 오류로 분류하고 방문까지 버립니다 — 확장 하나의 결함이
 * 사이트 통계 전체를 멈추고, 진단 화면은 엉뚱한 원인을 가리킵니다.
 *
 * 실패는 애플리케이션 로그에 남기고, 수집 경로가 요청 단위 실패 목록(`listener_exception`)으로
 * 옮길 수 있게 모아 둡니다({@see self::takeFailures()}).
 */
final class ExtensionFilter
{
    /**
     * 꺼내 가기 전까지 모아 두는 실패 최대 건수
     */
    private const MAX_PENDING = 20;

    /**
     * 아직 수집 경로가 가져가지 않은 필터 실패
     *
     * @var array<int, array{hook: string, message: string}>
     */
    private static array $failures = [];

    /**
     * 필터를 적용하고, 확장이 던지면 원래 값을 돌려줍니다.
     *
     * @param  string  $hook  필터 이름
     * @param  mixed  $value  코어 기본값
     * @param  mixed  ...$args  추가 인자
     * @return mixed 필터 결과 (실패하면 코어 기본값)
     */
    public static function apply(string $hook, mixed $value, mixed ...$args): mixed
    {
        try {
            return HookManager::applyFilters($hook, $value, ...$args);
        } catch (\Throwable $e) {
            Log::warning('방문자 통계 확장 필터 실패 — 코어 기본값으로 이어 갑니다.', [
                'hook' => $hook,
                'error' => $e->getMessage(),
            ]);

            // 수집 밖(집계·리포트)에서 난 실패는 아무도 꺼내 가지 않으므로 쌓이지 않게 자른다.
            if (count(self::$failures) < self::MAX_PENDING) {
                self::$failures[] = ['hook' => $hook, 'message' => $e->getMessage()];
            }

            return $value;
        }
    }

    /**
     * 모인 필터 실패를 꺼내고 비웁니다.
     *
     * @return array<int, array{hook: string, message: string}> 필터 실패 목록
     */
    public static function takeFailures(): array
    {
        $failures = self::$failures;
        self::$failures = [];

        return $failures;
    }
}
