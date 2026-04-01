<?php

namespace Tests\Unit\Repositories;

use App\Enums\ExtensionOwnerType;
use App\Enums\MailSendStatus;
use App\Models\MailSendLog;
use App\Repositories\MailSendLogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * MailSendLogRepository 테스트
 *
 * 메일 발송 이력 리포지토리의 필터링, 페이지네이션, 통계 조회를 검증합니다.
 */
class MailSendLogRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private MailSendLogRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(MailSendLogRepository::class);
    }

    // ========================================================================
    // create
    // ========================================================================

    /**
     * 발송 이력을 생성합니다
     */
    public function test_create_returns_mail_send_log(): void
    {
        $data = [
            'recipient_email' => 'test@example.com',
            'recipient_name' => 'Test User',
            'subject' => 'Test Subject',
            'template_type' => 'welcome',
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'source' => 'notification',
            'status' => 'sent',
            'sent_at' => now(),
        ];

        $log = $this->repository->create($data);

        $this->assertInstanceOf(MailSendLog::class, $log);
        $this->assertTrue($log->exists);
        $this->assertEquals('test@example.com', $log->recipient_email);
        $this->assertEquals('notification', $log->source);
        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'test@example.com',
            'source' => 'notification',
        ]);
    }

    // ========================================================================
    // getPaginated - 기본 동작
    // ========================================================================

    /**
     * 필터 없이 전체 목록을 페이지네이션하여 반환
     */
    public function test_get_paginated_returns_all_logs_without_filters(): void
    {
        MailSendLog::factory()->count(5)->create();

        $result = $this->repository->getPaginated();

        $this->assertCount(5, $result->items());
        $this->assertEquals(5, $result->total());
    }

    /**
     * 빈 결과를 올바르게 반환
     */
    public function test_get_paginated_returns_empty_when_no_logs(): void
    {
        $result = $this->repository->getPaginated();

        $this->assertCount(0, $result->items());
        $this->assertEquals(0, $result->total());
    }

    /**
     * sent_at 기준 최신순 정렬
     */
    public function test_get_paginated_orders_by_sent_at_desc(): void
    {
        $older = MailSendLog::factory()->create(['sent_at' => now()->subDays(2)]);
        $newer = MailSendLog::factory()->create(['sent_at' => now()]);

        $result = $this->repository->getPaginated();
        $items = $result->items();

        $this->assertEquals($newer->id, $items[0]->id);
        $this->assertEquals($older->id, $items[1]->id);
    }

    /**
     * perPage 파라미터로 페이지 크기 제어
     */
    public function test_get_paginated_respects_per_page(): void
    {
        MailSendLog::factory()->count(10)->create();

        $result = $this->repository->getPaginated([], 3);

        $this->assertCount(3, $result->items());
        $this->assertEquals(10, $result->total());
    }

    // ========================================================================
    // getPaginated - 필터
    // ========================================================================

    /**
     * extension_type 필터
     */
    public function test_get_paginated_filters_by_extension_type(): void
    {
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Core, 'core')->count(3)->create();
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Module, 'sirsoft-ecommerce')->count(2)->create();

        $result = $this->repository->getPaginated(['extension_type' => 'core']);

        $this->assertCount(3, $result->items());
    }

    /**
     * template_type 필터
     */
    public function test_get_paginated_filters_by_template_type(): void
    {
        MailSendLog::factory()->withTemplateType('welcome')->count(2)->create();
        MailSendLog::factory()->withTemplateType('password_reset')->count(3)->create();

        $result = $this->repository->getPaginated(['template_type' => 'welcome']);

        $this->assertCount(2, $result->items());
    }

    /**
     * status 필터
     */
    public function test_get_paginated_filters_by_status(): void
    {
        MailSendLog::factory()->count(4)->create(['status' => MailSendStatus::Sent->value]);
        MailSendLog::factory()->failed()->count(2)->create();

        $result = $this->repository->getPaginated(['status' => MailSendStatus::Failed->value]);

        $this->assertCount(2, $result->items());
    }

    /**
     * search 필터 - 이메일 검색
     */
    public function test_get_paginated_search_matches_email(): void
    {
        MailSendLog::factory()->create(['recipient_email' => 'admin@example.com']);
        MailSendLog::factory()->create(['recipient_email' => 'user@example.com']);

        $result = $this->repository->getPaginated(['search' => 'example.com']);

        $this->assertCount(1, $result->items());
        $this->assertEquals('admin@example.com', $result->items()[0]->recipient_email);
    }

    /**
     * search 필터 - 수신자 이름 검색
     */
    public function test_get_paginated_search_matches_recipient_name(): void
    {
        MailSendLog::factory()->create(['recipient_name' => 'Kim Admin']);
        MailSendLog::factory()->create(['recipient_name' => 'Lee User']);

        $result = $this->repository->getPaginated(['search' => 'Kim']);

        $this->assertCount(1, $result->items());
    }

    /**
     * search 필터 - 제목 검색
     */
    public function test_get_paginated_search_matches_subject(): void
    {
        MailSendLog::factory()->create(['subject' => '비밀번호 재설정 안내']);
        MailSendLog::factory()->create(['subject' => '환영합니다']);

        $result = $this->repository->getPaginated(['search' => '비밀번호']);

        $this->assertCount(1, $result->items());
    }

    /**
     * date_from 필터
     */
    public function test_get_paginated_filters_by_date_from(): void
    {
        MailSendLog::factory()->create(['sent_at' => Carbon::parse('2026-01-01')]);
        MailSendLog::factory()->create(['sent_at' => Carbon::parse('2026-03-01')]);

        $result = $this->repository->getPaginated(['date_from' => '2026-02-01']);

        $this->assertCount(1, $result->items());
    }

    /**
     * date_to 필터
     */
    public function test_get_paginated_filters_by_date_to(): void
    {
        MailSendLog::factory()->create(['sent_at' => Carbon::parse('2026-01-15')]);
        MailSendLog::factory()->create(['sent_at' => Carbon::parse('2026-03-15')]);

        $result = $this->repository->getPaginated(['date_to' => '2026-02-01']);

        $this->assertCount(1, $result->items());
    }

    /**
     * 복합 필터 조합
     */
    public function test_get_paginated_combines_multiple_filters(): void
    {
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Core, 'core')->withTemplateType('welcome')->create([
            'status' => MailSendStatus::Sent->value,
        ]);
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Core, 'core')->withTemplateType('welcome')->failed()->create();
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Module, 'sirsoft-ecommerce')->withTemplateType('welcome')->create();

        $result = $this->repository->getPaginated([
            'extension_type' => 'core',
            'template_type' => 'welcome',
            'status' => MailSendStatus::Sent->value,
        ]);

        $this->assertCount(1, $result->items());
    }

    // ========================================================================
    // getStatistics
    // ========================================================================

    /**
     * 빈 테이블에서 통계 반환
     */
    public function test_get_statistics_returns_zeros_when_empty(): void
    {
        $stats = $this->repository->getStatistics();

        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(0, $stats['sent']);
        $this->assertEquals(0, $stats['failed']);
        $this->assertEquals(0, $stats['today']);
    }

    /**
     * 올바른 통계 수치 반환
     */
    public function test_get_statistics_returns_correct_counts(): void
    {
        // sent 3건
        MailSendLog::factory()->count(3)->create([
            'status' => MailSendStatus::Sent->value,
            'sent_at' => now(),
        ]);
        // failed 2건
        MailSendLog::factory()->failed()->count(2)->create([
            'sent_at' => now(),
        ]);
        // 어제 sent 1건
        MailSendLog::factory()->create([
            'status' => MailSendStatus::Sent->value,
            'sent_at' => now()->subDay(),
        ]);

        $stats = $this->repository->getStatistics();

        $this->assertEquals(6, $stats['total']);
        $this->assertEquals(4, $stats['sent']);
        $this->assertEquals(2, $stats['failed']);
        $this->assertEquals(5, $stats['today']);
    }
}
