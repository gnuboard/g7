<?php

namespace Tests\Unit\Testing\SchemaCache;

use RuntimeException;
use Tests\PureTestCase;
use Tests\Support\TestingSchema\ProcessLock;

/**
 * 테스트 DB 단위 프로세스 잠금 — 같은 테스트 DB 를 두 phpunit 이 동시에 쓰지 못하게 한다.
 *
 * 잠금을 얻지 못하면 기다리거나 상대 연결을 끊지 않고, 사용 중인 PID 를 알려 주며 바로 멈춘다.
 * 별도 프로세스 테스트(부모가 잠금을 쥔 자식)는 잠금을 건너뛴다.
 */
class ProcessLockTest extends PureTestCase
{
    /** 시험용 잠금 디렉터리 */
    private string $dir;

    /** 시험 전 owner 환경 변수 값 */
    private string|false $previousOwner;

    /**
     * 잠금 디렉터리를 정하고 owner 환경 변수를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/g7-process-lock-'.getmypid().'-'.bin2hex(random_bytes(4));
        $this->previousOwner = getenv(ProcessLock::OWNER_ENV);
        putenv(ProcessLock::OWNER_ENV);
    }

    /**
     * 잠금을 풀고 환경 변수와 디렉터리를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ProcessLock::release('zz_lock_testing');
        putenv($this->previousOwner === false ? ProcessLock::OWNER_ENV : ProcessLock::OWNER_ENV.'='.$this->previousOwner);
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * 다른 핸들이 잠금을 쥐고 있으면 그 PID 를 담은 예외로 바로 멈춘다.
     *
     * @scenario case=held_by_other
     *
     * @effects second_process_fails_fast_with_owner_pid
     *
     * @return void 반환값 없음
     */
    public function test_second_holder_fails_with_owner_pid(): void
    {
        mkdir($this->dir, 0777, true);
        $file = $this->dir.'/zz_lock_testing.lock';
        $other = fopen($file, 'c+');
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB));
        file_put_contents($file.'.pid', '424242');

        try {
            ProcessLock::acquireOrSkipIfChild('zz_lock_testing', $this->dir);
            $this->fail('잠금을 얻지 못하면 예외로 멈춰야 한다');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('zz_lock_testing', $e->getMessage());
            $this->assertStringContainsString('424242', $e->getMessage());
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }
    }

    /**
     * 잠금을 얻으면 자기 PID 를 owner 로 남기고, 같은 프로세스의 재호출은 그대로 통과한다.
     *
     * @scenario case=acquired
     *
     * @effects owner_pid_exported_to_children
     *
     * @return void 반환값 없음
     */
    public function test_acquire_exports_owner_and_is_reentrant(): void
    {
        $this->assertTrue(ProcessLock::acquireOrSkipIfChild('zz_lock_testing', $this->dir));
        $this->assertTrue(ProcessLock::acquireOrSkipIfChild('zz_lock_testing', $this->dir));
        $this->assertSame((string) getmypid(), getenv(ProcessLock::OWNER_ENV));
        $this->assertSame((string) getmypid(), trim((string) file_get_contents($this->dir.'/zz_lock_testing.lock.pid')));
        $this->assertFalse(ProcessLock::isChildProcess());
    }

    /**
     * 부모가 잠금을 쥔 자식 프로세스(owner 가 다른 PID)는 잠금을 건너뛴다.
     *
     * @scenario case=child_process
     *
     * @effects child_process_skips_lock
     *
     * @return void 반환값 없음
     */
    public function test_child_process_skips_lock(): void
    {
        putenv(ProcessLock::OWNER_ENV.'=1');

        $this->assertTrue(ProcessLock::isChildProcess());
        $this->assertFalse(ProcessLock::acquireOrSkipIfChild('zz_lock_testing', $this->dir));
        $this->assertFileDoesNotExist($this->dir.'/zz_lock_testing.lock');
    }
}
