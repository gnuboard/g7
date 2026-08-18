<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioNotificationBindingRepository;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkTemplateService;
use Plugins\Sirsoft\MessageBizppurio\Services\KakaoTemplateContentResolver;
use Plugins\Sirsoft\MessageBizppurio\Services\NotificationBindingService;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * NotificationBindingService — 연동 조회(all·approvedTemplates)·저장(bind·unbind·applyFromTemplateSave) 검증.
 *
 * Phase 6 재설계 A: 알림톡 탭은 코어 기본 목록·편집 모달을 그대로 쓴다. 이 서비스는 코어 편집
 * 모달 전용 칸이 소비하는 조회(현재 연동 맵·승인 템플릿 드롭다운)와 코어 [저장] 훅이 호출하는
 * bind/unbind 를 제공한다. (회원 대상 판정·template 증강은 SeedChannelTemplatesListener 로 이동.)
 */
class NotificationBindingServiceTest extends PluginTestCase
{
    /**
     * kapi 목록 반환값을 지정한 AlimtalkTemplateService mock 을 만듭니다.
     *
     * @param  array<int, array<string, mixed>>  $templates  list()['templates'] 반환값
     */
    private function fakeTemplateService(array $templates): AlimtalkTemplateService
    {
        $service = Mockery::mock(AlimtalkTemplateService::class);
        $service->shouldReceive('list')->andReturn(['templates' => $templates, 'pagination' => []]);

        return $service;
    }

    private function makeService(array $templates = [], ?KakaoTemplateContentResolver $kakaoContent = null): NotificationBindingService
    {
        return new NotificationBindingService(
            new BizppurioNotificationBindingRepository,
            $this->fakeTemplateService($templates),
            $kakaoContent ?? $this->fakeKakaoContent(),
        );
    }

    /**
     * clearMany 호출 인자를 기록하는 KakaoTemplateContentResolver mock 을 만듭니다.
     */
    private function fakeKakaoContent(): KakaoTemplateContentResolver
    {
        $resolver = Mockery::mock(KakaoTemplateContentResolver::class);
        $resolver->shouldReceive('clearMany')
            ->andReturnUsing(fn (array $codes) => count(array_unique(array_filter($codes))));

        return $resolver;
    }

    public function test_all은_알림톡_연동을_type_키_맵으로_반환한다(): void
    {
        (new BizppurioNotificationBindingRepository)->upsert('order_confirmed', 'alimtalk', [
            'template_code' => 'TW_1234',
            'template_name' => '주문완료',
            'fallback_sms_enabled' => true,
            'is_active' => true,
        ]);

        $map = $this->makeService()->all();

        $this->assertArrayHasKey('order_confirmed', $map);
        $this->assertSame('TW_1234', $map['order_confirmed']['template_code']);
        $this->assertSame('주문완료', $map['order_confirmed']['template_name']);
        $this->assertTrue($map['order_confirmed']['fallback_sms_enabled']);
    }

    public function test_all은_연동이_없으면_빈_맵을_반환한다(): void
    {
        $this->assertSame([], $this->makeService()->all());
    }

    public function test_all_withAvailability는_승인목록에_있는_연동을_사용가능으로_표시한다(): void
    {
        (new BizppurioNotificationBindingRepository)->upsert('order_confirmed', 'alimtalk', [
            'template_code' => 'TW_LIVE',
            'template_name' => '살아있음',
            'is_active' => true,
        ]);

        // 승인 목록에 TW_LIVE 가 있음 → 사용 가능(is_unavailable=false)
        $map = $this->makeService([
            ['templateCode' => 'TW_LIVE', 'templateName' => '살아있음', 'serviceStatus' => 'ACT'],
        ])->all(withAvailability: true);

        $this->assertFalse($map['order_confirmed']['is_unavailable']);
    }

    public function test_all_withAvailability는_승인목록에_없는_연동을_사용불가로_표시한다(): void
    {
        (new BizppurioNotificationBindingRepository)->upsert('order_confirmed', 'alimtalk', [
            'template_code' => 'TW_GONE',
            'template_name' => '삭제됨',
            'is_active' => true,
        ]);

        // 승인 목록에 TW_GONE 이 없음(삭제·차단·미승인) → 사용 불가(is_unavailable=true)
        $map = $this->makeService([
            ['templateCode' => 'TW_OTHER', 'templateName' => '다른템플릿', 'serviceStatus' => 'ACT'],
        ])->all(withAvailability: true);

        $this->assertTrue($map['order_confirmed']['is_unavailable']);
    }

