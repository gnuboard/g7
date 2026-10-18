<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Template;

use App\Models\NotificationDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkTemplateService;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 관리 API 응답 안내 문장(`message`) 번역 테스트.
 *
 * 목록·요약·상세 조회, 알림톡 분류·발신 프로필 조회, 발송 결과 조회의 성공 응답과 없는 행의 404 가
 * 원문 키(`messages.success`, `messages.not_found`)를 그대로 내보냈다. 코어에는 `messages` 번역 묶음이
 * 없어 어느 언어에서도 번역되지 않는다(2026-10-07 실측 O-M2-5). 이 플러그인의 번역 문구가 관리자 언어로
 * 나가는지 본다.
 */
class ResponseMessageTranslationTest extends PluginTestCase
{
    private const TEMPLATE_BASE = '/api/plugins/sirsoft-message_bizppurio/admin/templates';

    private const REFERENCE_BASE = '/api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates';

    private const RESULT_BASE = '/api/plugins/sirsoft-message_bizppurio/admin/dispatch-results';

    private const VIEW = 'sirsoft-message_bizppurio.messaging.view';

    private const MANAGE = 'sirsoft-message_bizppurio.messaging.manage';

    /**
     * 메시지 조회·관리 권한을 가진 관리자의 인증 헤더를 만듭니다.
     *
     * @param  string  $locale  관리자 언어
     * @return array<string, string> 인증 헤더
     */
    private function headersFor(string $locale): array
    {
        $user = User::factory()->create(['language' => $locale]);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );

        $testRole = Role::create([
            'identifier' => 'bizppurio_msg_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);
        $testRole->permissions()->sync(array_map(
            fn (string $identifier) => Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => json_encode(['ko' => $identifier, 'en' => $identifier]), 'type' => 'admin']
            )->id,
            [self::VIEW, self::MANAGE],
        ));

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return [
            'Authorization' => 'Bearer '.$user->fresh()->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * 템플릿 행 1건(과 그 알림 정의)을 만듭니다.
     *
     * @return BizppurioTemplate 생성된 행
     */
    private function seedTemplate(): BizppurioTemplate
    {
        NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
            'is_active' => true,
        ]);

        return BizppurioTemplate::create(['notification_type' => 'welcome', 'status' => 'draft']);
    }

    /**
     * 언어별 기대 문장.
     *
     * @return array<string, array{string, array<string, string>}> 관리자 언어 / 응답 종류별 기대 문장
     */
    public static function localeProvider(): array
    {
        return [
            'ko' => ['ko', [
                'template' => '알림 템플릿을 불러왔습니다.',
                'not_found' => '알림 템플릿을 찾을 수 없습니다.',
                'categories' => '알림톡 카테고리를 불러왔습니다.',
                'profiles' => '알림톡 발신 프로필을 불러왔습니다.',
                'results' => '발송 결과를 불러왔습니다.',
            ]],
            'en' => ['en', [
                'template' => 'Notification templates loaded.',
                'not_found' => 'Notification template not found.',
                'categories' => 'AlimTalk categories loaded.',
                'profiles' => 'AlimTalk sender profiles loaded.',
                'results' => 'Dispatch results loaded.',
            ]],
        ];
    }

    /**
     * 템플릿 목록·요약·상세 조회와 없는 행 404 의 안내 문장이 관리자 언어로 번역된다.
     *
     * @param  string  $locale  관리자 언어
     * @param  array<string, string>  $expected  응답 종류별 기대 문장
     *
     * @scenario surface=template_lookup
     *
     * @effects response_message_is_translated
     *
     * @return void 반환값 없음
     */
    #[DataProvider('localeProvider')]
    public function test_template_lookup_messages_are_translated(string $locale, array $expected): void
    {
        $template = $this->seedTemplate();
        $headers = $this->headersFor($locale);

        $this->getJson(self::TEMPLATE_BASE, $headers)->assertOk()->assertJsonPath('message', $expected['template']);
        $this->getJson(self::TEMPLATE_BASE.'/map', $headers)->assertOk()->assertJsonPath('message', $expected['template']);
        $this->getJson(self::TEMPLATE_BASE.'/'.$template->id, $headers)->assertOk()->assertJsonPath('message', $expected['template']);

        $this->getJson(self::TEMPLATE_BASE.'/999999', $headers)->assertNotFound()->assertJsonPath('message', $expected['not_found']);
        $this->deleteJson(self::TEMPLATE_BASE.'/999999', [], $headers)->assertNotFound()->assertJsonPath('message', $expected['not_found']);
        $this->postJson(self::TEMPLATE_BASE.'/999999/request', [], $headers)->assertNotFound()->assertJsonPath('message', $expected['not_found']);
    }

    /**
     * 알림톡 분류·발신 프로필 조회 성공 응답의 안내 문장이 관리자 언어로 번역된다(외부 조회는 대역으로 바꾼다).
     *
     * @param  string  $locale  관리자 언어
     * @param  array<string, string>  $expected  응답 종류별 기대 문장
     *
     * @scenario surface=reference_lookup
     *
     * @effects response_message_is_translated
     *
     * @return void 반환값 없음
     */
    #[DataProvider('localeProvider')]
    public function test_reference_lookup_messages_are_translated(string $locale, array $expected): void
    {
        $mock = Mockery::mock(AlimtalkTemplateService::class);
        $mock->shouldReceive('categories')->once()->andReturn([]);
        $mock->shouldReceive('senderProfiles')->once()->andReturn([]);
        $this->app->instance(AlimtalkTemplateService::class, $mock);

        $headers = $this->headersFor($locale);

        $this->getJson(self::REFERENCE_BASE.'/categories', $headers)->assertOk()->assertJsonPath('message', $expected['categories']);
        $this->getJson(self::REFERENCE_BASE.'/profiles', $headers)->assertOk()->assertJsonPath('message', $expected['profiles']);
    }

    /**
     * 발송 결과 조회(최근·로그 id 지정) 응답의 안내 문장이 관리자 언어로 번역된다.
     *
     * @param  string  $locale  관리자 언어
     * @param  array<string, string>  $expected  응답 종류별 기대 문장
     *
     * @scenario surface=dispatch_result
     *
     * @effects response_message_is_translated
     *
     * @return void 반환값 없음
     */
    #[DataProvider('localeProvider')]
    public function test_dispatch_result_messages_are_translated(string $locale, array $expected): void
    {
        $headers = $this->headersFor($locale);

        $this->getJson(self::RESULT_BASE.'/recent', $headers)->assertOk()->assertJsonPath('message', $expected['results']);
        $this->postJson(self::RESULT_BASE.'/lookup', ['notification_log_ids' => []], $headers)->assertOk()->assertJsonPath('message', $expected['results']);
    }
}
