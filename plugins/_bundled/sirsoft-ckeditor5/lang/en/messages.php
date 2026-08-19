<?php

return [
    'image' => [
        'not_found' => 'Image not found.',
    ],
    'upload' => [
        'required' => 'An image file is required.',
        'invalid_file' => 'Invalid file.',
        'not_image' => 'Only image files can be uploaded.',
        'invalid_mime' => 'Only jpeg, jpg, png, gif, webp formats are supported.',
        'too_large' => 'The file size exceeds the allowed limit (:max MB).',
        'forbidden' => 'You do not have permission to upload images.',
        'failed' => 'Image upload failed.',
    ],
    'uploads' => [
        'not_found' => 'Upload not found.',
        'file_delete_failed' => 'Failed to delete the image file. Please try again shortly.',
        'ids_required' => 'Please select the images to delete.',
        'ids_invalid' => 'Some of the selected images no longer exist. Refresh the list and select again.',
        'deleted' => 'The image has been deleted.',
        'bulk_deleted' => 'Deleted :deleted selected image(s).',
        'bulk_partially_deleted' => 'Deleted :deleted of the selected images; :failed could not be deleted and remain in the list.',
    ],
    'cleanup' => [
        'retention_disabled' => 'The retention period is less than 1 day, so no cleanup was performed.',
        'sources_incomplete' => 'Unreferenced-image cleanup was skipped because an inactive module may hide content references. Activate or remove the module, then run again.',
    ],
];
