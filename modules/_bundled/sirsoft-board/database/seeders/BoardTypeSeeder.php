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

        foreach ($this->resolveTranslatedDefaults() as $boardTypeData) {
            $existing = BoardType::where('slug', $boardTypeData['slug'])->exists();

            $helper->sync(
                BoardType::class,
                ['slug' => $boardTypeData['slug']],
                ['name' => $boardTypeData['name']],
            );

            if ($existing) {
                $this->command->info("  게시판 유형 '{$boardTypeData['slug']}' 동기화 (사용자 수정 보존).");
            } else {
                $this->command->info("  게시판 유형 '{$boardTypeData['slug']}' 생성 완료.");
            }
        }

        // stale 정리는 하지 않는다 — 이 표는 운영자가 「유형 추가」 로 직접 유형을 만드는 표인데, 운영자 행을 가를
        // 표식(created_by 등)이 없어 "선언에 없는 slug" 를 지우면 운영자 유형까지 사라진다. 이 시더는 언어팩을 켜고
        // 끌 때마다 다시 돌기 때문에 그 삭제가 흔적 없이 반복됐다(#727 D5-④). 선언 유형(basic·gallery·card)을
        // 앞으로 없앨 일이 생기면 그 버전의 업그레이드 스텝이 명시적으로 처리한다.
    }
}
