<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Extension\ExtensionManager;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 모듈의 ActivityLog action 라벨 영역 분리 회귀 차단 (이슈 #317).
 */
class ActivityLogActionLabelTest extends ModuleTestCase
{
    public function test_listener_fqcn_resolves_to_page_identifier(): void
    {
        $listenerClass = \Modules\Sirsoft\Page\Listeners\PageActivityLogListener::class;
        $this->assertTrue(class_exists($listenerClass));
        $this->assertSame(
            'sirsoft-page',
            ExtensionManager::resolveExtensionByFqcn($listenerClass)
        );
    }

    public function test_module_lang_action_array_defined_ko(): void
    {
        $langFile = __DIR__.'/../../src/lang/ko/activity_log.php';
        $this->assertFileExists($langFile);

        $lang = require $langFile;
        $this->assertArrayHasKey('action', $lang, '모듈 lang 에 action 배열이 신설되어야 함 (이슈 #317)');

        // 페이지 origin 모든 last segment (모두 공용 어휘이지만 영역 분리 일관성)
        $required = ['create', 'update', 'delete', 'restore', 'upload'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $lang['action'], "ko: action.{$key} 누락");
            $this->assertNotEmpty($lang['action'][$key]);
        }
    }

    public function test_module_lang_action_array_defined_en(): void
    {
        $langFile = __DIR__.'/../../src/lang/en/activity_log.php';
        $this->assertFileExists($langFile);

        $lang = require $langFile;
        $this->assertArrayHasKey('action', $lang);

        $required = ['create', 'update', 'delete', 'restore', 'upload'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $lang['action'], "en: action.{$key} missing");
            $this->assertNotEmpty($lang['action'][$key]);
        }
    }
}
