<?php

namespace App\Mail;

use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Mail\SentMessage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * DB 기반 알림 템플릿 범용 Mailable.
 *
 * NotificationTemplateService에서 렌더링된 제목/본문을 받아 전송합니다.
 * 모든 코어/모듈 메일이 이 단일 Mailable을 사용합니다.
 */
class DbTemplateMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * 발송 스킵 여부.
     *
     * true이면 send() 시점에 실제 발송 없이 로그와 훅만 실행합니다.
     *
     * @var bool
     */
    private bool $shouldSkip = false;

    /**
     * DbTemplateMail 생성자.
     *
     * @param string $renderedSubject 변수 치환 완료된 제목
     * @param string $renderedBody 변수 치환 완료된 HTML 본문
     * @param string $recipientEmail 수신자 이메일 주소
     * @param string $templateType 템플릿 유형 (예: welcome, reset_password)
     * @param ExtensionOwnerType $extensionType 확장 타입 (core, module, plugin)
     * @param string $extensionIdentifier 확장 식별자 (예: core, sirsoft-board)
     * @param string $source 발송 출처 (예: notification, test_mail)
     * @param string|null $recipientName 수신자 이름
     */
    public function __construct(
        private string $renderedSubject,
        private string $renderedBody,
        private string $recipientEmail,
        private string $templateType = '',
        private ExtensionOwnerType $extensionType = ExtensionOwnerType::Core,
        private string $extensionIdentifier = 'core',
        private string $source = 'notification',
        private ?string $recipientName = null,
    ) {
        $this->to($this->recipientEmail, $this->recipientName);
    }

    /**
     * 발송을 스킵하는 인스턴스를 생성합니다.
     *
     * 메일 템플릿이 비활성이거나 미등록인 경우 사용합니다.
     * send() 시점에 로그 기록 + send_skipped 훅만 실행하고 실제 발송하지 않습니다.
     *
     * @param string $recipientEmail 수신자 이메일 주소
     * @param string $templateType 템플릿 유형
     * @param ExtensionOwnerType $extensionType 확장 타입
     * @param string $extensionIdentifier 확장 식별자
     * @param string|null $recipientName 수신자 이름
     * @return static 스킵 플래그가 설정된 인스턴스
     */
    public static function skipped(
        string $recipientEmail,
        string $templateType,
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        ?string $recipientName = null,
    ): static {
        $instance = new static(
            renderedSubject: '',
            renderedBody: '',
            recipientEmail: $recipientEmail,
            templateType: $templateType,
            extensionType: $extensionType,
            extensionIdentifier: $extensionIdentifier,
            recipientName: $recipientName,
        );
        $instance->shouldSkip = true;

        return $instance;
    }

    /**
     * 이메일 봉투(제목)를 정의합니다.
     *
     * @return Envelope
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->renderedSubject,
        );
    }

    /**
     * 이메일 본문을 정의합니다.
     *
     * @return Content
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.db-template',
            with: [
                'body' => $this->renderedBody,
            ],
        );
    }

    /**
     * 커스텀 헤더를 정의합니다.
     *
     * X-G7 헤더로 메일 유형과 모듈 정보를 추적할 수 있습니다.
     *
     * @return Headers
     */
    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-G7-Template-Type' => $this->templateType,
                'X-G7-Extension-Type' => $this->extensionType->value,
                'X-G7-Extension-Id' => $this->extensionIdentifier,
                'X-G7-Source' => $this->source,
            ],
        );
    }

    /**
     * 메일을 발송하고, 실패 시 이력을 기록합니다.
     *
     * @param MailerContract $mailer 메일러 인스턴스
     * @return SentMessage|null 발송된 메시지
     *
     * @throws \Throwable 발송 실패 시 예외를 다시 던짐
     */
    public function send($mailer): ?SentMessage
    {
        if ($this->shouldSkip) {
            Log::info("메일 템플릿 비활성 또는 미등록으로 발송 스킵: {$this->templateType}");

            HookManager::doAction('core.mail.send_skipped', [
                'recipientEmail' => $this->recipientEmail,
                'recipientName' => $this->recipientName,
                'templateType' => $this->templateType,
                'extensionType' => $this->extensionType->value,
                'extensionIdentifier' => $this->extensionIdentifier,
                'source' => $this->source,
            ]);

            return null;
        }

        try {
            $result = parent::send($mailer);

            $to = collect($this->to)->first();

            $from = collect($this->from)->first();

            HookManager::doAction('core.mail.after_send', [
                'recipientEmail' => $to['address'] ?? 'unknown',
                'recipientName' => $to['name'] ?? null,
                'senderEmail' => $from['address'] ?? config('mail.from.address'),
                'senderName' => $from['name'] ?? config('mail.from.name'),
                'subject' => $this->renderedSubject,
                'body' => $this->renderedBody,
                'templateType' => $this->templateType,
                'extensionType' => $this->extensionType->value,
                'extensionIdentifier' => $this->extensionIdentifier,
                'source' => $this->source,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $to = collect($this->to)->first();

            $from = collect($this->from)->first();

            HookManager::doAction('core.mail.send_failed', [
                'recipientEmail' => $to['address'] ?? 'unknown',
                'recipientName' => $to['name'] ?? null,
                'senderEmail' => $from['address'] ?? config('mail.from.address'),
                'senderName' => $from['name'] ?? config('mail.from.name'),
                'subject' => $this->renderedSubject,
                'body' => $this->renderedBody,
                'templateType' => $this->templateType,
                'extensionType' => $this->extensionType->value,
                'extensionIdentifier' => $this->extensionIdentifier,
                'source' => $this->source,
                'errorMessage' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 템플릿 유형을 반환합니다.
     *
     * @return string
     */
    public function getTemplateType(): string
    {
        return $this->templateType;
    }

    /**
     * 확장 타입을 반환합니다.
     *
     * @return ExtensionOwnerType
     */
    public function getExtensionType(): ExtensionOwnerType
    {
        return $this->extensionType;
    }

    /**
     * 확장 식별자를 반환합니다.
     *
     * @return string
     */
    public function getExtensionIdentifier(): string
    {
        return $this->extensionIdentifier;
    }

    /**
     * 발송 출처를 반환합니다.
     *
     * @return string
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * 발송 스킵 여부를 반환합니다.
     *
     * @return bool
     */
    public function isSkipped(): bool
    {
        return $this->shouldSkip;
    }
}
