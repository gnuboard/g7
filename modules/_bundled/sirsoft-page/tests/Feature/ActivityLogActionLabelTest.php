<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Extension\ExtensionManager;
use Modules\Sirsoft\Page\Listeners\PageActivityLogListener;
use Tests\PureTestCase;

/**
 * 페이지 모듈의 ActivityLog action 라벨 영역 분리 회귀 차단 (이슈 #317).
 */
class ActivityLogActionLabelTest extends PureTestCase
{
    /**
     * 페이지 활동 로그 리스너 FQCN 이 sirsoft-page 확장 식별자로 해석된다.
     *
     * @scenario origin_type=module, loggable_state=model_with_module_fqcn
     *
     * @effects extension_manager_resolves_modules_namespace_to_kebab_identifier
     *
     * @return void 반환값 없음
     */
    public function test_listener_fqcn_resolves_to_page_identifier(): void
    {
        $listenerClass = PageActivityLogListener::class;
        $this->assertTrue(class_exists($listenerClass));
        $this->assertSame(
            'sirsoft-page',
            ExtensionManager::resolveExtensionByFqcn($listenerClass)
        );
    }

    /**
     * 모듈 ko 언어 파일에 페이지 origin 의 action 라벨(create·update·delete·restore·upload)이 비어 있지 않게 정의돼 있다.
     *
     * @scenario origin_type=module, module_lang_state=last_segment_defined, locale=ko
     *
     * @effects module_lang_action_labels_defined_for_module_origin
     *
     * @return void 반환값 없음
     */
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

    /**
     * 모듈 en 언어 파일에도 같은 action 라벨 키가 비어 있지 않게 정의돼 있다.
     *
     * @scenario origin_type=module, module_lang_state=last_segment_defined, locale=en
     *
     * @effects module_lang_action_labels_defined_for_module_origin
     *
     * @return void 반환값 없음
     */
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
