<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use Modules\Sirsoft\Ecommerce\Listeners\Ckeditor5ReferenceSourcesListener;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이커머스 참조 소스 등록 리스너 테스트
 *
 * 이 리스너가 빠지면 상품 설명에만 쓰이는 이미지가 "미참조" 로 판정돼 자동 정리 대상이
 * 되므로, 구독 형식(filter)과 append 계약을 회귀로 고정합니다.
 */
class Ckeditor5ReferenceSourcesListenerTest extends ModuleTestCase
{
    /**
     * @effects reference_source_listener_subscribes_as_filter
     */
    #[Test]
    public function it_subscribes_to_the_reference_source_filter_hook(): void
    {
        $hooks = Ckeditor5ReferenceSourcesListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ckeditor5.image.filter_reference_sources', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ckeditor5.image.filter_reference_sources']['type']);
        $this->assertSame('addEcommerceSources', $hooks['sirsoft-ckeditor5.image.filter_reference_sources']['method']);
    }

    /**
     * @effects reference_source_listener_appends_without_dropping
     */
    #[Test]
    public function it_appends_ecommerce_sources_while_preserving_existing_ones(): void
    {
        $existing = [['table' => 'mail_templates', 'columns' => ['body']]];

        $result = (new Ckeditor5ReferenceSourcesListener)->addEcommerceSources($existing);

        $this->assertContains($existing[0], $result);
        $this->assertContains(['table' => 'ecommerce_products', 'columns' => ['description']], $result);
        $this->assertContains(['table' => 'ecommerce_product_common_infos', 'columns' => ['content']], $result);
    }

    /**
     * @effects reference_source_listener_schema_contract
     */
    #[Test]
    public function appended_sources_follow_the_declared_schema(): void
    {
        foreach ((new Ckeditor5ReferenceSourcesListener)->addEcommerceSources([]) as $source) {
            $this->assertArrayHasKey('table', $source);
            $this->assertArrayHasKey('columns', $source);
            $this->assertIsString($source['table']);
            $this->assertIsArray($source['columns']);
            $this->assertNotEmpty($source['columns']);
        }
    }
}
