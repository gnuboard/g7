<?php

namespace App\Support;

/**
 * 설정이 직접 참조하는 첨부 ID 모음 — 고아 첨부 정리의 보호 목록
 *
 * 로고·기본 공유 이미지·파비콘처럼 소유자(attachmentable) 없이 설정값이 ID 로 참조하는 첨부는 판정식만으로는
 * 고아와 구분되지 않는다. 종전 보호 목록은 사이트 로고 한 줄을 손으로 적어, 뒤에 생긴 같은 모양의 설정
 * (`seo.og_image_default`)이 빠졌다 — 미참조 첨부 자동 정리를 켜면 보존기간 뒤 기본 공유 이미지가 지워졌다.
 *
 * 그래서 목록을 **선언에서 파생**한다:
 * - 코어: `config/settings/defaults.json` 의 `frontend_schema` 에서 `transform: attachments` 인 키 전부
 * - 템플릿: 템플릿 설정 저장 파일 전부(`templates` 디스크 `*\/settings/setting.json`)의 숫자 배열 값 —
 *   템플릿을 지웠어도(「데이터도 삭제」 미선택) 파일이 남아 있으면 보호한다(재설치 때 복원되므로)
 *
 * 숫자 배열을 넉넉히 보호해도 안전 쪽이다 — 남는 것은 지워지지 않은 첨부뿐이다.
 */
final class SettingAttachmentReferences
{
    /**
     * 보호할 첨부 ID 목록을 돌려줍니다.
     *
     * @return array<int, int> 첨부 ID 목록 (중복 제거)
     */
    public static function ids(): array
    {
        return array_values(array_unique(array_merge(self::core(), self::templates())));
    }

    /**
     * 코어 설정의 첨부 키(선언 파생)가 참조하는 ID.
     *
     * @return array<int, int> 첨부 ID 목록
     */
    public static function core(): array
    {
        $ids = [];

        foreach (self::coreAttachmentKeys() as $key) {
            $ids = array_merge($ids, self::extractIds(g7_core_settings($key, [])));
        }

        return $ids;
    }

    /**
     * 코어 설정 선언에서 첨부 키(`범주.키`)를 모읍니다.
     *
     * @return array<int, string> 키 목록 (예: general.site_logo)
     */
    public static function coreAttachmentKeys(): array
    {
        $path = config_path('settings/defaults.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $schema = is_array($data['frontend_schema'] ?? null) ? $data['frontend_schema'] : [];
        $keys = [];

        foreach ($schema as $category => $categorySchema) {
            if (! is_array($categorySchema) || str_starts_with((string) $category, '_')) {
                continue;
            }

            foreach ((array) ($categorySchema['fields'] ?? []) as $field => $fieldSchema) {
                if (is_array($fieldSchema) && ($fieldSchema['transform'] ?? null) === 'attachments') {
                    $keys[] = $category.'.'.$field;
                }
            }
        }

        return $keys;
    }

    /**
     * 템플릿 설정 저장 파일 전부(섹션별 `{템플릿}/settings/{섹션}.json`)가 참조하는 ID.
     *
     * 선언을 읽지 않는다 — 데이터를 남기고 지운 템플릿은 선언 파일이 없어도 저장 파일이 남는다(다시 설치하면 복원).
     * 서비스는 첨부 칸을 「정수만 담은 목록」으로 저장하므로 그 모양의 값만 ID 로 본다(목록 칸의 행·숫자 칸은 제외).
     *
     * @return array<int, int> 첨부 ID 목록
     */
    public static function templates(): array
    {
        $root = ExtensionStoragePath::template('');
        $files = glob(rtrim($root, '/\\').'/*/settings/*.json') ?: [];
        $ids = [];

        foreach ($files as $file) {
            $section = json_decode((string) @file_get_contents($file), true);

            if (! is_array($section)) {
                continue;
            }

            foreach ($section as $value) {
                if (is_array($value) && $value !== [] && array_is_list($value) && array_filter($value, fn ($item) => ! is_int($item)) === []) {
                    $ids = array_merge($ids, $value);
                }
            }
        }

        return $ids;
    }

    /**
     * 첨부 ID 배열(또는 첨부 객체 배열)에서 ID 만 뽑습니다 — 숫자 배열이 아니면 빈 배열.
     *
     * @param  mixed  $value  설정 값
     * @return array<int, int> 첨부 ID 목록
     */
    private static function extractIds(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;

            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
