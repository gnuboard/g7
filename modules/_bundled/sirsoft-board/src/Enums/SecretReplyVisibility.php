<?php

namespace Modules\Sirsoft\Board\Enums;

/**
 * 비밀 답변 열람 범위 (게시판 설정 `secret_reply_visibility`)
 *
 * 비밀글 스레드에서 원글·답변 작성자와 비밀번호 인증자가 서로의 글을 어디까지 보는지를 게시판별로
 * 정합니다. 비밀글 조회 권한 자체(역할·스코프)는 이 설정과 무관하게 그대로 적용됩니다.
 */
enum SecretReplyVisibility: string
{
    /**
     * 경로 규칙 (기본값) — 열 수 있는 글의 하위 전체 + 자기 글의 조상 경로
     */
    case ThreadPath = 'thread_path';

    /**
     * 하위만 — 열 수 있는 글의 하위 전체 (답변 작성자는 조상을 보지 못함)
     */
    case DescendantsOnly = 'descendants_only';

    /**
     * 답변마다 따로 — 작성자·비밀번호 인증자·권한자만 (경로 규칙 없음)
     */
    case PerPost = 'per_post';

    /**
     * 자기 글의 조상 경로까지 여는지 여부
     *
     * @return bool 조상 열람 여부
     */
    public function opensAncestors(): bool
    {
        return $this === self::ThreadPath;
    }

    /**
     * 열 수 있는 글의 하위를 여는지 여부
     *
     * @return bool 하위 열람 여부
     */
    public function opensDescendants(): bool
    {
        return $this !== self::PerPost;
    }

    /**
     * 모든 값 배열 반환
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 값인지 확인
     *
     * @param  string  $value  검증할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 번역 키를 반환합니다.
     *
     * @return string 번역 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-board::enums.secret_reply_visibility.'.$this->value;
    }

    /**
     * 다국어 라벨 반환
     *
     * @return string 번역된 라벨
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 모든 값을 배열로 반환 (value, label 포함)
     *
     * @return array<array{value: string, label: string}>
     */
    public static function toArray(): array
    {
        return array_map(
            fn (self $item) => [
                'value' => $item->value,
                'label' => $item->label(),
            ],
            self::cases()
        );
    }
}
