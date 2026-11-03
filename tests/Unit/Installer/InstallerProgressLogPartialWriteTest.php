<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 진행 기록이 일부만 쓰였을 때(디스크 가득) 성공으로 보고하지 않는다.
 *
 * 배경: 진행 기록 쓰기는 `fwrite()` 가 `false` 일 때만 실패로 봤다. 디스크가 가득 차면 `fwrite()` 는
 * 쓴 만큼의 바이트 수를 돌려주므로, 줄이 잘려도 기록 함수는 성공을 돌려줬다. 업그레이드 스텝의 기록
 * 쓰기는 이미 「쓴 길이 ≠ 줄 길이」를 실패로 본다 — 같은 규칙을 진행 기록에도 건다.
 *
 * 디스크 가득을 재현하려고 BASE_PATH 를 정해진 바이트까지만 받는 스트림 래퍼 주소로 박는다.
 * BASE_PATH 는 프로세스당 한 번만 정의되므로 클래스 격리 실행이 필요하다.
 *
 * 효과 요약(마커 아님 — 평문): 진행 기록이 일부만 쓰이면 실패로 보고한다
 * (installation_log_partial_write_is_reported_as_failure). 표식은 각 테스트 메서드가 단다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerProgressLogPartialWriteTest extends TestCase
{
    private static string $skipReason = '';

    /**
     * 정해진 바이트까지만 받는 스트림 래퍼를 등록하고, BASE_PATH 를 그 주소로 정의한 뒤 상태 모듈을 불러온다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (defined('BASE_PATH')) {
            self::$skipReason = 'BASE_PATH 가 이미 정의돼 있다 — 격리 실행 필요: php artisan test --filter=InstallerProgressLogPartialWriteTest';

            return;
        }

        stream_wrapper_register('g7shortwrite', ShortWriteStream::class);
        define('BASE_PATH', 'g7shortwrite://base');

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/installer-state.php';
    }

    /**
     * 건너뛸 사유가 있으면 건너뛰고, 아니면 래퍼에 쌓인 파일을 비운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        ShortWriteStream::$files = [];
        ShortWriteStream::$capacity = 16;
    }

    /**
     * 단건 기록이 일부만 쓰이면 false 를 돌려주는지 확인한다.
     *
     * @scenario progress_log_volume=small
     *
     * @effects installation_log_partial_write_is_reported_as_failure
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_single_entry_cut_short_is_a_failure(): void
    {
        $this->assertFalse(addLog('디스크가 가득 차 이 줄은 끝까지 쓰이지 못한다'), '일부만 쓰인 기록을 성공으로 보고했다');
        $this->assertSame(16, strlen((string) (ShortWriteStream::$files['g7shortwrite://base/storage/logs/installation.log'] ?? '')), '전제: 래퍼가 정해진 바이트까지만 받았다');
    }

    /**
     * 배치 기록이 일부만 쓰이면 false 를 돌려주는지 확인한다.
     *
     * @scenario progress_log_volume=small
     *
     * @effects installation_log_partial_write_is_reported_as_failure
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_batch_cut_short_is_a_failure(): void
    {
        $this->assertFalse(addLogBatch(['첫 줄은 들어가도', '둘째 줄은 잘린다']), '일부만 쓰인 배치를 성공으로 보고했다');
    }

    /**
     * 자리가 충분하면 그대로 성공한다(비회귀).
     *
     * @scenario progress_log_volume=small
     *
     * @effects installation_log_partial_write_is_reported_as_failure
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_complete_write_still_succeeds(): void
    {
        ShortWriteStream::$capacity = 1 << 20;

        $this->assertTrue(addLog('끝까지 쓰이는 줄'));
        $this->assertTrue(addLogBatch(['배치 첫 줄', '배치 둘째 줄']));
    }
}

/**
 * 정해진 바이트까지만 받는 스트림 래퍼 — 디스크 가득을 모사한다.
 */
final class ShortWriteStream
{
    /** @var int 모든 파일을 합쳐 받을 최대 바이트 */
    public static int $capacity = 16;

    /** @var array<string, string> 경로별 내용 */
    public static array $files = [];

    /** @var resource|null 스트림 컨텍스트 (PHP 가 채운다) */
    public $context;

    private string $path = '';

    /**
     * 파일을 연다 — 없으면 빈 파일로 만든다.
     *
     * @param  string  $path  주소
     * @param  string  $mode  열기 모드
     * @param  int  $options  옵션
     * @param  string|null  $openedPath  실제 경로 (쓰지 않는다)
     * @return bool 언제나 true
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->path = $path;
        self::$files[$path] ??= '';

        return true;
    }

    /**
     * 남은 자리만큼만 받는다.
     *
     * @param  string  $data  쓸 내용
     * @return int 실제로 받은 바이트 수
     */
    public function stream_write(string $data): int
    {
        $used = array_sum(array_map('strlen', self::$files));
        $chunk = substr($data, 0, max(0, self::$capacity - $used));
        self::$files[$this->path] .= $chunk;

        return strlen($chunk);
    }

    /**
     * 잠금은 언제나 성공한다.
     *
     * @param  int  $operation  잠금 종류
     * @return bool 언제나 true
     */
    public function stream_lock(int $operation): bool
    {
        return true;
    }

    /**
     * 비울 버퍼가 없다.
     *
     * @return bool 언제나 true
     */
    public function stream_flush(): bool
    {
        return true;
    }

    /**
     * 닫는다 — 할 일이 없다.
     *
     * @return void 반환값 없음
     */
    public function stream_close(): void {}

    /**
     * 열린 파일의 상태.
     *
     * @return array<string, int> 크기·모드·파일 번호
     */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$files[$this->path] ?? ''), 'mode' => 0100666, 'ino' => 1];
    }

    /**
     * 경로의 상태 — 로그 폴더는 쓰기 가능한 디렉토리, 만든 파일은 일반 파일이다.
     *
     * @param  string  $path  주소
     * @param  int  $flags  조회 플래그
     * @return array<string, int>|false 상태. 없으면 false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (isset(self::$files[$path])) {
            return ['size' => strlen(self::$files[$path]), 'mode' => 0100666, 'ino' => 1, 'uid' => 0, 'gid' => 0];
        }

        if (str_ends_with($path, '/storage/logs')) {
            return ['size' => 0, 'mode' => 040777, 'ino' => 2, 'uid' => 0, 'gid' => 0];
        }

        return false;
    }

    /**
     * 디렉토리 만들기 — 언제나 성공한다.
     *
     * @param  string  $path  주소
     * @param  int  $mode  권한
     * @param  int  $options  옵션
     * @return bool 언제나 true
     */
    public function mkdir(string $path, int $mode, int $options): bool
    {
        return true;
    }
}
