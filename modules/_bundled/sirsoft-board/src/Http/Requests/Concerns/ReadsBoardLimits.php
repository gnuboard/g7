<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

/**
 * 게시판 제한값(config('sirsoft-board.limits')) 조회 트레이트
 *
 * 게시판 관련 FormRequest 들이 각자 `$limits['x'] ?? 기본값` 블록을 복제하면서
 * 파일마다 폴백 값이 어긋나는 드리프트가 발생했습니다. 폴백 기본값을 이 트레이트
 * 한 곳에 모아 config 가 제한값의 단일 출처(SSoT)가 되도록 합니다.
 */
trait ReadsBoardLimits
{
    /**
     * config 미정의 시 사용할 제한값 기본치
     *
     * config/board.php 의 limits 섹션과 동일한 값을 유지해야 합니다.
     */
    private const BOARD_LIMIT_DEFAULTS = [
        'per_page_min' => 5,
        'per_page_max' => 100,

        'min_title_length_min' => 0,
        'min_title_length_max' => 200,
        'max_title_length_min' => 1,
        'max_title_length_max' => 1000,

        'min_content_length_min' => 0,
        'min_content_length_max' => 10000,
        'max_content_length_min' => 1,
        'max_content_length_max' => 100000,

        'min_comment_length_min' => 0,
        'min_comment_length_max' => 1000,
        'max_comment_length_min' => 1,
        'max_comment_length_max' => 10000,

        'max_file_size_min' => 1,
        'max_file_size_max' => 200,
        'max_file_count_min' => 1,
        'max_file_count_max' => 20,

        'category_max' => 50,

        'max_reply_depth_min' => 1,
        'max_reply_depth_max' => 10,
        'max_comment_depth_min' => 0,
        'max_comment_depth_max' => 10,

        'auto_hide_threshold_min' => 0,
        'auto_hide_threshold_max' => 100,
        'daily_report_limit_min' => 0,
        'daily_report_limit_max' => 100,
        'rejection_limit_count_min' => 0,
        'rejection_limit_count_max' => 50,
        'rejection_limit_days_min' => 1,
        'rejection_limit_days_max' => 365,

        'post_cooldown_seconds_min' => 0,
        'post_cooldown_seconds_max' => 3600,
        'comment_cooldown_seconds_min' => 0,
        'comment_cooldown_seconds_max' => 3600,
        'report_cooldown_seconds_min' => 0,
        'report_cooldown_seconds_max' => 3600,
        'view_count_cache_ttl_min' => 60,
        'view_count_cache_ttl_max' => 604800,

        'new_display_hours_min' => 0,
        'new_display_hours_max' => 720,

        'attachment_purge_retention_days_min' => 1,
        'attachment_purge_retention_days_max' => 3650,
    ];

    /**
     * 게시판 제한값 전체를 반환합니다.
     *
     * config 값이 기본치를 덮어씁니다.
     *
     * @return array<string, int> 제한값 맵
     */
    protected function boardLimits(): array
    {
        $configured = config('sirsoft-board.limits', []);

        return array_merge(
            self::BOARD_LIMIT_DEFAULTS,
            is_array($configured) ? $configured : []
        );
    }

    /**
     * 특정 제한값을 반환합니다.
     *
     * @param  string  $key  제한값 키 (예: per_page_min)
     * @param  int|null  $default  키가 없을 때 쓸 값 (미지정 시 0)
     * @return int 제한값
     */
    protected function boardLimit(string $key, ?int $default = null): int
    {
        return (int) ($this->boardLimits()[$key] ?? $default ?? 0);
    }
}
