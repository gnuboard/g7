<?php

namespace Tests\Feature\Testing\SchemaCache;

use Illuminate\Support\Facades\DB;
use Tests\Support\TestingSchema\Baseline;
use Tests\TestCase;

/**
 * 기준 행 스냅숏 왕복 — 바이너리·JSON·마이크로초 시각·decimal·NULL 값이 기록 JSON 을 거쳐도 바이트 그대로 돌아온다.
 *
 * 검사용 테이블(`zz_scc_baseline`)을 만들고 `finally` 에서 지운다. DDL 을 쓰므로 트랜잭션 트레이트를 쓰지 않는다.
 */
class BaselineRoundTripTest extends TestCase
{
    /**
     * 여러 형식의 값을 스냅숏 → JSON 직렬화 → 비우기 → 복원했을 때 원래 행과 같다. 생성 컬럼은 스냅숏에서 빠진다.
     *
     * @scenario case=round_trip
     *
     * @effects baseline_round_trips_every_column_type
     *
     * @return void 반환값 없음
     */
    public function test_round_trips_binary_json_datetime_decimal_and_null(): void
    {
        $db = DB::connection()->getDatabaseName();
        $table = DB::getTablePrefix().'zz_scc_baseline';
        DB::statement("DROP TABLE IF EXISTS `{$table}`");
        DB::statement("CREATE TABLE `{$table}` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bin VARBINARY(16) NULL, blobv BLOB NULL, js JSON NULL, at DATETIME(6) NULL, amount DECIMAL(12,4) NULL, note VARCHAR(20) NULL,
            twice INT GENERATED ALWAYS AS (CHAR_LENGTH(note) * 2) VIRTUAL
        ) ENGINE=InnoDB");

        try {
            DB::table('zz_scc_baseline')->insert([
                ['bin' => "\x00\xff\x10ab", 'blobv' => random_bytes(40), 'js' => '{"a": [1, "가"]}', 'at' => '2026-11-03 01:02:03.123456', 'amount' => '1234.5678', 'note' => '한글'],
                ['bin' => null, 'blobv' => null, 'js' => null, 'at' => null, 'amount' => null, 'note' => null],
            ]);
            $read = fn (): array => array_map(
                static fn ($row): array => (array) $row,
                DB::select("SELECT id, bin, blobv, js, at, amount, note FROM `{$table}` ORDER BY id")
            );
            $before = $read();

            $snapshot = json_decode((string) json_encode(Baseline::snapshotTable(DB::connection(), $db, $table)), true);
            $this->assertNotContains('twice', $snapshot['columns'], '생성 컬럼은 넣을 수 없어 빠진다');
            $this->assertEqualsCanonicalizing(['bin', 'blobv'], $snapshot['b64']);

            DB::statement("DELETE FROM `{$table}`");
            Baseline::restore(DB::connection(), $table, $snapshot);

            $this->assertSame($before, $read());
        } finally {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
