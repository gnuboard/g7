<?php

namespace App\Exceptions;

use Exception;

class TemplateFileCopyException extends Exception
{
    private string $sourcePath;
    private string $destinationPath;
    private ?string $context;

    public function __construct(string $sourcePath, string $destinationPath, ?string $context = null)
    {
        $this->sourcePath = $sourcePath;
        $this->destinationPath = $destinationPath;
        $this->context = $context;

        // 다국어 함수 사용 (파라미터 치환)
        $errorMessage = __('exceptions.template_file_copy_failed', [
            'source' => $sourcePath,
            'destination' => $destinationPath,
        ]);

        // Context 정보가 있으면 메시지에 추가
        if ($context) {
            $errorMessage .= " ({$context})";
        }

        parent::__construct($errorMessage);
    }

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    public function getDestinationPath(): string
    {
        return $this->destinationPath;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }
}
