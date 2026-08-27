<?php

return [
    'errors' => [
        'not_found' => 'File not found: :path',
        'not_editable' => 'This file type (:extension) cannot be edited directly. Upload a replacement file instead.',
        'too_large_to_edit' => 'The file is too large to open in the editor. (limit: :limit bytes)',
        'read_failed' => 'Failed to read the file: :path',
        'write_failed' => 'Failed to save the file: :path',
        'delete_failed' => 'Failed to delete the file: :path',
        'directory_failed' => 'Failed to create the directory: :path',
        'invalid_path' => 'The path is not allowed: :path',
        'invalid_extension_target' => 'Target extension not found: :identifier',
        'extension_not_allowed' => 'File type not allowed: :extension',
        'upload_too_large' => 'The uploaded file is too large. (limit: :limit bytes)',
    ],

    'validation' => [
        'path_required' => 'Please specify the file path.',
        'content_present' => 'The content field is required.',
        'file_required' => 'Please choose a file to upload.',
        'file_invalid' => 'The upload is not a valid file.',
        'file_mimes' => 'File type not allowed. (allowed: :allowed)',
    ],

    'messages' => [
        'listed' => 'Loaded.',
        'saved' => 'Saved.',
        'uploaded' => 'File uploaded.',
        'deleted' => 'File deleted.',
    ],
];
