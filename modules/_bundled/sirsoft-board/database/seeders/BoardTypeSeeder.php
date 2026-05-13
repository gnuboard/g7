<?php

namespace Modules\Sirsoft\Board\Database\Seeders;

use App\Concerns\Seeder\HasTranslatableSeeder;
use App\Contracts\Seeder\TranslatableSeederInterface;
use App\Extension\Helpers\GenericEntitySyncHelper;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Board\Models\BoardType;

/**
 * 게시판 유형 초기 시더.
 *
 * GenericEntitySyncHelper 기반 upsert + stale cleanup 패턴.
 * 활성 언어팩의 seed/board_types.json 다국어 키는 trait 가 자동 머지.
 */
class BoardTypeSeeder extends Seeder implements TranslatableSeederInterface
{
    use HasTranslatableSeeder;

    public function getExtensionIdentifier(): string
    {
        return 'sirsoft-board';
    }

    public function getTranslatableEntity(): string
    {
        return 'board_types';
    }

    public function getMatchKey(): string
    {
        return 'slug';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDefaults(): array
    {
        return [
            ['slug' => 'basic', 'name' => ['ko' => '기본형', 'en' => 'Basic List']],
            ['slug' => 'gallery', 'name' => ['ko' => '갤러리형', 'en' => 'Gallery']],
            ['slug' => 'card', 'name' => ['ko' => '카드형', 'en' => 'Card']],
        ];
    }

    public function run(): void
    {
        $helper = app(GenericEntitySyncHelper::class);
        $definedSlugs = [];

        foreach ($this->resolveTranslatedDefaults() as $boardTypeData) {
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

        $deleted = $helper->cleanupStale(BoardType::class, [], 'slug', $definedSlugs);
        if ($deleted > 0) {
            $this->command->warn("  stale 게시판 유형 {$deleted}건 삭제");
        }
    }
}
