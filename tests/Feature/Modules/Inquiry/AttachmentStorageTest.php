<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Services\InquiryAttachmentStorage;
use Tests\TestCase;

class AttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = [
        'modules/_bundled/sirsoft-inquiry',
    ];

    public function test_store_creates_attachment_record_and_persists_file(): void
    {
        Storage::fake('local');
        $svc = app(InquiryAttachmentStorage::class);
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) \Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y', 'status' => 'received',
        ]);
        $file = UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf');

        $att = $svc->store($inquiry, $user->id, $file, context: 'message');

        $this->assertSame('application/pdf', $att->mime);
        $this->assertSame('plan.pdf', $att->original_name);
        Storage::disk('local')->assertExists($att->path);
    }

    public function test_store_rejects_disallowed_mime(): void
    {
        Storage::fake('local');
        $svc = app(InquiryAttachmentStorage::class);
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) \Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y', 'status' => 'received',
        ]);
        $file = UploadedFile::fake()->create('bad.exe', 10, 'application/x-msdownload');

        $this->expectException(\InvalidArgumentException::class);
        $svc->store($inquiry, $user->id, $file, context: 'message');
    }

    public function test_store_rejects_oversize_file(): void
    {
        config(['inquiry.attachment.max_size_message' => 1024]); // 1KB
        Storage::fake('local');
        $svc = app(InquiryAttachmentStorage::class);
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) \Str::uuid(),
            'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y', 'status' => 'received',
        ]);
        $file = UploadedFile::fake()->create('big.pdf', 10, 'application/pdf'); // 10KB

        $this->expectException(\InvalidArgumentException::class);
        $svc->store($inquiry, $user->id, $file, context: 'message');
    }
}
