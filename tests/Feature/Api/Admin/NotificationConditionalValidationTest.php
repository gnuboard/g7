<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\HookManager;
use App\Http\Requests\NotificationDefinition\UpdateNotificationDefinitionRequest;
use App\Http\Requests\NotificationTemplate\UpdateNotificationTemplateRequest;
use App\Models\NotificationDefinition;
use App\Rules\AvailableNotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 알림 정의/템플릿 조건부 검증 테스트
 *
 * - 템플릿 subject: 컬럼이 nullable 인데 검증만 required 였던 스키마 불일치 회귀
 * - 정의 channels: 허용 채널이 런타임(config + 훅)에 결정되므로 하드코딩 화이트리스트 금지
 */
class NotificationConditionalValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 알림 정의를 생성합니다.
     *
     * @param  array<int, string>  $channels  채널 목록
     * @return NotificationDefinition 생성된 정의
     */
    private function makeDefinition(array $channels = ['mail']): NotificationDefinition
    {
        return NotificationDefinition::create([
            'type' => 'conditional_validation_test',
            'hook_prefix' => 'core',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '조건부 검증 테스트'],
            'channels' => $channels,
            'hooks' => [],
        ]);
    }

    /**
     * 정의가 라우트 파라미터로 주입된 Update 요청을 생성합니다.
     *
     * @param  NotificationDefinition  $definition  대상 정의
     * @return UpdateNotificationDefinitionRequest 요청 객체
     */
    private function makeDefinitionRequest(NotificationDefinition $definition): UpdateNotificationDefinitionRequest
    {
        $request = new UpdateNotificationDefinitionRequest;

        $request->setRouteResolver(function () use ($definition) {
            $route = \Mockery::mock(Route::class);
            $route->shouldReceive('parameter')->with('definition', null)->andReturn($definition);

            return $route;
        });

        return $request;
    }

    /**
     * subject 미전송(부분 수정)이 통과해야 한다 (스키마 정합)
     */
    public function test_template_subject_can_be_omitted(): void
    {
        $request = new UpdateNotificationTemplateRequest;

        $validator = Validator::make([
            'body' => ['ko' => '본문'],
        ], $request->rules());

        $this->assertTrue(
            $validator->passes(),
            'subject 미전송은 통과해야 함: '.json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * subject null 전송이 통과해야 한다 (컬럼이 nullable)
     */
    public function test_template_subject_can_be_null(): void
    {
        $request = new UpdateNotificationTemplateRequest;

        $validator = Validator::make([
            'subject' => null,
            'body' => ['ko' => '본문'],
        ], $request->rules());

        $this->assertTrue(
            $validator->passes(),
            'subject=null 은 통과해야 함: '.json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * subject 를 전송하면 다국어 배열 계약은 그대로 검증되어야 한다 (완화가 검증을 무력화하지 않음)
     */
    public function test_template_subject_still_validated_when_present(): void
    {
        $request = new UpdateNotificationTemplateRequest;

        $validator = Validator::make([
            'subject' => 'not-an-array',
            'body' => ['ko' => '본문'],
        ], $request->rules());

        $this->assertFalse($validator->passes(), 'subject 가 배열이 아니면 실패해야 함');
        $this->assertArrayHasKey('subject', $validator->errors()->toArray());
    }

    /**
     * body 는 계속 필수여야 한다 (컬럼이 NOT NULL)
     */
    public function test_template_body_remains_required(): void
    {
        $request = new UpdateNotificationTemplateRequest;

        $validator = Validator::make(['subject' => ['ko' => '제목']], $request->rules());

        $this->assertFalse($validator->passes(), 'body 미전송은 실패해야 함');
        $this->assertArrayHasKey('body', $validator->errors()->toArray());
    }

    /**
     * 사용 가능한 채널은 통과해야 한다
     */
    public function test_definition_accepts_available_channel(): void
    {
        $definition = $this->makeDefinition();
        $request = $this->makeDefinitionRequest($definition);

        $validator = Validator::make(['channels' => ['mail', 'database']], $request->rules());

        $this->assertTrue(
            $validator->passes(),
            'config 에 선언된 채널은 통과해야 함: '.json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 알 수 없는 채널은 차단되어야 한다 (기존에는 임의 문자열이 그대로 저장됨)
     */
    public function test_definition_rejects_unknown_channel(): void
    {
        $definition = $this->makeDefinition();
        $request = $this->makeDefinitionRequest($definition);

        $validator = Validator::make(['channels' => ['carrier-pigeon']], $request->rules());

        $this->assertFalse($validator->passes(), '알 수 없는 채널은 실패해야 함');
        $this->assertArrayHasKey('channels.0', $validator->errors()->toArray());
    }

    /**
     * 이미 저장된 채널은 목록 밖이어도 통과해야 한다
     *
     * 채널을 제공하던 플러그인이 비활성화된 뒤에도 기존 레코드 수정이 막히면 안 된다.
     */
    public function test_definition_accepts_persisted_channel_even_if_unavailable(): void
    {
        $definition = $this->makeDefinition(['mail', 'sms']);
        $request = $this->makeDefinitionRequest($definition);

        $validator = Validator::make(['channels' => ['mail', 'sms']], $request->rules());

        $this->assertTrue(
            $validator->passes(),
            '이미 저장된 채널은 통과해야 함: '.json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 플러그인이 훅으로 추가한 채널도 허용되어야 한다 (하드코딩 화이트리스트 금지)
     */
    public function test_definition_accepts_channel_added_by_hook(): void
    {
        HookManager::addFilter(
            'core.notification.filter_available_channels',
            fn (array $channels) => array_merge($channels, [['id' => 'kakao', 'name' => 'KakaoTalk']])
        );

        $rule = new AvailableNotificationChannel;

        $message = null;
        $rule->validate('channels.0', 'kakao', function ($failMessage) use (&$message) {
            $message = (string) $failMessage;
        });

        $this->assertNull($message, '훅으로 추가된 채널은 통과해야 함');
    }

    /**
     * 테스트 정리
     */
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
