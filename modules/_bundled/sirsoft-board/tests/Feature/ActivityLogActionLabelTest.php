<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Extension\ExtensionManager;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 모듈의 ActivityLog action 라벨 영역 분리 회귀 차단 (이슈 #317).
 *
 * 5단계 fallback 매커니즘 자체는 코어 ModuleActionLabelIsolationTest 가 검증.
 * 본 테스트는 게시판 모듈이 정확히 식별되고 모듈 lang 에 필수 라벨이 정의되어 있는지 확인.
 */
class ActivityLogActionLabelTest extends ModuleTestCase
{
    public function test_listener_fqcn_resolves_to_board_identifier(): void
    {
        $listenerClass = \Modules\Sirsoft\Board\Listeners\BoardActivityLogListener::class;
        $this->assertTrue(class_exists($listenerClass));
        $this->assertSame(
            'sirsoft-board',
            ExtensionManager::resolveExtensionByFqcn($listenerClass)
        );
    }

    public function test_module_lang_action_array_includes_board_specific_last_segments_ko(): void
    {
        $langFile = __DIR__.'/../../src/lang/ko/activity_log.php';
        $this->assertFileExists($langFile);

        $lang = require $langFile;
        $this->assertArrayHasKey('action', $lang, '모듈 lang 에 action 배열이 신설되어야 함 (이슈 #317)');

        $required = ['blind', 'blind_content', 'restore_content', 'delete_content', 'add_to_menu', 'bulk_apply'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $lang['action'], "ko: action.{$key} 누락");
            $this->assertNotEmpty($lang['action'][$key]);
        }
    }

    public function test_module_lang_action_array_includes_board_specific_last_segments_en(): void
    {
        $langFile = __DIR__.'/../../src/lang/en/activity_log.php';
        $this->assertFileExists($langFile);

        $lang = require $langFile;
        $this->assertArrayHasKey('action', $lang);

        $required = ['blind', 'blind_content', 'restore_content', 'delete_content', 'add_to_menu', 'bulk_apply'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $lang['action'], "en: action.{$key} missing");
            $this->assertNotEmpty($lang['action'][$key]);
        }
    }
}
