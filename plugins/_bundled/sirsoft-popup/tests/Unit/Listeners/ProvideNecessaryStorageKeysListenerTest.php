<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Listeners;

use App\Extension\HookManager;
use Plugins\Sirsoft\Popup\Listeners\ProvideNecessaryStorageKeysListener;
use Plugins\Sirsoft\Popup\Plugin;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * GDPR 필수 저장 항목 제공 테스트 (매니페스트 listeners.yaml — GDPR 케이스)
 *
 * 「보지 않기」 쿠키 이름 하나를 GDPR 계약 훅의 cookie 스코프에 더한다(결정 D3·D6). GDPR 코드는 참조하지 않는다.
 */
class ProvideNecessaryStorageKeysListenerTest extends PluginTestCase
{
    /**
     * GDPR 계약 훅으로 등록돼, 훅 호출 결과의 cookie 스코프에 쿠키 이름이 들어간다.
     *
     * @scenario surface=gdpr_cookie_added_to_cookie_scope
     *
     * @effects necessary_cookie_provided
     *
     * @return void 반환값 없음
     */
    public function test_cookie_added_to_cookie_scope(): void
    {
        $provided = HookManager::applyFilters('sirsoft-gdpr.necessary_storage.filter_provided_keys', ['localStorage' => [], 'sessionStorage' => [], 'cookie' => []]);

        $this->assertSame([Plugin::DISMISS_COOKIE], $provided['cookie']);
        $this->assertSame('g7_popup_dismissed', Plugin::DISMISS_COOKIE);
        $this->assertSame([], $provided['localStorage']);
    }

    /**
     * 앞선 리스너가 넣은 항목은 지우지 않고, 같은 이름은 한 번만 둔다.
     *
     * @scenario surface=gdpr_existing_keys_preserved
     *
     * @effects necessary_cookie_provided
     *
     * @return void 반환값 없음
     */
    public function test_existing_keys_preserved(): void
    {
        $result = (new ProvideNecessaryStorageKeysListener)->provide([
            'sessionStorage' => ['g7:other:key'],
            'cookie' => ['other_cookie', Plugin::DISMISS_COOKIE],
        ]);

        $this->assertSame(['other_cookie', Plugin::DISMISS_COOKIE], $result['cookie']);
        $this->assertSame(['g7:other:key'], $result['sessionStorage']);
        $this->assertSame(['cookie' => [Plugin::DISMISS_COOKIE]], (new ProvideNecessaryStorageKeysListener)->provide(null));
    }

    /**
     * 쿠키 이름이 GDPR 형식 규칙(GDPR 이 받아들이는 이름)을 통과한다.
     *
     * GDPR 플러그인이 있으면 그 상수로, 없으면 같은 패턴 문자열로 대조한다(GDPR 은 형식이 틀린 항목을 버린다).
     *
     * @scenario surface=gdpr_cookie_name_passes_gdpr_pattern
     *
     * @effects necessary_cookie_provided
     *
     * @return void 반환값 없음
     */
    public function test_cookie_name_passes_gdpr_pattern(): void
    {
        $gdpr = 'Plugins\\Sirsoft\\Gdpr\\Plugin';
        $pattern = class_exists($gdpr) && defined($gdpr.'::NECESSARY_STORAGE_KEY_PATTERN')
            ? constant($gdpr.'::NECESSARY_STORAGE_KEY_PATTERN')
            : '/^[A-Za-z0-9_.:@+\-]+\*?$/';

        $this->assertSame(1, preg_match($pattern, Plugin::DISMISS_COOKIE));
        $this->assertLessThanOrEqual(128, strlen(Plugin::DISMISS_COOKIE));
    }
}
