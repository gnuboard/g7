<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use Modules\Sirsoft\Ecommerce\Listeners\Ckeditor5ReferenceSourcesListener;
use PHPUnit\Framework\Attributes\Test;
use Tests\PureTestCase;

/**
 * 이커머스 참조 소스 등록 리스너 테스트
 *
 * 이 리스너가 빠지면 상품 설명에만 쓰이는 이미지가 "미참조" 로 판정돼 자동 정리 대상이
 * 되므로, 구독 형식(filter)과 append 계약을 회귀로 고정합니다.
 */
class Ckeditor5ReferenceSourcesListenerTest extends PureTestCase
{
    /**
     * 리스너가 CKEditor5 참조 소스 훅을 filter 형식으로 구독하고 addEcommerceSources 로 처리하는지 검증한다.
     *
     * @effects reference_source_listener_subscribes_as_filter
     *
     * @return void 반환값 없음
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
     * 기존 참조 소스를 지우지 않고 상품 설명·상품 공통정보 본문 소스를 덧붙이는지 검증한다.
     *
     * @effects reference_source_listener_appends_without_dropping
     *
     * @return void 반환값 없음
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
     * 추가된 참조 소스마다 문자열 table 과 비어 있지 않은 columns 배열을 갖춰 선언된 스키마를 따르는지 검증한다.
     *
     * @effects reference_source_listener_schema_contract
     *
     * @return void 반환값 없음
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
