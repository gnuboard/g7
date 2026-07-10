<?php

namespace Plugins\Sirsoft\Ckeditor5\Support\ApiDoc;

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use App\Models\User;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;

/**
 * sirsoft-ckeditor5 API 문서 실측용 완전 샘플 시더
 *
 * 이미지 서빙 GET(`images/{hash}`)은 route 제약 `[a-f0-9]{12}` 의 12자리 소문자 16진수
 * `hash` 로 이미지 레코드를 조회한다. 실측/요청 예시 시 `{hash}` 를 실제 해시로 치환하지
 * 못하면 상세 GET 의 요청 예시에 `{hash}` placeholder 가 그대로 남으므로(결함 4), 이 시더는
 * 고정 해시의 이미지 업로드 레코드 1건을 멱등 생성하고 `images` 도메인의 `path_params` 맵으로
 * `{hash}` 를 실제 값으로 정확 일치 치환한다.
 *
 * 성공 응답은 이미지 바이너리 스트림(envelope 아님)이며, 실제 스토리지 파일이 없으면
 * `serve()` 가 null 을 반환해 404 JSON 이 되므로 응답 예시는 실측 제외로 남는다(정상 —
 * 바이너리 서빙 계약). 이 시더의 목적은 요청 예시의 path 치환이다.
 *
 * `api:docgen --scope=plugin:sirsoft-ckeditor5 --seed` 실행 시 커맨드가 규약 위치
 * (`Plugins\Sirsoft\Ckeditor5\Support\ApiDoc\ApiDocSampleService`)로 자동 발견한다.
 */
class ApiDocSampleService implements ApiDocSampleSeeder
{
    /**
     * @var string 샘플 이미지 해시 (12자리 소문자 16진수 — 라우트 제약 `[a-f0-9]{12}` 충족)
     */
    private const SAMPLE_HASH = 'a1b2c3d4e5f6';

    /**
     * 이미지 업로드 완전 샘플을 멱등 생성하고 `images` 도메인의 path_params 맵을 반환합니다.
     *
     * @return array<string, array{model: class-string, key: string, value: string, path_params?: array<string, string>}> 도메인 => 대표 레코드 정보
     */
    public function seed(): array
    {
        if (! class_exists(Ckeditor5ImageUpload::class)) {
            return [];
        }

        $image = $this->seedImage();

        $entry = [
            'model' => Ckeditor5ImageUpload::class,
            'key' => 'hash',
            'value' => (string) $image->hash,
            'path_params' => [
                'hash' => (string) $image->hash,
            ],
        ];

        return [
            'images' => $entry,
        ];
    }

    /**
     * 고정 해시의 이미지 업로드 레코드 1건을 멱등 생성합니다.
     *
     * @return Ckeditor5ImageUpload 샘플 이미지 업로드
     */
    private function seedImage(): Ckeditor5ImageUpload
    {
        $actor = User::query()->orderBy('id')->first();

        return Ckeditor5ImageUpload::query()->firstOrCreate(
            ['hash' => self::SAMPLE_HASH],
            [
                'original_name' => 'apidoc-sample.png',
                'file_path' => 'ckeditor5/apidoc-sample.png',
                'storage_disk' => 'public',
                'file_size' => 1024,
                'mime_type' => 'image/png',
                'uploaded_by' => $actor?->getKey(),
            ]
        );
    }
}
