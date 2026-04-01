<?php

namespace Database\Factories;

use App\Enums\AttachmentSourceType;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 첨부파일 팩토리
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var string
     */
    protected $model = Attachment::class;

    /**
     * 기본 상태 정의
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = $this->faker->word() . '.jpg';
        $storedFilename = Str::uuid() . '.jpg';

        return [
            'attachmentable_type' => null,
            'attachmentable_id' => null,
            'source_type' => AttachmentSourceType::Core,
            'source_identifier' => null,
            'hash' => Str::random(12),
            'original_filename' => $filename,
            'stored_filename' => $storedFilename,
            'disk' => 'local',
            'path' => 'attachments/' . date('Y/m/d') . '/' . $storedFilename,
            'mime_type' => 'image/jpeg',
            'size' => $this->faker->numberBetween(1024, 1024 * 1024),
            'collection' => 'default',
            'order' => 0,
            'meta' => null,
            'created_by' => null,
        ];
    }

    /**
     * 이미지 첨부파일
     */
    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'mime_type' => $this->faker->randomElement(['image/jpeg', 'image/png', 'image/gif', 'image/webp']),
            'original_filename' => $this->faker->word() . '.' . $this->faker->randomElement(['jpg', 'png', 'gif', 'webp']),
            'meta' => [
                'width' => $this->faker->numberBetween(100, 1920),
                'height' => $this->faker->numberBetween(100, 1080),
            ],
        ]);
    }

    /**
     * PDF 첨부파일
     */
    public function pdf(): static
    {
        $filename = $this->faker->word() . '.pdf';
        $storedFilename = Str::uuid() . '.pdf';

        return $this->state(fn (array $attributes) => [
            'mime_type' => 'application/pdf',
            'original_filename' => $filename,
            'stored_filename' => $storedFilename,
            'path' => 'attachments/' . date('Y/m/d') . '/' . $storedFilename,
        ]);
    }

    /**
     * 문서 첨부파일
     */
    public function document(): static
    {
        $extension = $this->faker->randomElement(['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']);
        $filename = $this->faker->word() . '.' . $extension;
        $storedFilename = Str::uuid() . '.' . $extension;

        $mimeTypes = [
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];

        return $this->state(fn (array $attributes) => [
            'mime_type' => $mimeTypes[$extension],
            'original_filename' => $filename,
            'stored_filename' => $storedFilename,
            'path' => 'attachments/' . date('Y/m/d') . '/' . $storedFilename,
        ]);
    }

    /**
     * 특정 사용자가 업로드한 상태
     */
    public function uploadedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }

    /**
     * 특정 컬렉션 지정
     */
    public function inCollection(string $collection): static
    {
        return $this->state(fn (array $attributes) => [
            'collection' => $collection,
        ]);
    }

    /**
     * 모듈 소스 타입
     */
    public function fromModule(string $identifier): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => AttachmentSourceType::Module,
            'source_identifier' => $identifier,
        ]);
    }

    /**
     * 플러그인 소스 타입
     */
    public function fromPlugin(string $identifier): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => AttachmentSourceType::Plugin,
            'source_identifier' => $identifier,
        ]);
    }

    /**
     * 특정 모델에 첨부
     */
    public function attachedTo(string $type, int $id): static
    {
        return $this->state(fn (array $attributes) => [
            'attachmentable_type' => $type,
            'attachmentable_id' => $id,
        ]);
    }

    /**
     * 정렬 순서 지정
     */
    public function withOrder(int $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order' => $order,
        ]);
    }
}
