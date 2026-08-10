<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Support\MileageRounding;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

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

    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 클래스는 오토로드 밖 — 직접 로드
        require_once dirname(__DIR__, 3).'/upgrades/data/1.0.5/migrations/07_BackfillMileageEarnRounding.php';

        $dir = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $this->settingsFile = $dir.'/mileage.json';
    }

    protected function tearDown(): void
    {
        if (File::exists($this->settingsFile)) {
            File::delete($this->settingsFile);
        }

        parent::tearDown();
    }

    private function migration(): object
    {
        $class = self::MIGRATION;

        return new $class;
    }

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
     * @return array<int, array> 저장된 통화별 규칙
     */
    private function readRules(): array
    {
        return json_decode((string) File::get($this->settingsFile), true)['currency_rules'];
    }

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
