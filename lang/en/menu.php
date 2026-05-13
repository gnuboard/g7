<?php

return [
    // Menu related messages
    'fetch_success' => 'Menu retrieved successfully.',
    'fetch_failed' => 'Failed to retrieve menu.',
    'active_fetch_failed' => 'Failed to retrieve active menus.',
    'create_success' => 'Menu created successfully.',
    'create_failed' => 'Failed to create menu.',
    'create_error' => 'An error occurred while creating menu.',
    'update_success' => 'Menu updated successfully.',
    'update_failed' => 'Failed to update menu.',
    'update_error' => 'An error occurred while updating menu.',
    'delete_success' => 'Menu deleted successfully.',
    'delete_failed' => 'Failed to delete menu.',
    'delete_error' => 'An error occurred while deleting menu.',
    'order_update_success' => 'Menu order updated successfully.',
    'order_update_failed' => 'Failed to update menu order.',
    'slug_already_exists' => 'Slug is already in use.',
    'parent_menu_not_found' => 'Parent menu does not exist.',
    'cannot_set_self_as_parent' => 'Cannot set itself as parent.',
    'cannot_delete_menu_with_children' => 'Cannot delete menu with children.',

    // Validation messages
    'validation' => [
        'is_active_boolean' => 'The active status must be a boolean value.',
        'sort_by_invalid' => 'The sort field is invalid.',
        'sort_order_invalid' => 'The sort order is invalid.',
        'filters_array' => 'Filters must be an array.',
        'filters_max' => 'Maximum of 10 filters can be specified.',
        'filter_field_required' => 'Filter field is required.',
        'filter_field_invalid' => 'Invalid filter field.',
        'filter_value_required' => 'Filter value is required.',
        'filter_value_max' => 'Filter value cannot exceed 255 characters.',
        'filter_operator_invalid' => 'Invalid filter operator.',
    ],
];
