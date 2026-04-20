<?php

namespace Plugins\Sirsoft\Ckeditor5;

use App\Extension\AbstractPlugin;

/**
 * CKEditor 5 WYSIWYG 에디터 플러그인
 *
 * extension_point: "html_editor" 슬롯을 통해 기존 HtmlEditor를 교체합니다.
 * 미설치 시 기존 HtmlEditor로 자동 폴백됩니다.
 */
class Plugin extends AbstractPlugin
{
    /**
     * 플러그인 설정 스키마 반환
     *
     * @return array 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            'imageUpload' => [
                'type' => 'boolean',
                'default' => true,
                'label' => [
                    'ko' => '이미지 업로드',
                    'en' => 'Image Upload',
                ],
                'hint' => [
                    'ko' => '에디터에서 이미지 업로드 기능을 활성화합니다.',
                    'en' => 'Enable image upload functionality in the editor.',
                ],
                'required' => false,
            ],
            'imageMaxSizeMb' => [
                'type' => 'integer',
                'default' => 2,
                'label' => [
                    'ko' => '이미지 최대 크기 (MB)',
                    'en' => 'Image Max Size (MB)',
                ],
                'hint' => [
                    'ko' => '업로드 가능한 이미지의 최대 파일 크기입니다.',
                    'en' => 'Maximum file size for uploadable images.',
                ],
                'required' => false,
            ],
            'editorHeight' => [
                'type' => 'integer',
                'default' => 400,
                'label' => [
                    'ko' => '에디터 높이 (px)',
                    'en' => 'Editor Height (px)',
                ],
                'hint' => [
                    'ko' => '에디터 영역의 최소 높이입니다.',
                    'en' => 'Minimum height of the editor area.',
                ],
                'required' => false,
            ],
            'toolbar' => [
                'type' => 'enum',
                'options' => ['standard', 'minimal', 'full'],
                'default' => 'standard',
                'label' => [
                    'ko' => '툴바 유형',
                    'en' => 'Toolbar Type',
                ],
                'hint' => [
                    'ko' => '에디터 툴바 구성을 선택합니다.',
                    'en' => 'Select the editor toolbar configuration.',
                ],
                'required' => false,
            ],
        ];
    }

    /**
     * 플러그인이 관리하는 동적 테이블 목록 반환
     *
     * @return array 테이블명 배열
     */
    public function getDynamicTables(): array
    {
        return [
            'ckeditor5_image_uploads',
        ];
    }
}
