<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit;

use Plugins\Sirsoft\Gdpr\Http\Middleware\CookieConsentMiddleware;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GDPR 플러그인 미들웨어 선언 계약 테스트.
 *
 * CookieConsentMiddleware 를 코어 self-gate 방식(getMiddleware())으로 선언한다.
 * EDPB Guidelines 2/2023 §16 사전 차단 요건을 위해 web/api 전 응답을 대상으로 하므로
 * targets=['everything'](광역), timing=before_core(코어 전처리 전) 여야 한다.
 */
class PluginMiddlewareDeclarationTest extends PluginTestCase
{
    /**
     * @effects gdpr_cookie_consent_declared_everything_before_core
     */
    public function test_declares_cookie_consent_middleware_via_get_middleware(): void
    {
        $plugin = new Plugin;

        $declaration = collect($plugin->getMiddleware())
            ->firstWhere('class', CookieConsentMiddleware::class);

        $this->assertNotNull($declaration, 'Plugin::getMiddleware() 가 CookieConsentMiddleware 를 선언해야 합니다.');
        $this->assertEqualsCanonicalizing(['web', 'api'], $declaration['groups'], 'web/api 양 그룹 선언이어야 합니다 (전 응답 대상).');
        $this->assertSame('before_core', $declaration['timing'] ?? null, '코어 전처리 전 응답 처리를 위해 before_core 여야 합니다.');
        $this->assertContains('everything', $declaration['targets'], 'EDPB §16 광역 타게팅을 위해 targets=everything 이어야 합니다.');
    }
}
