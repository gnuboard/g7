<?php

namespace Tests\Feature\Seo;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Seo\Editor\SeoOgPreviewService;
use App\Seo\SeoMetaResolver;
use Tests\TestCase;

/**
 * SeoOgPreviewService base 상속 출처 구분 테스트.
 *
 * og/twitter 미리보기는 병합본(base+자식) meta.seo 를 받아 키별 출처를 계산한다. 종전엔
 * 병합본에 있는 키를 모두 source='layout'(자체 override) 으로 봐서, base 상속분과 자식이
 * 직접 입력한 값이 구분되지 않았다(검색엔진 탭에 "상속됨" 출처가 원천적으로 안 뜸).
 *
 * 본 테스트는 own_seo(자식 직접 선언분, base 병합 전)를 함께 넘기면 병합본에만 있는 키가
 * source='inherited' / inheritedFromBase=true 로 분류되는지, 자식이 직접 채운 키는 여전히
 * source='layout' 인지, 자식이 빈 값으로 비우면 다시 상속/코어로 돌아가는지를 검증한다.
 */
class SeoOgPreviewServiceInheritanceTest extends TestCase
{
    private function service(): SeoOgPreviewService
    {
        // 모듈/플러그인 declaration 은 본 테스트 범위 밖 — 빈 매니저로 격리(코어/상속/자체만 검증).
        $moduleManager = $this->createMock(ModuleManagerInterface::class);
        $moduleManager->method('getModule')->willReturn(null);
        $pluginManager = $this->createMock(PluginManagerInterface::class);
        $pluginManager->method('getPlugin')->willReturn(null);

        return new SeoOgPreviewService(
            app(SeoMetaResolver::class),
            $moduleManager,
            $pluginManager,
        );
    }

    /** og 배열에서 특정 key 행을 찾는다. */
    private function ogRow(array $preview, string $key): ?array
    {
        foreach ($preview['og'] as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    public function test_병합본에만_있고_own_에_없는_og_키는_상속_출처로_분류된다(): void
    {
        // 병합본 = base(type=website) + 자식(자체 og 없음). own = 자식 직접 선언분(og 비움).
        $merged = ['og' => ['type' => 'website']];
        $own = ['og' => []]; // 자식은 og.type 을 직접 선언하지 않음 → 상속.

        $preview = $this->service()->preview($merged, [], [], $own);

        $typeRow = $this->ogRow($preview, 'type');
        $this->assertNotNull($typeRow);
        $this->assertSame('inherited', $typeRow['source'], 'base 상속 키는 source=inherited');
        $this->assertTrue($typeRow['inheritedFromBase']);
        $this->assertFalse($typeRow['overriddenByLayout'], '상속은 자체 override 아님');
    }

    public function test_자식이_직접_채운_og_키는_layout_출처로_분류된다(): void
    {
        // 병합본 = 자식이 type=product 로 덮음. own 에도 동일하게 존재 → 자체 override.
        $merged = ['og' => ['type' => 'product']];
        $own = ['og' => ['type' => 'product']];

        $preview = $this->service()->preview($merged, [], [], $own);

        $typeRow = $this->ogRow($preview, 'type');
        $this->assertNotNull($typeRow);
        $this->assertSame('layout', $typeRow['source'], '자식 직접 입력은 source=layout');
        $this->assertFalse($typeRow['inheritedFromBase']);
        $this->assertTrue($typeRow['overriddenByLayout']);
    }

    public function test_own_미전달이면_상속_구분_없이_종전대로_layout(): void
    {
        // own_seo 미전달(null) → 상속/자체 구분 안 함(하위호환).
        $merged = ['og' => ['type' => 'website']];

        $preview = $this->service()->preview($merged, [], []);

        $typeRow = $this->ogRow($preview, 'type');
        $this->assertNotNull($typeRow);
        $this->assertSame('layout', $typeRow['source']);
        $this->assertFalse($typeRow['inheritedFromBase']);
    }
}
