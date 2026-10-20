<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `users.language` 확장 마이그레이션(5 → 20자) 테스트 (#148).
 *
 * 언어팩으로 켤 수 있는 `fil-PH`·`zh-Hant-TW` 는 다섯 글자를 넘어, 회원 가입·프로필·관리자 회원 수정이
 * 엄격 모드에서 22001 로 실패했다. 칸 폭은 `language_packs.locale` varchar(20) 와 같다.
 */
class ModifyLanguageInUsersTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_19_000001_modify_language_in_users_table.php';

    /**
     * 열 글자 지역 표기 언어를 회원 언어로 저장할 수 있다.
     *
     * @scenario shape=region_tagged
     *
     * @effects user_language_column_holds_region_tag
     *
     * @return void 반환값 없음
     */
    public function test_region_tagged_language_is_stored(): void
    {
        $this->assertSame(20, $this->languageLength());

        $user = User::factory()->create(['language' => 'zh-Hant-TW']);

        $this->assertSame('zh-Hant-TW', $user->fresh()->language);
    }

    /**
     * up → down → up 왕복 — down 은 다섯 글자를 넘는 값을 'ko' 로 고친 뒤 칸을 줄인다.
     *
     * @scenario shape=over_length
     *
     * @effects user_language_column_holds_region_tag
     *
     * @return void 반환값 없음
     */
    public function test_round_trip_resets_long_values_before_shrinking(): void
    {
        $long = User::factory()->create(['language' => 'fil-PH']);
        $short = User::factory()->create(['language' => 'en']);

        try {
            Artisan::call('migrate:rollback', ['--path' => [self::MIGRATION], '--force' => true]);

            $this->assertSame(5, $this->languageLength());
            $this->assertSame('ko', DB::table('users')->where('id', $long->id)->value('language'));
            $this->assertSame('en', DB::table('users')->where('id', $short->id)->value('language'));
        } finally {
            // DDL 이 테스트 트랜잭션을 암묵 커밋하므로 남는 행은 직접 치운다.
            Artisan::call('migrate', ['--path' => [self::MIGRATION], '--force' => true]);
            DB::table('users')->whereIn('id', [$long->id, $short->id])->delete();
        }

        $this->assertSame(20, $this->languageLength());
    }

    /**
     * language 컬럼의 선언 길이를 읽습니다.
     *
     * @return int 문자 수 (varchar(N) 의 N)
     */
    private function languageLength(): int
    {
        foreach (Schema::getColumns('users') as $column) {
            if ($column['name'] === 'language') {
                preg_match('/\((\d+)\)/', (string) $column['type'], $matches);

                return (int) ($matches[1] ?? 0);
            }
        }

        $this->fail('users.language 컬럼이 없다');
    }
}
