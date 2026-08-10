<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * PG 플러그인이 선언하는 supported_methods 어휘 정합 테스트
 *
 * 관리자 결제수단 설정의 PG사 드롭다운은 `supported_methods.includes($method.id)` 로
 * 후보를 거른다. 즉 PG 플러그인이 선언하는 값은 반드시 **결제수단 id 어휘**여야 한다.
 * 다른 어휘(예: 'virtual_account')로 선언하면 교집합이 비어 드롭다운이 영구히 `---` 만
 * 노출되고, 그 결제수단은 기본 PG사 상속 외에는 PG를 지정할 수 없게 된다.
 * 백엔드는 결제수단별 pg_provider 를 실제로 사용하므로(OrderProcessingService 의
 * `$methodConfig['pg_provider'] ?? default_pg_provider`) 이는 기능 손실이다.
 *
 * 어휘 목록을 손으로 열거하면 플러그인이 늘어날 때 누락되므로, 저장소의 PG 플러그인
 * 선언을 전수 스캔해 검증한다.
 *
 * @effects pg_supported_methods_use_payment_method_vocabulary
 */
class PgProviderSupportedMethodsVocabularyTest extends ModuleTestCase
{
    /**
     * 저장소의 모든 PG 플러그인에서 supported_methods 선언을 수집합니다.
     *
     * @return array<string, array<int, string>> 파일 경로 => 선언된 결제수단 값 목록
     */
    private function collectDeclarations(): array
    {
        $roots = [base_path('plugins/_bundled'), base_path('plugins')];
        $found = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                if ($contents === false || ! str_contains($contents, 'supported_methods')) {
                    continue;
                }

                if (! preg_match("/'supported_methods'\s*=>\s*\[(.*?)\]/s", $contents, $m)) {
                    continue;
                }

                preg_match_all("/'([^']+)'/", $m[1], $values);
                if ($values[1] !== []) {
                    // 활성 디렉토리와 _bundled 중복 수집을 피하기 위해 상대 경로 키를 정규화한다.
                    $key = str_replace('\\', '/', $file->getPathname());
                    $found[$key] = $values[1];
                }
            }
        }

        return $found;
    }

    public function test_declared_supported_methods_use_payment_method_ids(): void
    {
        $declarations = $this->collectDeclarations();

        $this->assertNotEmpty(
            $declarations,
            'PG 플러그인의 supported_methods 선언을 하나도 찾지 못했습니다 (테스트 전제 붕괴 — 스캔 경로/패턴 확인 필요).'
        );

        $known = array_map(
            static fn (PaymentMethodEnum $case): string => $case->value,
            PaymentMethodEnum::cases()
        );

        $violations = [];
        foreach ($declarations as $path => $methods) {
            foreach ($methods as $method) {
                if (! in_array($method, $known, true)) {
                    $violations[] = sprintf('%s → %s', $path, $method);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "PG 플러그인이 결제수단 id 가 아닌 어휘를 선언했습니다.\n".
            "이 값은 관리자 PG 드롭다운 필터(`supported_methods.includes(method.id)`)와 직접 대조되므로,\n".
            "어휘가 어긋나면 해당 결제수단은 PG를 지정할 수 없습니다.\n".
            '허용 어휘: '.implode(', ', $known)."\n위반:\n  ".implode("\n  ", $violations)
        );
    }

    /**
     * PG 가 필요한 결제수단이 최소 한 곳의 PG 플러그인에서 지원 선언되어야,
     * 관리자 화면에서 그 결제수단에 PG를 지정할 수 있다.
     */
    public function test_pg_requiring_builtin_methods_are_covered_by_some_provider(): void
    {
        $declared = [];
        foreach ($this->collectDeclarations() as $methods) {
            foreach ($methods as $m) {
                $declared[$m] = true;
            }
        }

        // 무통장입금(dbank)·포인트·예치금·무료는 PG 불필요라 대상이 아니다.
        foreach ([PaymentMethodEnum::CARD, PaymentMethodEnum::VBANK, PaymentMethodEnum::BANK, PaymentMethodEnum::PHONE] as $case) {
            $this->assertArrayHasKey(
                $case->value,
                $declared,
                "결제수단 '{$case->value}' 를 지원한다고 선언한 PG 플러그인이 없습니다 → 관리자 화면에서 PG 지정 불가."
            );
        }
    }
}
