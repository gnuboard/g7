<?php

namespace Tests\Unit\Seo;

use App\Seo\PipeRegistry;
use Tests\TestCase;

class PipeRegistryTest extends TestCase
{
    private PipeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new PipeRegistry;
    }

    // ============================================
    // 파이프 분리 (splitPipes)
    // ============================================

    /**
     * 단일 파이프를 정확하게 분리합니다.
     */
    public function test_split_pipes_single(): void
    {
        [$expr, $pipes] = PipeRegistry::splitPipes('value | uppercase');
        $this->assertSame('value', $expr);
        $this->assertSame(['uppercase'], $pipes);
    }

    /**
     * 다중 파이프 체인을 분리합니다.
     */
    public function test_split_pipes_chain(): void
    {
        [$expr, $pipes] = PipeRegistry::splitPipes('value | truncate(100) | uppercase');
        $this->assertSame('value', $expr);
        $this->assertSame(['truncate(100)', 'uppercase'], $pipes);
    }

    /**
     * OR 연산자(||)는 파이프로 인식하지 않습니다.
     */
    public function test_split_pipes_ignores_or_operator(): void
    {
        [$expr, $pipes] = PipeRegistry::splitPipes("a || b ? 'yes' : 'no'");
        $this->assertSame("a || b ? 'yes' : 'no'", $expr);
        $this->assertSame([], $pipes);
    }

    /**
     * 파이프 없는 표현식에서는 빈 배열을 반환합니다.
     */
    public function test_split_pipes_no_pipe(): void
    {
        [$expr, $pipes] = PipeRegistry::splitPipes('user.name');
        $this->assertSame('user.name', $expr);
        $this->assertSame([], $pipes);
    }

    /**
     * 문자열 내부의 | 는 파이프로 인식하지 않습니다.
     */
    public function test_split_pipes_inside_string_ignored(): void
    {
        [$expr, $pipes] = PipeRegistry::splitPipes("'a|b'");
        $this->assertSame("'a|b'", $expr);
        $this->assertSame([], $pipes);
    }

    // ============================================
    // hasPipes
    // ============================================

    /**
     * 파이프가 포함된 표현식을 감지합니다.
     */
    public function test_has_pipes_true(): void
    {
        $this->assertTrue(PipeRegistry::hasPipes('value | date'));
    }

    /**
     * 파이프가 없는 표현식에서 false를 반환합니다.
     */
    public function test_has_pipes_false(): void
    {
        $this->assertFalse(PipeRegistry::hasPipes('a || b'));
        $this->assertFalse(PipeRegistry::hasPipes('user.name'));
    }

    // ============================================
    // parsePipeExpression
    // ============================================

    /**
     * 인자 없는 파이프를 파싱합니다.
     */
    public function test_parse_pipe_no_args(): void
    {
        $result = PipeRegistry::parsePipeExpression('uppercase');
        $this->assertSame('uppercase', $result['name']);
        $this->assertSame([], $result['args']);
    }

    /**
     * 인자가 있는 파이프를 파싱합니다.
     */
    public function test_parse_pipe_with_args(): void
    {
        $result = PipeRegistry::parsePipeExpression('truncate(100)');
        $this->assertSame('truncate', $result['name']);
        $this->assertSame([100], $result['args']);
    }

    /**
     * 다중 인자가 있는 파이프를 파싱합니다.
     */
    public function test_parse_pipe_multiple_args(): void
    {
        $result = PipeRegistry::parsePipeExpression("truncate(50, '...')");
        $this->assertSame('truncate', $result['name']);
        $this->assertSame([50, '...'], $result['args']);
    }

    // ============================================
    // 날짜 파이프
    // ============================================

    /**
     * date 파이프가 날짜를 기본 포맷으로 변환합니다.
     */
    public function test_date_pipe_default_format(): void
    {
        $result = $this->registry->execute('date', '2024-01-15 14:30:00');
        $this->assertSame('2024-01-15', $result);
    }

    /**
     * date 파이프가 커스텀 포맷을 적용합니다.
     */
    public function test_date_pipe_custom_format(): void
    {
        $result = $this->registry->execute('date', '2024-01-15', ['YYYY/MM/DD']);
        $this->assertSame('2024/01/15', $result);
    }

    /**
     * datetime 파이프가 날짜+시간을 포맷합니다.
     */
    public function test_datetime_pipe(): void
    {
        $result = $this->registry->execute('datetime', '2024-01-15 14:30:45');
        $this->assertSame('2024-01-15 14:30', $result);
    }

    /**
     * null 날짜에 대해 빈 문자열을 반환합니다.
     */
    public function test_date_pipe_null(): void
    {
        $result = $this->registry->execute('date', null);
        $this->assertSame('', $result);
    }

    /**
     * relativeTime 파이프가 상대 시간을 반환합니다.
     */
    public function test_relative_time_pipe(): void
    {
        $result = $this->registry->execute('relativeTime', now()->subMinutes(5)->toDateTimeString());
        $this->assertNotEmpty($result);
    }

    // ============================================
    // 숫자 파이프
    // ============================================

    /**
     * number 파이프가 천단위 구분자를 추가합니다.
     */
    public function test_number_pipe_formatting(): void
    {
        $result = $this->registry->execute('number', 1234567);
        $this->assertSame('1,234,567', $result);
    }

    /**
     * number 파이프가 소수점 자릿수를 지정합니다.
     */
    public function test_number_pipe_with_decimals(): void
    {
        $result = $this->registry->execute('number', 1234.5, [2]);
        $this->assertSame('1,234.50', $result);
    }

    /**
     * number 파이프가 비숫자에 대해 원본을 반환합니다.
     */
    public function test_number_pipe_non_numeric(): void
    {
        $result = $this->registry->execute('number', 'abc');
        $this->assertSame('abc', $result);
    }

    // ============================================
    // 문자열 파이프
    // ============================================

    /**
     * truncate 파이프가 문자열을 자릅니다.
     */
    public function test_truncate_pipe(): void
    {
        $result = $this->registry->execute('truncate', '안녕하세요 반갑습니다 좋은 하루 되세요', [10]);
        $this->assertSame('안녕하세요 반갑습니...', $result);
    }

    /**
     * truncate 파이프가 짧은 문자열은 그대로 반환합니다.
     */
    public function test_truncate_pipe_short_string(): void
    {
        $result = $this->registry->execute('truncate', '짧은 텍스트', [100]);
        $this->assertSame('짧은 텍스트', $result);
    }

    /**
     * uppercase 파이프가 대문자로 변환합니다.
     */
    public function test_uppercase_pipe(): void
    {
        $result = $this->registry->execute('uppercase', 'hello');
        $this->assertSame('HELLO', $result);
    }

    /**
     * lowercase 파이프가 소문자로 변환합니다.
     */
    public function test_lowercase_pipe(): void
    {
        $result = $this->registry->execute('lowercase', 'HELLO');
        $this->assertSame('hello', $result);
    }

    /**
     * stripHtml 파이프가 HTML 태그를 제거합니다.
     */
    public function test_strip_html_pipe(): void
    {
        $result = $this->registry->execute('stripHtml', '<p>Hello <b>World</b></p>');
        $this->assertSame('Hello World', $result);
    }

    // ============================================
    // 기본값 파이프
    // ============================================

    /**
     * default 파이프가 null에 기본값을 적용합니다.
     */
    public function test_default_pipe_null(): void
    {
        $result = $this->registry->execute('default', null, ['Unknown']);
        $this->assertSame('Unknown', $result);
    }

    /**
     * default 파이프가 빈 문자열에 기본값을 적용합니다.
     */
    public function test_default_pipe_empty_string(): void
    {
        $result = $this->registry->execute('default', '', ['N/A']);
        $this->assertSame('N/A', $result);
    }

    /**
     * default 파이프가 값이 있으면 원본을 반환합니다.
     */
    public function test_default_pipe_with_value(): void
    {
        $result = $this->registry->execute('default', '홍길동', ['Unknown']);
        $this->assertSame('홍길동', $result);
    }

    /**
     * fallback 파이프가 null에만 폴백을 적용합니다.
     */
    public function test_fallback_pipe(): void
    {
        $this->assertSame('fallback', $this->registry->execute('fallback', null, ['fallback']));
        $this->assertSame('', $this->registry->execute('fallback', '', ['fallback'])); // 빈 문자열은 유지
    }

    // ============================================
    // 배열 파이프
    // ============================================

    /**
     * first 파이프가 배열의 첫 요소를 반환합니다.
     */
    public function test_first_pipe(): void
    {
        $this->assertSame('a', $this->registry->execute('first', ['a', 'b', 'c']));
    }

    /**
     * last 파이프가 배열의 마지막 요소를 반환합니다.
     */
    public function test_last_pipe(): void
    {
        $this->assertSame('c', $this->registry->execute('last', ['a', 'b', 'c']));
    }

    /**
     * join 파이프가 배열을 결합합니다.
     */
    public function test_join_pipe(): void
    {
        $result = $this->registry->execute('join', ['태그1', '태그2', '태그3']);
        $this->assertSame('태그1, 태그2, 태그3', $result);
    }

    /**
     * join 파이프가 커스텀 구분자로 결합합니다.
     */
    public function test_join_pipe_custom_separator(): void
    {
        $result = $this->registry->execute('join', ['a', 'b', 'c'], [' | ']);
        $this->assertSame('a | b | c', $result);
    }

    /**
     * length 파이프가 배열 길이를 반환합니다.
     */
    public function test_length_pipe_array(): void
    {
        $this->assertSame(3, $this->registry->execute('length', ['a', 'b', 'c']));
    }

    /**
     * length 파이프가 문자열 길이를 반환합니다.
     */
    public function test_length_pipe_string(): void
    {
        $this->assertSame(5, $this->registry->execute('length', '안녕하세요'));
    }

    // ============================================
    // 객체 파이프
    // ============================================

    /**
     * keys 파이프가 키 배열을 반환합니다.
     */
    public function test_keys_pipe(): void
    {
        $result = $this->registry->execute('keys', ['a' => 1, 'b' => 2]);
        $this->assertSame(['a', 'b'], $result);
    }

    /**
     * values 파이프가 값 배열을 반환합니다.
     */
    public function test_values_pipe(): void
    {
        $result = $this->registry->execute('values', ['a' => 1, 'b' => 2]);
        $this->assertSame([1, 2], $result);
    }

    /**
     * json 파이프가 JSON 문자열을 반환합니다.
     */
    public function test_json_pipe(): void
    {
        $result = $this->registry->execute('json', ['key' => '값']);
        $this->assertSame('{"key":"값"}', $result);
    }

    // ============================================
    // 다국어 파이프
    // ============================================

    /**
     * localized 파이프가 현재 로케일 값을 추출합니다.
     */
    public function test_localized_pipe(): void
    {
        $this->registry->setLocale('ko');
        $result = $this->registry->execute('localized', ['ko' => '상품명', 'en' => 'Product']);
        $this->assertSame('상품명', $result);
    }

    /**
     * localized 파이프가 지정된 로케일 값을 추출합니다.
     */
    public function test_localized_pipe_with_locale_arg(): void
    {
        $result = $this->registry->execute('localized', ['ko' => '상품명', 'en' => 'Product'], ['en']);
        $this->assertSame('Product', $result);
    }

    /**
     * localized 파이프가 문자열 값은 그대로 반환합니다.
     */
    public function test_localized_pipe_string(): void
    {
        $result = $this->registry->execute('localized', '일반 문자열');
        $this->assertSame('일반 문자열', $result);
    }

    // ============================================
    // 미등록 파이프 안전 처리
    // ============================================

    /**
     * 존재하지 않는 파이프는 원본 값을 반환합니다.
     */
    public function test_unknown_pipe_returns_original(): void
    {
        $result = $this->registry->execute('nonexistent', 'hello');
        $this->assertSame('hello', $result);
    }

    /**
     * has()가 등록된 파이프를 확인합니다.
     */
    public function test_has_pipe(): void
    {
        $this->assertTrue($this->registry->has('date'));
        $this->assertTrue($this->registry->has('number'));
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    // ============================================
    // filterBy 파이프
    // ============================================

    /**
     * filterBy 파이프가 허용 목록으로 필터링합니다.
     */
    public function test_filter_by_pipe(): void
    {
        $items = [
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
            ['id' => 3, 'name' => 'C'],
        ];

        $result = $this->registry->execute('filterBy', $items, [[1, 3], 'id']);
        $this->assertCount(2, $result);
        $this->assertSame('A', $result[0]['name']);
        $this->assertSame('C', $result[1]['name']);
    }
}
