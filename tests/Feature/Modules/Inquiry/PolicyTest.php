<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = [
        'modules/_bundled/sirsoft-inquiry',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // 프로젝트 커스텀 권한 시스템용 — inquiry.manage 권한 생성
        Permission::firstOrCreate(
            ['identifier' => 'inquiry.manage'],
            ['name' => ['ko' => '제작의뢰 관리'], 'type' => 'admin']
        );
        Permission::firstOrCreate(
            ['identifier' => 'inquiry.notify'],
            ['name' => ['ko' => '제작의뢰 알림'], 'type' => 'admin']
        );
    }

    private function makeUserAndInquiry(string $status = 'received'): array
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $operator = User::factory()->create();

        // 운영자 역할 생성 및 inquiry.manage 권한 부여
        $role = Role::create([
            'identifier' => 'inquiry-operator-' . Str::random(6),
            'name' => ['ko' => '의뢰 운영자'],
            'is_active' => true,
        ]);

        $permission = Permission::where('identifier', 'inquiry.manage')->first();
        $role->permissions()->attach($permission->id, [
            'granted_at' => now(),
        ]);

        $operator->roles()->attach($role->id, [
            'assigned_at' => now(),
        ]);

        $inquiry = Inquiry::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'title' => 'X', 'content' => 'Y',
            'status' => $status,
        ]);

        return compact('owner', 'other', 'operator', 'inquiry');
    }

    public function test_owner_can_view_others_cannot(): void
    {
        ['owner' => $owner, 'other' => $other, 'inquiry' => $inquiry] = $this->makeUserAndInquiry();
        $this->assertTrue($owner->can('view', $inquiry));
        $this->assertFalse($other->can('view', $inquiry));
    }

    public function test_operator_can_view(): void
    {
        ['operator' => $op, 'inquiry' => $inquiry] = $this->makeUserAndInquiry();
        $this->assertTrue($op->can('view', $inquiry));
    }

    public function test_owner_can_update_only_in_received(): void
    {
        ['owner' => $owner, 'inquiry' => $inquiry] = $this->makeUserAndInquiry('received');
        $this->assertTrue($owner->can('update', $inquiry));

        $inquiry->update(['status' => 'quoted']);
        $this->assertFalse($owner->can('update', $inquiry->fresh()));
    }

    public function test_only_operator_can_issue_quote(): void
    {
        ['owner' => $owner, 'operator' => $op, 'inquiry' => $inquiry] = $this->makeUserAndInquiry('received');
        $this->assertFalse($owner->can('issueQuote', $inquiry));
        $this->assertTrue($op->can('issueQuote', $inquiry));
    }

    public function test_owner_can_accept_quote_only_in_quoted(): void
    {
        ['owner' => $owner, 'inquiry' => $inquiry] = $this->makeUserAndInquiry('received');
        $this->assertFalse($owner->can('acceptQuote', $inquiry));
        $inquiry->update(['status' => 'quoted']);
        $this->assertTrue($owner->can('acceptQuote', $inquiry->fresh()));
    }
}
