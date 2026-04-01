<?php

namespace Tests\Unit\Mail;

use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Mail\DbTemplateMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * DbTemplateMail 테스트
 *
 * DB 기반 메일 템플릿 Mailable의 봉투, 본문, 헤더, 접근자를 검증합니다.
 */
class DbTemplateMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HookManager::resetAll();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }
    // ========================================================================
    // 봉투 (Envelope)
    // ========================================================================

    /**
     * 렌더링된 제목이 봉투에 설정됨
     */
    public function test_envelope_contains_rendered_subject(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: '환영합니다, John!',
            renderedBody: '<p>Welcome</p>',
            recipientEmail: 'test@example.com',
        );

        $envelope = $mail->envelope();

        $this->assertEquals('환영합니다, John!', $envelope->subject);
    }

    // ========================================================================
    // 본문 (Content)
    // ========================================================================

    /**
     * 올바른 뷰와 body 변수를 사용함
     */
    public function test_content_uses_db_template_view_with_body(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test',
            renderedBody: '<p>Hello World</p>',
            recipientEmail: 'test@example.com',
        );

        $content = $mail->content();

        $this->assertEquals('emails.db-template', $content->view);
        $this->assertArrayHasKey('body', $content->with);
        $this->assertEquals('<p>Hello World</p>', $content->with['body']);
    }

    // ========================================================================
    // 커스텀 헤더 (Headers)
    // ========================================================================

    /**
     * X-G7-Template-Type 헤더가 설정됨
     */
    public function test_headers_contain_template_type(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'test@example.com',
            templateType: 'welcome',
        );

        $headers = $mail->headers();

        $this->assertArrayHasKey('X-G7-Template-Type', $headers->text);
        $this->assertEquals('welcome', $headers->text['X-G7-Template-Type']);
    }

    /**
     * X-G7-Extension-Type, X-G7-Extension-Id 헤더가 설정됨
     */
    public function test_headers_contain_extension_type_and_id(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'test@example.com',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-ecommerce',
        );

        $headers = $mail->headers();

        $this->assertArrayHasKey('X-G7-Extension-Type', $headers->text);
        $this->assertEquals('module', $headers->text['X-G7-Extension-Type']);
        $this->assertArrayHasKey('X-G7-Extension-Id', $headers->text);
        $this->assertEquals('sirsoft-ecommerce', $headers->text['X-G7-Extension-Id']);
    }

    /**
     * X-G7-Source 헤더가 설정됨
     */
    public function test_headers_contain_source(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'test@example.com',
            source: 'notification',
        );

        $headers = $mail->headers();

        $this->assertArrayHasKey('X-G7-Source', $headers->text);
        $this->assertEquals('notification', $headers->text['X-G7-Source']);
    }

    /**
     * 모든 4개 헤더가 동시에 설정됨
     */
    public function test_headers_contain_all_g7_headers(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Order',
            renderedBody: '<p>Order</p>',
            recipientEmail: 'test@example.com',
            templateType: 'order_confirmed',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-ecommerce',
            source: 'notification',
        );

        $headers = $mail->headers();

        $this->assertCount(4, $headers->text);
        $this->assertEquals('order_confirmed', $headers->text['X-G7-Template-Type']);
        $this->assertEquals('module', $headers->text['X-G7-Extension-Type']);
        $this->assertEquals('sirsoft-ecommerce', $headers->text['X-G7-Extension-Id']);
        $this->assertEquals('notification', $headers->text['X-G7-Source']);
    }

    // ========================================================================
    // 기본값
    // ========================================================================

    /**
     * 기본값: templateType='', extensionType=Core, extensionIdentifier='core', source='notification'
     */
    public function test_default_values(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'test@example.com',
        );

        $this->assertEquals('', $mail->getTemplateType());
        $this->assertEquals(ExtensionOwnerType::Core, $mail->getExtensionType());
        $this->assertEquals('core', $mail->getExtensionIdentifier());
        $this->assertEquals('notification', $mail->getSource());
    }

    // ========================================================================
    // 접근자 (Getters)
    // ========================================================================

    /**
     * getTemplateType()이 올바른 값을 반환
     */
    public function test_get_template_type_returns_correct_value(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'test@example.com',
            templateType: 'password_reset',
        );

        $this->assertEquals('password_reset', $mail->getTemplateType());
    }

    /**
     * getExtensionType()과 getExtensionIdentifier()가 올바른 값을 반환
     */
    public function test_get_extension_type_and_identifier_return_correct_values(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'test@example.com',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
        );

        $this->assertEquals(ExtensionOwnerType::Module, $mail->getExtensionType());
        $this->assertEquals('sirsoft-board', $mail->getExtensionIdentifier());
    }

    /**
     * getSource()가 올바른 값을 반환
     */
    public function test_get_source_returns_correct_value(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'test@example.com',
            source: 'test_mail',
        );

        $this->assertEquals('test_mail', $mail->getSource());
    }

    // ========================================================================
    // 수신자 설정 (To)
    // ========================================================================

    /**
     * 생성자에서 수신자가 자동 설정됨
     */
    public function test_constructor_sets_recipient(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'recipient@example.com',
            recipientName: 'Recipient Name',
        );

        $to = collect($mail->to)->first();

        $this->assertEquals('recipient@example.com', $to['address']);
        $this->assertEquals('Recipient Name', $to['name']);
    }

    /**
     * recipientName이 null이면 이름 없이 수신자 설정됨
     */
    public function test_constructor_sets_recipient_without_name(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'noname@example.com',
        );

        $to = collect($mail->to)->first();

        $this->assertEquals('noname@example.com', $to['address']);
    }

    // ========================================================================
    // send() 훅 호출
    // ========================================================================

    /**
     * 발송 성공 시 core.mail.after_send 훅이 호출됨
     */
    public function test_send_calls_after_send_hook_on_success(): void
    {
        Mail::fake();

        $hookCalled = false;
        $hookData = null;

        HookManager::addAction('core.mail.after_send', function (array $data) use (&$hookCalled, &$hookData) {
            $hookCalled = true;
            $hookData = $data;
        });

        $mail = new DbTemplateMail(
            renderedSubject: 'Test Subject',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'test@example.com',
            templateType: 'welcome',
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            source: 'notification',
            recipientName: 'Test User',
        );

        $mail->send(app('mailer'));

        $this->assertTrue($hookCalled, 'core.mail.after_send 훅이 호출되어야 합니다');
        $this->assertEquals('test@example.com', $hookData['recipientEmail']);
        $this->assertEquals('Test User', $hookData['recipientName']);
        $this->assertEquals('Test Subject', $hookData['subject']);
        $this->assertEquals('<p>Test</p>', $hookData['body']);
        $this->assertEquals('welcome', $hookData['templateType']);
        $this->assertEquals('core', $hookData['extensionType']);
        $this->assertEquals('core', $hookData['extensionIdentifier']);
        $this->assertEquals('notification', $hookData['source']);
        $this->assertArrayHasKey('senderEmail', $hookData);
        $this->assertArrayHasKey('senderName', $hookData);
    }

    /**
     * 발송 실패 시 core.mail.send_failed 훅이 호출됨
     */
    public function test_send_calls_send_failed_hook_on_failure(): void
    {
        $hookCalled = false;
        $hookData = null;

        HookManager::addAction('core.mail.send_failed', function (array $data) use (&$hookCalled, &$hookData) {
            $hookCalled = true;
            $hookData = $data;
        });

        // parent::send() 내부에서 예외를 발생시키는 mock 메일러 사용
        $mockMailer = \Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
        $mockMailer->shouldReceive('send')->andThrow(new \RuntimeException('SMTP connection failed'));

        $mail = new DbTemplateMail(
            renderedSubject: 'Fail Test',
            renderedBody: '<p>Fail</p>',
            recipientEmail: 'fail@example.com',
            templateType: 'welcome',
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            source: 'notification',
            recipientName: 'Fail User',
        );

        try {
            $mail->send($mockMailer);
        } catch (\Throwable) {
            // 예외는 예상됨
        }

        $this->assertTrue($hookCalled, 'core.mail.send_failed 훅이 호출되어야 합니다');
        $this->assertEquals('fail@example.com', $hookData['recipientEmail']);
        $this->assertEquals('welcome', $hookData['templateType']);
        $this->assertEquals('core', $hookData['extensionType']);
        $this->assertEquals('core', $hookData['extensionIdentifier']);
        $this->assertNotEmpty($hookData['errorMessage']);
    }

    /**
     * 발송 실패 시 예외를 다시 던짐
     */
    public function test_send_rethrows_exception_after_hook(): void
    {
        // parent::send() 내부에서 예외를 발생시키는 mock 메일러 사용
        $mockMailer = \Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
        $mockMailer->shouldReceive('send')->andThrow(new \RuntimeException('SMTP connection failed'));

        $mail = new DbTemplateMail(
            renderedSubject: 'Fail Test',
            renderedBody: '<p>Fail</p>',
            recipientEmail: 'fail@example.com',
        );

        $this->expectException(\RuntimeException::class);
        $mail->send($mockMailer);
    }

    // ========================================================================
    // skipped() 팩토리 및 발송 스킵
    // ========================================================================

    /**
     * skipped()로 생성된 인스턴스는 isSkipped()가 true
     */
    public function test_skipped_factory_creates_skipped_instance(): void
    {
        $mail = DbTemplateMail::skipped(
            recipientEmail: 'test@example.com',
            templateType: 'welcome',
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            recipientName: 'Test User',
        );

        $this->assertInstanceOf(DbTemplateMail::class, $mail);
        $this->assertTrue($mail->isSkipped());
        $this->assertEquals('welcome', $mail->getTemplateType());
        $this->assertEquals(ExtensionOwnerType::Core, $mail->getExtensionType());
        $this->assertEquals('core', $mail->getExtensionIdentifier());
    }

    /**
     * 일반 인스턴스는 isSkipped()가 false
     */
    public function test_normal_instance_is_not_skipped(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'test@example.com',
        );

        $this->assertFalse($mail->isSkipped());
    }

    /**
     * skipped 인스턴스는 send() 시 실제 발송 없이 null 반환
     */
    public function test_skipped_instance_send_returns_null_without_sending(): void
    {
        Mail::fake();

        $mail = DbTemplateMail::skipped(
            recipientEmail: 'test@example.com',
            templateType: 'welcome',
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
        );

        $result = $mail->send(app('mailer'));

        $this->assertNull($result);
        Mail::assertNothingSent();
    }

    /**
     * skipped 인스턴스는 send() 시 core.mail.send_skipped 훅 호출
     */
    public function test_skipped_instance_send_fires_send_skipped_hook(): void
    {
        $hookCalled = false;
        $hookData = null;

        HookManager::addAction('core.mail.send_skipped', function (array $data) use (&$hookCalled, &$hookData) {
            $hookCalled = true;
            $hookData = $data;
        });

        $mail = DbTemplateMail::skipped(
            recipientEmail: 'skip@example.com',
            templateType: 'welcome',
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            recipientName: 'Skip User',
        );

        $mail->send(app('mailer'));

        $this->assertTrue($hookCalled, 'core.mail.send_skipped 훅이 호출되어야 합니다');
        $this->assertEquals('skip@example.com', $hookData['recipientEmail']);
        $this->assertEquals('Skip User', $hookData['recipientName']);
        $this->assertEquals('welcome', $hookData['templateType']);
        $this->assertEquals('core', $hookData['extensionType']);
        $this->assertEquals('core', $hookData['extensionIdentifier']);
    }
}
