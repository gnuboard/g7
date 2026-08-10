<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkPayloadMapper;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * AlimtalkPayloadMapper — 카카오 상세조회 응답 → 발송 API content.at 변환 + 변수 치환 검증 (B안 5-2).
 *
 * 카카오 관리 API 필드(templateContent/buttons.linkMo/…)를 발송 API 필드
 * (message/button.url_mobile/…)로 변환하고, 각 필드의 #{var} 를 알림 data 로 치환한다.
 * 빈/부재 필드는 payload 에 넣지 않는다(방어적).
 */
class AlimtalkPayloadMapperTest extends PluginTestCase
{
    private function mapper(): AlimtalkPayloadMapper
    {
        return new AlimtalkPayloadMapper;
    }

    public function test_본문의_변수를_치환해_message로_반환한다(): void
    {
        $result = $this->mapper()->map(
            ['templateContent' => '#{name}님 주문 #{order_no} 완료'],
            ['name' => '홍길동', 'order_no' => 'A123'],
        );

        $this->assertSame('홍길동님 주문 A123 완료', $result['message']);
    }

    public function test_웹링크_버튼을_발송형식으로_변환하고_url변수를_치환한다(): void
    {
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'buttons' => [
                    [
                        'name' => '주문조회',
                        'linkType' => 'WL',
                        'linkMo' => 'https://m.shop/orders/#{order_no}',
                        'linkPc' => 'https://shop/orders/#{order_no}',
                    ],
                ],
            ],
            ['order_no' => 'A123'],
        );

        $button = $result['extra']['button'][0];
        $this->assertSame('주문조회', $button['name']);
        $this->assertSame('WL', $button['type']);
        $this->assertSame('https://m.shop/orders/A123', $button['url_mobile']);
        $this->assertSame('https://shop/orders/A123', $button['url_pc']);
        // 카카오 필드명(linkType/linkMo)은 발송 payload 에 남지 않아야 한다.
        $this->assertArrayNotHasKey('linkType', $button);
        $this->assertArrayNotHasKey('linkMo', $button);
    }

    public function test_앱링크_전화_플러그인_버튼_필드를_매핑한다(): void
    {
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'buttons' => [
                    ['name' => '앱', 'linkType' => 'AL', 'linkAnd' => 'myapp://a', 'linkIos' => 'myapp://i'],
                    ['name' => '전화', 'linkType' => 'TN', 'telNumber' => '1588-0000'],
                    ['name' => '플러그인', 'linkType' => 'P1', 'pluginId' => 'PLUG_1'],
                ],
            ],
            [],
        );

        $buttons = $result['extra']['button'];
        $this->assertSame('myapp://a', $buttons[0]['scheme_android']);
        $this->assertSame('myapp://i', $buttons[0]['scheme_ios']);
        $this->assertSame('1588-0000', $buttons[1]['tel_number']);
        $this->assertSame('PLUG_1', $buttons[2]['plugin_id']);
    }

    public function test_quick_replies를_quickreply로_변환한다(): void
    {
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'quickReplies' => [
                    ['name' => '바로가기', 'linkType' => 'WL', 'linkMo' => 'https://m.shop'],
                ],
            ],
            [],
        );

        $qr = $result['extra']['quickreply'][0];
        $this->assertSame('바로가기', $qr['name']);
        $this->assertSame('WL', $qr['type']);
        $this->assertSame('https://m.shop', $qr['url_mobile']);
    }

    public function test_title_header를_치환해_매핑한다(): void
    {
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'templateTitle' => '#{name}님',
                'templateHeader' => '주문 안내',
            ],
            ['name' => '홍길동'],
        );

        $this->assertSame('홍길동님', $result['extra']['title']);
        $this->assertSame('주문 안내', $result['extra']['header']);
    }

    public function test_item과_itemhighlight를_매핑하고_치환한다(): void
    {
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'templateItem' => [
                    'list' => [
                        ['title' => '상품', 'description' => '#{product}'],
                    ],
                    'summary' => ['title' => '합계', 'description' => '#{total}원'],
                ],
                'templateItemHighlight' => ['title' => '#{name}님', 'description' => '주문완료'],
            ],
            ['product' => '티셔츠', 'total' => '20,000', 'name' => '홍길동'],
        );

        $this->assertSame('티셔츠', $result['extra']['item']['list'][0]['description']);
        $this->assertSame('20,000원', $result['extra']['item']['summary']['description']);
        $this->assertSame('홍길동님', $result['extra']['itemhighlight']['title']);
    }

    public function test_대표링크를_link로_변환하고_치환한다(): void
    {
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'templateRepresentLink' => [
                    'linkMo' => 'https://m.shop/#{id}',
                    'linkPc' => 'https://shop/#{id}',
                ],
            ],
            ['id' => 'X1'],
        );

        $this->assertSame('https://m.shop/X1', $result['extra']['link']['url_mobile']);
        $this->assertSame('https://shop/X1', $result['extra']['link']['url_pc']);
    }

    public function test_원문에_프로토콜_접두어가_있고_변수값도_완전url이면_중복을_제거한다(): void
    {
        // 결함② — 카카오 콘솔에 `http://#{action_url}` 로 등록된 버튼. action_url 자체가
        // config('app.url') 기반 완전 URL(https://...)이라 단순 치환 시 프로토콜이 중복된다.
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'buttons' => [
                    ['name' => '로그인하기', 'linkType' => 'WL', 'linkMo' => 'http://#{action_url}'],
                ],
            ],
            ['action_url' => 'https://ehkim.gnuboard.net/login'],
        );

        $this->assertSame(
            'https://ehkim.gnuboard.net/login',
            $result['extra']['button'][0]['url_mobile'],
            '원문 접두어(http://)를 제거해 프로토콜 중복(http://https://...)을 방지해야 한다.',
        );
    }

    public function test_원문_접두어가_https이고_변수값도_https이면_중복을_제거한다(): void
    {
        // 실제 카카오 콘솔 등록값(관리자 화면 상세조회 스크린샷 확인, 2026-07-24) —
        // "회원가입 환영" 템플릿 버튼이 http:// 가 아니라 https://#{action_url} 로 등록돼 있다.
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'buttons' => [
                    ['name' => '로그인하기', 'linkType' => 'WL', 'linkMo' => 'https://#{action_url}'],
                ],
            ],
            ['action_url' => 'https://ehkim.gnuboard.net/login'],
        );

        $this->assertSame(
            'https://ehkim.gnuboard.net/login',
            $result['extra']['button'][0]['url_mobile'],
            '원문 접두어가 https:// 인 경우도 동일하게 중복(https://https://...)을 방지해야 한다.',
        );
    }

    public function test_원문에_프로토콜_접두어가_없으면_변수값을_그대로_둔다(): void
    {
        // 가이드 문서 원안(#{action_url} 만 등록)대로면 애초에 중복이 없으므로 손대지 않는다.
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'buttons' => [
                    ['name' => '로그인하기', 'linkType' => 'WL', 'linkMo' => '#{action_url}'],
                ],
            ],
            ['action_url' => 'https://ehkim.gnuboard.net/login'],
        );

        $this->assertSame('https://ehkim.gnuboard.net/login', $result['extra']['button'][0]['url_mobile']);
    }

    public function test_원문_접두어가_있어도_변수값이_상대경로면_접두어를_유지한다(): void
    {
        // 변수값 자체가 프로토콜을 포함하지 않는 경우(상대경로 등) 접두어는 진짜로 필요한
        // 부분이므로 제거하면 안 된다.
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'buttons' => [
                    ['name' => '이동', 'linkType' => 'WL', 'linkMo' => 'http://#{path}'],
                ],
            ],
            ['path' => '/foo'],
        );

        $this->assertSame('http:///foo', $result['extra']['button'][0]['url_mobile']);
    }

    public function test_대표링크도_프로토콜_중복을_제거한다(): void
    {
        // mapLinkFields(대표링크)도 mapButtons 와 동일 규칙을 공유해야 한다.
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'templateRepresentLink' => ['linkMo' => 'http://#{action_url}'],
            ],
            ['action_url' => 'https://ehkim.gnuboard.net/login'],
        );

        $this->assertSame('https://ehkim.gnuboard.net/login', $result['extra']['link']['url_mobile']);
    }

    public function test_변수가_data에_없으면_원문을_유지한다(): void
    {
        // data 에 없는 변수는 원문(#{key}) 유지 정책 — 프로토콜 중복 방어 로직이 이 정책을
        // 깨서는 안 된다(원문이 http:// 로 시작하지만 치환 자체가 안 일어나므로 그대로 둔다).
        $result = $this->mapper()->map(
            [
                'templateContent' => '본문',
                'buttons' => [
                    ['name' => '로그인하기', 'linkType' => 'WL', 'linkMo' => 'http://#{unknown_var}'],
                ],
            ],
            [],
        );

        $this->assertSame('http://#{unknown_var}', $result['extra']['button'][0]['url_mobile']);
    }

    public function test_부재_필드는_extra에_넣지_않는다(): void
    {
        // 본문만 있는 단순 템플릿 → extra 는 비어야 한다.
        $result = $this->mapper()->map(['templateContent' => '본문'], []);

        $this->assertSame('본문', $result['message']);
        $this->assertSame([], $result['extra']);
    }

    public function test_빈_버튼배열은_extra에_button을_만들지_않는다(): void
    {
        $result = $this->mapper()->map(
            ['templateContent' => '본문', 'buttons' => []],
            [],
        );

        $this->assertArrayNotHasKey('button', $result['extra']);
    }
}
