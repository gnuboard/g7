<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\NicknameRequirement;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationDefinitionService;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioDispatchRepository;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioTemplateRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkChannelDriver;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkPayloadMapper;
use Plugins\Sirsoft\MessageBizppurio\Services\MessagePayloadBuilder;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsChannelDriver;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsTypeResolver;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 발송 이력에 남기는 받는 사람 이름
 *
 * 문자와 알림톡 발송 이력(`bizppurio_dispatches.to_name`)은 관리자 화면의 기록물이다. 회원 수신자는 그 시점의 관리자 영역
 * (`core:admin`) 표시 이름으로 남기고(코어 알림 발송 이력과 같은 규칙), 비회원 수신자는 넘겨받은 이름을 그대로 남긴다.
 * 설정을 바꾸지 않은 사이트는 수정 전과 같이 회원 이름이 남는다.
 */
class DispatchRecipientNameTest extends PluginTestCase
{
    /**
     * 관리자 영역을 닉네임으로 둔 사이트에서 회원에게 보낸 문자 이력의 받는 사람 이름은 닉네임이다.
     *
     * @scenario case=sms_member_nickname_admin_area
     *
     * @effects member_dispatch_to_name_is_admin_area_name
     *
     * @return void 반환값 없음
     */
    public function test_sms_member_row_records_admin_area_name(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $this->smsDriver()->send($this->member(), $this->notification());

        $this->assertSame('길동이', BizppurioDispatch::query()->sole()->to_name);
    }

    /**
     * 관리자 영역을 닉네임으로 둔 사이트에서 회원에게 보낸 알림톡 이력의 받는 사람 이름도 닉네임이다.
     *
     * @scenario case=alimtalk_member_nickname_admin_area
     *
     * @effects member_dispatch_to_name_is_admin_area_name
     *
     * @return void 반환값 없음
     */
    public function test_alimtalk_member_row_records_admin_area_name(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $this->alimtalkDriver()->send($this->member(), $this->notification(['name' => '길동이']));

        $this->assertSame('길동이', BizppurioDispatch::query()->sole()->to_name);
    }

    /**
     * 설정을 바꾸지 않은 사이트의 회원 발송 이력은 수정 전과 같은 이름이다.
     *
     * @scenario case=member_default_site
     *
     * @effects member_dispatch_to_name_unchanged_on_default_site
     *
     * @return void 반환값 없음
     */
    public function test_member_row_keeps_name_on_default_site(): void
    {
        $this->smsDriver()->send($this->member(), $this->notification());

        $this->assertSame('홍길동', BizppurioDispatch::query()->sole()->to_name);
    }

