<?php

namespace Tests\Unit\Models;

use App\Enums\ExtensionOwnerType;
use App\Enums\MailSendStatus;
use App\Models\MailSendLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MailSendLog 모델 테스트
 *
 * 모델의 fillable, 캐스팅, 스코프 메서드를 검증합니다.
 */
class MailSendLogTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // fillable / casts
    // ========================================================================

    /**
     * source 컬럼이 fillable에 포함
     */
    public function test_source_is_fillable(): void
    {
        $log = MailSendLog::create([
            'recipient_email' => 'test@example.com',
            'subject' => 'Test',
            'template_type' => 'welcome',
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'source' => 'notification',
            'status' => MailSendStatus::Sent->value,
            'sent_at' => now(),
        ]);

        $this->assertEquals('notification', $log->source);
    }

    /**
     * subject가 nullable
     */
    public function test_subject_is_nullable(): void
    {
        $log = MailSendLog::create([
            'recipient_email' => 'test@example.com',
            'subject' => null,
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'status' => MailSendStatus::Sent->value,
            'sent_at' => now(),
        ]);

        $this->assertNull($log->subject);
    }

    /**
     * sent_at이 datetime으로 캐스팅됨
     */
    public function test_sent_at_is_cast_to_datetime(): void
    {
        $log = MailSendLog::factory()->create();

        $this->assertInstanceOf(\Carbon\Carbon::class, $log->sent_at);
    }

    // ========================================================================
    // scopeByTemplateType
    // ========================================================================

    /**
     * 특정 템플릿 유형으로 필터
     */
    public function test_scope_by_template_type_filters_correctly(): void
    {
        MailSendLog::factory()->withTemplateType('welcome')->count(2)->create();
        MailSendLog::factory()->withTemplateType('password_reset')->count(3)->create();

        $result = MailSendLog::byTemplateType('welcome')->get();

        $this->assertCount(2, $result);
    }

    /**
     * null 템플릿 유형으로 필터 (whereNull)
     */
    public function test_scope_by_template_type_handles_null(): void
    {
        MailSendLog::factory()->create(['template_type' => null]);
        MailSendLog::factory()->withTemplateType('welcome')->create();

        $result = MailSendLog::byTemplateType(null)->get();

        $this->assertCount(1, $result);
        $this->assertNull($result->first()->template_type);
    }

    // ========================================================================
    // scopeByExtension
    // ========================================================================

    /**
     * 확장 타입별 필터
     */
    public function test_scope_by_extension_filters_by_type(): void
    {
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Core, 'core')->count(3)->create();
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Module, 'sirsoft-board')->count(2)->create();

        $result = MailSendLog::byExtension(ExtensionOwnerType::Core)->get();

        $this->assertCount(3, $result);
    }

    /**
     * 확장 타입 + 식별자 필터
     */
    public function test_scope_by_extension_filters_by_type_and_identifier(): void
    {
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Module, 'sirsoft-board')->count(2)->create();
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Module, 'sirsoft-ecommerce')->count(3)->create();

        $result = MailSendLog::byExtension(ExtensionOwnerType::Module, 'sirsoft-board')->get();

        $this->assertCount(2, $result);
    }

    // ========================================================================
    // scopeBySource
    // ========================================================================

    /**
     * 발송 출처별 필터
     */
    public function test_scope_by_source_filters_correctly(): void
    {
        MailSendLog::factory()->create(['source' => 'notification']);
        MailSendLog::factory()->create(['source' => 'test_mail']);
        MailSendLog::factory()->create(['source' => 'notification']);

        $result = MailSendLog::bySource('notification')->get();

        $this->assertCount(2, $result);
    }

    /**
     * null 발송 출처로 필터 (whereNull)
     */
    public function test_scope_by_source_handles_null(): void
    {
        MailSendLog::factory()->create(['source' => null]);
        MailSendLog::factory()->create(['source' => 'notification']);

        $result = MailSendLog::bySource(null)->get();

        $this->assertCount(1, $result);
        $this->assertNull($result->first()->source);
    }

    // ========================================================================
    // scopeByStatus
    // ========================================================================

    /**
     * 상태별 필터
     */
    public function test_scope_by_status_filters_correctly(): void
    {
        MailSendLog::factory()->count(3)->create(['status' => MailSendStatus::Sent->value]);
        MailSendLog::factory()->failed()->count(2)->create();

        $result = MailSendLog::byStatus(MailSendStatus::Failed->value)->get();

        $this->assertCount(2, $result);
    }
}
