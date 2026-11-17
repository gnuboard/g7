<?php

namespace App\Extension\Storage\Concerns;

use App\Helpers\InlineMediaPolicy;
use App\Support\PublicAssetDisk;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\MimeTypes;

/**
 * 공개 디스크 쓰기의 최후 관문과 객체 저장소 형식 정보 — 코어 저장소 드라이버 셋이 함께 쓴다.
 *
 * 공개 디스크의 파일은 서버를 거치지 않고 직접 주소로 나간다. 서비스 계층이 디스크를 고를 때
 * 「공개 디스크로 내보낼 확장자」 를 보지만, 그 판정이 빠진 경로가 생겨도 그대로 열리는 파일이
 * 공개 디스크에 놓이지 않게 저장 드라이버가 한 번 더 막는다. 조용히 다른 디스크로 돌리지 않고
 * 거부(false)하고 경고 로그를 남긴다 — 호출측이 실패를 알아야 고칠 수 있다.
 *
 * 쓰는 클래스는 `$disk`(디스크 이름) 프로퍼티를 가져야 한다.
 *
 * @since 7.1.6
 */
trait GuardsPublicDiskWrites
{
    /**
     * 이 저장소를 만든 확장의 기본 디스크 (코어 저장소는 null)
     *
     * `withDisk()` 로 디스크를 갈아 끼워도 바뀌지 않는다 — 지금 쓰는 디스크가 확장의 원래 저장 공간인지 가린다.
     */
    private ?string $homeDisk = null;

    /**
     * 이 쓰기를 거부해야 하면 경고 로그를 남기고 true 를 반환합니다.
     *
     * @param  string  $category  저장 카테고리
     * @param  string  $fullPath  디스크 기준 전체 경로
     * @return bool 거부해야 하면 true
     */
    private function refusesPublicDiskWrite(string $category, string $fullPath): bool
    {
        if (! PublicAssetDisk::refusesWrite($this->disk, $fullPath, $this->homeDisk)) {
            return false;
        }

        Log::warning('공개 디스크로 내보낼 수 없는 확장자의 파일 쓰기를 거부했습니다.', [
            'disk' => $this->disk,
            'category' => $category,
            'path' => $fullPath,
        ]);

        return true;
    }

    /**
     * 객체 저장소(S3)에 쓸 때 함께 실을 형식 옵션을 만듭니다.
     *
     * 객체 저장소의 직접 주소에서는 서버가 응답 헤더를 붙일 수 없으므로, 올릴 때 형식을 고정한다.
     * - `ContentType`: 내용 기준(문자열 내용일 때). 스트림은 저장소 어댑터의 판정에 맡긴다.
     * - `ContentDisposition: attachment`: 인라인으로 열어도 되는 래스터 이미지가 아닐 때.
     *
     * 객체 저장소가 아닌 디스크에는 아무 옵션도 싣지 않는다.
     *
     * @param  string  $fullPath  디스크 기준 전체 경로
     * @param  mixed  $content  쓸 내용 (문자열이면 내용으로 형식을 판정)
     * @return array<string, string> 저장 옵션
     */
    private function objectStorageWriteOptions(string $fullPath, mixed $content): array
    {
        if (config("filesystems.disks.{$this->disk}.driver") !== 's3') {
            return [];
        }

        $options = [];
        $mimeType = null;

        if (is_string($content) && class_exists(\finfo::class)) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
            if (is_string($detected) && $detected !== '') {
                $mimeType = strtolower($detected);
                $options['ContentType'] = $mimeType;
            }
        }

        // 내용으로 판정하지 못했으면(스트림 등) 경로 확장자로 래스터 여부만 가린다.
        $mimeType ??= MimeTypes::getDefault()->getMimeTypes(
            strtolower(pathinfo($fullPath, PATHINFO_EXTENSION))
        )[0] ?? null;

        if (! InlineMediaPolicy::isInlineImage($mimeType)) {
            $options['ContentDisposition'] = 'attachment';
        }

        return $options;
    }
}
