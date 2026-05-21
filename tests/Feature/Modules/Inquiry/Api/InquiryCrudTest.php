<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class InquiryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    public function test_index_returns_only_my_inquiries(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'Mine', 'content' => 'x', 'status' => 'received']);
        Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $other->id, 'title' => 'Theirs', 'content' => 'x', 'status' => 'received']);

        Sanctum::actingAs($me);
        $res = $this->getJson('/api/modules/sirsoft-inquiry/inquiries');
        $res->assertOk();
        $titles = array_column($res->json('data'), 'title');
        $this->assertContains('Mine', $titles);
        $this->assertNotContains('Theirs', $titles);
    }

    public function test_show_returns_inquiry_for_owner(): void
    {
        $me = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        Sanctum::actingAs($me);
        $res = $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}");
        $res->assertOk();
        $res->assertJsonPath('data.uuid', $inquiry->uuid);
        $res->assertJsonPath('data.is_owner', true);
    }

    public function test_show_returns_403_for_others(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $owner->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        Sanctum::actingAs($other);
        $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}")
            ->assertForbidden();
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/modules/sirsoft-inquiry/inquiries')
            ->assertUnauthorized();
    }
}
