<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Services\SettingsService;
use Tests\TestCase;

/**
 * 고급 탭 저장이 병합 대상 카테고리를 빠짐없이 분류하는지 고정합니다.
 *
 * 고급 탭은 화면 하나가 여러 카테고리(cache/debug/core_update/geoip/pagination)를 함께
 * 편집하므로, 저장 시점에 어느 카테고리 소속인지 다시 분류해야 합니다. 이 분류표가
 * 스키마와 어긋나면 그 카테고리 값은 **어디에도 담기지 않고 조용히 버려집니다** —
 * 저장 응답은 200 이고 검증도 통과하므로 화면·API 어느 쪽에도 실패가 드러나지 않습니다.
 *
 * 실제 피해: `pagination`(목록 상한값)이 분류표에 없어 운영자가 상한을 바꿔도 반영되지
 * 않았고, `storage/app/settings/pagination.json` 이 만들어진 적이 없었습니다.
 *
 * 분류 대상은 손으로 열거하지 않고 스키마(`frontend_schema.*.merge_into === 'advanced'`)
 * 에서 도출합니다. 새 카테고리가 고급 탭에 합류해도 이 테스트가 자동으로 그것을 셉니다.
 *
 * @scenario case=advanced_tab_category_persistence
 *
 * @effects pagination_limits_persist_from_admin_settings
 * @effects advanced_tab_persists_every_merged_category
 */
class AdvancedTabCategoryPersistenceTest extends TestCase
{
    /**
     * 고급 탭에서 저장한 목록 상한값이 pagination 카테고리에 기록되는지 확인합니다.
     *
     * @effects pagination_limits_persist_from_admin_settings
     */
    public function test_pagination_limits_saved_from_advanced_tab_are_persisted(): void
    {
        $saved = app(SettingsService::class)->saveSettings([
            '_tab' => 'advanced',
            'advanced' => [
                'pagination_result_cap' => 5000,
                'pagination_max_page' => 300,
            ],
        ]);

        $this->assertTrue($saved, '고급 탭 저장이 실패했습니다.');

        $pagination = app(ConfigRepositoryInterface::class)->getCategory('pagination');

        $this->assertSame(
            5000,
            $pagination['result_cap'] ?? null,
            'pagination.result_cap 이 저장되지 않았습니다 — 고급 탭 분류표에서 누락되면 값이 조용히 버려집니다.'
        );
        $this->assertSame(
            300,
            $pagination['max_page'] ?? null,
            'pagination.max_page 가 저장되지 않았습니다.'
        );
    }

    /**
     * 고급 탭에 병합되는 모든 카테고리가 저장 경로에서 분류되는지 확인합니다.
     *
     * 모집단은 스키마에서 도출하므로, 분류표가 뒤처지면 그 카테고리에서 실패합니다.
     *
     * @effects advanced_tab_persists_every_merged_category
     */
    public function test_every_category_merged_into_advanced_is_persisted(): void
    {
        $repository = app(ConfigRepositoryInterface::class);
        $schema = $repository->getFrontendSchema();

        $mergedCategories = [];
        foreach ($schema as $category => $categorySchema) {
            if (str_starts_with($category, '_') || ($categorySchema['merge_into'] ?? null) !== 'advanced') {
                continue;
            }

            $mergedCategories[$category] = $categorySchema['fields'] ?? [];
        }

        $this->assertNotEmpty(
            $mergedCategories,
            '고급 탭 병합 카테고리 모집단이 비었습니다 — 스키마 조회가 바뀌었는지 확인하세요.'
        );

        $payload = [];
        $expected = [];

        foreach ($mergedCategories as $category => $fields) {
            $this->assertNotEmpty($fields, "카테고리 {$category} 의 필드 목록이 비었습니다.");

            foreach ($fields as $fieldName => $fieldSchema) {
                $value = $this->sampleValueFor($fieldSchema['type'] ?? 'string', $fieldName);
                $payload[$fieldSchema['frontend_key'] ?? $fieldName] = $value;
                $expected[$category][$fieldName] = $value;
            }
        }

        $saved = app(SettingsService::class)->saveSettings([
            '_tab' => 'advanced',
            'advanced' => $payload,
        ]);

        $this->assertTrue($saved, '고급 탭 저장이 실패했습니다.');

        foreach ($expected as $category => $fields) {
            $stored = $repository->getCategory($category);

            foreach ($fields as $fieldName => $value) {
                $this->assertSame(
                    $value,
                    $stored[$fieldName] ?? null,
                    "{$category}.{$fieldName} 이 저장되지 않았습니다 — 고급 탭 분류표에 이 카테고리가 없습니다."
                );
            }
        }
    }

    /**
     * 필드 타입에 맞는 검사용 값을 만듭니다.
     *
     * @param  string  $type  스키마가 선언한 타입
     * @param  string  $fieldName  필드명 (문자열 값의 구분용)
     * @return mixed 검사용 값
     */
    private function sampleValueFor(string $type, string $fieldName): mixed
    {
        return match ($type) {
            'integer' => 4242,
            'boolean' => true,
            default => 'advanced-tab-'.$fieldName,
        };
    }
}
