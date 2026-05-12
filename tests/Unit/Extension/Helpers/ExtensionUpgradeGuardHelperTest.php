<?php

namespace Tests\Unit\Extension\Helpers;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\AbstractUpgradeStep;
use App\Extension\Helpers\ExtensionUpgradeGuardHelper;
use App\Extension\UpgradeContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ExtensionUpgradeGuardHelper 단위 테스트.
 *
 * 검증 대상:
 *   - resolveSinceVersion: g7_version 제약 → 의무 시작 버전 분기
 *   - assertAbstractInheritance: stepVersion + sinceVersion + step 상속 조합 전수
 */
class ExtensionUpgradeGuardHelperTest extends TestCase
{
    // ── resolveSinceVersion ─────────────────────────────────────────────

    #[Test]
    public function resolveSinceVersion_g7_version_제약이_null이면_null을_반환합니다(): void
    {
        $this->assertNull(ExtensionUpgradeGuardHelper::resolveSinceVersion(null, '1.0.0'));
    }

    #[Test]
    public function resolveSinceVersion_g7_version_제약이_빈_문자열이면_null을_반환합니다(): void
    {
        $this->assertNull(ExtensionUpgradeGuardHelper::resolveSinceVersion('', '1.0.0'));
        $this->assertNull(ExtensionUpgradeGuardHelper::resolveSinceVersion('   ', '1.0.0'));
    }

    #[Test]
    public function resolveSinceVersion_제약_최소가_beta5_미만이면_null(): void
    {
        $cases = [
            '>=7.0.0-beta.4',
            '>=7.0.0-beta.1',
            '^7.0.0-beta.4',
            '~7.0.0-beta.4',
            '7.0.0-alpha.1',
            '>=6.0.0',
        ];

        foreach ($cases as $constraint) {
            $this->assertNull(
                ExtensionUpgradeGuardHelper::resolveSinceVersion($constraint, '1.2.3'),
                "constraint={$constraint} 는 legacy 로 판정되어야 함 (가드 미발동)"
            );
        }
    }

    #[Test]
    public function resolveSinceVersion_제약_최소가_beta5_이상이면_working_version_반환(): void
    {
        $cases = [
            '>=7.0.0-beta.5' => '1.0.0-beta.5',
            '>=7.0.0' => '2.5.0',
            '^7.0.0' => '0.1.0',
            '~7.0.0-beta.5' => '3.0.0-rc.1',
            '7.0.0-beta.5' => '1.0.0',
            '=7.0.0-beta.5' => '1.0.0',
        ];

        foreach ($cases as $constraint => $workingVersion) {
            $this->assertSame(
                $workingVersion,
                ExtensionUpgradeGuardHelper::resolveSinceVersion($constraint, $workingVersion),
                "constraint={$constraint} 는 working version={$workingVersion} 반환해야 함"
            );
        }
    }

    #[Test]
    public function resolveSinceVersion_OR_제약은_첫_후보_기준으로_판정(): void
    {
        $this->assertSame(
            '1.0.0',
            ExtensionUpgradeGuardHelper::resolveSinceVersion('>=7.0.0-beta.5|>=8.0.0', '1.0.0'),
            'OR 의 첫 후보가 beta.5 이상이면 가드 발동',
        );

        $this->assertNull(
            ExtensionUpgradeGuardHelper::resolveSinceVersion('>=7.0.0-beta.4|>=7.0.0-beta.5', '1.0.0'),
            'OR 의 첫 후보가 beta.5 미만이면 legacy 로 판정',
        );
    }

    #[Test]
    public function resolveSinceVersion_파싱_실패는_보수적으로_null(): void
    {
        $cases = [
            'not-a-version',
            'beta',
            '>=',
            'v1',
        ];

        foreach ($cases as $constraint) {
            $this->assertNull(
                ExtensionUpgradeGuardHelper::resolveSinceVersion($constraint, '1.0.0'),
                "constraint={$constraint} 는 파싱 실패 → 가드 미발동",
            );
        }
    }

    // ── assertAbstractInheritance ───────────────────────────────────────

    #[Test]
    public function assertAbstractInheritance_stepVersion이_sinceVersion보다_낮으면_상속_없어도_통과(): void
    {
        $step = $this->createLegacyStep();

        ExtensionUpgradeGuardHelper::assertAbstractInheritance(
            stepVersion: '1.0.0',
            sinceVersion: '1.2.0',
            step: $step,
            extensionType: 'module',
            extensionIdentifier: 'vendor-foo',
        );

        $this->assertTrue(true, '예외 미발생');
    }

    #[Test]
    public function assertAbstractInheritance_stepVersion이_sinceVersion_이상이고_상속_OK면_통과(): void
    {
        $step = $this->createAbstractStep();

        ExtensionUpgradeGuardHelper::assertAbstractInheritance(
            stepVersion: '1.2.0',
            sinceVersion: '1.2.0',
            step: $step,
            extensionType: 'module',
            extensionIdentifier: 'vendor-foo',
        );

        $this->assertTrue(true, '예외 미발생');
    }

    #[Test]
    public function assertAbstractInheritance_stepVersion이_sinceVersion_이상이고_상속_없으면_throw(): void
    {
        $step = $this->createLegacyStep();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must extend App\\\\Extension\\\\AbstractUpgradeStep/');
        $this->expectExceptionMessageMatches('/vendor-foo/');

        ExtensionUpgradeGuardHelper::assertAbstractInheritance(
            stepVersion: '1.2.0',
            sinceVersion: '1.2.0',
            step: $step,
            extensionType: 'module',
            extensionIdentifier: 'vendor-foo',
        );
    }

    #[Test]
    public function assertAbstractInheritance_stepVersion이_sinceVersion보다_높고_상속_없으면_throw(): void
    {
        $step = $this->createLegacyStep();

        $this->expectException(\RuntimeException::class);

        ExtensionUpgradeGuardHelper::assertAbstractInheritance(
            stepVersion: '2.0.0',
            sinceVersion: '1.2.0',
            step: $step,
            extensionType: 'plugin',
            extensionIdentifier: 'vendor-bar',
        );
    }

    #[Test]
    public function assertAbstractInheritance_예외_메시지에_확장_타입과_식별자_그리고_step_버전_포함(): void
    {
        $step = $this->createLegacyStep();

        try {
            ExtensionUpgradeGuardHelper::assertAbstractInheritance(
                stepVersion: '3.1.4',
                sinceVersion: '1.0.0',
                step: $step,
                extensionType: 'plugin',
                extensionIdentifier: 'sirsoft-payment',
            );
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('plugin', $e->getMessage());
            $this->assertStringContainsString('sirsoft-payment', $e->getMessage());
            $this->assertStringContainsString('3.1.4', $e->getMessage());
            $this->assertStringContainsString('1.0.0', $e->getMessage());
            $this->assertStringContainsString('7.0.0-beta.5', $e->getMessage());
        }
    }

    private function createLegacyStep(): UpgradeStepInterface
    {
        return new class implements UpgradeStepInterface
        {
            public function run(UpgradeContext $context): void {}
        };
    }

    private function createAbstractStep(): AbstractUpgradeStep
    {
        return new class extends AbstractUpgradeStep {};
    }
}
