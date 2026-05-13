<?php

namespace Modules\Sirsoft\Board\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 게시판 팩토리
 *
 * 테스트용 게시판 데이터 생성
 */
class BoardFactory extends Factory
{
    /**
     * 모델 클래스
     */
    protected $model = Board::class;

    /**
     * 모델 기본 상태 정의
     */
    public function definition(): array
    {
        return [
            'name' => [
                'ko' => $this->faker->words(3, true),
                'en' => $this->faker->words(3, true),
            ],
            'slug' => $this->faker->unique()->lexify('test-board-??????'),
            'type' => 'basic',
            'is_active' => true,
            'per_page' => 20,
            'per_page_mobile' => 15,
            'order_by' => 'created_at',
            'order_direction' => 'DESC',
            'secret_mode' => 'disabled',
            'use_comment' => true,
            'use_reply' => true,
            'comment_order' => 'ASC',
            'show_view_count' => false,
            'use_report' => false,
            'use_file_upload' => false,
            'max_file_size' => 10485760, // 10MB
            'max_file_count' => 5,
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'],
            'min_title_length' => 2,
            'max_title_length' => 200,
            'min_content_length' => 10,
            'max_content_length' => 10000,
            'min_comment_length' => 2,
            'max_comment_length' => 1000,
            'notify_admin_on_post' => true,
            'notify_author' => true,
            'blocked_keywords' => [],
            'categories' => [],
        ];
    }
}
