<?php

namespace Tests\Unit\Testing\SchemaCache;

use Tests\PureTestCase;
use Tests\Support\TestingSchema\RecordStore;
use Tests\Support\TestingSchema\TestingSchemaCache;

/**
 * 테스트 스키마 기록 파일 — 원자적으로 쓰고, 읽을 수 없거나 형식이 다른 기록은 "기록 없음" 으로 본다.
 *
 * 전체 재생 시작 전에 기록을 지우므로, 재생이 중간에 끊기면 다음 실행은 기록이 없어 다시 재생한다(반쪽 스키마 재사용 차단).
 */
class RecordStoreTest extends PureTestCase
{
    /** 시험용 기록 디렉터리 */
    private string $dir;

    /**
     * 빈 기록 디렉터리를 정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/g7-record-store-'.getmypid().'-'.bin2hex(random_bytes(4));
    }

    /**
     * 기록 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * 정상 기록 하나를 만듭니다.
     *
     * @param  string  $fingerprint  지문
     * @return array<string, mixed> 기록
     */
    private function record(string $fingerprint = 'fp'): array
    {
        return RecordStore::compose($fingerprint, 'env', 'sum', ['b', 'a'], ['g7_x' => ['columns' => ['id'], 'b64' => [], 'rows' => [['1']]]], null);
    }

    /**
     * 쓰고 읽으면 같은 기록이 나오고 임시 파일이 남지 않으며, 다시 쓰면 덮어쓴다.
     *
     * @scenario case=write_read
     *
     * @effects record_written_atomically
     *
     * @return void 반환값 없음
     */
    public function test_write_then_read_round_trips_without_tmp_files(): void
    {
        RecordStore::write('g7_testing', $this->record('one'), $this->dir);
        RecordStore::write('g7_testing', $this->record('two'), $this->dir);

        $read = RecordStore::read('g7_testing', $this->dir);
        $this->assertSame('two', $read['fingerprint']);
        $this->assertSame(['a', 'b'], $read['migrationNames'], '이름 목록은 정렬해 담는다');
        $this->assertSame([], glob($this->dir.'/*.tmp'));
    }

    /**
     * 손상된 JSON·다른 형식 판·다른 로직 판·키 누락 기록은 기록 없음(null)이다.
     *
     * @scenario case=unusable_record
     *
     * @effects unusable_record_treated_as_missing
     *
     * @return void 반환값 없음
     */
    public function test_unusable_records_read_as_null(): void
    {
        mkdir($this->dir, 0777, true);
        $path = RecordStore::path('g7_testing', $this->dir);

        file_put_contents($path, '{broken');
        $this->assertNull(RecordStore::read('g7_testing', $this->dir), '손상 JSON');

        file_put_contents($path, json_encode(['format' => 99] + $this->record()));
        $this->assertNull(RecordStore::read('g7_testing', $this->dir), '다른 형식 판');

        file_put_contents($path, json_encode(['logic' => TestingSchemaCache::LOGIC_VERSION + 1] + $this->record()));
        $this->assertNull(RecordStore::read('g7_testing', $this->dir), '다른 로직 판');

        $missing = $this->record();
        unset($missing['checksum']);
        file_put_contents($path, json_encode($missing));
        $this->assertNull(RecordStore::read('g7_testing', $this->dir), '키 누락');
    }

    /**
     * 지우면 기록 없음이 되고, 남은 임시 파일은 정리된다. 상태 토큰은 쓸 때마다 달라진다.
     *
     * @scenario case=delete_and_state
     *
     * @effects record_deleted_before_fresh, state_token_changes_on_touch
     *
     * @return void 반환값 없음
     */
    public function test_delete_cleanup_and_state_token(): void
    {
        RecordStore::write('g7_testing', $this->record(), $this->dir);
        file_put_contents($this->dir.'/g7_testing.json.123.tmp', 'x');

        RecordStore::delete('g7_testing', $this->dir);
        RecordStore::cleanupTemporaryFiles('g7_testing', $this->dir);

        $this->assertNull(RecordStore::read('g7_testing', $this->dir));
        $this->assertSame([], glob($this->dir.'/*.tmp'));

        $this->assertSame('', RecordStore::readState('g7_testing', $this->dir));
        $first = RecordStore::touchState('g7_testing', $this->dir);
        $second = RecordStore::touchState('g7_testing', $this->dir);
        $this->assertNotSame($first, $second);
        $this->assertSame($second, RecordStore::readState('g7_testing', $this->dir));
    }
}
