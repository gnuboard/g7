<?php

namespace App\Mail;

use App\Enums\ExtensionOwnerType;

/**
 * IDV(본인인증) 전용 메일 wrapper.
 *
 * DbTemplateMail 을 상속해 메일 발송 인프라(헤더/큐/로깅)를 공유하면서
 * source='identity_message' 식별자와 templateType 매트릭스 키를 캡슐화합니다.
 *
 * 알림 시스템(notification_*)의 GenericNotification 흐름과 분리된 IDV 전용 진입점.
 */
class IdentityMessageMail extends DbTemplateMail
{
    /**
     * @param  string  $renderedSubject  변수 치환 완료된 제목
     * @param  string  $renderedBody  변수 치환 완료된 HTML 본문
     * @param  string  $recipientEmail  수신자 이메일
     * @param  string  $providerId  IDV 프로바이더 ID (예: g7:core.mail)
     * @param  string  $scopeType  provider_default | purpose | policy
     * @param  string  $scopeValue  scope 식별자 (provider_default 는 빈 문자열)
     * @param  string|null  $recipientName
     */
    public function __construct(
        string $renderedSubject,
        string $renderedBody,
        string $recipientEmail,
        string $providerId,
        string $scopeType,
        string $scopeValue,
        ?string $recipientName = null,
    ) {
        parent::__construct(
            renderedSubject: $renderedSubject,
            renderedBody: $renderedBody,
            recipientEmail: $recipientEmail,
            templateType: $this->buildTemplateType($providerId, $scopeType, $scopeValue),
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            source: 'identity_message',
            recipientName: $recipientName,
        );
    }

    /**
     * 템플릿 식별자(`identity:{provider}|{scope_type}[|{scope_value}]`)를 조립합니다.
     *
     * @param  string  $providerId
     * @param  string  $scopeType
     * @param  string  $scopeValue
     * @return string
     */
    private function buildTemplateType(string $providerId, string $scopeType, string $scopeValue): string
    {
        $key = 'identity:'.$providerId.'|'.$scopeType;

        if ($scopeValue !== '') {
            $key .= '|'.$scopeValue;
        }

        return $key;
    }
}
