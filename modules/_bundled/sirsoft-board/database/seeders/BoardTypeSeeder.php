<?php

namespace Modules\Sirsoft\Board\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Board\Models\BoardType;

/**
 * 게시판 유형 초기 시더
 *
 * 기본 3개 유형(basic, gallery, card)을 생성합니다.
 * module.php getSeeders()에서 직접 등록됩니다.
 */
class BoardTypeSeeder extends Seeder
{
    /**
     * 초기 게시판 유형 데이터
     */
    private const DEFAULT_BOARD_TYPES = [
        [
            'slug' => 'basic',
            'name' => ['ko' => '기본형', 'en' => 'Basic List'],
        ],
        [
            'slug' => 'gallery',
            'name' => ['ko' => '갤러리형', 'en' => 'Gallery'],
        ],
        [
            'slug' => 'card',
            'name' => ['ko' => '카드형', 'en' => 'Card'],
        ],
    ];

    /**
     * 시더 실행
     *
     * @return void
     */
    public function run(): void
    {
        foreach (self::DEFAULT_BOARD_TYPES as $boardTypeData) {
            if (BoardType::where('slug', $boardTypeData['slug'])->exists()) {
                $this->command->warn("  게시판 유형 '{$boardTypeData['slug']}' 이미 존재합니다. 건너뜁니다.");

                continue;
            }

            BoardType::create($boardTypeData);
            $this->command->info("  게시판 유형 '{$boardTypeData['slug']}' 생성 완료.");
        }
    }
}
