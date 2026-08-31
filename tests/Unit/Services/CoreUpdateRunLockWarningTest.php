<?php

declare(strict_types=1);

namespace App\Services {
    use Tests\Unit\Services\CoreUpdateRunLockWarningTest;

    /**
     * `CoreUpdateService` 가 부르는 `filemtime()` 을 가로챕니다.
     *
     * 이름 해석 규칙상 네임스페이스 안의 비한정 호출은 같은 네임스페이스의 함수를 먼저 찾으므로,
     * 이 파일이 로드된 뒤 `App\Services` 안의 `filemtime()` 호출은 여기로 온다. 토글이 꺼져
     * 있으면 전역 함수에 그대로 위임한다 — 실재 파일의 stat 실패는 재현 수단이 없어 이 자리가
     * 유일한 통로다. 토글은 테스트가 `tearDown` 에서 되돌린다.
     *
     * @param  string  $filename  경로
     * @return int|false 수정 시각, 토글이 켜져 있으면 false
     */
    function filemtime(string $filename): int|false
    {
        if (CoreUpdateRunLockWarningTest::$filemtimeFails) {
            return false;
        }

        return \filemtime($filename);
    }
}

namespace Tests\Unit\Services {

    use App\Services\CoreUpdateService;
    use Illuminate\Support\Facades\File;
    use Illuminate\Support\Facades\Log;
    use Tests\TestCase;

    /**
     * 실행 잠금을 **쓸 수 없어** 보호 없이 진행하는 경우가 운영자에게 도달한다.
     *
     * 그 경로는 `acquireRunLock()` 이 `true` 를 돌려주므로 호출부가 「잠금 획득 성공」과
     * 구분할 수 없다. 종전에는 사유가 `Log::warning` 으로만 남아 운영자 화면에 아무 표시가
     * 없었고, 그러면 두 창에서 동시에 업데이트를 눌러 백업이 서로 파괴되는 상태 — 잠금을
     * 넣은 바로 그 사유 — 가 **무경고로** 재현된다.
     *
     * @scenario lock_state=unavailable, lock_state=acquired, channel=console, channel=log
     */
    class CoreUpdateRunLockWarningTest extends TestCase
    {
        /** @var string 격리 storage 루트 */
        private string $fixtureRoot;

        /** @var bool `App\Services\filemtime()` 가로채기 토글 — 켜면 조회 실패(false)를 돌려준다 */
        public static bool $filemtimeFails = false;

        protected function setUp(): void
        {
            parent::setUp();

            CoreUpdateService::takeRunLockWarnings();

            $this->fixtureRoot = storage_path('framework/testing/run-lock-'.uniqid());
            File::ensureDirectoryExists($this->fixtureRoot);
        }

        protected function tearDown(): void
        {
            self::$filemtimeFails = false;
            CoreUpdateService::takeRunLockWarnings();
            File::deleteDirectory($this->fixtureRoot);

            parent::tearDown();
        }

        public function test_unusable_lock_records_a_reason_the_caller_can_surface(): void
        {
            // `storage/app` 자리를 **파일**로 만들어 디렉토리 생성을 실패시킨다 —
            // 읽기 전용 storage 를 가진 제한 호스팅에서 실제로 도는 경로다.
            $isolated = $this->fixtureRoot.DIRECTORY_SEPARATOR.'storage';
            File::ensureDirectoryExists($isolated);
            File::put($isolated.DIRECTORY_SEPARATOR.'app', 'not a directory');

            $this->app->useStoragePath($isolated);

            $service = $this->app->make(CoreUpdateService::class);

            $this->assertTrue(
                $service->acquireRunLock(),
                '잠금을 쓸 수 없을 때는 진행한다 — 이 폴백 자체는 의도된 동작이다'
            );

            $warnings = CoreUpdateService::takeRunLockWarnings();

            $this->assertNotEmpty(
                $warnings,
                '잠금 없이 진행했는데 사유가 남지 않았다 — 호출부가 그 사실을 표면화할 수단이 없다'
            );

            $this->assertStringContainsString(
                '한 번에 하나만',
                $warnings[0],
                '사유 문구가 운영자 안내(다국어 키)를 거치지 않았다'
            );

            $service->releaseRunLock();
        }

