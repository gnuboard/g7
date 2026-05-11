<?php

namespace Tests\Unit\Helpers;

use App\Extension\Helpers\IdentityMessageSyncHelper;
use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IdentityMessageSyncHelper 단위 테스트.
 *
 * 정의/템플릿 syncOrCreate, user_overrides 보존, stale cleanup 검증.
 */
class IdentityMessageSyncHelperTest extends TestCase
{
    use RefreshDatabase;

    private IdentityMessageSyncHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = app(IdentityMessageSyncHelper::class);
    }

    public function test_sync_definition_creates_new_record(): void
    {
        $definition = $this->helper->syncDefinition($this->definitionData('signup'));

        $this->assertSame('g7:core.mail', $definition->provider_id);
        $this->assertSame('purpose', $definition->scope_type->value);
        $this->assertSame('signup', $definition->scope_value);
        $this->assertTrue($definition->is_active);
    }

    public function test_sync_definition_preserves_user_overrides_on_reseed(): void
    {
        $definition = $this->helper->syncDefinition($this->definitionData('signup'));

        $definition->update(['name' => ['ko' => '운영자 변경 이름', 'en' => 'Custom Name']]);
        $definition->refresh();
        $this->assertContains('name', $definition->user_overrides ?? []);

        $reseeded = $this->helper->syncDefinition($this->definitionData('signup'));

        $this->assertSame('운영자 변경 이름', $reseeded->name['ko']);
    }

    public function test_sync_template_creates_and_links_to_definition(): void
    {
        $definition = $this->helper->syncDefinition($this->definitionData('signup'));

        $template = $this->helper->syncTemplate($definition->id, [
            'channel' => 'mail',
            'subject' => ['ko' => '제목', 'en' => 'Subject'],
            'body' => ['ko' => '본문', 'en' => 'Body'],
        ]);

        $this->assertSame($definition->id, $template->definition_id);
        $this->assertSame('mail', $template->channel);
    }

    public function test_cleanup_stale_definitions_deletes_unmapped_records(): void
    {
        $signup = $this->helper->syncDefinition($this->definitionData('signup'));
        $reset = $this->helper->syncDefinition($this->definitionData('password_reset'));
        $extra = $this->helper->syncDefinition($this->definitionData('legacy'));

        // signup, password_reset 만 유지 → legacy 삭제 대상
        $deleted = $this->helper->cleanupStaleDefinitions('core', 'core', [
            ['provider_id' => 'g7:core.mail', 'scope_type' => 'purpose', 'scope_value' => 'signup'],
            ['provider_id' => 'g7:core.mail', 'scope_type' => 'purpose', 'scope_value' => 'password_reset'],
        ]);

        $this->assertSame(1, $deleted);
        $this->assertNotNull(IdentityMessageDefinition::find($signup->id));
        $this->assertNotNull(IdentityMessageDefinition::find($reset->id));
        $this->assertNull(IdentityMessageDefinition::find($extra->id));
    }

    public function test_cleanup_stale_definitions_cascade_deletes_templates(): void
    {
        $extra = $this->helper->syncDefinition($this->definitionData('legacy'));
        $this->helper->syncTemplate($extra->id, [
            'channel' => 'mail',
            'subject' => ['ko' => '제목'],
            'body' => ['ko' => '본문'],
        ]);

        $this->helper->cleanupStaleDefinitions('core', 'core', []);

        $this->assertSame(0, IdentityMessageTemplate::where('definition_id', $extra->id)->count());
    }

    public function test_cleanup_stale_templates_only_removes_unmapped_channels(): void
    {
        $definition = $this->helper->syncDefinition($this->definitionData('signup'));
        $this->helper->syncTemplate($definition->id, [
            'channel' => 'mail',
            'subject' => ['ko' => 'mail'],
            'body' => ['ko' => 'mail body'],
        ]);
        $this->helper->syncTemplate($definition->id, [
            'channel' => 'sms',
            'subject' => ['ko' => 'sms'],
            'body' => ['ko' => 'sms body'],
        ]);

        $deleted = $this->helper->cleanupStaleTemplates($definition->id, ['mail']);

        $this->assertSame(1, $deleted);
        $this->assertSame(1, IdentityMessageTemplate::where('definition_id', $definition->id)->count());
        $this->assertNotNull(IdentityMessageTemplate::where('definition_id', $definition->id)->where('channel', 'mail')->first());
    }

    private function definitionData(string $scopeValue): array
    {
        return [
            'provider_id' => 'g7:core.mail',
            'scope_type' => 'purpose',
            'scope_value' => $scopeValue,
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '시드 이름 '.$scopeValue, 'en' => 'Seed Name '.$scopeValue],
            'channels' => ['mail'],
        ];
    }
}
