<?php

namespace Tests\Feature\Documentation;

use App\Support\ExtensionDoc\ExtensionDocContext;
use App\Support\ExtensionDoc\ExtensionDocScaffolder;
use App\Support\ExtensionDoc\ExtensionInventory;
use Tests\TestCase;

/**
 * 확장 README 요구사항 블록의 PHP 확장 행 (#151 W1-d)
 *
 * 확장이 composer.json `require.ext-*`(필수)·`suggest.ext-*`(권장)로 선언한 PHP 확장을 README 요구사항 표에 싣는다.
 * 운영자가 설치 전에 서버 준비물을 알 수 있게 하는 표면이다.
 */
class ExtensionDocPhpRequirementsRowTest extends TestCase
{
    /**
     * 권장(suggest) 선언은 사유와 함께 「PHP 확장(권장)」 행으로, 필수(require) 선언은 「PHP 확장(필수)」 행으로 렌더합니다.
     *
     * @scenario surface=readme_requirements
     *
     * @effects readme_lists_declared_php_extensions
     *
     * @return void 반환값 없음
     */
    public function test_requirements_block_lists_declared_php_extensions(): void
    {
        $kcp = $this->requirementsBlock('plugin:sirsoft-pay_nhnkcp', 'sirsoft-pay_nhnkcp');
        $this->assertStringContainsString('| PHP 확장(권장) | `soap` — 모바일 결제(SmartPhone Pay) 승인키 발급에 필요 |', $kcp);
        $this->assertStringNotContainsString('PHP 확장(필수)', $kcp);

        $totp = $this->requirementsBlock('plugin:sirsoft-totp', 'sirsoft-totp');
        $this->assertStringContainsString('| PHP 확장(필수) | `hash` |', $totp);

        $ecommerce = $this->requirementsBlock('module:sirsoft-ecommerce', 'sirsoft-ecommerce');
        $this->assertStringNotContainsString('PHP 확장(', $ecommerce, '이커머스는 PHP 확장을 요구하지 않는다(bcmath 의존 제거)');
    }

    /**
     * 한 확장의 requirements 블록을 렌더합니다.
     *
     * @param  string  $scope  수집 범위 (type:id)
     * @param  string  $id  확장 식별자
     * @return string requirements 블록 마크다운
     */
    private function requirementsBlock(string $scope, string $id): string
    {
        $records = array_values(array_filter((new ExtensionInventory)->collect($scope), fn (array $r): bool => $r['id'] === $id));
        $this->assertNotEmpty($records, "{$id} 를 찾지 못했습니다.");

        return (new ExtensionDocScaffolder)->renderBlock('requirements', app(ExtensionDocContext::class)->build($records[0]));
    }
}
