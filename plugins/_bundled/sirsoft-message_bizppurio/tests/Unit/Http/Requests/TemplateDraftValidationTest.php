<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Http\Requests;

use App\Models\NotificationDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\RequestBizppurioInspectionRequest;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\StoreBizppurioTemplateRequest;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\UpdateBizppurioTemplateRequest;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 알림톡 템플릿 「저장」(임시 저장)과 검수 신청의 검증 강도 테스트.
 *
 * 작성 창의 「저장」은 draft 표시를 실어 쓰다 만 내용도 초안으로 남기고, 빠진 필수값은
 * 검수 신청이 저장된 content 에 전체 규칙을 걸어 카카오 호출 전에 알린다. 라우트를 거치지 않고
 * FormRequest 를 직접 해석해 검증 강도만 잰다.
 */
class TemplateDraftValidationTest extends PluginTestCase
{
    /** 쓰다 만 EX×TEXT content — 본문·카테고리·부가정보·강조 제목이 비어 있다 */
    private const PARTIAL = [
        'templateName' => '작성 중',
        'templateMessageType' => 'EX',
        'templateEmphasizeType' => 'TEXT',
        'templateContent' => '',
        'categoryCode' => '',
    ];

    /**
     * FormRequest 를 주어진 입력·라우트 id 로 해석하고 검증 오류 필드를 돌려준다(통과면 빈 배열).
     *
     * @param  class-string<FormRequest>  $class  요청 클래스
     * @param  array<string, mixed>  $input  요청 본문
     * @param  int  $id  라우트 id
     * @return array<int, string> 오류 필드 목록
     */
    private function errorsOf(string $class, array $input, int $id = 1): array
    {
        $request = $class::create('/', 'POST', $input);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setRouteResolver(fn () => new class($id)
        {
            /**
             * 흉내 낼 라우트 id 를 받는다.
             *
             * @param  int  $id  라우트 id
             */
            public function __construct(private int $id) {}

            /**
             * 라우트 매개변수 조회를 흉내 낸다.
             *
             * @param  string  $key  매개변수 이름
             * @param  mixed  $default  기본값
             * @return mixed 매개변수 값
             */
            public function parameter(string $key, $default = null): mixed
            {
                return $key === 'id' ? $this->id : $default;
            }
        });

        try {
            $request->validateResolved();
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    }

    /**
     * 「저장」은 필수값이 빠진 content 도 받지만, 형식 제약은 그대로 건다 — 신규·수정 모두.
     *
     * @scenario content_variant=ex_text, transition=draft_to_requested
     *
     * @effects draft_save_accepts_partial_content_and_request_validates_fully
     *
     * @return void 반환값 없음
     */
    public function test_draft_save_accepts_partial_content_but_keeps_format_rules(): void
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

        $this->assertSame([], $this->errorsOf(StoreBizppurioTemplateRequest::class, ['notification_type' => 'welcome', 'draft' => true, 'content' => self::PARTIAL]));
        $this->assertSame([], $this->errorsOf(UpdateBizppurioTemplateRequest::class, ['draft' => true, 'content' => self::PARTIAL]));

        $this->assertContains('content.templateMessageType', $this->errorsOf(UpdateBizppurioTemplateRequest::class, [
            'draft' => true,
            'content' => [...self::PARTIAL, 'templateMessageType' => 'ZZ'],
        ]));
    }

    /**
     * draft 표시가 없는 저장(저장 후 검수 신청)은 종전대로 전부 검증한다.
     *
     * @scenario content_variant=ex_text, transition=draft_to_requested
     *
     * @effects draft_save_accepts_partial_content_and_request_validates_fully
     *
     * @return void 반환값 없음
     */
    public function test_save_without_draft_flag_still_requires_everything(): void
    {
        $errors = $this->errorsOf(UpdateBizppurioTemplateRequest::class, ['content' => self::PARTIAL]);

        foreach (['content.templateContent', 'content.categoryCode', 'content.templateExtra', 'content.templateTitle'] as $field) {
            $this->assertContains($field, $errors);
        }
    }

    /**
     * 검수 신청은 저장된 미완성 content 를 전체 규칙으로 막는다 — 본문에 draft 를 실어도 완화되지 않는다.
     *
     * @scenario content_variant=ex_text, transition=draft_to_requested
     *
     * @effects draft_save_accepts_partial_content_and_request_validates_fully
     *
     * @return void 반환값 없음
     */
    public function test_inspection_request_validates_stored_content_fully(): void
    {
        $template = BizppurioTemplate::create(['notification_type' => 'welcome', 'status' => 'draft', 'content' => self::PARTIAL]);

        $errors = $this->errorsOf(RequestBizppurioInspectionRequest::class, ['draft' => true], $template->id);

        foreach (['content.templateContent', 'content.categoryCode', 'content.templateExtra', 'content.templateTitle'] as $field) {
            $this->assertContains($field, $errors);
        }

        $complete = BizppurioTemplate::create([
            'notification_type' => 'order_completed',
            'status' => 'draft',
            'content' => ['templateName' => '환영', 'templateMessageType' => 'BA', 'templateEmphasizeType' => 'NONE', 'templateContent' => '본문', 'categoryCode' => '001001'],
        ]);
        $this->assertSame([], $this->errorsOf(RequestBizppurioInspectionRequest::class, ['comment' => '확인 부탁드립니다'], $complete->id));
    }
}