    /**
     * 비회원 수신자는 영역 설정과 무관하게 넘겨받은 이름 그대로 남는다.
     *
     * @scenario case=guest_name_kept
     *
     * @effects guest_dispatch_to_name_is_given_name
     *
     * @return void 반환값 없음
     */
    public function test_guest_row_keeps_given_name(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $guest = new GuestNotifiable('guest@example.com', '김비회원', 'ko');

        $this->smsDriver()->send($guest, $this->notification([SmsChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000']));

        $this->assertSame('김비회원', BizppurioDispatch::query()->sole()->to_name);
    }

    /**
     * 이름, 닉네임, 휴대폰 번호가 있는 회원을 만들고 발송 작업 위임을 막습니다.
     *
     * @return User 회원
     */
    private function member(): User
    {
        Bus::fake();

        return User::factory()->create(['name' => '홍길동', 'nickname' => '길동이', 'mobile' => '010-1234-5678']);
    }

    /**
     * 알림을 만듭니다.
     *
     * @param  array<string, mixed>  $data  알림 데이터
     * @return GenericNotification 알림
     */
    private function notification(array $data = []): GenericNotification
    {
        Bus::fake();

        return new GenericNotification('order_confirmed', 'sirsoft-ecommerce', $data, 'module', 'sirsoft-ecommerce');
    }

    /**
     * 문자 본문이 있는 템플릿 행으로 문자 드라이버를 조립합니다.
     *
     * @return SmsChannelDriver 드라이버
     */
    private function smsDriver(): SmsChannelDriver
    {
        $row = new BizppurioTemplate;
        $row->notification_type = 'order_confirmed';
        $row->status = BizppurioTemplateStatus::Draft;
        $row->sms_body = ['ko' => '주문이 완료되었습니다.'];
        $row->sms_only = false;
        $row->is_active = true;

        return new SmsChannelDriver(
            $this->definitions(),
            $this->templates($row),
            new AlimtalkPayloadMapper,
            new SmsTypeResolver,
            $this->builder(),
            new BizppurioDispatchRepository,
            $this->settings(),
        );
    }

    /**
     * 승인된 템플릿 행으로 알림톡 드라이버를 조립합니다.
     *
     * @return AlimtalkChannelDriver 드라이버
     */
    private function alimtalkDriver(): AlimtalkChannelDriver
    {
        $row = new BizppurioTemplate;
        $row->notification_type = 'order_confirmed';
        $row->alimtalk_enabled = true;
        $row->template_code = 'g7_abcd1234_1';
        $row->status = BizppurioTemplateStatus::Approved;
        $row->approved_content = ['templateContent' => '#{name}님 주문이 완료되었습니다.'];
        $row->fallback_sms_enabled = false;
        $row->sms_body = null;
        $row->is_active = true;

        return new AlimtalkChannelDriver(
            $this->definitions(),
            $this->templates($row),
            $this->builder(),
            new BizppurioDispatchRepository,
            new AlimtalkPayloadMapper,
            $this->settings(),
        );
    }

    /**
     * 알림 유형 라벨 조회를 정의 없음으로 돌려주는 정의 서비스를 만듭니다.
     *
     * @return NotificationDefinitionService 정의 서비스
     */
    private function definitions(): NotificationDefinitionService
    {
        $service = Mockery::mock(NotificationDefinitionService::class);
        $service->shouldReceive('resolve')->andReturn(null);

        return $service;
    }

    /**
     * 지정한 템플릿 행을 돌려주는 템플릿 저장소를 만듭니다.
     *
     * @param  BizppurioTemplate  $row  템플릿 행
     * @return BizppurioTemplateRepositoryInterface 템플릿 저장소
     */
    private function templates(BizppurioTemplate $row): BizppurioTemplateRepositoryInterface
    {
        $repository = Mockery::mock(BizppurioTemplateRepositoryInterface::class);
        $repository->shouldReceive('findByType')->andReturn($row);

        return $repository;
    }

    /**
     * 검수 모드 설정을 돌려주는 플러그인 설정 서비스를 만듭니다.
     *
     * @return PluginSettingsService 설정 서비스
     */
    private function settings(): PluginSettingsService
    {
        $settings = Mockery::mock(PluginSettingsService::class);
        $settings->shouldReceive('get')->with('sirsoft-message_bizppurio', 'is_test_mode', true)->andReturn(true);

        return $settings;
    }

    /**
     * 외부 의존 없이 최소 본문을 돌려주는 본문 조립기를 만듭니다.
     *
     * @return MessagePayloadBuilder 본문 조립기
     */
    private function builder(): MessagePayloadBuilder
    {
        return new class extends MessagePayloadBuilder
        {
            /**
             * 부모 생성자 의존성 없이 만듭니다.
             */
            public function __construct() {}

            /**
             * 문자 본문을 돌려줍니다.
             *
             * @param  string  $to  수신 번호
             * @param  string  $message  본문
             * @param  string  $refkey  발송 참조 키
             * @return array<string, mixed> 본문
             */
            public function buildSms(string $to, string $message, string $refkey): array
            {
                return ['type' => 'sms', 'to' => $to, 'refkey' => $refkey];
            }

            /**
             * 장문 본문을 돌려줍니다.
             *
             * @param  string  $to  수신 번호
             * @param  string  $message  본문
             * @param  string  $refkey  발송 참조 키
             * @param  string|null  $subject  제목
             * @return array<string, mixed> 본문
             */
            public function buildLms(string $to, string $message, string $refkey, ?string $subject = null): array
            {
                return ['type' => 'lms', 'to' => $to, 'refkey' => $refkey];
            }

            /**
             * 알림톡 본문을 돌려줍니다.
             *
             * @param  string  $to  수신 번호
             * @param  string  $templateCode  템플릿 코드
             * @param  string  $message  본문
             * @param  string  $refkey  발송 참조 키
             * @param  array<string, mixed>  $extra  추가 필드
             * @return array<string, mixed> 본문
             */
            public function buildAlimtalk(string $to, string $templateCode, string $message, string $refkey, array $extra = []): array
            {
                return ['type' => 'at', 'to' => $to, 'refkey' => $refkey, 'templatecode' => $templateCode];
            }
        };
    }

    /**
     * 영역 설정을 바꿉니다 (사이트 기본과 관리자 화면은 지정하지 않으면 이름 고정).
     *
     * @param  array<string, array<string, mixed>>  $areas  영역 키 => 설정
     * @return void 반환값 없음
     */
    private function areas(array $areas): void
    {
        config(['g7_settings.core.member.display_name_areas' => $areas + [
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'name'],
        ]]);
        app(DisplayNameAreaRegistry::class)->flush();
        app(NicknameRequirement::class)->flush();
    }
}
