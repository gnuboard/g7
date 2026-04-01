<?php

namespace Tests\Unit\Models;

use App\Models\MailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MailTemplate 모델 테스트
 *
 * 모델의 fillable, casts, 스코프, replaceVariables, 관계를 검증합니다.
 */
class MailTemplateTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // fillable / casts
    // ========================================================================

    /**
     * type이 fillable에 포함
     */
    public function test_type_is_fillable(): void
    {
        $template = MailTemplate::factory()->withType('welcome')->create();

        $this->assertEquals('welcome', $template->type);
    }

    /**
     * subject가 array로 캐스팅됨
     */
    public function test_subject_is_cast_to_array(): void
    {
        $template = MailTemplate::factory()->create([
            'subject' => ['ko' => '한국어 제목', 'en' => 'English Subject'],
        ]);

        $this->assertIsArray($template->subject);
        $this->assertEquals('한국어 제목', $template->subject['ko']);
    }

    /**
     * body가 array로 캐스팅됨
     */
    public function test_body_is_cast_to_array(): void
    {
        $template = MailTemplate::factory()->create([
            'body' => ['ko' => '<p>본문</p>', 'en' => '<p>Body</p>'],
        ]);

        $this->assertIsArray($template->body);
        $this->assertEquals('<p>본문</p>', $template->body['ko']);
    }

    /**
     * variables가 array로 캐스팅됨
     */
    public function test_variables_is_cast_to_array(): void
    {
        $template = MailTemplate::factory()->create([
            'variables' => [['key' => 'name', 'description' => '이름']],
        ]);

        $this->assertIsArray($template->variables);
        $this->assertEquals('name', $template->variables[0]['key']);
    }

    /**
     * is_active가 boolean으로 캐스팅됨
     */
    public function test_is_active_is_cast_to_boolean(): void
    {
        $template = MailTemplate::factory()->create(['is_active' => 1]);

        $this->assertIsBool($template->is_active);
        $this->assertTrue($template->is_active);
    }

    /**
     * is_default가 boolean으로 캐스팅됨
     */
    public function test_is_default_is_cast_to_boolean(): void
    {
        $template = MailTemplate::factory()->create(['is_default' => 0]);

        $this->assertIsBool($template->is_default);
        $this->assertFalse($template->is_default);
    }

    // ========================================================================
    // 관계
    // ========================================================================

    /**
     * updater 관계가 User를 반환
     */
    public function test_updater_returns_user(): void
    {
        $user = User::factory()->create();
        $template = MailTemplate::factory()->create(['updated_by' => $user->id]);

        $this->assertInstanceOf(User::class, $template->updater);
        $this->assertEquals($user->id, $template->updater->id);
    }

    /**
     * updated_by가 null이면 updater도 null
     */
    public function test_updater_returns_null_when_no_updater(): void
    {
        $template = MailTemplate::factory()->create(['updated_by' => null]);

        $this->assertNull($template->updater);
    }

    // ========================================================================
    // scopeActive
    // ========================================================================

    /**
     * active 스코프가 활성 템플릿만 반환
     */
    public function test_scope_active_filters_correctly(): void
    {
        MailTemplate::factory()->count(2)->create(['is_active' => true]);
        MailTemplate::factory()->inactive()->create();

        $result = MailTemplate::active()->get();

        $this->assertCount(2, $result);
    }

    // ========================================================================
    // scopeByType
    // ========================================================================

    /**
     * byType 스코프가 특정 유형만 반환
     */
    public function test_scope_by_type_filters_correctly(): void
    {
        MailTemplate::factory()->withType('welcome')->create();
        MailTemplate::factory()->withType('reset_password')->create();

        $result = MailTemplate::byType('welcome')->get();

        $this->assertCount(1, $result);
        $this->assertEquals('welcome', $result->first()->type);
    }

    // ========================================================================
    // getLocalizedSubject / getLocalizedBody
    // ========================================================================

    /**
     * getLocalizedSubject가 현재 로케일 제목을 반환
     */
    public function test_get_localized_subject_returns_current_locale(): void
    {
        app()->setLocale('ko');

        $template = MailTemplate::factory()->create([
            'subject' => ['ko' => '한국어 제목', 'en' => 'English Subject'],
        ]);

        $this->assertEquals('한국어 제목', $template->getLocalizedSubject());
    }

    /**
     * getLocalizedSubject가 지정된 로케일 제목을 반환
     */
    public function test_get_localized_subject_with_specific_locale(): void
    {
        $template = MailTemplate::factory()->create([
            'subject' => ['ko' => '한국어 제목', 'en' => 'English Subject'],
        ]);

        $this->assertEquals('English Subject', $template->getLocalizedSubject('en'));
    }

    /**
     * getLocalizedSubject가 fallback으로 ko를 반환
     */
    public function test_get_localized_subject_falls_back_to_ko(): void
    {
        $template = MailTemplate::factory()->create([
            'subject' => ['ko' => '한국어 제목'],
        ]);

        $this->assertEquals('한국어 제목', $template->getLocalizedSubject('ja'));
    }

    /**
     * getLocalizedBody가 현재 로케일 본문을 반환
     */
    public function test_get_localized_body_returns_current_locale(): void
    {
        app()->setLocale('en');

        $template = MailTemplate::factory()->create([
            'body' => ['ko' => '<p>본문</p>', 'en' => '<p>Body</p>'],
        ]);

        $this->assertEquals('<p>Body</p>', $template->getLocalizedBody());
    }

    // ========================================================================
    // replaceVariables
    // ========================================================================

    /**
     * replaceVariables가 변수를 올바르게 치환
     */
    public function test_replace_variables_substitutes_correctly(): void
    {
        $template = MailTemplate::factory()->withVariables()->create();

        $result = $template->replaceVariables([
            'name' => '홍길동',
            'app_name' => 'G7',
        ], 'ko');

        $this->assertEquals('[G7] 홍길동님 환영합니다', $result['subject']);
        $this->assertStringContainsString('홍길동님, G7에 가입해 주셔서', $result['body']);
    }

    /**
     * replaceVariables가 누락된 변수는 그대로 유지
     */
    public function test_replace_variables_keeps_unreplaced_variables(): void
    {
        $template = MailTemplate::factory()->withVariables()->create();

        $result = $template->replaceVariables(['name' => '홍길동'], 'ko');

        $this->assertStringContainsString('{app_name}', $result['subject']);
    }
}
