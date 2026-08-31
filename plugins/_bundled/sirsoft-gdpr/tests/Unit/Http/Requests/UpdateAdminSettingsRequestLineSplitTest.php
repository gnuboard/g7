<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Http\Requests;

use Plugins\Sirsoft\Gdpr\Http\Requests\UpdateAdminSettingsRequest;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;
use ReflectionMethod;

/**
 * 차단 도메인 입력의 줄 분리가 한글을 글자 중간에서 쪼개지 않는다.
 *
 * 운영자는 카테고리별 차단 도메인을 **여러 줄 텍스트**로 입력하고, 요청 클래스가 그것을
 * 줄 단위 배열로 정규화해 저장한다. 그 분리에 PCRE `\R` 을 쓰면 UTF-8 모드가 아닌 한
 * **바이트 0x85**(NEL)도 개행으로 읽힌다. 3바이트 한글 중 마지막 바이트가 0x85 인 글자가
 * 흔해(`종` = EC A2 85), 그런 글자가 든 줄은 한가운데서 갈려 **깨진 조각 두 개**로 저장된다.
 *
 * 예외도 경고도 나지 않는다 — 저장은 성공하고 화면에는 깨진 글자가 남을 뿐이라, 값을
 * 눈으로 보지 않으면 드러나지 않는다.
 *
 * @scenario input_shape=multiline_text, charset=korean_nel_byte
 */
class UpdateAdminSettingsRequestLineSplitTest extends PluginTestCase
{
    /**
     * 정규화 결과를 얻는다 (비공개 메서드를 리플렉션으로 부른다).
     *
     * @param  array<string, string>  $input  카테고리 → 여러 줄 입력
     * @param  list<string>  $categories  허용 카테고리 목록
     * @return array<string, list<string>>|null 정규화 결과
     */
    private function normalize(array $input, array $categories): ?array
    {
        $method = new ReflectionMethod(UpdateAdminSettingsRequest::class, 'normalizeBlockedDomains');
        $method->setAccessible(true);

        return $method->invoke(new UpdateAdminSettingsRequest, $input, $categories);
    }

    public function test_korean_line_containing_nel_byte_is_not_split_mid_character(): void
    {
        // `종` 은 EC A2 85 — 마지막 바이트가 NEL 이다. 바이트를 직접 쌓아 이 파일의
        // 인코딩이 결과를 흐리지 않게 한다.
        $jong = chr(0xEC).chr(0xA2).chr(0x85);

        $result = $this->normalize(
            ['analytics' => "a.example.com\n".$jong."류.example.com\nb.example.com"],
            ['analytics'],
        );

        $this->assertSame(
            ['analytics' => ['a.example.com', $jong.'류.example.com', 'b.example.com']],
            $result,
            '한글이 든 줄이 글자 중간에서 쪼개졌다 — 줄 분리가 실제 줄 종결자만 보지 않는다'
        );
    }

    /**
     * @scenario input_shape=multiline_text, charset=ascii
     *
     * @effects operator_multiline_input_is_split_only_at_real_line_terminators
     */
    public function test_real_line_terminators_are_still_split(): void
    {
        $result = $this->normalize(
            ['analytics' => "a.example.com\r\nb.example.com\rc.example.com\n\n  d.example.com  "],
            ['analytics'],
        );

        $this->assertSame(
            ['analytics' => ['a.example.com', 'b.example.com', 'c.example.com', 'd.example.com']],
            $result,
            'CRLF·CR·LF 와 빈 줄·주변 공백 처리가 종전과 달라졌다'
        );
    }
}
