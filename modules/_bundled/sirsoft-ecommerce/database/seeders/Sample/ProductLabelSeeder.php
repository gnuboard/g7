<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;

/**
 * 상품 라벨 시더
 *
 * 상품에 표시할 기본 라벨 데이터를 생성합니다.
 * (신상품, 베스트, 할인, 추천 등)
 */
class ProductLabelSeeder extends Seeder
{
    /**
     * 상품 라벨 시더를 실행합니다.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command->info('상품 라벨 데이터 생성을 시작합니다.');

        $this->deleteExistingLabels();

        $labels = [
            [
                'name' => ['ko' => '신상품', 'en' => 'New'],
                'color' => '#22C55E',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '베스트', 'en' => 'Best'],
                'color' => '#F59E0B',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '할인', 'en' => 'Sale'],
                'color' => '#EF4444',
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '품절임박', 'en' => 'Almost Sold Out'],
                'color' => '#F97316',
                'sort_order' => 4,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '한정판', 'en' => 'Limited'],
                'color' => '#8B5CF6',
                'sort_order' => 5,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '추천', 'en' => 'Recommended'],
                'color' => '#3B82F6',
                'sort_order' => 6,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '이벤트', 'en' => 'Event'],
                'color' => '#EC4899',
                'sort_order' => 7,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '사은품증정', 'en' => 'Free Gift'],
                'color' => '#14B8A6',
                'sort_order' => 8,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '무료배송', 'en' => 'Free Shipping'],
                'color' => '#6366F1',
                'sort_order' => 9,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '당일발송', 'en' => 'Same Day'],
                'color' => '#84CC16',
                'sort_order' => 10,
                'is_active' => true,
            ],
        ];

        foreach ($labels as $label) {
            ProductLabel::create($label);
            $this->command->line("  - 라벨 생성: {$label['name']['ko']} ({$label['name']['en']})");
        }

        $count = ProductLabel::count();
        $this->command->info("상품 라벨 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 상품 라벨 데이터를 삭제합니다.
     *
     * ProductLabelAssignment는 label_id에 cascadeOnDelete가 설정되어 있어
     * 라벨 삭제 시 자동으로 함께 삭제됩니다.
     *
     * @return void
     */
    private function deleteExistingLabels(): void
    {
        $deletedCount = ProductLabel::count();

        if ($deletedCount > 0) {
            ProductLabel::query()->delete();
            $this->command->warn("기존 상품 라벨 {$deletedCount}건을 삭제했습니다.");
        }
    }
}
