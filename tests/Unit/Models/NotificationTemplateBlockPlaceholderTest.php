<?php

namespace Tests\Unit\Models;

use App\Models\NotificationTemplate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 블록 HTML 을 값으로 받는 자리표시자가 문단(<p>) 안에 들어가지 않는지 검증합니다.
 *
 * 메일 본문 편집기(CKEditor)는 최상위에 놓인 글자를 문단으로 감싸므로, 기본 본문의
 * `{notice_sections}{privacy_policy_link}` 가 저장 뒤 `<p>{notice_sections}{privacy_policy_link}</p>` 가 된다.
 * 값이 `<h3>…</h3><p>…</p>` 같은 블록 HTML 이면 치환 결과가 `<p><h3>` 처럼 문단 안에 블록이 든 잘못된 HTML 이 되어
 * 메일 프로그램마다 다르게(문단이 끊기거나 여백이 겹치게) 그려졌다(7.1.0 실측 M1-O8).
 *
 * @effects block_placeholder_paragraph_unwrapped
 */
class NotificationTemplateBlockPlaceholderTest extends TestCase
{
    /**
     * 본문 하나로 템플릿을 만듭니다.
     *
     * @param  string  $body  한국어 본문
     * @return NotificationTemplate 템플릿
     */
    private function template(string $body): NotificationTemplate
    {
        $template = new NotificationTemplate;
        $template->subject = ['ko' => '제목'];
        $template->body = ['ko' => $body];

        return $template;
    }

    /**
     * 자리표시자만 든 문단에 블록 HTML 이 들어가면 문단을 걷는다.
     *
     * @return void 반환값 없음
     *
     * @scenario wrapper=paragraph_only_placeholders
     *
     * @effects block_placeholder_paragraph_unwrapped
     */
    #[Test]
    public function paragraph_holding_only_block_placeholders_is_unwrapped(): void
    {
        $rendered = $this->template('<p>{intro}</p><p>{notice_sections}&nbsp;{privacy_policy_link}</p>')->replaceVariables([
            'intro' => '안내',
            'notice_sections' => '<h3>주문 정보</h3><p>상품 주문 시</p>',
            'privacy_policy_link' => '<p><a href="https://example.com/p">개인정보처리방침</a></p>',
        ], 'ko');

        $this->assertSame(
            '<p>안내</p><h3>주문 정보</h3><p>상품 주문 시</p>&nbsp;<p><a href="https://example.com/p">개인정보처리방침</a></p>',
            $rendered['body'],
        );
        $this->assertDoesNotMatchRegularExpression('/<p>\s*<(h3|p)\b/', $rendered['body']);
    }

    /**
     * 값이 인라인 글자면 문단을 그대로 둔다(일반 변수 문단은 바뀌지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario wrapper=paragraph_only_placeholders
     *
     * @effects block_placeholder_paragraph_unwrapped
     */
    #[Test]
    public function paragraph_with_inline_values_is_kept(): void
    {
        $rendered = $this->template('<p>{recipient_name}</p><p>{a}{b}</p>')->replaceVariables(['recipient_name' => '홍길동', 'a' => '<strong>A</strong>', 'b' => ''], 'ko');

        $this->assertSame('<p>홍길동</p><p><strong>A</strong></p>', $rendered['body']);
    }

    /**
     * 자리표시자 옆에 글자가 함께 든 문단, 최상위 자리표시자는 손대지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario wrapper=paragraph_with_text
     *
     * @effects block_placeholder_paragraph_unwrapped
     */
    #[Test]
    public function paragraph_with_text_and_top_level_placeholder_are_untouched(): void
    {
        $rendered = $this->template('<p>처리 내역: {notice_sections}</p>{notice_sections}')->replaceVariables(['notice_sections' => '<h3>T</h3>'], 'ko');

        $this->assertSame('<p>처리 내역: <h3>T</h3></p><h3>T</h3>', $rendered['body']);
    }
}
