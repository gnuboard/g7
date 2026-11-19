<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use App\Services\Auth\LoginEntryService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 로그인 화면 자동 이동(D14 ③) — 사이트 전체 강제 + 자동 이동 IdP 일 때만 그 IdP 경로를 답한다.
 *
 * @since 1.0.0
 */
class AutoRedirectTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 자동 이동 조건별 답을 확인한다.
     *
     * @scenario enforce_site=false|true, auto_redirect_idp=none|enabled|disabled, guard=user|admin
     *
     * @effects redirect_only_when_site_enforced
     *
     * @return void 반환값 없음
     */
    public function test_auto_redirect_conditions(): void
    {
        $idp = $this->makeIdp('corp');
        $entry = app(LoginEntryService::class);

        $this->setPolicy(['auto_redirect_idp' => 'corp']);
        $this->assertNull($entry->autoRedirect('user'), '사이트 전체 강제가 꺼져 있으면 자동 이동 없음');

        $this->setPolicy(['enforce_site' => true, 'auto_redirect_idp' => null]);
        $this->assertNull($entry->autoRedirect('user'), '자동 이동 IdP 가 없으면 자동 이동 없음');

        $this->setPolicy(['enforce_site' => true, 'auto_redirect_idp' => 'corp']);
        $this->assertSame('/plugins/sirsoft-sso/login/corp?guard=user', $entry->autoRedirect('user'));
        $this->assertSame('/plugins/sirsoft-sso/login/corp?guard=admin', $entry->autoRedirect('admin'));

        $idp->update(['enabled' => false]);
        $this->assertNull($entry->autoRedirect('user'), '꺼진 IdP 로는 보내지 않는다');
    }
}
