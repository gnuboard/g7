<?php

namespace Tests\Unit\Extension;

use App\Support\ExtensionIntegrations;
use PHPUnit\Framework\TestCase;

/**
 * manifest 선택 연동 선언(`integrations`) 정규화 (#639 ⑥, 결정 P10)
 *
 * `dependencies` 는 「없으면 설치·활성화 불가」, `integrations` 는 「있으면 연동, 없어도 동작」이다. 관리자 확장
 * 상세 「함께 쓰면 연동되는 확장」과 정적 검사가 같은 정규화 결과를 쓴다.
 */
class ManifestIntegrationsTest extends TestCase
{
    /**
     * 목록 형식과 객체 형식을 모두 식별자 목록으로 정규화한다.
     *
     * @scenario shape=list_and_object
     *
     * @effects integrations_are_normalized_by_type
     *
     * @return void 반환값 없음
     */
    public function test_normalizes_list_and_object_shapes(): void
    {
        $this->assertSame(
            ['modules' => ['sirsoft-ecommerce', 'sirsoft-board'], 'plugins' => [], 'templates' => ['sirsoft-basic']],
            ExtensionIntegrations::normalize([
                'modules' => ['sirsoft-ecommerce', 'sirsoft-board', 'sirsoft-board'],
                'templates' => ['sirsoft-basic' => '기본 템플릿 화면에 탭을 끼운다'],
            ]),
        );
    }

    /**
     * 모르는 유형·형식이 틀린 식별자·잘못된 값은 버린다.
     *
     * @scenario shape=malformed
     *
     * @effects malformed_integrations_are_ignored
     *
     * @return void 반환값 없음
     */
    public function test_ignores_malformed_input(): void
    {
        $empty = ['modules' => [], 'plugins' => [], 'templates' => []];

        $this->assertSame($empty, ExtensionIntegrations::normalize(null));
        $this->assertSame($empty, ExtensionIntegrations::normalize('sirsoft-board'));
        $this->assertSame(
            ['modules' => [], 'plugins' => ['sirsoft-gdpr'], 'templates' => []],
            ExtensionIntegrations::normalize([
                'widgets' => ['sirsoft-x'],
                'modules' => 'sirsoft-board',
                'plugins' => ['sirsoft-gdpr', 'NoVendor', 42, '../evil'],
            ]),
        );
    }
}
