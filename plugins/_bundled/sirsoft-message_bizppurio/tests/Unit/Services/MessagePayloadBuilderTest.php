<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Services\MessagePayloadBuilder;
use Tests\PureTestCase;

/**
 * MessagePayloadBuilder — SMS/LMS/알림톡 payload 조립(부록 C-2 구조) 검증.
 */
class MessagePayloadBuilderTest extends PureTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    /**
     * 환경설정 값을 반환하는 PluginSettingsService mock 을 만들어 빌더를 생성합니다.
     *
     * @param  array<string, string>  $settings  설정 키별로 돌려줄 값(없는 키는 기본값)
     * @return MessagePayloadBuilder 설정 목을 주입한 payload 빌더
     */
    private function makeBuilder(array $settings): MessagePayloadBuilder
    {
        $mock = Mockery::mock(PluginSettingsService::class);
        $mock->shouldReceive('get')
            ->with(self::IDENTIFIER, Mockery::type('string'), Mockery::any())
            ->andReturnUsing(fn ($id, $key, $default) => $settings[$key] ?? $default);

        return new MessagePayloadBuilder($mock);
    }

    /**
     * SMS payload 에 계정·type=sms·발신·수신·refkey 와 content.sms.message 본문이 들어간다.
     *
     * @scenario case=sms_build
     *
     * @effects sms_payload_follows_appendix_structure
     *
     * @return void 반환값 없음
     */
    public function test_sms_payload_구조(): void
    {
        $builder = $this->makeBuilder([
            'bizppurio_id' => 'acct01',
            'sender_number' => '07012345678',
        ]);

        $payload = $builder->buildSms('01011112222', '테스트 본문', 'ref123');

        $this->assertSame('acct01', $payload['account']);
        $this->assertSame('sms', $payload['type']);
        $this->assertSame('07012345678', $payload['from']);
        $this->assertSame('01011112222', $payload['to']);
        $this->assertSame('ref123', $payload['refkey']);
        $this->assertSame('테스트 본문', $payload['content']['sms']['message']);
    }

    /**
     * LMS payload 는 type=lms 이고 content.lms 에 subject 와 message 를 함께 담는다.
     *
     * @scenario case=lms_build
     *
     * @effects lms_payload_includes_subject
     *
     * @return void 반환값 없음
     */
    public function test_lms_payload는_subject를_포함한다(): void
    {
        $builder = $this->makeBuilder([
            'bizppurio_id' => 'acct01',
            'sender_number' => '07012345678',
        ]);

        $payload = $builder->buildLms('01011112222', '긴 본문', 'ref456', '제목');

        $this->assertSame('lms', $payload['type']);
        $this->assertSame('제목', $payload['content']['lms']['subject']);
        $this->assertSame('긴 본문', $payload['content']['lms']['message']);
    }

    /**
     * LMS 제목이 null 이면 content.lms 에 subject 키 자체를 넣지 않는다.
     *
     * @scenario case=lms_build
     *
     * @effects lms_null_subject_omitted
     *
     * @return void 반환값 없음
     */
    public function test_lms_subject_null이면_생략된다(): void
    {
        $builder = $this->makeBuilder([
            'bizppurio_id' => 'acct01',
            'sender_number' => '07012345678',
        ]);

        $payload = $builder->buildLms('01011112222', '본문', 'ref789', null);

        $this->assertArrayNotHasKey('subject', $payload['content']['lms']);
    }

    /**
     * 알림톡 payload 는 type=at 이고 senderkey·templatecode·message 와 버튼 등 extra 를 content.at 에 병합한다.
     *
     * @scenario case=alimtalk_build
     *
     * @effects alimtalk_payload_includes_senderkey_templatecode_and_extra
     *
     * @return void 반환값 없음
     */
    public function test_알림톡_payload는_senderkey와_templatecode를_포함한다(): void
    {
        $builder = $this->makeBuilder([
            'bizppurio_id' => 'acct01',
            'sender_number' => '07012345678',
            'sender_key' => 'senderkey40',
        ]);

        $payload = $builder->buildAlimtalk('01011112222', 'TW_1234', '알림톡 본문', 'ref999', [
            'button' => [['name' => '확인', 'type' => 'WL', 'url_mobile' => 'https://x']],
        ]);

        $this->assertSame('at', $payload['type']);
        $at = $payload['content']['at'];
        $this->assertSame('senderkey40', $at['senderkey']);
        $this->assertSame('TW_1234', $at['templatecode']);
        $this->assertSame('알림톡 본문', $at['message']);
        // extra(버튼) 병합 확인
        $this->assertSame('확인', $at['button'][0]['name']);
    }

    /**
     * 이력용 SMS payload 에서는 다른 컬럼과 겹치는 to·refkey·type·본문을 빼고 계정·발신 번호는 남긴다.
     *
     * @scenario case=history_redaction
     *
     * @effects history_drops_duplicated_columns_and_message, history_keeps_non_duplicated_fields
     *
     * @return void 반환값 없음
     */
    public function test_for_history는_sms_payload에서_to_refkey_type_message를_제외한다(): void
    {
        $builder = $this->makeBuilder([
            'bizppurio_id' => 'acct01',
            'sender_number' => '07012345678',
        ]);

        $payload = $builder->buildSms('01011112222', '테스트 본문', 'ref123');
        $history = $builder->forHistory($payload);

        $this->assertArrayNotHasKey('to', $history);
        $this->assertArrayNotHasKey('refkey', $history);
        $this->assertArrayNotHasKey('type', $history);
        $this->assertArrayNotHasKey('message', $history['content']['sms']);
        // 다른 컬럼에 없는 값은 유지돼야 한다.
        $this->assertSame('acct01', $history['account']);
        $this->assertSame('07012345678', $history['from']);
    }

    /**
     * 이력용 LMS payload 에서는 본문만 빼고 다른 컬럼에 없는 subject 는 남긴다.
     *
     * @scenario case=history_redaction
     *
     * @effects history_drops_duplicated_columns_and_message, history_keeps_non_duplicated_fields
     *
     * @return void 반환값 없음
     */
    public function test_for_history는_lms_payload에서_subject는_유지하고_message만_제외한다(): void
    {
        $builder = $this->makeBuilder([
            'bizppurio_id' => 'acct01',
            'sender_number' => '07012345678',
        ]);

        $payload = $builder->buildLms('01011112222', '긴 본문', 'ref456', '제목');
        $history = $builder->forHistory($payload);

        $this->assertArrayNotHasKey('message', $history['content']['lms']);
        $this->assertSame('제목', $history['content']['lms']['subject'], 'subject 는 다른 컬럼에 없으므로 유지돼야 한다.');
    }

    /**
     * 이력용 알림톡 payload 에서는 본문만 빼고 senderkey·templatecode·버튼 요소는 남긴다.
     *
     * @scenario case=history_redaction
     *
     * @effects history_drops_duplicated_columns_and_message, history_keeps_non_duplicated_fields
     *
     * @return void 반환값 없음
     */
    public function test_for_history는_알림톡_payload에서_message만_제외하고_버튼_등_요소는_유지한다(): void
    {
        $builder = $this->makeBuilder([
            'bizppurio_id' => 'acct01',
            'sender_number' => '07012345678',
            'sender_key' => 'senderkey40',
        ]);

        $payload = $builder->buildAlimtalk('01011112222', 'TW_1234', '알림톡 본문', 'ref999', [
            'button' => [['name' => '확인', 'type' => 'WL', 'url_mobile' => 'https://x']],
        ]);
        $history = $builder->forHistory($payload);

        $at = $history['content']['at'];
        $this->assertArrayNotHasKey('message', $at, '치환된 본문은 content 컬럼과 중복이라 제외돼야 한다.');
        $this->assertSame('senderkey40', $at['senderkey'], 'senderkey 는 다른 컬럼에 없으므로 유지돼야 한다.');
        $this->assertSame('TW_1234', $at['templatecode'], 'templatecode 는 다른 컬럼에 없으므로 유지돼야 한다.');
        $this->assertSame('확인', $at['button'][0]['name'], '버튼(요소)은 결함② URL 검증에 필요하므로 유지돼야 한다.');
    }

    /**
     * 이력용 payload 에서 대체발송 recontent 본문도 빼고 resend 구조는 그대로 남긴다.
     *
     * @scenario case=history_redaction
     *
     * @effects history_drops_fallback_recontent_message
     *
     * @return void 반환값 없음
     */
    public function test_for_history는_대체발송_recontent_message도_제외한다(): void
    {
        $builder = $this->makeBuilder([
            'bizppurio_id' => 'acct01',
            'sender_number' => '07012345678',
            'sender_key' => 'senderkey40',
        ]);

        $payload = $builder->buildAlimtalk('01011112222', 'TW_1234', '알림톡 본문', 'ref999');
        $payload['resend'] = ['first' => 'sms'];
        $payload['recontent'] = ['sms' => ['message' => '대체 SMS 본문']];

        $history = $builder->forHistory($payload);

        $this->assertArrayNotHasKey('message', $history['recontent']['sms'], '대체발송 본문도 개인식별 텍스트이므로 제외돼야 한다.');
        $this->assertSame(['first' => 'sms'], $history['resend'], 'resend 구조 자체는 다른 컬럼에 없으므로 유지돼야 한다.');
    }

    /**
     * 테스트마다 만든 Mockery 목 객체의 기대치를 검증하고 정리한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
