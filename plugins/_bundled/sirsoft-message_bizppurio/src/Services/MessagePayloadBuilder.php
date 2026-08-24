<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use App\Services\PluginSettingsService;

/**
 * 비즈뿌리오 발송(`/v3/message`) payload 조립기.
 *
 * SMS/LMS/알림톡(at) content 를 부록 C-2(매뉴얼 5.메시지발송) 구조로 조립한다.
 * 공통 필드(account/from/to/refkey/type)와 채널별 content 를 결합하며, account 는
 * bizppurio_id, from 은 sender_number, 알림톡 senderkey 는 sender_key 를 환경설정에서
 * 읽는다. refkey(우리 부여 unique 키)와 수신번호·본문은 호출측(채널 드라이버)이 전달한다.
 *
 * 대체발송(resend/recontent)·예약(sendtime) 등 선택 필드는 1차 범위에서 채널
 * 드라이버(Phase 3·6)가 필요 시 반환 배열에 병합한다. 이 빌더는 필수 골격만 만든다.
 */
class MessagePayloadBuilder
{
    /** 플러그인 식별자 (manifest 와 일치) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /**
     * @param  PluginSettingsService  $pluginSettings  플러그인 환경설정 조회
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * SMS 발송 payload 를 조립합니다. (message: EUC-KR 최대 90byte)
     *
     * @param  string  $to  수신 전화번호
     * @param  string  $message  본문
     * @param  string  $refkey  우리 부여 키 (webhook 매칭용, UTF-8 최대 32byte)
     * @return array<string, mixed> 발송 payload
     */
    public function buildSms(string $to, string $message, string $refkey): array
    {
        return $this->withCommon('sms', $to, $refkey, [
            'sms' => [
                'message' => $message,
            ],
        ]);
    }

    /**
     * LMS 발송 payload 를 조립합니다. (message: EUC-KR 최대 2000byte, subject 최대 64byte)
     *
     * @param  string  $to  수신 전화번호
     * @param  string  $message  본문
     * @param  string  $refkey  우리 부여 키
     * @param  string|null  $subject  제목(코어 subject 재사용). 없으면 생략
     * @return array<string, mixed> 발송 payload
     */
    public function buildLms(string $to, string $message, string $refkey, ?string $subject = null): array
    {
        $lms = ['message' => $message];

        if ($subject !== null && $subject !== '') {
            $lms['subject'] = $subject;
        }

        return $this->withCommon('lms', $to, $refkey, [
            'lms' => $lms,
        ]);
    }

    /**
     * 알림톡(AT) 발송 payload 를 조립합니다. (message: 한/영 1300자)
     *
     * senderkey 는 환경설정 sender_key 를 사용한다. 버튼/바로연결 등 유형별 선택
     * 필드는 $extra 로 병합한다(템플릿에 포함된 경우 필수 — 채널 드라이버가 구성).
     *
     * @param  string  $to  수신 전화번호
     * @param  string  $templateCode  카카오 템플릿 코드
     * @param  string  $message  치환 완료된 본문 (#{변수} 형식)
     * @param  string  $refkey  우리 부여 키
     * @param  array<string, mixed>  $extra  button/quickreply/title 등 유형별 선택 필드
     * @return array<string, mixed> 발송 payload
     */
    public function buildAlimtalk(
        string $to,
        string $templateCode,
        string $message,
        string $refkey,
        array $extra = [],
    ): array {
        $at = array_merge([
            'senderkey' => $this->senderKey(),
            'templatecode' => $templateCode,
            'message' => $message,
        ], $extra);

        return $this->withCommon('at', $to, $refkey, [
            'at' => $at,
        ]);
    }

    /**
     * 공통 필드(account/type/from/to/refkey)와 채널별 content 를 결합합니다.
     *
     * @param  string  $type  메시지 타입 (sms/lms/at)
     * @param  string  $to  수신 전화번호
     * @param  string  $refkey  우리 부여 키
     * @param  array<string, mixed>  $content  채널별 content 배열
     * @return array<string, mixed> 발송 payload
     */
    private function withCommon(string $type, string $to, string $refkey, array $content): array
    {
        return [
            'account' => $this->account(),
            'type' => $type,
            'from' => $this->senderNumber(),
            'to' => $to,
            'refkey' => $refkey,
            'content' => $content,
        ];
    }

    /**
     * 발송 payload 에서 발송 이력(request_payload) 저장용으로 개인식별 정보를 제거합니다.
     *
     * 이력 테이블에 이미 저장되는 필드(to→to_number, refkey→refkey, type→channel,
     * content.{sms,lms,at}.message→content)는 완전 중복이므로 제외한다. 나머지(account/
     * from/senderkey/templatecode/button 등 요소·resend/recontent)는 이력 테이블 어디에도
     * 없고 발송 실패 원인 분석(결함①)·버튼 URL 검증(결함②)에 필요하므로 그대로 남긴다.
     *
     * @param  array<string, mixed>  $payload  buildSms/buildLms/buildAlimtalk 가 조립한 발송 payload
     * @return array<string, mixed> 개인식별 정보를 제거한 이력 저장용 payload
     */
    public function forHistory(array $payload): array
    {
        unset($payload['to'], $payload['refkey'], $payload['type']);

        foreach (['sms', 'lms', 'at'] as $channelKey) {
            if (isset($payload['content'][$channelKey]['message'])) {
                unset($payload['content'][$channelKey]['message']);
            }
        }

        if (isset($payload['recontent']['sms']['message'])) {
            unset($payload['recontent']['sms']['message']);
        }

        return $payload;
    }

    /**
     * 발송 계정(account = bizppurio_id)을 반환합니다.
     */
    private function account(): string
    {
        return (string) $this->pluginSettings->get(self::PLUGIN_IDENTIFIER, 'bizppurio_id', '');
    }

    /**
     * 발신 번호(from = sender_number)를 반환합니다.
     */
    private function senderNumber(): string
    {
        return (string) $this->pluginSettings->get(self::PLUGIN_IDENTIFIER, 'sender_number', '');
    }

    /**
     * 알림톡 발신프로필 키(senderkey = sender_key)를 반환합니다.
     */
    private function senderKey(): string
    {
        return (string) $this->pluginSettings->get(self::PLUGIN_IDENTIFIER, 'sender_key', '');
    }
}
