<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class CleanupOrphanAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(\Modules\Sirsoft\Inquiry\Providers\InquiryServiceProvider::class);
    }

    public function test_cleanup_removes_old_unattached_attachments(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        // Orphan: message_id is null AND created_at > 30 min ago
        $orphan = $inquiry->attachments()->create([
            'message_id' => null,
            'uploader_user_id' => $user->id,
            'disk' => 'local',
            'path' => 'inquiries/test/orphan.pdf',
            'original_name' => 'orphan.pdf',
            'mime' => 'application/pdf',
            'size' => 100,
        ]);
        // Backdate created_at so it falls outside the cleanup window
        $orphan->timestamps = false;
        $orphan->created_at = now()->subHour();
        $orphan->save();
        Storage::disk('local')->put($orphan->path, 'content');

        // Fresh upload: not orphan yet
        $fresh = $inquiry->attachments()->create([
            'message_id' => null,
            'uploader_user_id' => $user->id,
            'disk' => 'local',
            'path' => 'inquiries/test/fresh.pdf',
            'original_name' => 'fresh.pdf',
            'mime' => 'application/pdf',
            'size' => 100,
        ]);
        Storage::disk('local')->put($fresh->path, 'content');

        $this->artisan('inquiry:cleanup-orphan-attachments')->assertExitCode(0);

        $this->assertNull($orphan->fresh());
        Storage::disk('local')->assertMissing($orphan->path);
        $this->assertNotNull($fresh->fresh());
    }
}
