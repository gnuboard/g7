<?php

namespace Tests\Feature\Auth;

use App\Rules\PasswordPolicy;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 작성 규칙(조합·추측 방지) 테스트 (#736 D12·D13·D16)
 *
 * 운영자가 켠 규칙만 적용되는지, 경계(2종류·글자 수)가 맞는지, 저장값이 범위 밖이어도
 * 한계값으로 클램프해 읽는지를 규칙 단위로 고정한다. 가입·변경·재설정 전 경로가 같은
 * `PasswordPolicy::rules()` 를 쓰므로 여기의 판정이 곧 모든 경로의 판정이다.
 */
class PasswordComplexityRulesTest extends TestCase
{
    use PasswordPolicyFixtures;

    /**
     * 비밀번호 하나를 정책 규칙으로 검증하고 password 오류 목록을 돌려줍니다.
     *
     * @param  string  $password  검증할 비밀번호
     * @param  string|null  $identifier  아이디 판정용 이메일
     * @return array<int, string> 오류 메시지 목록
     */
    private function errorsFor(string $password, ?string $identifier = null): array
    {
        $validator = Validator::make(['password' => $password], ['password' => PasswordPolicy::rules($identifier)]);
        $validator->passes();

        return $validator->errors()->get('password');
    }

    /**
     * 조합 규칙이 꺼져 있으면 한 종류만 써도 통과하고, 켜면 2종류 미만을 거부한다.
     *
     * @scenario entry_point=register, char_types_setting=on, input_state=violates_char_types
     *
     * @effects char_types_rule_follows_setting
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function char_types_rule_applies_only_when_enabled(): void
    {
        $this->assertSame([], $this->errorsFor('abcdefghij'), '꺼져 있으면 영문만으로도 통과해야 한다');

        $this->setSecurity(['password_require_char_types' => true]);

        $this->assertNotSame([], $this->errorsFor('abcdefghij'), '영문만');
        $this->assertNotSame([], $this->errorsFor('1234509876'), '숫자만');
        $this->assertSame([], $this->errorsFor('abcdef1234'), '영문+숫자');
        $this->assertSame([], $this->errorsFor('abcdefgh!!'), '영문+특수');
        $this->assertSame([], $this->errorsFor('12345678 9'), '숫자+공백(유니코드 구분자 = 특수)');
        $this->assertSame([], $this->errorsFor('abcdefgh★'), '영문+유니코드 기호');
    }

    /**
     * 아이디(이메일 앞부분) 포함 금지는 대소문자를 무시하고, 3자 미만 앞부분은 검사하지 않는다.
     *
     * @scenario entry_point=register, guessable_setting=identifier, input_state=contains_identifier
     *
     * @effects identifier_rule_blocks_local_part
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function identifier_rule_blocks_the_email_local_part(): void
    {
        $this->assertSame([], $this->errorsFor('XHongGil99!', 'hong@example.com'), '꺼져 있으면 통과');

        $this->setSecurity(['password_block_identifier' => true]);

        $this->assertNotSame([], $this->errorsFor('XHongGil99!', 'honggil@example.com'));
        $this->assertNotSame([], $this->errorsFor('my-HONGGIL-pw', 'honggil@example.com'));
        $this->assertSame([], $this->errorsFor('abab-zz-99', 'ab@example.com'), '3자 미만 앞부분은 검사하지 않는다');
        $this->assertSame([], $this->errorsFor('Tiger!2026'), '식별자가 없으면 검사하지 않는다');
    }

    /**
     * 같은 문자 연속 반복 금지는 꺼져 있으면 통과하고, 켜면 설정한 글자 수(3~6)부터 거부한다.
     *
     * @scenario entry_point=register, guessable_setting=repeated|off, input_state=repeated_chars
     *
     * @effects repeated_rule_uses_configured_length
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function repeated_chars_rule_uses_the_configured_length(): void
    {
        $this->setSecurity(['password_block_repeated_chars' => false, 'password_repeated_chars_length' => 4]);
        $this->assertSame([], $this->errorsFor('aaaaaab-xz-19'), '꺼져 있으면 반복이 길어도 통과');

        $this->setSecurity(['password_block_repeated_chars' => true, 'password_repeated_chars_length' => 4]);

        $this->assertSame([], $this->errorsFor('aaab-xyz-19'), '3회 연속은 통과(기준 4)');
        $this->assertNotSame([], $this->errorsFor('aaaab-xyz-19'), '4회 연속은 거부');

        $this->setSecurity(['password_repeated_chars_length' => 3]);
        $this->assertNotSame([], $this->errorsFor('aaab-xyz-19'), '기준 3이면 3회 연속도 거부');

        $this->setSecurity(['password_repeated_chars_length' => 6]);
        $this->assertSame([], $this->errorsFor('aaaaab-xz-19'), '기준 6이면 5회 연속은 통과');
        $this->assertNotSame([], $this->errorsFor('aaaaaab-xz-19'), '기준 6이면 6회 연속은 거부');
    }

    /**
     * 연속 문자 금지는 꺼져 있으면 통과하고, 켜면 숫자·영문·자판 행을 오름·내림 모두 보고 대소문자를 무시한다.
     *
     * @scenario entry_point=register, guessable_setting=sequential|off, input_state=sequential_chars
     *
     * @effects sequential_rule_covers_digits_letters_keyboard
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function sequential_chars_rule_covers_digits_letters_and_keyboard_rows(): void
    {
        $this->setSecurity(['password_block_sequential_chars' => false, 'password_sequential_chars_length' => 4]);
        $this->assertSame([], $this->errorsFor('x123456y!z'), '꺼져 있으면 연속 문자가 길어도 통과');

        $this->setSecurity(['password_block_sequential_chars' => true, 'password_sequential_chars_length' => 4]);

        $this->assertNotSame([], $this->errorsFor('x1234y!z'), '숫자 오름');
        $this->assertNotSame([], $this->errorsFor('x9876y!z'), '숫자 내림');
        $this->assertNotSame([], $this->errorsFor('xABCDy!9'), '영문 오름(대문자)');
        $this->assertNotSame([], $this->errorsFor('xdcbay!9'), '영문 내림');
        $this->assertNotSame([], $this->errorsFor('xQWERy!9'), '자판 행');
        $this->assertNotSame([], $this->errorsFor('xLKJHy!9'), '자판 행 내림(lkjh)');
        $this->assertSame([], $this->errorsFor('x123y!z9'), '3자 연속은 기준 4 미만');
    }

    /**
     * 저장값이 한계값 밖이어도(단건 저장 경로는 범위를 보지 않는다) 판독부가 클램프한다.
     *
     * @scenario entry_point=register, guessable_setting=repeated, input_state=repeated_chars
     *
     * @effects guessable_lengths_are_clamped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function out_of_range_lengths_are_clamped_to_the_limits(): void
    {
        // 1 → 하한 3 으로 클램프: 2회 연속은 통과, 3회 연속은 거부
        $this->setSecurity(['password_block_repeated_chars' => true, 'password_repeated_chars_length' => 1]);

        $this->assertSame([], $this->errorsFor('aab-xyz-19'));
        $this->assertNotSame([], $this->errorsFor('aaab-xyz-19'));

        // 99 → 상한 6 으로 클램프
        $this->setSecurity(['password_repeated_chars_length' => 99]);
        $this->assertNotSame([], $this->errorsFor('aaaaaab-xz-19'));
    }

    /**
     * 위반 문구는 설정한 글자 수를 그대로 보여 준다(고정 숫자 아님).
     *
     * @scenario entry_point=register, guessable_setting=sequential, input_state=sequential_chars
     *
     * @effects violation_messages_carry_configured_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function violation_messages_carry_the_configured_count(): void
    {
        $this->setSecurity(['password_block_sequential_chars' => true, 'password_sequential_chars_length' => 5]);

        $errors = $this->errorsFor('x12345y!z');

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('5', $errors[0]);
    }
}
