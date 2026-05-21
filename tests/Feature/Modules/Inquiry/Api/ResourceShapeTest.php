<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryAttachmentResource;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryMessageResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class ResourceShapeTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    private function makeInquiry(): Inquiry
    {
        $user = User::factory()->create();
        return Inquiry::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y',
            'status' => 'received',
        ]);
    }

    public function test_message_resource_shape(): void
    {
        $inquiry = $this->makeInquiry();
        $msg = $inquiry->messages()->create([
            'sender_user_id' => $inquiry->user_id,
            'sender_role' => 'client',
            'body' => '안녕하세요',
        ]);

        $array = (new InquiryMessageResource($msg))->resolve();

        $this->assertSame($msg->id, $array['id']);
        $this->assertSame('client', $array['sender_role']);
        $this->assertSame('안녕하세요', $array['body']);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertNull($array['meta']);
    }

    public function test_attachment_resource_shape(): void
    {
        $inquiry = $this->makeInquiry();
        $att = $inquiry->attachments()->create([
            'uploader_user_id' => $inquiry->user_id,
            'disk' => 'local',
            'path' => 'inquiries/x/plan.pdf',
            'original_name' => 'plan.pdf',
            'mime' => 'application/pdf',
            'size' => 1234,
        ]);

        $array = (new InquiryAttachmentResource($att))->resolve();

        $this->assertSame($att->id, $array['id']);
        $this->assertSame('plan.pdf', $array['original_name']);
        $this->assertSame('application/pdf', $array['mime']);
        $this->assertSame(1234, $array['size']);
        $this->assertStringContainsString("/api/modules/sirsoft-inquiry/attachments/{$att->id}", $array['download_url']);
    }
}
