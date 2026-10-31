<?php

namespace Tests\Unit\LanguagePack;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 확장이 등록한 관리자 메뉴 라벨이 언어팩으로 번역될 수 있는지를 두 겹에서 잠근다.
 *
 * 이 결함군은 오류를 남기지 않는다 — 번역되지 않은 라벨이 그 로케일 화면에 원문 그대로
 * 남는 것이 유일한 증상이다. 겹이 둘이라 한쪽만 고치면 화면은 그대로다.
 *
 *   ① 결선 — `PluginManager` 는 `plugin.{id}.admin_menus.translations` 를 발행하는데
 *      구독이 `module.` 로 고정돼 있으면 플러그인 메뉴는 **어떤 언어팩으로도** 번역될 수 없다.
 *   ② 데이터 — 팩 생성기의 원본 목록에 그 확장의 `admin_menus` 줄이 없으면 팩에 라벨이
 *      아예 담기지 않는다. 그 축은 생성기가 배포본에 실리지 않는 내부 도구라 이 파일이
 *      재지 않는다 — 그 도구 옆의 회귀 검사가 소유한다. 여기서는 ⑤ 가 **산출물**을 재서
 *      같은 결함을 반대편에서 잡는다.
 *
 * 두 축 모두 모집단을 디스크에서 도출한다 — 확장이 하나 늘면 이 검사가 그것까지 본다.
 */
class ExtensionMenuTranslationWiringTest extends TestCase
{
    /**
     * `getAdminMenus()` 를 선언한 번들 확장을 디스크에서 도출합니다.
     *
     * @return array<string, string> 확장 식별자 => 선언 파일의 저장소 상대 경로
     */
    private function extensionsDeclaringAdminMenus(): array
    {
        $found = [];

        foreach (['modules' => 'module.php', 'plugins' => 'plugin.php'] as $dir => $entry) {
            foreach (glob(base_path("{$dir}/_bundled/*"), GLOB_ONLYDIR) ?: [] as $extDir) {
                $file = $extDir.DIRECTORY_SEPARATOR.$entry;
                if (! is_file($file)) {
                    continue;
                }
                $source = (string) file_get_contents($file);
                if (! str_contains($source, 'function getAdminMenus')) {
                    continue;
                }
                // 빈 배열만 돌려주는 선언은 번역할 메뉴가 없다 — 그 팩은 seed/menus.json 을 두지 않는다
                // (sirsoft-totp: 등록 화면은 코어「플러그인 관리 → 설정」 자동 경로, 생성기도 추출기를 두지 않는다).
                if (preg_match('/function getAdminMenus\(\)\s*:\s*array\s*\{\s*return\s*\[\s*\]\s*;\s*\}/', $source) === 1) {
                    continue;
                }
                $found[basename($extDir)] = "{$dir}/_bundled/".basename($extDir)."/{$entry}";
            }
        }

        return $found;
    }

    #[Test]
    public function test_menu_translation_filter_is_subscribed_by_scope_not_hardcoded_to_module(): void
    {
        $provider = base_path('app/Providers/LanguagePackServiceProvider.php');
        $this->assertFileExists($provider);

        $source = (string) file_get_contents($provider);

        $this->assertStringNotContainsString(
            'addFilter("module.{$target}.admin_menus.translations"',
            $source,
            'admin_menus 번역 필터 구독이 `module.` 로 고정돼 있다 — 그러면 PluginManager 가 발행하는 '
                .'`plugin.{id}.admin_menus.translations` 를 아무도 구독하지 않아 플러그인 메뉴는 '
                .'어떤 언어팩으로도 번역될 수 없다. 형제 항목(roles·permissions)처럼 스코프로 구독할 것.'
        );

        $this->assertStringContainsString(
            'addFilter("{$scopeStr}.{$target}.admin_menus.translations"',
            $source,
            'admin_menus 번역 필터가 스코프로 구독되지 않는다.'
        );
    }

    /**
     * ③ 주입기 — 구독을 스코프로 바꿔도 **찾는 곳**이 모듈로 고정돼 있으면 결과는 같다.
     *
     * 필터는 정상 발화하고 값만 그대로 돌아오므로 오류도 로그도 남지 않는다. 형제 주입기
     * (roles·permissions·manifest)는 이미 스코프를 인자로 받는데 menus 만 빠져 있었다.
     */
    #[Test]
    public function test_menu_seed_injector_is_scope_aware_like_its_siblings(): void
    {
        $path = base_path('app/Services/LanguagePack/LanguagePackSeedInjector.php');
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            // 스코프 인자의 기본값 `'module'` 은 종전 2인자 호출 호환이다(7.2.0) — 기본값이 있어도 조회는
            // 아래 대칭 검사대로 그 인자(`getActivePacks($scope)`)로 한다.
            '/function injectExtensionMenus\(array \$menus, string \$targetIdentifier, string \$scope(?:\s*=\s*\'module\')?\)/',
            $source,
            'injectExtensionMenus 가 스코프를 받지 않는다 — 형제 주입기(roles·permissions·manifest)와 어긋난다.'
        );

