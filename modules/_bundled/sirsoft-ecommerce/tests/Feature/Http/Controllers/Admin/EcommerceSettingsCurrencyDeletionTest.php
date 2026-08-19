<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 통화 삭제 저장 왕복 테스트 (공개 #91)
 *
 * 제보된 증상은 "저장 응답에 이미 삭제한 통화가 되살아나 있다" 였다. 서비스 단위 테스트만으로는
 * 저장 응답이 재조회 결과를 그대로 싣는 이 층을 잡지 못하므로, PUT 응답과 재-GET 양쪽을 검증한다.
 */
class EcommerceSettingsCurrencyDeletionTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    /**
     * 응답 payload 에서 통화 코드 목록을 추출합니다.
     *
     * @param  array|null  $currencies  통화 배열
     * @return array<int, string> 통화 코드 목록
     */
    private function codes(?array $currencies): array
    {
        return array_values(array_map(fn ($c) => $c['code'] ?? null, $currencies ?? []));
    }

    /**
     * 기본 제공 통화를 삭제해 저장하면 저장 응답과 재조회 양쪽에서 사라진다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects intentionally_removed_default_currency_stays_deleted
     */
    public function test_put_response_reflects_currency_deletion(): void
    {
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'language_currency',
            'language_currency' => [
                'default_currency' => 'KRW',
                'currencies' => [
                    [
                        'code' => 'KRW',
                        'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'],
                        'exchange_rate' => null,
                        'base_unit' => 1000,
                        'rounding_unit' => '1',
                        'rounding_method' => 'floor',
                        'decimal_places' => 0,
                        'is_default' => true,
                    ],
                ],
            ],
        ]);

        $response->assertOk();

        // 저장 응답 자체가 삭제를 반영해야 한다 (제보된 증상: 응답에 5종 부활)
        $saved = $this->codes($response->json('data.language_currency.currencies'));
        $this->assertSame(['KRW'], $saved, '저장 응답에 삭제한 통화가 되살아났습니다.');

        // 재조회에서도 유지 (새로고침 시 부활 여부)
        $fetched = $this->actingAs($this->adminUser)->getJson($this->apiBase);
        $fetched->assertOk();

        $this->assertSame(['KRW'], $this->codes($fetched->json('data.language_currency.currencies')), '재조회에서 삭제한 통화가 되살아났습니다.');
    }
}
