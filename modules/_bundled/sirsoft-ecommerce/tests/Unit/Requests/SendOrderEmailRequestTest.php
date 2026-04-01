<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\SendOrderEmailRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 이메일 발송 요청 검증 테스트
 */
class SendOrderEmailRequestTest extends ModuleTestCase
{
    /**
     * 유효한 기본 데이터
     *
     * @param array $overrides
     * @return array
     */
    protected function validData(array $overrides = []): array
    {
        return array_merge([
            'email' => 'customer@example.com',
            'message' => '주문 관련 안내드립니다.',
        ], $overrides);
    }

    /**
     * 검증 수행
     *
     * @param array $data
     * @return \Illuminate\Validation\Validator
     */
    protected function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new SendOrderEmailRequest();

        return Validator::make($data, $request->rules());
    }

    public function test_valid_request_passes(): void
    {
        $validator = $this->validate($this->validData());

        $this->assertFalse($validator->fails());
    }

    public function test_email_required(): void
    {
        $validator = $this->validate($this->validData(['email' => '']));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    public function test_email_must_be_valid(): void
    {
        $validator = $this->validate($this->validData(['email' => 'not-an-email']));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    public function test_email_max_length(): void
    {
        $validator = $this->validate($this->validData([
            'email' => str_repeat('a', 247) . '@test.com',
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    public function test_message_required(): void
    {
        $validator = $this->validate($this->validData(['message' => '']));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('message', $validator->errors()->toArray());
    }

    public function test_message_max_length(): void
    {
        $validator = $this->validate($this->validData([
            'message' => str_repeat('가', 5001),
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('message', $validator->errors()->toArray());
    }

    public function test_message_at_max_length_passes(): void
    {
        $validator = $this->validate($this->validData([
            'message' => str_repeat('a', 5000),
        ]));

        $this->assertFalse($validator->fails());
    }
}
