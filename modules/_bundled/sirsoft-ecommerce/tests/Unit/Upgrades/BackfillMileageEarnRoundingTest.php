<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Support\MileageRounding;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\UpgradeFiles;

/**
 * 마일리지 적립 절사 기준 백필 마이그레이션 테스트
 *
 * 폴백이 있어 백필 없이도 금액은 같다. 이 백필의 목적은 "운영자가 버림을 선택했다" 와
 * "키가 없어서 폴백됐다" 를 설정 파일에서 구분 가능하게 만드는 것이다 — 그래야 이후 폴백
 * 기본값을 바꿔도 기존 사이트의 적립 절사가 조용히 따라 바뀌지 않는다.
 */
class BackfillMileageEarnRoundingTest extends ModuleTestCase
{
    private const MIGRATION = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_0_5\\Migrations\\BackfillMileageEarnRounding';

    private string $settingsFile;

    /**
     * 1.0.5 백필 마이그레이션 파일을 불러오고 테스트 전용 마일리지 설정 파일 경로를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 클래스는 오토로드 밖 — 직접 로드
        UpgradeFiles::requireOnce(dirname(__DIR__, 3).'/upgrades/data/1.0.5/migrations/07_BackfillMileageEarnRounding.php');

        $dir = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $this->settingsFile = $dir.'/mileage.json';
    }

    /**
     * 테스트가 남긴 마일리지 설정 파일을 지워 다음 테스트에 새지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::exists($this->settingsFile)) {
            File::delete($this->settingsFile);
        }

        parent::tearDown();
    }

    /**
     * 검증 대상 절사 기준 백필 마이그레이션 인스턴스를 새로 만든다.
     *
     * @return object BackfillMileageEarnRounding 인스턴스
     */
    private function migration(): object
    {
        $class = self::MIGRATION;

        return new $class;
    }

    /**
     * 1.0.2 → 1.0.5 업그레이드 단계를 나타내는 컨텍스트를 만든다.
     *
     * @return UpgradeContext 1.0.5 단계 업그레이드 컨텍스트
     */
    private function context(): UpgradeContext
    {
        return new UpgradeContext(
            fromVersion: '1.0.2',
            toVersion: '1.0.5',
            currentStep: '1.0.5',
        );
    }

    /**
     * 마일리지 설정 파일을 기록합니다.
     *
     * @param  array<int, array>  $rules  통화별 규칙
     * @return void 반환값 없음
     */
    private function writeSettings(array $rules): void
    {
        File::put($this->settingsFile, json_encode([
            'enabled' => true,
            'default_earn_rate' => 1,
            'currency_rules' => $rules,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 백필 후 설정 파일에 저장된 통화별 마일리지 규칙을 읽어 온다.
     *
     * @return array<int, array> 저장된 통화별 규칙
     */
    private function readRules(): array
    {
        return json_decode((string) File::get($this->settingsFile), true)['currency_rules'];
    }

    /**
     * 절사 키가 없는 모든 통화 규칙에 종전 동작값(단위 1·버림)이 명시적으로 기입된다.
     *
     * @scenario rule_source=legacy_absent, rounding_unit=1, rounding_method=floor
     *
     * @effects upgrade_backfills_missing_rounding_keys
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 절사_키가_없는_행에_종전_동작값을_기입한다(): void
    {
        $this->writeSettings([
            ['currency_code' => 'KRW', 'point_value' => 1, 'use_unit' => 10],
            ['currency_code' => 'USD', 'point_value' => 0.001, 'use_unit' => 1],
        ]);

        $this->migration()->run($this->context());

        foreach ($this->readRules() as $rule) {
            $this->assertSame('1', $rule[MileageRounding::UNIT_KEY]);
            $this->assertSame('floor', $rule[MileageRounding::METHOD_KEY]);
        }
    }

    /**
     * 운영자가 이미 고른 단위·방식(100·올림)은 두 번 실행해도 덮어쓰지 않는다.
     *
     * @scenario rule_source=live_settings, rounding_unit=100, rounding_method=ceil
     *
     * @effects upgrade_preserves_operator_chosen_values
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이미_기입된_값은_덮어쓰지_않는다(): void
    {
        $this->writeSettings([
            [
                'currency_code' => 'KRW',
                MileageRounding::UNIT_KEY => '100',
                MileageRounding::METHOD_KEY => 'ceil',
            ],
        ]);

        $this->migration()->run($this->context());
        // 멱등 확인 — 두 번 돌려도 운영자 선택이 보존되어야 한다
        $this->migration()->run($this->context());

        $rules = $this->readRules();
        $this->assertSame('100', $rules[0][MileageRounding::UNIT_KEY]);
        $this->assertSame('ceil', $rules[0][MileageRounding::METHOD_KEY]);
    }

    /**
     * 방식만 있는 행은 빠진 단위만 기본값으로 채우고 있던 방식(반올림)은 보존한다.
     *
     * @scenario rule_source=legacy_absent, rounding_unit=1, rounding_method=round
     *
     * @effects upgrade_backfills_missing_rounding_keys, upgrade_preserves_operator_chosen_values
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 한쪽_키만_있는_행은_빠진_키만_채운다(): void
    {
        $this->writeSettings([
            ['currency_code' => 'KRW', MileageRounding::METHOD_KEY => 'round'],
        ]);

        $this->migration()->run($this->context());

        $rules = $this->readRules();
        $this->assertSame('1', $rules[0][MileageRounding::UNIT_KEY], '빠진 단위는 채워야 한다');
        $this->assertSame('round', $rules[0][MileageRounding::METHOD_KEY], '있던 방식은 보존해야 한다');
    }

    /**
     * 설정 파일이 없는 설치본에서는 백필이 새 설정 파일을 만들지 않는다.
     *
     * @scenario rule_source=legacy_absent
     *
     * @effects upgrade_skips_when_settings_file_absent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 설정_파일이_없으면_아무것도_만들지_않는다(): void
    {
        if (File::exists($this->settingsFile)) {
            File::delete($this->settingsFile);
        }

        $this->migration()->run($this->context());

        // 신규 설치는 config/settings/defaults.json 이 값을 갖는다 — 여기서 파일을 만들면
        // 운영자가 저장한 적 없는 설정 파일이 생겨 defaults 병합 경로가 달라진다.
        $this->assertFalse(File::exists($this->settingsFile));
    }

    /**
     * 백필로 기입된 규칙으로 산출한 적립액이 종전 (int) floor 결과와 같다.
     *
     * @scenario rule_source=legacy_absent, rounding_unit=1, rounding_method=floor
     *
     * @effects fallback_matches_legacy_int_floor
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 백필_후_적립_산출이_종전과_같다(): void
    {
        $this->writeSettings([['currency_code' => 'KRW', 'point_value' => 1]]);

        $this->migration()->run($this->context());

        $rounding = MileageRounding::normalize($this->readRules()[0]);

        foreach ([781.6, 2735.6, 1516.5] as $raw) {
            $this->assertSame(
                (int) floor($raw),
                MileageRounding::apply($raw, $rounding),
                '백필된 값이 종전 (int) floor 동작과 어긋난다'
            );
        }
    }
}