        /**
         * 잠금 **파일을 열지 못하는** 경로도 사유를 남긴다 — 그 자리에 경로가 아니라 **사유**가 들어간다.
         *
         * 형제 테스트(`test_unusable_lock_records_a_reason_the_caller_can_surface`)는 디렉토리
         * 생성 실패 분기를 탄다. 그 분기와 이 분기는 **다른 코드**이고, 「`:reason` 자리에 경로가
         * 들어가 문장이 성립하지 않는다」는 결함이 실제로 있던 곳은 이쪽이었다. 그런데 그 수정
         * 이후에도 이 분기에 들어가는 테스트가 하나도 없어, 수정을 되돌려도 전부 초록이었다
         * (분기 계측으로 확인).
         *
         * @scenario lock_failure=cannot_open_file, reason_source=php_error, reason_source=fallback_message
         *
         * @effects run_lock_degradation_reaches_the_operator_not_only_the_log
         */
        public function test_unopenable_lock_file_records_a_reason_not_the_path(): void
        {
            $isolated = $this->fixtureRoot.DIRECTORY_SEPARATOR.'storage3';
            File::ensureDirectoryExists($isolated.DIRECTORY_SEPARATOR.'app');

            // 잠금 **파일 자리**를 디렉토리로 막는다 — 디렉토리 생성은 성공하므로 앞 분기를
            // 지나 `fopen()` 실패 분기로만 들어간다.
            $lockPath = $isolated.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'core-update.lock';
            File::ensureDirectoryExists($lockPath);

            $this->app->useStoragePath($isolated);

            $this->assertDirectoryExists(
                $isolated.DIRECTORY_SEPARATOR.'app',
                'storage/app 이 없으면 앞 분기로 갈라져 이 검사가 다른 코드를 잰다'
            );

            $service = $this->app->make(CoreUpdateService::class);

            $this->assertTrue($service->acquireRunLock(), '잠금을 쓸 수 없을 때는 진행한다');

            $warnings = CoreUpdateService::takeRunLockWarnings();

            $this->assertNotEmpty($warnings, '잠금 파일을 열지 못했는데 사유가 남지 않았다');

            // 구분자를 접고 비교한다. `storage_path('app/core-update.lock')` 은 Windows 에서
            // `…\app/core-update.lock`(혼합)이고 이 테스트가 조립한 `$lockPath` 는
            // `…\app\core-update.lock` 이라, 날 문자열 비교는 **되돌린 구현에서도** 「다르다」로
            // 떨어져 언제나 통과한다 — 이 단언이 그 플랫폼에서 아무것도 재지 않았다(실측:
            // `:reason` 에 경로를 넣는 변이를 걸어도 초록). 재려는 것은 문자열 동일성이 아니라
            // 「그 자리에 사유가 아니라 경로가 들어갔는가」이므로 표기 차이는 접는다.
            $foldSeparators = static fn (string $text): string => str_replace('\\', '/', $text);

            $this->assertNotSame(
                $foldSeparators((string) __('settings.core_update.run_lock_unavailable', ['reason' => $lockPath])),
                $foldSeparators($warnings[0]),
                ':reason 자리에 경로가 들어갔다 — 세 언어 모두 문장이 성립하지 않고 운영자가 원인을 알 수 없다'
            );

            $this->assertStringNotContainsString(
                ':reason',
                $warnings[0],
                '치환 자리가 그대로 남았다'
            );

            $service->releaseRunLock();
        }

