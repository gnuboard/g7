<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Export\Listeners;

use Modules\Sirsoft\Ecommerce\Listeners\ExportActivityLogDescriptionResolver;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 내보내기 활동 로그 설명 매개변수 해석 테스트
 *
 * 설명 키가 내보내기 것일 때만 작업유형·파일명·거부 사유를 보는 사람의 언어로 채우고, 다른 확장의 키는 손대지 않는다.
 */
class ExportActivityLogDescriptionResolverTest extends ExportTestCase
{
    /**
     * 파일명 스냅샷이 있으면 그것을, 없으면 「작업유형 #번호」를 쓴다. 거부 사유는 라벨로 바꾼다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=activity_log
     *
     * @effects activity_log_labels_resolved
     */
    public function test_resolves_type_file_name_and_reason_labels(): void
    {
        app()->setLocale('ko');
        $resolver = new ExportActivityLogDescriptionResolver;
        $prefix = 'sirsoft-ecommerce::activity_log.description.';

        $named = $resolver->resolveDescriptionParams(['export_id' => 7], $prefix.'export_complete', ['type' => 'orders', 'name' => 'orders_20261015.csv']);
        $this->assertSame('주문내역', $named['type_label']);
        $this->assertSame('orders_20261015.csv', $named['file_name']);

        $unnamed = $resolver->resolveDescriptionParams(['export_id' => 7], $prefix.'export_request', ['type' => 'mileage']);
        $this->assertSame('마일리지 내역 #7', $unnamed['file_name']);

        $denied = $resolver->resolveDescriptionParams(['reason' => 'too_many_pending', 'type' => 'products'], $prefix.'export_request_denied', []);
        $this->assertSame('동시 요청 한도 초과', $denied['reason_label']);
        $this->assertSame('상품내역', $denied['type_label']);
    }

    /**
     * 이커머스의 다른 설명 키(주문 수정 등)는 그대로 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=activity_log
     *
     * @effects activity_log_labels_resolved
     */
    public function test_leaves_other_extension_keys_untouched(): void
    {
        $params = ['export_id' => 7];

        $this->assertSame($params, (new ExportActivityLogDescriptionResolver)->resolveDescriptionParams($params, 'sirsoft-ecommerce::activity_log.description.order_update', ['type' => 'orders']));
    }
}
