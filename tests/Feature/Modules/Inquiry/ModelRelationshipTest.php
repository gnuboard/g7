<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Enums\InquiryStatus;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryAttachment;
use Modules\Sirsoft\Inquiry\Models\InquiryMessage;
use Modules\Sirsoft\Inquiry\Models\InquiryQuote;
use Modules\Sirsoft\Inquiry\Models\InquiryQuoteItem;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = [
        'modules/_bundled/sirsoft-inquiry',
    ];

    public function test_inquiry_belongs_to_user_and_casts_status(): void
    {
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => '홈페이지 리뉴얼 의뢰',
            'content' => '기존 사이트 개편 부탁드립니다.',
            'status' => InquiryStatus::Received->value,
        ]);

        $this->assertInstanceOf(User::class, $inquiry->user);
        $this->assertSame($user->id, $inquiry->user->id);
        $this->assertInstanceOf(InquiryStatus::class, $inquiry->status);
        $this->assertSame(InquiryStatus::Received, $inquiry->status);
    }

    public function test_quote_has_items_and_inquiry(): void
    {
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y',
            'status' => 'received',
        ]);
        $quote = $inquiry->quotes()->create([
            'version' => 1,
            'total_amount' => 1000000,
            'status' => 'issued',
        ]);
        $quote->items()->create([
            'position' => 1,
            'name' => '메인 페이지 디자인',
            'qty' => 1,
            'unit_price' => 1000000,
            'amount' => 1000000,
        ]);

        $this->assertSame(1, $quote->items()->count());
        $this->assertSame($inquiry->id, $quote->inquiry->id);
    }

    public function test_message_and_attachment(): void
    {
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y',
            'status' => 'received',
        ]);
        $msg = $inquiry->messages()->create([
            'sender_user_id' => $user->id,
            'sender_role' => 'client',
            'body' => '안녕하세요',
        ]);
        $att = $inquiry->attachments()->create([
            'message_id' => $msg->id,
            'uploader_user_id' => $user->id,
            'disk' => 'local',
            'path' => 'inquiries/test.pdf',
            'original_name' => 'test.pdf',
            'mime' => 'application/pdf',
            'size' => 1234,
        ]);

        $this->assertSame('client', $msg->sender_role->value);
        $this->assertSame($msg->id, $att->message->id);
        $this->assertSame(1, $msg->attachments()->count());
    }

    public function test_repository_find_and_create(): void
    {
        $repo = app(\Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface::class);
        $user = User::factory()->create();
        $inquiry = $repo->create([
            'uuid' => (string) \Str::uuid(),
            'user_id' => $user->id,
            'title' => '리뉴얼',
            'content' => '본문',
            'status' => 'received',
        ]);
        $found = $repo->findByUuidOrFail($inquiry->uuid);
        $this->assertTrue($found->is($inquiry));
    }
}
