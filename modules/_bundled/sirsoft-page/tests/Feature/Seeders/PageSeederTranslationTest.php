<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Seeders;

use App\Contracts\Seeder\TranslatableSeederInterface;
use App\Extension\HookManager;
use Modules\Sirsoft\Page\Database\Seeders\PageSeeder;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageVersion;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 기본 페이지 시더가 언어팩 seed 번역을 받는다.
 *
 * 페이지 시더는 다국어 JSON(제목·본문)을 시드하면서 언어팩 필터를 거치지 않아, 활성 언어팩이
 * seed/pages.json 을 실어도 기본 페이지는 ko/en 만으로 만들어졌다. 다른 확장 시더와 같은
 * `seed.{확장}.{entity}.translations` 경로로 머지되는지 잠근다.
 */
class PageSeederTranslationTest extends ModuleTestCase
{
    private const FILTER = 'seed.sirsoft-page.pages.translations';

    /**
     * 테스트가 단 언어팩 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(self::FILTER);

        parent::tearDown();
    }

    /**
     * 시더가 언어팩 seed 계약을 선언한다.
     *
     * @scenario seed_source=defaults_only
     *
     * @effects page_seeder_declares_lang_pack_seed_contract
     *
     * @return void 반환값 없음
     */
    public function test_page_seeder_declares_lang_pack_seed_contract(): void
    {
        $seeder = new PageSeeder;

        $this->assertInstanceOf(TranslatableSeederInterface::class, $seeder);
        $this->assertSame('sirsoft-page', $seeder->getExtensionIdentifier());
        $this->assertSame('pages', $seeder->getTranslatableEntity());
        $this->assertSame('slug', $seeder->getMatchKey());

        $slugs = array_column($seeder->getDefaults(), 'slug');
        $this->assertSame(['terms', 'privacy', 'refund', 'about', 'faq', 'contact'], $slugs);
    }

    /**
     * 언어팩 seed 번역이 있으면 기본 페이지 제목에 머지된다.
     *
     * @scenario seed_source=lang_pack_filter
     *
     * @effects page_seeder_merges_lang_pack_translations
     *
     * @return void 반환값 없음
     */
    public function test_lang_pack_translation_is_merged_into_seeded_page(): void
    {
        // 시더는 이미 있는 slug 를 건너뛰므로 terms 를 비운다(트랜잭션 안이라 테스트 뒤 되돌아간다).
        $existing = Page::where('slug', 'terms')->pluck('id');
        PageVersion::whereIn('page_id', $existing)->delete();
        Page::whereIn('id', $existing)->delete();

        HookManager::addFilter(self::FILTER, function (array $entries): array {
            foreach ($entries as &$entry) {
                if ($entry['slug'] === 'terms') {
                    $entry['title']['ja'] = '利用規約';
                }
            }

            return $entries;
        });

        $this->seed(PageSeeder::class);

        $title = Page::where('slug', 'terms')->firstOrFail()->getRawOriginal('title');
        $title = is_string($title) ? json_decode($title, true) : $title;

        $this->assertSame('이용약관', $title['ko']);
        $this->assertSame('Terms of Service', $title['en']);
        $this->assertSame('利用規約', $title['ja']);
    }
}
