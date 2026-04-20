<?php

namespace Modules\Sirsoft\Board\Database\Seeders;

use App\Extension\Helpers\GenericEntitySyncHelper;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Board\Models\BoardType;

/**
 * 게시판 유형 초기 시더
 *
 * 기본 3개 유형(basic, gallery, card)을 생성합니다.
 * module.php getSeeders()에서 직접 등록됩니다.
 *
 * GenericEntitySyncHelper 기반 upsert + stale cleanup 패턴.
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
        $helper = app(GenericEntitySyncHelper::class);
        $definedSlugs = [];

        foreach (self::DEFAULT_BOARD_TYPES as $boardTypeData) {
            $existing = BoardType::where('slug', $boardTypeData['slug'])->exists();

            $helper->sync(
                BoardType::class,
                ['slug' => $boardTypeData['slug']],
                ['name' => $boardTypeData['name']],
            );
            $definedSlugs[] = $boardTypeData['slug'];

            if ($existing) {
                $this->command->info("  게시판 유형 '{$boardTypeData['slug']}' 동기화 (사용자 수정 보존).");
            } else {
                $this->command->info("  게시판 유형 '{$boardTypeData['slug']}' 생성 완료.");
            }
        }

        // 완전 동기화: seeder 에서 제거된 유형 삭제 (user_overrides 무관)
        $deleted = $helper->cleanupStale(BoardType::class, [], 'slug', $definedSlugs);
        if ($deleted > 0) {
            $this->command->warn("  stale 게시판 유형 {$deleted}건 삭제");
        }
    }
}
