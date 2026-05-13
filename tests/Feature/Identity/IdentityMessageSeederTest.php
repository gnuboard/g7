<?php

namespace Tests\Feature\Identity;

use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IdentityMessageDefinitionSeeder 테스트.
 *
 * 시더 5건 정상 등록 + 재실행 시 user_overrides 보존 검증.
 */
class IdentityMessageSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_registers_five_definitions(): void
    {
        $this->seed(IdentityMessageDefinitionSeeder::class);

        $this->assertEquals(5, IdentityMessageDefinition::count());
        $this->assertEquals(5, IdentityMessageTemplate::where('channel', 'mail')->count());

        // provider_default
        $this->assertDatabaseHas('identity_message_definitions', [
            'provider_id' => 'g7:core.mail',
            'scope_type' => IdentityMessageDefinition::SCOPE_PROVIDER_DEFAULT,
            'scope_value' => '',
        ]);

        // 4 purposes
        foreach (['signup', 'password_reset', 'self_update', 'sensitive_action'] as $purpose) {
            $this->assertDatabaseHas('identity_message_definitions', [
                'provider_id' => 'g7:core.mail',
                'scope_type' => IdentityMessageDefinition::SCOPE_PURPOSE,
                'scope_value' => $purpose,
            ]);
        }
    }

    public function test_seeder_preserves_user_overrides_on_reseed(): void
    {
        $this->seed(IdentityMessageDefinitionSeeder::class);

        $template = IdentityMessageTemplate::whereHas('definition', fn ($q) => $q
            ->where('scope_type', IdentityMessageDefinition::SCOPE_PURPOSE)
            ->where('scope_value', 'signup')
        )->firstOrFail();

        $template->update([
            'subject' => ['ko' => '운영자가 수정한 제목', 'en' => 'Custom Subject'],
        ]);

        $template->refresh();
        $this->assertEqualsCanonicalizing(
            ['subject'],
            $template->user_overrides ?? [],
            'subject 가 user_overrides 에 기록되어야 합니다.'
        );

        $customSubject = $template->subject;

        $this->seed(IdentityMessageDefinitionSeeder::class);

        $template->refresh();
        $this->assertSame($customSubject, $template->subject, '재시드 시 운영자 수정값이 보존되어야 합니다.');
    }

    public function test_seeder_password_reset_uses_action_url_in_body(): void
    {
        $this->seed(IdentityMessageDefinitionSeeder::class);

        $definition = IdentityMessageDefinition::where('scope_type', IdentityMessageDefinition::SCOPE_PURPOSE)
            ->where('scope_value', 'password_reset')
            ->firstOrFail();

        $template = $definition->templates()->where('channel', 'mail')->firstOrFail();

        $body = $template->body['ko'] ?? '';
        $this->assertStringContainsString('{action_url}', $body, 'password_reset 본문은 link 흐름 변수 {action_url} 을 포함해야 합니다.');
    }

    public function test_seeder_signup_uses_code_in_body(): void
    {
        $this->seed(IdentityMessageDefinitionSeeder::class);

        $definition = IdentityMessageDefinition::where('scope_type', IdentityMessageDefinition::SCOPE_PURPOSE)
            ->where('scope_value', 'signup')
            ->firstOrFail();

        $template = $definition->templates()->where('channel', 'mail')->firstOrFail();

        $body = $template->body['ko'] ?? '';
        $this->assertStringContainsString('{code}', $body, 'signup 본문은 text_code 흐름 변수 {code} 를 포함해야 합니다.');
    }
}