        public function test_buffer_is_cleared_after_being_taken(): void
        {
            $isolated = $this->fixtureRoot.DIRECTORY_SEPARATOR.'storage2';
            File::ensureDirectoryExists($isolated);
            File::put($isolated.DIRECTORY_SEPARATOR.'app', 'not a directory');

            $this->app->useStoragePath($isolated);

            $service = $this->app->make(CoreUpdateService::class);
            $service->acquireRunLock();

            $this->assertNotEmpty(CoreUpdateService::takeRunLockWarnings());
            $this->assertSame(
                [],
                CoreUpdateService::takeRunLockWarnings(),
                '소비 후에도 버퍼가 남아 같은 경고가 두 번 나간다'
            );

            $service->releaseRunLock();
        }

        public function test_command_consumes_the_channel(): void
        {
            // 채널이 있어도 소비자가 없으면 로그 전용이던 종전 상태와 같다.
            $command = (string) file_get_contents(
                base_path('app/Console/Commands/Core/CoreUpdateCommand.php')
            );

            $this->assertStringContainsString(
                'takeRunLockWarnings(',
                $command,
                '커맨드가 잠금 저하 사유를 꺼내지 않는다 — 운영자 화면에 아무 표시가 없다'
            );
        }

        /**
         * 격리 storage 를 만들고 잠금 파일 경로를 돌려준다.
         *
         * @param  string  $name  격리 디렉토리 이름
         * @return string 잠금 파일 경로
         */
        private function isolatedLockPath(string $name): string
        {
            $isolated = $this->fixtureRoot.DIRECTORY_SEPARATOR.$name;
            File::ensureDirectoryExists($isolated.DIRECTORY_SEPARATOR.'app');
            $this->app->useStoragePath($isolated);

            return $isolated.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'core-update.lock';
        }

        /**
         * 오래 남은 잠금 파일은 **나이를 알 때만** 잔해로 경고한다.
         *
         * @scenario lock_file=stale, mtime=readable
         *
         * @effects run_lock_degradation_reaches_the_operator_not_only_the_log
         */
        public function test_stale_lock_file_with_readable_mtime_is_reported(): void
        {
            $lockPath = $this->isolatedLockPath('storage-stale');
            File::put($lockPath, (string) (time() - 7200));
            touch($lockPath, time() - 7200);

            Log::spy();

            $service = $this->app->make(CoreUpdateService::class);
            $this->assertTrue($service->acquireRunLock());
            $service->releaseRunLock();

            Log::shouldHaveReceived('warning')
                ->withArgs(fn (string $message): bool => str_contains($message, '오래 남아 있습니다'))
                ->once();
        }

        /**
         * 수정 시각을 **읽지 못한 것**은 「오래됨」이 아니다.
         *
         * 종전에는 `(int) @filemtime()` 이라 조회 실패(`false`)가 0 으로 접혀 `time() - 0` 이
         * 언제나 상한을 넘었고, 방금 만든 잠금 파일이 「이전 실행이 비정상 종료했을 수 있다」는
         * 경고를 남겼다. 잘못된 경고는 운영자가 진짜 잔해를 볼 때 무시하게 만든다.
         *
         * @scenario lock_file=fresh, mtime=unreadable
         *
         * @effects run_lock_degradation_reaches_the_operator_not_only_the_log
         */
        public function test_unreadable_mtime_does_not_masquerade_as_a_stale_lock(): void
        {
            $lockPath = $this->isolatedLockPath('storage-unreadable');
            File::put($lockPath, (string) time());

            self::$filemtimeFails = true;

            Log::spy();

            $service = $this->app->make(CoreUpdateService::class);
            $this->assertTrue($service->acquireRunLock());
            $service->releaseRunLock();

            Log::shouldNotHaveReceived('warning', [
                \Mockery::on(fn ($message): bool => str_contains((string) $message, '오래 남아 있습니다')),
                \Mockery::any(),
            ]);
        }
    }
}
