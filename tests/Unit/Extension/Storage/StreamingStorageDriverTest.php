<?php

namespace Tests\Unit\Extension\Storage;

use App\Contracts\Extension\StreamingStorageInterface;
use App\Exceptions\UnsafeStoragePathException;
use App\Extension\Storage\CoreStorageDriver;
use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 코어 저장소 드라이버 셋의 스트림 계약 (#883 3단계)
 *
 * 큰 파일(동영상)을 메모리에 통째로 올리지 않고 옮기고(파일 보상 도구), 일부 구간만 내보내려고(Range 서빙) 둔 계약이다.
 * 모든 경로는 기존 경로 가드를 거친다.
 *
 * @scenario-manifest tests/scenarios/storage-transaction-compensation.yaml
 */
class StreamingStorageDriverTest extends TestCase
{
    /**
     * 세 드라이버를 돌려줍니다.
     *
     * @return array<string, array{0: callable(): StreamingStorageInterface}> 드라이버 이름 => 생성 함수
     */
    public static function drivers(): array
    {
        return [
            'core' => [fn () => new CoreStorageDriver('local')],
            'module' => [fn () => new ModuleStorageDriver('stream-mod')],
            'plugin' => [fn () => new PluginStorageDriver('stream-plugin')],
        ];
    }

    /**
     * 가짜 로컬 디스크를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * 쓰기·읽기·크기·로컬 경로·구간 읽기가 같은 상대 경로로 동작한다.
     *
     * @param  callable(): StreamingStorageInterface  $make  드라이버 생성
     * @return void 반환값 없음
     *
     * @scenario case=streaming_round_trip
     *
     * @effects streaming_contract_guarded
     */
    #[DataProvider('drivers')]
    public function test_stream_round_trip_and_range(callable $make): void
    {
        $driver = $make();
        $this->assertInstanceOf(StreamingStorageInterface::class, $driver);

        $source = fopen('php://temp', 'r+');
        fwrite($source, '0123456789');
        rewind($source);
        $this->assertTrue($driver->writeStream('media', 'v/clip.bin', $source));
        fclose($source);

        $this->assertSame(10, $driver->size('media', 'v/clip.bin'));
        $this->assertNotNull($driver->localPath('media', 'v/clip.bin'));

        $read = $driver->readStream('media', 'v/clip.bin');
        $this->assertSame('0123456789', stream_get_contents($read));
        fclose($read);

        $range = $driver->readRangeStream('media', 'v/clip.bin', 3, 5);
        $this->assertSame('345', fread($range, 3));
        fclose($range);

        $this->assertNull($driver->readStream('media', 'v/none.bin'));
        $this->assertNull($driver->size('media', 'v/none.bin'));
        $this->assertNull($driver->readRangeStream('media', 'v/clip.bin', 6, 2), '끝이 시작보다 앞이면 null');
    }

    /**
     * 스트림 경로도 경로 가드를 거친다 — 순회 경로는 거부된다.
     *
     * @param  callable(): StreamingStorageInterface  $make  드라이버 생성
     * @return void 반환값 없음
     *
     * @scenario case=streaming_path_guard
     *
     * @effects streaming_contract_guarded
     */
    #[DataProvider('drivers')]
    public function test_stream_paths_are_guarded(callable $make): void
    {
        $driver = $make();

        $this->expectException(UnsafeStoragePathException::class);
        $driver->readStream('media', '../../escape.txt');
    }
}
