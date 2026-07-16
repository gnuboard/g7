<?php

return [
    // Source types
    'source_type' => [
        'core' => 'Core',
        'module' => 'Module',
        'plugin' => 'Plugin',
    ],

    // Permission types
    'permission_type' => [
        'read' => 'Read/Download',
        'update' => 'Update',
        'delete' => 'Delete',
    ],

    // Default policies
    'default_policy' => [
        'public' => 'Public (All authenticated users)',
        'owner_only' => 'Owner and Admin only',
        'read_only' => 'Read only',
    ],

    // Validation messages
    'validation' => [
        'file_required' => 'Please select a file.',
        'file_invalid' => 'The file is not valid.',
        'file_max' => 'The file size cannot exceed :max MB.',
        'files_required' => 'Please select files.',
        'files_array' => 'The files format is invalid.',
        'files_min' => 'Please select at least one file.',
        'type_invalid' => 'The attachmentable type is invalid.',
        'id_invalid' => 'The attachmentable ID is invalid.',
        'role_ids_required' => 'Please select roles.',
        'role_ids_invalid' => 'The role IDs format is invalid.',
        'role_id_integer' => 'The role ID must be an integer.',
        'role_id_exists' => 'The selected role does not exist.',
        'order_required' => 'Order data is required.',
        'order_array' => 'The order data format is invalid.',
        'order_id_required' => 'The attachment ID is required.',
        'order_id_exists' => 'The selected attachment does not exist.',
        'order_value_required' => 'The order value is required.',
        'order_value_integer' => 'The order value must be an integer.',
        'permissions_required' => 'Permissions data is required.',
        'permissions_invalid' => 'The permissions format is invalid.',
        'permission_roles_invalid' => 'The permission roles format is invalid.',
    ],

    // Response messages
    'upload_success' => 'File uploaded successfully.',
    'upload_batch_success' => 'Files uploaded successfully.',
    'upload_failed' => 'File upload failed.',
    'delete_success' => 'File deleted successfully.',
    'delete_failed' => 'File deletion failed.',
    'reorder_success' => 'File order updated successfully.',
    'reorder_failed' => 'File reorder failed.',
    'sync_roles_success' => 'Roles synchronized successfully.',
    'sync_roles_failed' => 'Role synchronization failed.',
    'sync_permissions_success' => 'Permissions synchronized successfully.',
    'sync_permissions_failed' => 'Permission synchronization failed.',
    'not_found' => 'Attachment not found.',
    'access_denied' => 'You do not have permission to access this attachment.',
    'update_denied' => 'You do not have permission to update this attachment.',
    'delete_denied' => 'You do not have permission to delete this attachment.',
];
