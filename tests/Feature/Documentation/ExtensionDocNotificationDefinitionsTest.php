<?php

namespace Tests\Feature\Documentation;

use App\Support\ExtensionDoc\DeclarativeSurfaceCollector;
use App\Support\ExtensionDoc\ExtensionInventory;
use Tests\TestCase;

/**
 * 확장 문서 생성기의 알림 정의 수집 — 레코드마다 생기는 정의 제외
 *
 * 쇼핑몰 1.4.1 은 발급 알림을 켠 쿠폰마다 `coupon_issued.{쿠폰ID}` 정의를 선언에 싣는다(업데이트의 정리가 지우지 않게).
 * 문서 생성기가 그 값을 그대로 쓰면 개발 DB 의 쿠폰 수에 따라 생성 문서가 바뀌고(드리프트) 데이터 id 가 커밋 문서에 남는다.
 */
class ExtensionDocNotificationDefinitionsTest extends TestCase
{
    /**
     * 레코드별 정의(`종류.{숫자 id}`)만 빼고 고정 선언·점이 든 고정 종류·문자열 항목은 그대로 둡니다.
     *
     * @scenario surface=collector
     *
     * @effects per_record_notification_types_excluded
     *
     * @return void 반환값 없음
     */
    public function test_per_record_notification_types_are_excluded(): void
    {
        $kept = DeclarativeSurfaceCollector::staticNotificationDefinitions([
            ['type' => 'order_confirmed', 'channels' => ['mail']],
            ['type' => 'coupon_issued.309', 'channels' => ['mail', 'database']],
            ['type' => 'coupon_issued.12'],
            ['type' => 'v2.order_shipped'],
            'legacy_string_entry',
        ]);

        $types = array_map(fn ($d) => is_array($d) ? $d['type'] : $d, $kept);
        $this->assertSame(['order_confirmed', 'v2.order_shipped', 'legacy_string_entry'], $types);
    }

    /**
     * 문자열 키 배열은 키를 유지하고, 목록 배열은 키를 다시 매깁니다.
     *
     * @scenario surface=collector
     *
     * @effects list_shape_preserved
     *
     * @return void 반환값 없음
     */
    public function test_array_shape_is_preserved(): void
    {
        $assoc = DeclarativeSurfaceCollector::staticNotificationDefinitions([
            'a' => ['type' => 'order_confirmed'],
            'b' => ['type' => 'coupon_issued.1'],
        ]);
        $this->assertSame(['a'], array_keys($assoc));

        $list = DeclarativeSurfaceCollector::staticNotificationDefinitions([
            ['type' => 'coupon_issued.1'],
            ['type' => 'order_confirmed'],
        ]);
        $this->assertSame([0], array_keys($list));
    }

    /**
     * 번들 쇼핑몰의 실제 수집 결과에 쿠폰별 정의가 없고, 고정 선언(쿠폰 만료 임박 등)은 남습니다.
     *
     * @scenario surface=bundled_ecommerce
     *
     * @effects bundled_docs_free_of_per_record_types
     *
     * @return void 반환값 없음
     */
    public function test_bundled_ecommerce_surface_has_no_per_record_types(): void
    {
        $record = (new ExtensionInventory)->find('module', 'sirsoft-ecommerce');
        $this->assertNotNull($record, '번들 쇼핑몰 레코드를 찾지 못했습니다');

        $surface = (new DeclarativeSurfaceCollector)->collect($record);

        if (($surface['available'] ?? false) !== true) {
            $this->fail('쇼핑몰 표면을 수집하지 못했습니다: '.json_encode($surface['reason'] ?? null, JSON_UNESCAPED_UNICODE));
        }

        $types = array_map(
            fn ($d) => is_array($d) ? (string) ($d['type'] ?? '') : (string) $d,
            $surface['values']['getNotificationDefinitions'] ?? [],
        );
        $this->assertContains('coupon_expiring_soon', $types);
        $this->assertSame([], array_values(array_filter($types, fn ($t) => preg_match('/\.\d+$/', $t) === 1)));
    }
}
