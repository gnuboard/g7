<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 실패한 설치가 남긴 활성 디렉토리를 되돌리는 헬퍼
 *
 * 설치 흐름은 원본(`_pending`/`_bundled`)을 활성 디렉토리로 **먼저 복사한 뒤** 확장을
 * 로드해 코어 버전·의존성·언어 경로 등을 검증한다. 검증에 로드된 확장 인스턴스가 필요해
 * 순서를 뒤집을 수 없는데, 그 검증이 실패하면 방금 만든 활성 디렉토리가 그대로 남는다.
 *
 * 남은 디렉토리는 DB 행이 없어 목록에도 뜨지 않고 번들 병합에도 참여하지 않는다 — 즉
 * 오류도 경고도 없이 디스크만 점유하는 고아가 된다. 더 나쁜 것은 다음 설치 시도가 그
 * 디렉토리를 "이미 있는 설치본" 으로 보고 원본 복사를 건너뛸 수 있다는 점이다.
 *
 * 이 헬퍼는 **이번 호출이 만든 디렉토리만** 지운다. 이미 설치돼 있던 확장을 `--force` 로
 * 다시 설치하다 실패한 경우에는 운영자의 기존 설치본이므로 손대지 않는다.
 */
final class ExtensionInstallRollbackHelper
{
    /**
     * 이번 설치가 만든 활성 디렉토리를 제거합니다.
     *
     * @param  string  $activePath  활성 디렉토리 절대경로
     * @param  bool  $existedBefore  이번 설치 이전에 그 디렉토리가 있었는지
     * @param  string  $identifier  확장 식별자 (로그용)
     * @param  string  $type  확장 유형 (module|plugin|template, 로그용)
     * @return bool 실제로 제거했으면 true
     */
    public static function removeIfCreatedByThisInstall(
        string $activePath,
        bool $existedBefore,
        string $identifier,
        string $type,
    ): bool {
        if ($existedBefore || ! File::isDirectory($activePath)) {
            return false;
        }

        $removed = File::deleteDirectory($activePath);

        Log::warning('설치 실패로 활성 디렉토리를 되돌렸습니다.', [
            'type' => $type,
            'identifier' => $identifier,
            'path' => $activePath,
            'removed' => $removed,
        ]);

        return $removed;
    }
}
