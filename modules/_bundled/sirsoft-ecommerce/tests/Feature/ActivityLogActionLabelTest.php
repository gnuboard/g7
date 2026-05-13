<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Extension\ExtensionManager;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 모듈의 ActivityLog action 라벨 영역 분리 회귀 차단 (이슈 #317).
 *
 * 검증 포인트:
 *   1) 모듈 listener 의 FQCN 이 ExtensionManager 에 의해 'sirsoft-ecommerce' 로 정확히
 *      해석되어, getActionLabelAttribute 5단계 fallback 의 1·2단계로 모듈 lang 라우팅 가능
 *   2) 모듈 lang src/lang/{ko,en}/activity_log.php 의 'action' 배열에 발화 last segment
 *      라벨이 정의되어 있어 5단계 fallback 의 1·2단계가 실제 매칭됨
 *
 * 5단계 매커니즘 자체의 동작 검증은 코어 ModuleActionLabelIsolationTest 가 담당.
 */
class ActivityLogActionLabelTest extends ModuleTestCase
{
    public function test_listener_fqcn_resolves_to_ecommerce_identifier(): void
    {
        $listenerClass = \Modules\Sirsoft\Ecommerce\Listeners\OrderActivityLogListener::class;
        $this->assertTrue(class_exists($listenerClass));
        $this->assertSame(
            'sirsoft-ecommerce',
            ExtensionManager::resolveExtensionByFqcn($listenerClass)
        );
    }

    public function test_loggable_model_fqcn_resolves_to_ecommerce_identifier(): void
    {
        $this->assertSame(
            'sirsoft-ecommerce',
            ExtensionManager::resolveExtensionByFqcn(\Modules\Sirsoft\Ecommerce\Models\Order::class)
        );
    }

    public function test_module_lang_action_array_includes_module_specific_last_segments_ko(): void
    {
        $langFile = __DIR__.'/../../src/lang/ko/activity_log.php';
        $this->assertFileExists($langFile);

        $lang = require $langFile;
        $this->assertArrayHasKey('action', $lang, '모듈 lang 에 action 배열이 신설되어야 함 (이슈 #317)');

        // 이커머스 specific 키들 — 코어 lang 에서 제거되었으므로 모듈 lang 에 정의 필수
        $required = ['confirm', 'partial_cancel', 'payment_complete', 'earn', 'use', 'change_option', 'update_quantity'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $lang['action'], "ko: action.{$key} 누락");
            $this->assertNotEmpty($lang['action'][$key], "ko: action.{$key} 라벨 비어있음");
        }
    }

    public function test_module_lang_action_array_includes_module_specific_last_segments_en(): void
    {
        $langFile = __DIR__.'/../../src/lang/en/activity_log.php';
        $this->assertFileExists($langFile);

        $lang = require $langFile;
        $this->assertArrayHasKey('action', $lang, 'Module lang must define action array (#317)');

        $required = ['confirm', 'partial_cancel', 'payment_complete', 'earn', 'use', 'change_option', 'update_quantity'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $lang['action'], "en: action.{$key} missing");
            $this->assertNotEmpty($lang['action'][$key], "en: action.{$key} empty");
        }
    }
}
