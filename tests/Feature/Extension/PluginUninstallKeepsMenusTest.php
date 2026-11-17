<?php

namespace Tests\Feature\Extension;

use App\Extension\AbstractPlugin;
use App\Extension\PluginManager;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 플러그인 제거가 「데이터도 함께 삭제」와 무관하게 메뉴를 지우던 결함 (#888).
 *
 * 메뉴·채널 템플릿 같은 데이터 정리는 코어가 `deleteData` 일 때만 한다 — 메뉴는 `PluginManager::uninstallPlugin()` 의
 * `deleteData` 갈래가 `cleanupStaleMenus()`(하위 트리·운영자 자식 보존·기록)로, 플러그인이 더한 데이터는 `uninstallData()` 로.
 * 번들 플러그인의 `uninstall()`(데이터 보존 제거에서도 불린다)은 메뉴를 지우지 않는다.
 *
 * 실제 제거는 오토로드·훅·라우트 캐시를 다시 굽기 때문에(테스트가 실제 bootstrap/cache 에 쓰면 안 된다) 소스 계약으로 잠근다.
 */
class PluginUninstallKeepsMenusTest extends TestCase
{
    /**
     * 메뉴 정리를 uninstall() 에서 하던 번들 플러그인 5종.
     *
     * @return array<string, array{0: string}> [플러그인]
     */
    public static function plugins(): array
    {
        return array_combine(
            ['sirsoft-ckeditor5', 'sirsoft-gdpr', 'sirsoft-sso', 'sirsoft-totp', 'sirsoft-message_bizppurio'],
            [['sirsoft-ckeditor5'], ['sirsoft-gdpr'], ['sirsoft-sso'], ['sirsoft-totp'], ['sirsoft-message_bizppurio']]
        );
    }

    /**
     * 메서드 본문 소스를 읽습니다.
     *
     * @param  string  $file  파일
     * @param  string  $method  메서드 이름
     * @return string 본문
     */
    private function methodBody(string $file, string $method): string
    {
        $source = (string) file_get_contents($file);
        $start = strpos($source, "public function {$method}(");
        $this->assertNotFalse($start, "{$method}() 가 있어야 한다");
        $end = strpos($source, "\n    }\n", $start);

        return substr($source, $start, $end - $start);
    }

    /**
     * 번들 플러그인의 uninstall() 은 메뉴를 지우지 않는다(데이터 보존 제거에서도 불린다).
     *
     * @scenario case=bundled_plugin_uninstall__keep_data
     *
     * @effects plugin_uninstall_keeps_menus_without_delete_data
     *
     * @param  string  $plugin  플러그인 식별자
     * @return void 반환값 없음
     */
    #[DataProvider('plugins')]
    public function test_bundled_plugin_uninstall_does_not_delete_menus(string $plugin): void
    {
        $body = $this->methodBody(base_path("plugins/_bundled/{$plugin}/plugin.php"), 'uninstall');

        $this->assertStringNotContainsString('cleanupStaleMenus', $body);
        $this->assertStringNotContainsString('cleanupChannelContributions', $body, '데이터 정리는 uninstallData() 로');
    }

    /**
     * 코어가 데이터 삭제 갈래에서만 메뉴를 지우고 플러그인 데이터 정리를 부른다.
     *
     * @scenario case=core_uninstall__delete_data
     *
     * @effects plugin_uninstall_deletes_menus_only_with_delete_data
     *
     * @return void 반환값 없음
     */
    public function test_core_deletes_menus_only_in_delete_data_branch(): void
    {
        $body = $this->methodBody((new ReflectionMethod(PluginManager::class, 'uninstallPlugin'))->getFileName(), 'uninstallPlugin');
        $branch = substr($body, (int) strpos($body, 'if ($deleteData) {', (int) strpos($body, '$plugin->uninstall()')));

        $this->assertStringContainsString('cleanupStaleMenus(ExtensionOwnerType::Plugin', $branch);
        $this->assertStringContainsString('uninstallData()', $branch);
        $this->assertTrue(method_exists(AbstractPlugin::class, 'uninstallData'));
        $this->assertStringContainsString('cleanupChannelContributions', $this->methodBody(base_path('plugins/_bundled/sirsoft-message_bizppurio/plugin.php'), 'uninstallData'));
    }
}
