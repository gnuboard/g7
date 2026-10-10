<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit;

use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 번들 ja 언어팩의 메뉴 시드가 이 플러그인의 관리자 메뉴를 빠짐없이 덮는지 검증합니다.
 *
 * 플러그인 언어팩을 켜면 코어는 `lang-packs/_bundled/g7-plugin-sirsoft-gdpr-ja/seed/menus.json` 만 읽어 메뉴 이름에 ja 를 넣는다. 1.1.0 에서 생긴
 * 「개인정보 통지 이력」 메뉴의 번역이 다른 이름의 파일(`admin_menus.json`)에만 있어, ja 팩을 켜도 그 메뉴만
 * 일본어 사이드바에 영어로 남았다.
 */
class JaMenuSeedCoverageTest extends PluginTestCase
{
    /**
     * 선언한 관리자 메뉴 slug 마다 ja 메뉴 시드에 이름이 있다.
     *
     * @scenario locale=ja, surface=menu_seed
     *
     * @effects ja_menu_seed_covers_every_admin_menu
     *
     * @return void 반환값 없음
     */
    public function test_ja_menu_seed_covers_every_declared_admin_menu(): void
    {
        $seedPath = base_path('lang-packs/_bundled/g7-plugin-sirsoft-gdpr-ja/seed/menus.json');
        $this->assertFileExists($seedPath);
        $seed = json_decode((string) file_get_contents($seedPath), true);
        $this->assertIsArray($seed);

        $slugs = array_column((new Plugin)->getAdminMenus(), 'slug');
        $this->assertNotEmpty($slugs, '모집단이 비면 비교 없이 초록이 된다');

        foreach ($slugs as $slug) {
            $this->assertNotEmpty($seed[$slug]['name'] ?? null, "ja 메뉴 시드에 {$slug} 이름이 없다");
        }
    }
}
