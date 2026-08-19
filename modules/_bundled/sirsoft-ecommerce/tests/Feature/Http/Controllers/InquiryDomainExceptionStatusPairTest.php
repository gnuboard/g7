<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Exceptions\ProductInquiryOperationException;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ProductInquiryService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 문의 도메인 액션의 예외 → 상태코드 페어 검증
 *
 * `ProductInquiryService` 가 도메인 실패를 `ProductInquiryOperationException` 으로
 * 승격한 뒤에도 사용자 컨트롤러는 `catch (\RuntimeException)` 을 유지하고 있었다.
 * 그 catch 는 승격된 도메인 예외보다 넓어서, 남는 것은 인프라 RuntimeException 뿐인데
 * 그것까지 422 + 예외 원문으로 뭉갰다 — #104 가 없애려던 바로 그 형태다.
 *
 * 반대 방향의 짝도 있었다. `destroy` 는 `deleteInquiry` 가 던지는 도메인 예외를
 * 구분하는 catch 가 없어, 운영자가 고칠 수 있는 사유(문의 게시판 미설정)가 500 으로
 * 나갔다.
 *
 * 두 축을 실제 HTTP 응답으로 고정한다.
 *
 *  - 도메인 예외(typed) → 422 + 해석된 사유 문구
 *  - 그 외 예외(인프라/코드 결함) → 500, 응답 본문에 예외 원문 미포함
 *
 * 소스 전수 판정(`GenericCatchStatusCodeContractTest`)은 `\Exception`/`\Throwable` 만
 * generic 으로 보므로 `\RuntimeException` 형태의 광역 catch 를 잡지 못한다. 이
 * 테스트가 그 사각을 응답 단에서 메운다.
 */
class InquiryDomainExceptionStatusPairTest extends ModuleTestCase
{
    private User $user;

    private User $manager;

    private ProductInquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        $this->manager = $this->createAdminUser(['sirsoft-ecommerce.inquiries.update']);