    public function test_all_withAvailability는_카카오_조회_실패시_소실판정을_생략한다(): void
    {
        (new BizppurioNotificationBindingRepository)->upsert('order_confirmed', 'alimtalk', [
            'template_code' => 'TW_1234',
            'template_name' => '주문완료',
            'is_active' => true,
        ]);

        // 카카오 승인 목록 조회가 실패(자격증명 미설정·장애)하면 판정을 건너뛴다 —
        // 살아있는 연동이 일시 장애로 "사용 불가"로 오탐되지 않게(is_unavailable 필드 미부여).
        $throwingTemplates = Mockery::mock(AlimtalkTemplateService::class);
        $throwingTemplates->shouldReceive('list')
            ->andThrow(new \Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException('자격증명 미설정'));

        $service = new NotificationBindingService(
            new BizppurioNotificationBindingRepository,
            $throwingTemplates,
            $this->fakeKakaoContent(),
        );

        $map = $service->all(withAvailability: true);

        $this->assertArrayNotHasKey('is_unavailable', $map['order_confirmed'], '조회 실패 시 소실 판정을 생략해야 한다.');
    }

    public function test_all은_기본값에서_소실판정을_하지_않는다(): void
    {
        (new BizppurioNotificationBindingRepository)->upsert('order_confirmed', 'alimtalk', [
            'template_code' => 'TW_1234',
            'template_name' => '주문완료',
            'is_active' => true,
        ]);

        // store(저장) 경로처럼 프리필만 필요하면 카카오 대조를 생략한다(is_unavailable 필드 없음).
        $map = $this->makeService()->all();

        $this->assertArrayNotHasKey('is_unavailable', $map['order_confirmed']);
    }

    public function test_승인_상태_템플릿만_연결_후보로_노출한다(): void
    {
        $service = $this->makeService([
            ['templateCode' => 'TW_A', 'templateName' => '승인됨', 'serviceStatus' => 'ACT'],
            ['templateCode' => 'TW_B', 'templateName' => '발송전', 'serviceStatus' => 'RDY'],
            ['templateCode' => 'TW_C', 'templateName' => '검수중', 'serviceStatus' => 'REQ'],
            ['templateCode' => 'TW_D', 'templateName' => '반려', 'serviceStatus' => 'REJ'],
        ]);

        $codes = array_column($service->approvedTemplates(), 'template_code');

        $this->assertContains('TW_A', $codes, 'ACT(정상)는 노출되어야 한다.');
        $this->assertContains('TW_B', $codes, 'RDY(발송전)는 노출되어야 한다.');
        $this->assertNotContains('TW_C', $codes, 'REQ(검수중)는 제외되어야 한다.');
        $this->assertNotContains('TW_D', $codes, 'REJ(반려)는 제외되어야 한다.');
    }

    /**
     * bind() 승인 검증을 통과시키는 템플릿 목록으로 서비스를 만듭니다 (bind 계열 테스트 공용 fixture).
     *
     * @param  array<int, string>  $approvedCodes  승인(발송 가능) 처리할 template_code 목록
     */
    private function makeServiceWithApprovedCodes(array $approvedCodes): NotificationBindingService
    {
        return $this->makeService(array_map(
            fn (string $code) => ['templateCode' => $code, 'templateName' => $code, 'serviceStatus' => 'ACT'],
            $approvedCodes,
        ));
    }

    public function test_bind는_연동을_생성하고_unbind는_삭제한다(): void
    {
        $service = $this->makeServiceWithApprovedCodes(['TW_1236']);

        $service->bind('welcome', [
            'template_code' => 'TW_1236',
            'template_name' => '가입환영',
            'fallback_sms_enabled' => false,
        ]);

        $this->assertDatabaseHas('bizppurio_notification_bindings', [
            'notification_type' => 'welcome',
            'channel' => 'alimtalk',
            'template_code' => 'TW_1236',
            'is_active' => true,
        ]);

        $service->unbind('welcome');

        $this->assertDatabaseMissing('bizppurio_notification_bindings', [
            'notification_type' => 'welcome',
            'channel' => 'alimtalk',
        ]);
    }

