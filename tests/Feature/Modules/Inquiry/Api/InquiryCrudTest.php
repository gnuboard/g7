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

    public function test_store_creates_inquiry(): void
    {
        $me = User::factory()->create();
        Sanctum::actingAs($me);

        $res = $this->postJson('/api/modules/sirsoft-inquiry/inquiries', [
            'title' => '홈페이지 리뉴얼',
            'content' => '기존 사이트를 모던하게 개편 부탁드립니다.',
            'category' => 'web',
            'budget_range' => '300-500만원',
            'desired_due_at' => now()->addMonth()->toDateString(),
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.title', '홈페이지 리뉴얼');
        $res->assertJsonPath('data.status', 'received');
        $res->assertJsonPath('data.is_owner', true);

        $this->assertDatabaseHas('inquiries', [
            'user_id' => $me->id,
            'title' => '홈페이지 리뉴얼',
            'status' => 'received',
        ]);
    }

    public function test_update_only_in_received_state(): void
    {
        $me = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'old', 'content' => 'old', 'status' => 'received']);

        Sanctum::actingAs($me);
        $this->patchJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}", ['title' => 'new'])
            ->assertOk()
            ->assertJsonPath('data.title', 'new');

        $inquiry->update(['status' => 'quoted', 'quoted_at' => now()]);
        $this->patchJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}", ['title' => 'newer'])
            ->assertForbidden();
    }

    public function test_cancel_transitions_status(): void
    {
        $me = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        Sanctum::actingAs($me);
        $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        $this->assertNotNull($inquiry->fresh()->canceled_at);
    }
}
