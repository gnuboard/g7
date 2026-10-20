<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Unit\Exceptions;

use Illuminate\Support\Facades\Lang;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 가입·연결·재확인 거절 문구 — API 응답 문구에 번역 키 원문이 뜨지 않는다.
 *
 * @since 1.0.0
 */
class SocialFlowExceptionTest extends PluginTestCase
{
    /**
     * 플러그인 소스가 고정 코드로 던지는 거절은 모두 ko·en 문구가 있다. 모집단은 소스에서 뽑는다.
     *
     * @scenario code=literal, locale=ko|en
     *
     * @effects flow_literal_codes_have_messages
     *
     * @return void 반환값 없음
     */
    public function test_every_literal_flow_code_has_a_message(): void
    {
        $codes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src'));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                preg_match_all("/new SocialFlowException\\('([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $m);
                $codes = array_merge($codes, $m[1]);
            }
        }
        $codes = array_values(array_unique($codes));

        $this->assertGreaterThan(10, count($codes), '소스에서 거절 코드를 찾지 못했습니다');
        foreach (['ko', 'en'] as $locale) {
            foreach ($codes as $code) {
                $this->assertTrue(Lang::has('sirsoft-social_login::exceptions.flow.'.$code, $locale, false), "{$locale} flow.{$code}");
            }
        }
    }

    /**
     * 제공자·드라이버가 정한 코드(일반 OIDC discovery 실패 등)는 문구가 없어도 공통 문구로 답하고 코드는 그대로 남긴다.
     *
     * @scenario code=provider_defined, locale=ko|en
     *
     * @effects unknown_flow_code_uses_provider_error_message
     *
     * @return void 반환값 없음
     */
    public function test_provider_defined_code_falls_back_to_common_message(): void
    {
        foreach (['ko', 'en'] as $locale) {
            app()->setLocale($locale);
            $e = new SocialFlowException('discovery_failed', 422);

            $this->assertSame('discovery_failed', $e->errorCode);
            $this->assertSame('exceptions.flow.provider_error', $e->messageKey());
            $this->assertSame(__('sirsoft-social_login::exceptions.flow.provider_error'), $e->getMessage());
            $this->assertStringNotContainsString('exceptions.', $e->getMessage());
        }
    }
}
