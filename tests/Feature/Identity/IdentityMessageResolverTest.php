<?php

namespace Tests\Feature\Identity;

use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use App\Services\IdentityMessageDefinitionService;
use App\Services\IdentityMessageResolver;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IdentityMessageResolver scope fallback 체인 검증.
 *
 * policy → purpose → provider_default 우선순위.
 */
class IdentityMessageResolverTest extends TestCase
{
    use RefreshDatabase;

    private IdentityMessageResolver $resolver;

    private IdentityMessageDefinitionService $definitionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityMessageDefinitionSeeder::class);
        $this->resolver = $this->app->make(IdentityMessageResolver::class);
        $this->definitionService = $this->app->make(IdentityMessageDefinitionService::class);
        $this->definitionService->invalidateAllCache();
    }

    public function test_resolves_to_purpose_definition_when_no_policy(): void
    {
        $resolved = $this->resolver->resolve(
            providerId: 'g7:core.mail',
            purpose: 'signup',
            policyKey: null,
            channel: 'mail',
        );

        $this->assertNotNull($resolved);
        $this->assertSame(IdentityMessageDefinition::SCOPE_PURPOSE, $resolved['definition']->scope_type->value);
        $this->assertSame('signup', $resolved['definition']->scope_value);
    }

    public function test_falls_back_to_provider_default_when_purpose_inactive(): void
    {
        IdentityMessageDefinition::where('scope_value', 'signup')->update(['is_active' => false]);
        $this->definitionService->invalidateAllCache();

        $resolved = $this->resolver->resolve(
            providerId: 'g7:core.mail',
            purpose: 'signup',
            policyKey: null,
            channel: 'mail',
        );

        $this->assertNotNull($resolved);
        $this->assertSame(IdentityMessageDefinition::SCOPE_PROVIDER_DEFAULT, $resolved['definition']->scope_type->value);
    }

    public function test_returns_null_when_no_definition_active(): void
    {
        IdentityMessageDefinition::query()->update(['is_active' => false]);
        $this->definitionService->invalidateAllCache();

        $resolved = $this->resolver->resolve(
            providerId: 'g7:core.mail',
            purpose: 'signup',
            policyKey: null,
            channel: 'mail',
        );

        $this->assertNull($resolved);
    }

    public function test_returns_null_when_template_inactive(): void
    {
        IdentityMessageTemplate::query()->update(['is_active' => false]);
        $this->definitionService->invalidateAllCache();

        $resolved = $this->resolver->resolve(
            providerId: 'g7:core.mail',
            purpose: 'signup',
            policyKey: null,
            channel: 'mail',
        );

        $this->assertNull($resolved);
    }

    public function test_policy_definition_overrides_purpose(): void
    {
        $purposeDef = IdentityMessageDefinition::where('scope_value', 'sensitive_action')->firstOrFail();

        $policyDef = IdentityMessageDefinition::create([
            'provider_id' => 'g7:core.mail',
            'scope_type' => IdentityMessageDefinition::SCOPE_POLICY,
            'scope_value' => 'sirsoft-ecommerce.checkout.before_payment',
            'name' => ['ko' => '결제 정책 전용', 'en' => 'Checkout Policy Only'],
            'channels' => ['mail'],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'is_active' => true,
            'is_default' => true,
        ]);

        IdentityMessageTemplate::create([
            'definition_id' => $policyDef->id,
            'channel' => 'mail',
            'subject' => ['ko' => '정책 제목', 'en' => 'Policy Subject'],
            'body' => ['ko' => '정책 본문 {code}', 'en' => 'Policy body {code}'],
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->definitionService->invalidateAllCache();

        $resolved = $this->resolver->resolve(
            providerId: 'g7:core.mail',
            purpose: 'sensitive_action',
            policyKey: 'sirsoft-ecommerce.checkout.before_payment',
            channel: 'mail',
        );

        $this->assertNotNull($resolved);
        $this->assertSame($policyDef->id, $resolved['definition']->id);
        $this->assertNotEquals($purposeDef->id, $resolved['definition']->id);
    }
}
