<?php

namespace Tests\Unit\Support;

use App\Support\FormRequestRunner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * `FormRequestRunner` — 관리자 저장 FormRequest 를 HTTP 없이 같은 순서로 실행한다(권한 검사 제외).
 */
class FormRequestRunnerTest extends TestCase
{
    /**
     * `authorize()` 가 false 여도 부르지 않으므로 검증만으로 통과한다 (셸 접근이 곧 권한).
     *
     * @effects form_request_runner_skips_authorize
     *
     * @return void 반환값 없음
     */
    public function test_does_not_call_authorize(): void
    {
        $validated = FormRequestRunner::validate(RunnerProbeRequest::class, ['name' => 'abc']);

        $this->assertSame('abc', $validated['name']);
        $this->assertFalse(RunnerProbeRequest::$authorizeCalled);
    }

    /**
     * `prepareForValidation()` 의 정규화가 검증 전에 적용된다.
     *
     * @effects form_request_runner_applies_prepare_for_validation
     *
     * @return void 반환값 없음
     */
    public function test_applies_prepare_for_validation(): void
    {
        $validated = FormRequestRunner::validate(RunnerProbeRequest::class, ['name' => '  padded  ']);

        $this->assertSame('padded', $validated['name']);
    }

    /**
     * `withValidator()` 의 교차 검증과 라우트 매개변수가 화면 요청과 같게 작동한다.
     *
     * @effects form_request_runner_applies_with_validator_and_route_parameters
     *
     * @return void 반환값 없음
     */
    public function test_applies_with_validator_and_route_parameters(): void
    {
        try {
            FormRequestRunner::validate(RunnerProbeRequest::class, ['name' => 'forbidden'], ['key' => 'locked']);
            $this->fail('교차 검증이 실패를 내지 않았다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
            $this->assertStringContainsString('locked', $e->errors()['name'][0]);
        }
    }
}

/**
 * 실행 순서 관찰용 FormRequest.
 */
class RunnerProbeRequest extends FormRequest
{
    /** authorize() 호출 여부 */
    public static bool $authorizeCalled = false;

    /**
     * 권한 — 부르면 기록하고 거부한다.
     *
     * @return bool 항상 false
     */
    public function authorize(): bool
    {
        self::$authorizeCalled = true;

        return false;
    }

    /**
     * 앞뒤 공백 제거.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /**
     * 규칙.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:20']];
    }

    /**
     * 교차 검증 — 라우트 키가 locked 이고 이름이 forbidden 이면 실패.
     *
     * @param  \Illuminate\Validation\Validator  $validator  검증기
     * @return void 반환값 없음
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->route('key') === 'locked' && $this->input('name') === 'forbidden') {
                $validator->errors()->add('name', 'route key '.$this->route('key'));
            }
        });
    }
}