        $this->assertStringNotContainsString(
            'function injectExtensionMenus(array $menus, string $targetIdentifier): array',
            $source,
            'injectExtensionMenus 의 옛 시그니처가 남아 있다.'
        );

        // 형제 대칭 — 스코프를 받는 주입기는 그 인자로 팩을 조회해야 한다.
        foreach (['injectExtensionMenus', 'injectExtensionRoles', 'injectExtensionPermissions', 'injectExtensionManifest'] as $method) {
            $offset = strpos($source, "function {$method}(");
            $this->assertNotFalse($offset, "주입기가 없다: {$method}");

            $body = substr($source, $offset, 400);
            $this->assertStringContainsString(
                'getActivePacks($scope)',
                $body,
                "{$method} 가 스코프 인자로 팩을 조회하지 않는다 — 스코프가 다른 팩은 영원히 찾지 못한다."
            );
        }
    }

    /**
     * ④ 활성화 경로 — 플러그인이 activate() 에서 메뉴를 직접 심으면 그 경로에는 필터가 없다.
     *
     * 실측: 활성화 직후 언어팩이 넣어 둔 ja 라벨이 원문으로 되돌아갔다. 필터를 아는 지점은
     * createPluginMenus 한 곳이므로 활성화도 그것을 지나야 한다.
     */
    #[Test]
    public function test_plugin_activation_passes_through_the_filtered_menu_sync(): void
    {
        $path = base_path('app/Extension/PluginManager.php');
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        $offset = strpos($source, 'public function activatePlugin(');
        $this->assertNotFalse($offset, 'activatePlugin 을 찾지 못했다.');

        $end = strpos($source, "\n    public function ", $offset + 10);
        $body = $end === false ? substr($source, $offset) : substr($source, $offset, $end - $offset);

        $this->assertStringContainsString(
            '$this->createPluginMenus($plugin)',
            $body,
            '활성화 경로가 메뉴 동기화를 지나지 않는다 — 플러그인이 activate() 에서 메뉴를 직접 심으면 '
                .'그 경로에 `plugin.{id}.admin_menus.translations` 필터가 없어 언어팩 번역이 원문으로 되돌아간다.'
        );
    }

    /**
     * ⑤ 산출물 — 생성기에 줄이 있어도 팩을 재생성하지 않으면 파일이 없고, 파일이 없으면
     * 구독 자체가 등록되지 않는다(구독은 팩의 `seed/*.json` 을 순회하며 붙는다).
     *
     * 즉 ①②③ 이 모두 옳아도 이 축이 비면 화면은 그대로다.
     */
    #[Test]
    public function test_every_pack_of_a_menu_declaring_extension_ships_a_menu_seed(): void
    {
        $declaring = $this->extensionsDeclaringAdminMenus();
        $this->assertNotEmpty($declaring, '관리자 메뉴를 선언한 번들 확장을 하나도 찾지 못했다.');

        $checked = [];
        $missing = [];

        foreach (glob(base_path('lang-packs/_bundled/*'), GLOB_ONLYDIR) ?: [] as $packDir) {
            $manifest = $packDir.DIRECTORY_SEPARATOR.'language-pack.json';
            if (! is_file($manifest)) {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($manifest), true) ?: [];
            $target = (string) ($data['target_identifier'] ?? '');

            if ($target === '' || ! array_key_exists($target, $declaring)) {
                continue;
            }

            $checked[] = basename($packDir);

            if (! is_file($packDir.DIRECTORY_SEPARATOR.'seed'.DIRECTORY_SEPARATOR.'menus.json')) {
                $missing[] = basename($packDir).' — seed/menus.json 이 없다 (그 팩의 메뉴 구독이 등록되지 않는다)';
            }
        }

        $this->assertNotEmpty(
            $checked,
            '메뉴를 선언한 확장의 언어팩을 하나도 찾지 못했다 — 모집단이 비면 이 검사는 아무것도 재지 않는다.'
        );

        $this->assertSame(
            [],
            $missing,
            "메뉴를 선언한 확장의 언어팩에 메뉴 라벨이 담기지 않았다:\n".implode("\n", $missing)
        );
    }
}
