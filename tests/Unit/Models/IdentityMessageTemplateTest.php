<?php

namespace Tests\Unit\Models;

use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IdentityMessageTemplate 모델 단위 테스트.
 *
 * 다국어 fallback, replaceVariables, user_overrides 보존을 격리 검증.
 */
class IdentityMessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    private IdentityMessageDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->definition = IdentityMessageDefinition::create([
            'provider_id' => 'g7:core.mail',
            'scope_type' => IdentityMessageDefinition::SCOPE_PURPOSE,
            'scope_value' => 'signup',
            'name' => ['ko' => '회원가입 인증', 'en' => 'Signup Verification'],
            'channels' => ['mail'],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    public function test_localized_subject_returns_current_locale(): void
    {
        $template = $this->makeTemplate([
            'subject' => ['ko' => '한글 제목', 'en' => 'English Subject'],
            'body' => ['ko' => '한글 본문', 'en' => 'English body'],
        ]);

        $this->assertSame('한글 제목', $template->getLocalizedSubject('ko'));
        $this->assertSame('English Subject', $template->getLocalizedSubject('en'));
    }

    public function test_localized_subject_falls_back_to_ko_then_en(): void
    {
        $template = $this->makeTemplate([
            'subject' => ['ko' => '한글만'],
            'body' => ['ko' => '본문'],
        ]);

        $this->assertSame('한글만', $template->getLocalizedSubject('en'));
        $this->assertSame('한글만', $template->getLocalizedSubject('ja'));
    }

    public function test_replace_variables_substitutes_placeholders(): void
    {
        $template = $this->makeTemplate([
            'subject' => ['ko' => '[{app_name}] 인증 코드', 'en' => '[{app_name}] Code'],
            'body' => ['ko' => '코드: {code} (만료 {expire_minutes}분)', 'en' => 'Code: {code} ({expire_minutes}m)'],
        ]);

        $rendered = $template->replaceVariables(
            ['app_name' => '테스트사이트', 'code' => '987654', 'expire_minutes' => 10],
            'ko'
        );

        $this->assertSame('[테스트사이트] 인증 코드', $rendered['subject']);
        $this->assertSame('코드: 987654 (만료 10분)', $rendered['body']);
        $this->assertStringNotContainsString('{code}', $rendered['body']);
    }

    public function test_replace_variables_skips_null_values_safely(): void
    {
        $template = $this->makeTemplate([
            'subject' => ['ko' => '코드: {code}', 'en' => 'Code: {code}'],
            'body' => ['ko' => '링크: {action_url}', 'en' => 'Link: {action_url}'],
        ]);

        // text_code 흐름이라 action_url=null. 본문에 {action_url} 잔여 허용 (운영자가 본문 수정 시 의도 가시화).
        $rendered = $template->replaceVariables(
            ['code' => '111222', 'action_url' => null],
            'ko'
        );

        $this->assertSame('코드: 111222', $rendered['subject']);
        $this->assertStringContainsString('{action_url}', $rendered['body'], 'null 값은 치환에서 제외되어 잔여 토큰이 유지되어야 합니다.');
    }

    public function test_user_overrides_records_field_change_outside_seeder(): void
    {
        $template = $this->makeTemplate([
            'subject' => ['ko' => '원본', 'en' => 'Original'],
            'body' => ['ko' => '본문', 'en' => 'Body'],
        ]);

        $template->update(['subject' => ['ko' => '변경됨', 'en' => 'Changed']]);
        $template->refresh();

        $this->assertContains('subject', $template->user_overrides ?? []);
    }

    public function test_user_overrides_skips_recording_in_seeder_context(): void
    {
        app()->instance('user_overrides.seeding', true);

        try {
            $template = $this->makeTemplate([
                'subject' => ['ko' => '원본', 'en' => 'Original'],
                'body' => ['ko' => '본문', 'en' => 'Body'],
            ]);

            $template->update(['subject' => ['ko' => '시드재실행', 'en' => 'Reseed']]);
            $template->refresh();

            $this->assertEmpty(
                $template->user_overrides ?? [],
                '시더 컨텍스트에서는 user_overrides 가 기록되지 않아야 합니다.'
            );
        } finally {
            app()->forgetInstance('user_overrides.seeding');
        }
    }

    public function test_active_scope_filters_inactive_templates(): void
    {
        $this->makeTemplate(['subject' => ['ko' => 'A'], 'body' => ['ko' => 'a'], 'is_active' => true]);

        IdentityMessageTemplate::create([
            'definition_id' => $this->definition->id,
            'channel' => 'sms',
            'subject' => ['ko' => 'B'],
            'body' => ['ko' => 'b'],
            'is_active' => false,
            'is_default' => true,
        ]);

        $this->assertSame(1, IdentityMessageTemplate::active()->count());
        $this->assertSame(1, IdentityMessageTemplate::active()->byChannel('mail')->count());
        $this->assertSame(0, IdentityMessageTemplate::active()->byChannel('sms')->count());
    }

    /**
     * 헬퍼 — 기본 템플릿 생성.
     */
    private function makeTemplate(array $overrides = []): IdentityMessageTemplate
    {
        return IdentityMessageTemplate::create(array_merge([
            'definition_id' => $this->definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '제목', 'en' => 'Subject'],
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'is_active' => true,
            'is_default' => true,
        ], $overrides));
    }
}
