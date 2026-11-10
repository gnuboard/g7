<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Extension\ExtensionManager;
use Modules\Sirsoft\Board\Listeners\BoardActivityLogListener;
use Tests\PureTestCase;

/**
 * 게시판 모듈의 ActivityLog action 라벨 영역 분리 회귀 차단 (이슈 #317).
 *
 * 5단계 fallback 매커니즘 자체는 코어 ModuleActionLabelIsolationTest 가 검증.
 * 본 테스트는 게시판 모듈이 정확히 식별되고 모듈 lang 에 필수 라벨이 정의되어 있는지 확인.
 */
class ActivityLogActionLabelTest extends PureTestCase
{
    /**
     * 게시판 활동 로그 리스너 FQCN 이 sirsoft-board 확장 식별자로 해석된다.
     *
     * @scenario origin_type=module, loggable_state=model_with_module_fqcn
     *
     * @effects extension_manager_resolves_modules_namespace_to_kebab_identifier
     *
     * @return void 반환값 없음
     */
    public function test_listener_fqcn_resolves_to_board_identifier(): void
    {
        $listenerClass = BoardActivityLogListener::class;
        $this->assertTrue(class_exists($listenerClass));
        $this->assertSame(
            'sirsoft-board',
            ExtensionManager::resolveExtensionByFqcn($listenerClass)
        );
    }

    /**
     * 모듈 ko 언어 파일에 게시판 전용 action 라벨(blind·blind_content·restore_content·delete_content·add_to_menu·bulk_apply)이 비어 있지 않게 정의돼 있다.
     *
     * @scenario origin_type=module, module_lang_state=last_segment_defined, locale=ko
     *
     * @effects module_lang_action_labels_defined_for_module_origin
     *
     * @return void 반환값 없음
     */
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

    /**
     * 모듈 en 언어 파일에도 같은 게시판 전용 action 라벨 키가 비어 있지 않게 정의돼 있다.
     *
     * @scenario origin_type=module, module_lang_state=last_segment_defined, locale=en
     *
     * @effects module_lang_action_labels_defined_for_module_origin
     *
     * @return void 반환값 없음
     */
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
