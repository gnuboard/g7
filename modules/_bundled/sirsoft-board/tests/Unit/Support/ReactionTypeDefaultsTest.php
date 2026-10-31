<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Support;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Support\ReactionTypeDefaults;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * 반응 유형 기본값 단일 원천(ReactionTypeDefaults) 검증.
 *
 * 같은 기본값이 시더와 1.0.3·1.2.0 업그레이드 백필에 따로 정의돼 있어 한쪽만 고치면
 * 신규 설치와 업그레이드 설치의 유형이 갈라졌다. 시더는 이 클래스를 읽고,
 * 백필 상수는 그 시점 값으로 동결한다 — 이 테스트가 둘의 드리프트를 감시한다.
 */
class ReactionTypeDefaultsTest extends ModuleTestCase
{
    /**
     * 시더가 기본값을 자체 하드코딩하지 않고 단일 원천에서 읽는다.
     *
     * @scenario case=defaults_single_source_and_backfill_no_drift
     *
     * @effects seeder_reads_defaults_from_single_source
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_seeder_uses_defaults(): void
    {
        $this->assertSame(
            ReactionTypeDefaults::all(),
            (new BoardReactionTypeSeeder)->getDefaults(),
            'BoardReactionTypeSeeder::getDefaults() 가 ReactionTypeDefaults::all() 과 다릅니다. 기본값은 단일 원천에서만 정의합니다.',
        );
    }

    /**
     * 동결된 1.0.3 백필 상수가 현행 단일 원천의 부분집합이다.
     *
     * 백필은 이미 실행된 설치본의 재현성 때문에 값을 동결하므로 현행 기본값과
     * 완전히 같을 필요는 없지만(예: 나중에 유형이 추가될 수 있다), 백필이 넣는
     * 유형의 code·name·icon 은 현행 기본값과 어긋나면 안 된다 — 어긋나면 업그레이드
     * 설치본과 신규 설치본의 같은 code 가 다른 라벨·아이콘을 갖게 된다.
     *
     * @scenario case=defaults_single_source_and_backfill_no_drift
     *
     * @effects backfill_snapshot_does_not_drift_from_defaults
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_v1_0_3_backfill_snapshot_is_subset_of_defaults(): void
    {
        $this->assertSnapshotIsSubsetOfDefaults(
            '1.0.3/migrations/07_SeedReactionTypes.php',
            'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftBoard\\V1_0_3\\Migrations\\SeedReactionTypes',
        );
    }

    /**
     * 동결된 1.2.0 반응 유형 시드 상수가 현행 단일 원천의 부분집합이다.
     *
     * 1.0.3 이상에서 출발하는 업데이트는 1.2.0 스텝이 유형을 채우므로 같은 드리프트 감시를 받는다.
     *
     * @scenario case=defaults_single_source_and_backfill_no_drift
     *
     * @effects backfill_snapshot_does_not_drift_from_defaults
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_v1_2_0_seed_snapshot_is_subset_of_defaults(): void
    {
        $this->assertSnapshotIsSubsetOfDefaults(
            '1.2.0/migrations/06_SeedReactionTypes.php',
            'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftBoard\\V1_2_0\\Migrations\\SeedReactionTypes',
        );
    }

    /**
     * 업그레이드 스텝의 DEFAULTS 상수가 현행 기본값과 어긋나지 않는지 확인합니다.
     *
     * @param  string  $relativePath  upgrades/data 아래 스텝 파일 경로
     * @param  string  $stepClass  스텝 클래스 FQCN
     * @return void 반환값 없음
     */
    private function assertSnapshotIsSubsetOfDefaults(string $relativePath, string $stepClass): void
    {
        // 업그레이드 스텝은 PSR-4 오토로드 대상이 아니라 러너가 파일 경로로 싣는다 —
        // 리플렉션으로 상수를 읽으려면 이 테스트도 같은 방식으로 파일을 먼저 로드해야 한다.
        $stepFile = __DIR__.'/../../../upgrades/data/'.$relativePath;

        $this->assertFileExists($stepFile, "반응 유형 백필 스텝 파일이 없습니다: {$relativePath}");

        if (! class_exists($stepClass, false)) {
            require_once $stepFile;
        }

        $constant = (new ReflectionClass($stepClass))->getConstant('DEFAULTS');

        $this->assertIsArray($constant, '백필 스텝의 DEFAULTS 상수를 읽지 못했습니다.');
        $this->assertNotEmpty($constant, '백필 스텝의 DEFAULTS 상수가 비어 있습니다.');

        $current = collect(ReactionTypeDefaults::all())->keyBy('code');

        foreach ($constant as $frozen) {
            $code = $frozen['code'];

            $this->assertTrue(
                $current->has($code),
                "백필이 넣는 유형 '{$code}' 가 현행 ReactionTypeDefaults 에 없습니다.",
            );

            $this->assertSame(
                $current[$code]['name'],
                $frozen['name'],
                "유형 '{$code}' 의 라벨이 백필 동결본과 현행 기본값 사이에서 어긋났습니다.",
            );

            $this->assertSame(
                $current[$code]['icon'],
                $frozen['icon'],
                "유형 '{$code}' 의 아이콘이 백필 동결본과 현행 기본값 사이에서 어긋났습니다.",
            );

            $this->assertSame(
                $current[$code]['display_order'],
                $frozen['display_order'],
                "유형 '{$code}' 의 표시 순서가 백필 동결본과 현행 기본값 사이에서 어긋났습니다.",
            );
        }
    }

    /**
     * codes() 가 all() 의 code 목록과 일치한다.
     *
     * @scenario case=defaults_single_source_and_backfill_no_drift
     *
     * @effects seeder_reads_defaults_from_single_source
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_codes_matches_all(): void
    {
        $this->assertSame(['like', 'dislike'], ReactionTypeDefaults::codes());
    }
}
