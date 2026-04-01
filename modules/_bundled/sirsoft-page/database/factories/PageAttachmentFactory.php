<?php

namespace Modules\Sirsoft\Page\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;

/**
 * 페이지 첨부파일 팩토리
 *
 * 테스트용 페이지 첨부파일 데이터 생성
 */
class PageAttachmentFactory extends Factory
{
    /**
     * 모델 클래스
     */
    protected $model = PageAttachment::class;

    /**
     * 모델 기본 상태 정의
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'page_id' => Page::factory(),
            'hash' => $this->faker->lexify('????????????'),
            'original_filename' => $this->faker->word().'.pdf',
            'stored_filename' => $this->faker->uuid().'.pdf',
            'disk' => 'local',
            'path' => 'pages/attachments/'.$this->faker->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => $this->faker->numberBetween(1024, 10485760),
            'collection' => 'attachments',
            'order' => 0,
            'meta' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * 이미지 첨부파일로 설정
     *
     * @return static
     */
    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'original_filename' => $this->faker->word().'.jpg',
            'stored_filename' => $this->faker->uuid().'.jpg',
            'path' => 'pages/attachments/'.$this->faker->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'meta' => [
                'width' => 800,
                'height' => 600,
            ],
        ]);
    }

    /**
     * 임시 첨부파일로 설정
     *
     * @return static
     */
    public function temporary(): static
    {
        $tempKey = $this->faker->uuid();

        return $this->state(fn (array $attributes) => [
            'page_id' => null,
            'temp_key' => $tempKey,
            'path' => "temp/{$tempKey}/".$this->faker->uuid().'.pdf',
        ]);
    }
}
