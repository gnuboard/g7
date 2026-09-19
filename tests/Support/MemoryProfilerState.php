<?php

namespace Tests\Support;

/**
 * MemoryProfilerExtension 의 계측 상태 (테스트 스위트 = 클래스 단위 누적).
 *
 * PHPUnit 은 테스트 클래스 하나를 하나의 TestSuite 로 발행한다. 클래스 시작 시점의 사용량과
 * 종료 후 `gc_collect_cycles()` 를 돌린 뒤의 사용량 차이가 **그 클래스가 남긴 잔존량**이다.
 * 정상적으로 정리되는 클래스는 이 값이 0 근처이고, 정적 상태·클로저 캡처로 컨테이너를 붙잡는
 * 클래스만 큰 양수로 튄다 — 그 상위 몇 개가 전량 스위트 OOM 의 실질 원인이다.
 */
final class MemoryProfilerState
{
    /** 현재 클래스 시작 시점 사용량 (bytes) */
    private int $startBytes = 0;

    /** 현재 클래스에서 실행된 테스트 수 */
    private int $tests = 0;

    /** 중첩 깊이 — 최상위 testsuite(Unit/Feature/…)와 클래스를 구분하기 위함 */
    private int $depth = 0;

    /** CSV 헤더 기록 여부 */
    private bool $headerWritten = false;

    /**
     * 클래스(스위트) 시작을 기록합니다.
     *
     * @param  string  $name  스위트 이름
     */
    public function begin(string $name): void
    {
        $this->depth++;

        if ($this->depth === 1) {
            return;
        }

        // `memory_get_usage(true)` 는 OS 에서 받아온 **할당 arena** 라 객체가 해제돼도 줄지 않는다
        // (PHP 는 arena 를 OS 에 거의 반납하지 않는다). 그 값으로 재면 전 클래스가 "누수" 로 보이고
        // 실제 누수원이 묻힌다 — 첫 계측이 그렇게 나왔다(684 클래스 합 834MB, 최대값은 34MB뿐).
        // 살아 있는 객체 총량은 `false` 로 재야 한다.
        $this->startBytes = memory_get_usage(false);
        $this->tests = 0;
    }

    /**
     * 테스트 1건 종료를 셉니다.
     */
    public function countTest(): void
    {
        $this->tests++;
    }

    /**
     * 클래스(스위트) 종료 시 잔존 증가분을 기록합니다.
     *
     * @param  string  $name  스위트 이름
     */
    public function end(string $name): void
    {
        $depth = $this->depth;
        $this->depth--;

        if ($depth === 1) {
            return;
        }

        gc_collect_cycles();

        $live = memory_get_usage(false);
        $this->write($name, $this->tests, $live - $this->startBytes, $live);
    }

    /**
     * CSV 한 줄을 씁니다.
     *
     * @param  string  $name  스위트 이름
     * @param  int  $tests  테스트 수
     * @param  int  $retained  잔존 증가 bytes
     * @param  int  $total  누적 사용량 bytes
     */
    private function write(string $name, int $tests, int $retained, int $total): void
    {
        $path = storage_path('logs/memory-profile.csv');

        if (! $this->headerWritten) {
            @mkdir(dirname($path), 0o777, true);
            file_put_contents($path, "suite,tests,retained_bytes,total_bytes\n");
            $this->headerWritten = true;
        }

        file_put_contents($path, sprintf("%s,%d,%d,%d\n", $name, $tests, $retained, $total), FILE_APPEND);
    }
}
