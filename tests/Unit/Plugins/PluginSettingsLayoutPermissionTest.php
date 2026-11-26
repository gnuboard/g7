<?php

namespace Tests\Unit\Plugins;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 플러그인 설정 화면 진입 권한 잠금
 *
 * 설정 조회 API 는 「플러그인 보기」(`core.plugins.read`)로 열려 있고 응답 `abilities.can_update` 로 화면이 읽기 전용을 고른다.
 * 그런데 화면 레이아웃이 진입에 「플러그인 수정」(`core.plugins.update`)을 요구하면 보기 권한만 있는 운영자는
 * 「레이아웃 접근 권한이 없습니다」 오류 화면만 본다(실측). 저장은 서버(PUT `core.plugins.update`)가 막는다.
 */
class PluginSettingsLayoutPermissionTest extends TestCase
{
    /**
     * 번들 플러그인 설정 레이아웃(짝 .json) 목록.
     *
     * @return array<string, string> 플러그인 식별자 => 레이아웃 경로
     */
    private function layouts(): array
    {
        $layouts = [];

        foreach (glob(base_path('plugins/_bundled/*/resources/layouts/admin/plugin_settings.json')) ?: [] as $path) {
            $layouts[basename(dirname($path, 4))] = $path;
        }

        return $layouts;
    }

    /**
     * 진입에 「플러그인 수정」을 요구하지 않고, 수정 권한이 없을 때 쓰는 읽기 전용 계산값을 둔다.
     *
     * @scenario case=plugin_settings_permission
     *
     * @effects plugin_settings_readable_with_view_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_plugin_settings_layouts_open_for_view_permission(): void
    {
        $layouts = $this->layouts();
        $this->assertGreaterThanOrEqual(14, count($layouts), '번들 플러그인 설정 화면을 찾지 못했다');

        foreach ($layouts as $plugin => $path) {
            $layout = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($layout, $plugin);

            $this->assertNotContains('core.plugins.update', $layout['permissions'] ?? [], $plugin.' — 진입은 보기 권한으로 연다(저장은 서버가 막는다)');
        }
    }

    /**
     * 보기 권한으로 열던 화면 14개는 읽기 전용 계산값(`isPluginReadOnly`)을 응답 권한에서 정한다.
     * ckeditor5 미리보기 편집기도 읽기 전용을 받는다(편집기가 바꿔 끼워져도 글쓰기·이미지 올리기가 열리지 않게).
     *
     * @scenario case=plugin_settings_permission
     *
     * @effects plugin_settings_readable_with_view_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_view_only_screens_compute_read_only_from_abilities(): void
    {
        $layouts = $this->layouts();

        foreach (['gnuboard7-hello_plugin', 'sirsoft-ckeditor5', 'sirsoft-daum_postcode', 'sirsoft-gdpr', 'sirsoft-hero_section',
            'sirsoft-marketing', 'sirsoft-message_bizppurio', 'sirsoft-pay_kginicis', 'sirsoft-pay_nhnkcp', 'sirsoft-pay_nicepayments',
            'sirsoft-popup', 'sirsoft-tosspayments', 'sirsoft-verification_kginicis', 'sirsoft-verification_nhnkcp'] as $plugin) {
            $layout = json_decode((string) file_get_contents($layouts[$plugin]), true);

            $this->assertSame(['core.plugins.read'], $layout['permissions'], $plugin);
            $this->assertStringContainsString('abilities?.can_update !== true', (string) ($layout['computed']['isPluginReadOnly'] ?? ''), $plugin);
        }

        $preview = $this->findNode(json_decode((string) file_get_contents($layouts['sirsoft-ckeditor5']), true), 'preview_editor');
        $this->assertNotNull($preview, 'ckeditor5 미리보기 편집기 자리');
        $this->assertSame('{{_computed.isPluginReadOnly}}', $preview['props']['disabled'] ?? null);
    }

    /**
     * id 로 레이아웃 노드를 찾습니다.
     *
     * @param  mixed  $node  탐색 시작 노드
     * @param  string  $id  노드 id
     * @return array<string, mixed>|null 노드 (없으면 null)
     */
    private function findNode(mixed $node, string $id): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if (($node['id'] ?? null) === $id) {
            return $node;
        }

        foreach ($node as $child) {
            $found = $this->findNode($child, $id);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
