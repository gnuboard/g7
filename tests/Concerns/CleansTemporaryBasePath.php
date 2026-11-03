<?php

namespace Tests\Concerns;

/**
 * temp `BASE_PATH` 트리를 클래스 종료 시 지운다 (12차 L-4).
 *
 * 인스톨러 테스트는 실 `.env`·상태 파일을 파괴하지 않으려고 `BASE_PATH` 를 시스템 temp 하위의
 * 임의 디렉토리로 고정한다(R7). 그런데 그 디렉토리를 **만들기만 하고 지우지 않는** 클래스가
 * 다수였다 — 42개 중 tearDown 보유는 14개뿐이었다. 게다가 이 클래스들은 대부분
 * `#[RunTestsInSeparateProcesses]` 라 **테스트 메서드마다** 새 임의 디렉토리가 생긴다.
 * 접미사가 임의값이라 테스트 간 교차 오염은 없지만, 한 번 돌 때마다 개발자 임시 디렉토리에
 * 수십 개의 트리가 쌓이고 아무도 치우지 않는다.
 *
 * 정리 대상은 **시스템 temp 하위**로 좁힌다 — `BASE_PATH` 가 실 저장소 루트로 박힌 경우
 * (안전가드가 skip 시키는 상황)에는 아무것도 지우지 않는다. 그 판정을 잘못하면 이 트레이트
 * 자신이 저장소를 지우는 도구가 된다.
 *
 * 클래스가 자기 `tearDownAfterClass()` 를 정의하면 그쪽이 우선한다(PHP 의 트레이트 우선순위)
 * — 이미 자기 정리 절차를 가진 클래스는 그대로 둔다.
 */
trait CleansTemporaryBasePath
{
    /**
     * 부모 클래스 정리를 먼저 돌린 뒤, 상수로 고정된 `BASE_PATH` 가 시스템 temp 하위 트리이면 그 트리를 지운다.
     *
     * @return void 반환값 없음
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (defined('BASE_PATH')) {
            self::removeTemporaryTree((string) BASE_PATH);
        }
    }

    /**
     * 시스템 temp 하위의 디렉토리 트리를 지운다 (그 밖이면 아무것도 하지 않는다).
     *
     * @param  string  $path  대상 경로
     * @return void 반환값 없음
     */
    protected static function removeTemporaryTree(string $path): void
    {
        $temp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $resolved = realpath($path);

        if ($resolved === false || ! is_dir($resolved)) {
            return;
        }

        $temp = rtrim(str_replace('\\', '/', $temp), '/');
        $normalized = rtrim(str_replace('\\', '/', $resolved), '/');

        // temp 자신이거나 temp 밖이면 손대지 않는다 — 이 가드가 이 트레이트의 전부다.
        if ($normalized === $temp || strpos($normalized, $temp.'/') !== 0) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());

                continue;
            }
            @unlink($item->getPathname());
        }

        @rmdir($resolved);
    }
}
