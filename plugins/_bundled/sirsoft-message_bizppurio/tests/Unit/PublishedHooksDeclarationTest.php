<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit;

use Plugins\Sirsoft\MessageBizppurio\Plugin;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 발행 훅 선언(`getHooks()`) 테스트.
 *
 * 관리자 확장 관리의 플러그인 상세 창 「훅」·「필터」 칸과 확장 문서의 발행 훅 표는 `getHooks()` 선언을
 * 읽는다. 이 플러그인은 잔액 부족 훅(`balance.low`)을 발행하면서 선언하지 않아 상세 창이
 * 「제공하는 훅이 없습니다」로 보였다(2026-10-07 실측 O-M2-10). 소스에서 발행하는 훅이 모두 선언돼
 * 있고 유형·설명이 채워져 있는지 본다.
 */
class PublishedHooksDeclarationTest extends PluginTestCase
{
    /**
     * 소스의 `HookManager::doAction` / `applyFilters` 로 발행하는 이 플러그인 접두 훅을 모은다.
     *
     * @return array<string, string> 훅 이름 → 유형(action|filter)
     */
    private function publishedHooks(): array
    {
        $published = [];
        $root = dirname(__DIR__, 2).'/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                "/HookManager::(doAction|applyFilters)\\(\\s*'(sirsoft-message_bizppurio\\.[^']+)'/",
                (string) file_get_contents($file->getPathname()),
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $published[$match[2]] = $match[1] === 'doAction' ? 'action' : 'filter';
            }
        }

        return $published;
    }

    /**
     * 발행하는 훅은 모두 `getHooks()` 에 같은 유형과 ko·en 설명으로 선언돼 있다.
     *
     * @scenario surface=extension_detail_hooks
     *
     * @effects published_hooks_are_declared
     *
     * @return void 반환값 없음
     */
    public function test_every_published_hook_is_declared_with_type_and_description(): void
    {
        $published = $this->publishedHooks();
        $this->assertArrayHasKey('sirsoft-message_bizppurio.balance.low', $published, '발행 지점 탐지가 동작해야 한다');

        $declared = collect((new Plugin)->getHooks())->keyBy('name');

        foreach ($published as $name => $type) {
            $this->assertTrue($declared->has($name), "{$name} 을 발행하지만 getHooks() 에 선언하지 않았다");
            $this->assertSame($type, $declared[$name]['type'] ?? null, "{$name} 의 유형");
            $this->assertNotEmpty($declared[$name]['description']['ko'] ?? null, "{$name} 의 한국어 설명");
            $this->assertNotEmpty($declared[$name]['description']['en'] ?? null, "{$name} 의 영어 설명");
        }
    }
}
