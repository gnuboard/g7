<?php

return [
    // Role management messages
    'fetch_success' => 'Role information has been fetched successfully.',
    'fetch_failed' => 'Failed to fetch role information.',
    'create_success' => 'Role has been created successfully.',
    'create_failed' => 'Failed to create role.',
    'update_success' => 'Role has been updated successfully.',
    'update_failed' => 'Failed to update role.',
    'delete_success' => 'Role has been deleted successfully.',
    'delete_failed' => 'Failed to delete role.',
    'system_role_delete_error' => 'System roles cannot be deleted.',

    // Validation messages
    'validation' => [
        'name_required' => 'Role name is required.',
        'identifier_required' => 'Identifier is required.',
        'identifier_format' => 'Identifier must start with a lowercase letter and contain only lowercase letters, numbers, and underscores.',
        'identifier_unique' => 'This identifier is already in use.',
        'identifier_max' => 'Identifier must not exceed 100 characters.',
        'permission_ids_array' => 'Permission list must be an array.',
        'permission_ids_exists' => 'One or more selected permissions are invalid.',
        'permission_ids_integer' => 'Permission ID must be an integer.',
    ],

    // Error messages
    'errors' => [
        'system_role_delete' => 'System roles cannot be deleted.',
        'extension_owned_role_delete' => 'Roles owned by extensions (modules/plugins) cannot be deleted. They will be cleaned up when the extension is removed.',
    ],
];