    public function test_bind는_같은_알림에_대해_갱신한다(): void
    {
        $service = $this->makeServiceWithApprovedCodes(['TW_1', 'TW_2']);

        $service->bind('welcome', ['template_code' => 'TW_1', 'template_name' => '첫번째']);
        $service->bind('welcome', ['template_code' => 'TW_2', 'template_name' => '두번째']);

        $this->assertDatabaseCount('bizppurio_notification_bindings', 1);
        $this->assertDatabaseHas('bizppurio_notification_bindings', [
            'notification_type' => 'welcome',
            'template_code' => 'TW_2',
        ]);
    }

    public function test_bind는_미승인_템플릿이면_예외를_던지고_저장하지_않는다(): void
    {
        // 회귀: bind() 가 승인 상태를 재검증하지 않아, 드롭다운(화면) 필터를 우회해 API 를
        // 직접 호출하면 미승인 템플릿도 저장되던 결함.
        $service = $this->makeServiceWithApprovedCodes(['TW_OTHER']);

        $this->expectException(\Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException::class);

        try {
            $service->bind('welcome', ['template_code' => 'TW_UNAPPROVED', 'template_name' => '미승인']);
        } finally {
            $this->assertDatabaseMissing('bizppurio_notification_bindings', [
                'notification_type' => 'welcome',
                'template_code' => 'TW_UNAPPROVED',
            ]);
        }
    }

    public function test_bind는_카카오_조회_실패시_예외를_던지고_저장하지_않는다(): void
    {
        // 승인 여부를 판정할 수 없으면 안전측으로 저장을 거부한다(조회 실패="승인됨" 오인 방지).
        $throwingTemplates = Mockery::mock(AlimtalkTemplateService::class);
        $throwingTemplates->shouldReceive('list')
            ->andThrow(new \Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException('자격증명 미설정'));

        $service = new NotificationBindingService(
            new BizppurioNotificationBindingRepository,
            $throwingTemplates,
            $this->fakeKakaoContent(),
        );

        $this->expectException(\Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException::class);

        try {
            $service->bind('welcome', ['template_code' => 'TW_1', 'template_name' => '가입환영']);
        } finally {
            $this->assertDatabaseMissing('bizppurio_notification_bindings', [
                'notification_type' => 'welcome',
                'template_code' => 'TW_1',
            ]);
        }
    }

    public function test_apply_from_template_save는_코드가_있으면_연동을_저장한다(): void
    {
        $this->makeServiceWithApprovedCodes(['TW_9'])
            ->applyFromTemplateSave('welcome', 'TW_9', '가입환영', true);

        $this->assertDatabaseHas('bizppurio_notification_bindings', [
            'notification_type' => 'welcome',
            'channel' => 'alimtalk',
            'template_code' => 'TW_9',
            'fallback_sms_enabled' => true,
        ]);
    }

    public function test_apply_from_template_save는_코드가_비면_연동을_해제한다(): void
    {
        $service = $this->makeServiceWithApprovedCodes(['TW_1']);
        $service->bind('welcome', ['template_code' => 'TW_1', 'template_name' => '기존']);

        // 편집 모달에서 "연결 안 함"으로 저장 → 빈 코드 → 해제 (승인 검증 대상 아님)
        $service->applyFromTemplateSave('welcome', '', null, false);

        $this->assertDatabaseMissing('bizppurio_notification_bindings', [
            'notification_type' => 'welcome',
            'channel' => 'alimtalk',
        ]);
    }

    public function test_캐시초기화는_연결된_모든_template_code를_resolver에_넘긴다(): void
    {
        // 서로 다른 알림에 두 템플릿을 연결.
        $repo = new BizppurioNotificationBindingRepository;
        $repo->upsert('order_confirmed', 'alimtalk', ['template_code' => 'TW_1', 'template_name' => 'A', 'is_active' => true]);
        $repo->upsert('welcome', 'alimtalk', ['template_code' => 'TW_2', 'template_name' => 'B', 'is_active' => true]);

        // resolver 가 실제로 받은 코드 목록을 포착.
        $received = null;
        $resolver = Mockery::mock(KakaoTemplateContentResolver::class);
        $resolver->shouldReceive('clearMany')
            ->once()
            ->andReturnUsing(function (array $codes) use (&$received) {
                $received = $codes;

                return count($codes);
            });

        $cleared = $this->makeService([], $resolver)->clearTemplateContentCache();

        $this->assertSame(2, $cleared);
        $this->assertContains('TW_1', $received);
        $this->assertContains('TW_2', $received);
    }

    public function test_캐시초기화는_연동이_없으면_0을_반환한다(): void
    {
        $this->assertSame(0, $this->makeService()->clearTemplateContentCache());
    }
}
