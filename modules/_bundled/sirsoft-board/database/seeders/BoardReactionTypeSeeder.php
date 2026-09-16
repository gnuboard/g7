<?php

namespace Modules\Sirsoft\Board\Database\Seeders;

use App\Concerns\Seeder\HasTranslatableSeeder;
use App\Contracts\Seeder\TranslatableSeederInterface;
use App\Extension\Helpers\GenericEntitySyncHelper;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Support\ReactionTypeDefaults;

/**
 * 게시판 반응 유형 초기 시더.
 *
 * 관리자 CRUD 화면이 없으므로(이슈 #525 확정 01) 이 시더가 유일한 유형 등록 경로다.
 * 라벨 기본값은 ko/en/ja를 직접 하드코딩하되, 활성 언어팩의 seed/reaction_types.json
 * 다국어 키를 trait 이 자동 머지한다 (BoardTypeSeeder 와 동일 패턴).
 *
 * code로 매칭하는 upsert(user_overrides 보존)라 재실행해도 중복 생성되지 않는다.
 */
class BoardReactionTypeSeeder extends Seeder implements TranslatableSeederInterface
{
    use HasTranslatableSeeder;

    public function getExtensionIdentifier(): string
    {
        return 'sirsoft-board';
    }

    public function getTranslatableEntity(): string
    {
        return 'reaction_types';
    }

    public function getMatchKey(): string
    {
        return 'code';
    }

    /**
     * 기본값은 Support\ReactionTypeDefaults 가 단일 원천이다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDefaults(): array
    {
        return ReactionTypeDefaults::all();
    }

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $helper = app(GenericEntitySyncHelper::class);

        foreach ($this->resolveTranslatedDefaults() as $reactionType) {
            $existing = ReactionType::where('code', $reactionType['code'])->exists();

            $helper->sync(
                ReactionType::class,
                ['code' => $reactionType['code']],
                [
                    'name' => $reactionType['name'],
                    'icon' => $reactionType['icon'],
                    'display_order' => $reactionType['display_order'],
                    'is_active' => $reactionType['is_active'],
                ],
            );

            if ($existing) {
                $this->command->info("  반응 유형 '{$reactionType['code']}' 동기화 (사용자 수정 보존).");
            } else {
                $this->command->info("  반응 유형 '{$reactionType['code']}' 생성 완료.");
            }
        }
    }
}
