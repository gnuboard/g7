<?php

namespace Tests\Unit\Testing\SchemaCache;

use Tests\PureTestCase;
use Tests\Support\TestingSchema\Decision;

/**
 * 테스트 스키마 재사용 판정 — 어느 조건 하나라도 어긋나면 전체 재생, 모두 맞을 때만 재사용한다.
 *
 * 구조 지문은 비싼 조회라, 앞 조건(기록·강제·지문·환경·마이그레이션 이름 집합)이 이미 재생을 정했으면 계산하지 않는다.
 */
class DecisionTest extends PureTestCase
{
    /** 정규 마이그레이션 파일 이름 */
    private const FILES = ['2026_01_01_000000_create_a', '2026_01_02_000000_create_b'];

    /**
     * 모든 조건이 맞는 기록을 만듭니다.
     *
     * @param  array<string, mixed>  $override  덮어쓸 키
     * @return array<string, mixed> 기록
     */
    private function record(array $override = []): array
    {
        return array_merge([
            'fingerprint' => 'fp',
            'envKey' => 'env',
            'checksum' => 'sum',
            'freshRunId' => null,
        ], $override);
    }

    /**
     * 판정을 호출합니다. 구조 지문 클로저가 불렸는지 세어 돌려줍니다.
     *
     * @param  array<string, mixed>|null  $record  기록
     * @param  string|null  $force  강제 재생 값
     * @param  bool  $forcedOnce  이 프로세스에서 이미 `1` 로 재생했는지
     * @param  array<int, string>  $applied  DB migrations 이름
     * @param  string  $checksum  DB 구조 지문
     * @return array{0: Decision, 1: int} 판정과 구조 지문 계산 횟수
     */
    private function decide(?array $record, ?string $force = null, bool $forcedOnce = false, array $applied = self::FILES, string $checksum = 'sum'): array
    {
        $calls = 0;
        $decision = Decision::decide(
            $record,
            'fp',
            'env',
            $force,
            $forcedOnce,
            static fn (): array => $applied,
            static function () use ($checksum, &$calls): string {
                $calls++;

                return $checksum;
            },
            self::FILES,
        );

        return [$decision, $calls];
    }

    /**
     * 기록이 없으면 전체 재생한다.
     *
     * @scenario case=no_record
     *
     * @effects missing_record_forces_fresh
     *
     * @return void 반환값 없음
     */
    public function test_no_record_is_fresh(): void
    {
        [$decision, $calls] = $this->decide(null);

        $this->assertSame(Decision::FRESH, $decision->action);
        $this->assertSame('no-record', $decision->reason);
        $this->assertSame(0, $calls);
    }

    /**
     * 마이그레이션 지문·환경 키가 다르면 각각 전체 재생한다.
     *
     * @scenario case=fingerprint_or_env_changed
     *
     * @effects source_change_forces_fresh
     *
     * @return void 반환값 없음
     */
    public function test_fingerprint_or_env_mismatch_is_fresh(): void
    {
        [$byFingerprint] = $this->decide($this->record(['fingerprint' => 'old']));
        [$byEnv] = $this->decide($this->record(['envKey' => 'old']));

        $this->assertSame(['FRESH', 'fingerprint-changed'], [$byFingerprint->action, $byFingerprint->reason]);
        $this->assertSame(['FRESH', 'env-changed'], [$byEnv->action, $byEnv->reason]);
    }

    /**
     * migrations 기록 이름 집합이 파일과 다르면(반쪽 스키마) 전체 재생하고, 구조 지문은 계산하지 않는다.
     *
     * @scenario case=migration_set_mismatch
     *
     * @effects partial_schema_forces_fresh, structure_checksum_evaluated_lazily
     *
     * @return void 반환값 없음
     */
    public function test_migration_name_set_mismatch_is_fresh_without_checksum(): void
    {
        [$decision, $calls] = $this->decide($this->record(), applied: [self::FILES[0]]);

        $this->assertSame(['FRESH', 'migration-set-mismatch'], [$decision->action, $decision->reason]);
        $this->assertSame(0, $calls, '이름 집합이 이미 어긋났으면 구조 지문을 계산하지 않는다');
    }

    /**
     * DB 실물 구조 지문이 기록과 다르면 전체 재생한다.
     *
     * @scenario case=structure_changed
     *
     * @effects schema_drift_forces_fresh
     *
     * @return void 반환값 없음
     */
    public function test_structure_checksum_mismatch_is_fresh(): void
    {
        [$decision, $calls] = $this->decide($this->record(), checksum: 'drifted');

        $this->assertSame(['FRESH', 'structure-changed'], [$decision->action, $decision->reason]);
        $this->assertSame(1, $calls);
    }

    /**
     * 모든 조건이 맞으면 재사용하고, 이름 집합은 순서와 무관하게 비교한다.
     *
     * @scenario case=all_match
     *
     * @effects matching_schema_is_reused
     *
     * @return void 반환값 없음
     */
    public function test_all_match_is_reset(): void
    {
        [$decision, $calls] = $this->decide($this->record(), applied: array_reverse(self::FILES));

        $this->assertSame(['RESET', 'reuse'], [$decision->action, $decision->reason]);
        $this->assertSame(1, $calls);
    }

    /**
     * 강제 재생 값 `1` 은 프로세스마다 처음 한 번만 재생한다.
     *
     * @scenario case=force_once
     *
     * @effects force_value_one_refreshes_once_per_process
     *
     * @return void 반환값 없음
     */
    public function test_force_one_refreshes_only_first_time_in_process(): void
    {
        [$first] = $this->decide($this->record(), '1', false);
        [$second] = $this->decide($this->record(), '1', true);

        $this->assertSame(['FRESH', 'forced'], [$first->action, $first->reason]);
        $this->assertSame('RESET', $second->action);
    }

    /**
     * 실행 ID 는 기록에 남은 ID 와 다를 때만 재생한다 — 같은 ID 로 여러 프로세스를 띄워도 한 번만 재생.
     *
     * @scenario case=force_run_id
     *
     * @effects run_id_refreshes_once_across_processes
     *
     * @return void 반환값 없음
     */
    public function test_run_id_refreshes_only_when_different_from_record(): void
    {
        [$different] = $this->decide($this->record(['freshRunId' => '100']), '200');
        [$same] = $this->decide($this->record(['freshRunId' => '200']), '200');

        $this->assertSame(['FRESH', 'forced-run-id'], [$different->action, $different->reason]);
        $this->assertSame('RESET', $same->action);
    }
}