        $product = Product::factory()->create();
        $this->inquiry = ProductInquiry::factory()->create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'is_answered' => false,
        ]);

        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-inquiry-board');

        // 다른 모듈이 등록한 inquiry.* 필터가 스냅샷으로 잔존해 서비스 mock 과 충돌하는
        // cross-module contamination 차단 (UserProductInquiryControllerTest 와 동형).
        foreach ([
            'sirsoft-ecommerce.inquiry.update',
            'sirsoft-ecommerce.inquiry.delete',
            'sirsoft-ecommerce.inquiry.create',
            'sirsoft-ecommerce.inquiry.update_reply',
            'sirsoft-ecommerce.inquiry.delete_reply',
            'sirsoft-ecommerce.inquiry.update_validation_rules',
            'sirsoft-board.post.get_by_ids',
        ] as $hook) {
            HookManager::clearFilter($hook);
        }
    }

    /**
     * 도메인 실패를 던지는 서비스 mock (본인 문의 조회는 성공).
     */
    private function mockServiceThrowing(string $method, \Throwable $e): void
    {
        $inquiry = $this->inquiry;

        $this->mock(ProductInquiryService::class, function ($mock) use ($method, $e, $inquiry) {
            $mock->shouldReceive('findById')->andReturn($inquiry);
            $mock->shouldReceive($method)->andThrow($e);
            $mock->shouldReceive()->andReturnNull();
        });
    }

    /**
     * 도메인 예외 — 게시판 미설정.
     */
    private function domainException(): ProductInquiryOperationException
    {
        return new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.board_not_configured');
    }

    /**
     * 인프라 예외 — DB 장애. `\RuntimeException` 이라 종전 광역 catch 에 걸렸다.
     */
    private function infraException(): \RuntimeException
    {
        return new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
    }

    /**
     * 응답 메시지가 "해석된 도메인 사유" 인지 단언한다.
     *
     * 응답 로케일은 요청 처리 중에 결정되어 테스트 프로세스의 로케일과 다를 수 있으므로
     * 특정 언어로 못박지 않고 지원 로케일의 해석 결과 집합과 대조한다. 고정하려는 것은
     * ① 메시지 키 원문이 새지 않을 것 ② 일반 실패 문구로 뭉개지지 않을 것 두 가지다.
     *
     * @param  string|null  $message  응답 message 필드
     * @param  string  $context  실패 시 표시할 맥락 설명
     */
    private function assertResolvedReason(?string $message, string $context = '도메인 실패 사유가 해석된 다국어 문구로 나가야 합니다.'): void
    {
        $this->assertNotNull($message, $context);
        $this->assertStringNotContainsString(
            'sirsoft-ecommerce::',
            $message,
            '메시지 키 원문이 응답에 노출되면 안 됩니다.'
        );

        $key = 'sirsoft-ecommerce::messages.inquiries.board_not_configured';
        $resolved = array_map(
            fn (string $locale) => trans($key, [], $locale),
            config('app.supported_locales')
        );

        $this->assertContains($message, $resolved, $context);
    }

    // ------------------------------------------------------------------
    // 문의 수정
    // ------------------------------------------------------------------

    /**
     * @scenario endpoint=update_inquiry, error_class=domain
     *
     * @effects inquiry_update_domain_exception_returns_422
     */
    public function test_update_domain_exception_returns_422_with_resolved_reason(): void
    {
        $this->mockServiceThrowing('updateInquiry', $this->domainException());

        $response = $this->actingAs($this->user)->putJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}",
            ['content' => '수정된 문의 내용입니다.']
        );

        $response->assertStatus(422);
        $this->assertResolvedReason($response->json('message'));
    }

    /**
     * @scenario endpoint=update_inquiry, error_class=infrastructure
     *
     * @effects inquiry_update_infrastructure_exception_returns_500_not_422
     */
    public function test_update_infrastructure_exception_returns_500_not_422(): void
    {
        $this->mockServiceThrowing('updateInquiry', $this->infraException());

        $response = $this->actingAs($this->user)->putJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}",
            ['content' => '수정된 문의 내용입니다.']
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    // ------------------------------------------------------------------
    // 문의 삭제 (도메인 예외가 500 으로 나가던 역방향 짝)
    // ------------------------------------------------------------------

    /**
     * @scenario endpoint=destroy_inquiry, error_class=domain
     *
     * @effects inquiry_destroy_domain_exception_returns_422
     */
    public function test_destroy_domain_exception_returns_422_not_500(): void
    {
        $this->mockServiceThrowing('deleteInquiry', $this->domainException());

        $response = $this->actingAs($this->user)->deleteJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}"
        );

        $response->assertStatus(422);
        $this->assertResolvedReason($response->json('message'), '삭제 실패 사유가 운영자에게 도달해야 합니다 (종전에는 500 일반 문구).');
    }

    /**
     * @scenario endpoint=destroy_inquiry, error_class=infrastructure
     *
     * @effects inquiry_destroy_infrastructure_exception_returns_500_not_422
     */
    public function test_destroy_infrastructure_exception_returns_500(): void
    {
        $this->mockServiceThrowing('deleteInquiry', $this->infraException());

        $response = $this->actingAs($this->user)->deleteJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}"
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    // ------------------------------------------------------------------
    // 답변 등록
    // ------------------------------------------------------------------

    /**
     * @scenario endpoint=create_reply, error_class=domain
     *
     * @effects inquiry_reply_domain_exception_returns_422
     */
    public function test_reply_domain_exception_returns_422(): void
    {
        $this->mockServiceThrowing('createReply', $this->domainException());

        $response = $this->actingAs($this->manager)->postJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}/reply",
            ['content' => '답변 내용입니다.']
        );

        $response->assertStatus(422);
        $this->assertResolvedReason($response->json('message'));
    }

    /**
     * @scenario endpoint=create_reply, error_class=infrastructure
     *
     * @effects inquiry_reply_infrastructure_exception_returns_500_not_422
     */
    public function test_reply_infrastructure_exception_returns_500_not_422(): void
    {
        $this->mockServiceThrowing('createReply', $this->infraException());

        $response = $this->actingAs($this->manager)->postJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}/reply",
            ['content' => '답변 내용입니다.']
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    // ------------------------------------------------------------------
    // 답변 수정
    // ------------------------------------------------------------------

    /**
     * @scenario endpoint=update_reply, error_class=domain
     *
     * @effects inquiry_update_reply_domain_exception_returns_422
     */
    public function test_update_reply_domain_exception_returns_422(): void
    {
        $this->mockServiceThrowing('updateReply', $this->domainException());

        $response = $this->actingAs($this->manager)->putJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}/reply",
            ['content' => '수정된 답변 내용입니다.']
        );

        $response->assertStatus(422);
        $this->assertResolvedReason($response->json('message'));
    }

    /**
     * @scenario endpoint=update_reply, error_class=infrastructure
     *
     * @effects inquiry_update_reply_infrastructure_exception_returns_500_not_422
     */
    public function test_update_reply_infrastructure_exception_returns_500_not_422(): void
    {
        $this->mockServiceThrowing('updateReply', $this->infraException());

        $response = $this->actingAs($this->manager)->putJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}/reply",
            ['content' => '수정된 답변 내용입니다.']
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    // ------------------------------------------------------------------
    // 답변 삭제
    // ------------------------------------------------------------------

    /**
     * @scenario endpoint=destroy_reply, error_class=domain
     *
     * @effects inquiry_destroy_reply_domain_exception_returns_422
     */
    public function test_destroy_reply_domain_exception_returns_422(): void
    {
        $this->mockServiceThrowing('deleteReply', $this->domainException());

        $response = $this->actingAs($this->manager)->deleteJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}/reply"
        );

        $response->assertStatus(422);
        $this->assertResolvedReason($response->json('message'));
    }

    /**
     * @scenario endpoint=destroy_reply, error_class=infrastructure
     *
     * @effects inquiry_destroy_reply_infrastructure_exception_returns_500_not_422
     */
    public function test_destroy_reply_infrastructure_exception_returns_500_not_422(): void
    {
        $this->mockServiceThrowing('deleteReply', $this->infraException());

        $response = $this->actingAs($this->manager)->deleteJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}/reply"
        );

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }
}
