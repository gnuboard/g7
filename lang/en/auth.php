<?php

return [
    // Authentication related messages
    'login_success' => 'Login successful.',
    'login_failed' => 'Authentication failed.',
    'register_success' => 'Registration successful.',
    'register_failed' => 'Registration failed.',
    'logout_success' => 'Logout successful.',
    'logout_all_devices_success' => 'Logged out from all devices.',
    'password_reset_success' => 'Password has been successfully reset.',
    'password_reset_failed' => 'Password reset failed.',
    'invalid_credentials' => 'Invalid credentials.',
    'admin_login_success' => 'Admin login successful.',
    'admin_required' => 'Administrator privileges required.',
    'unauthenticated' => 'Authentication required.',
    'permission_denied' => 'Permission denied.',
    'layout_permission_denied' => 'You do not have permission to access this layout. (Required: :required_permissions)',
    'layout_guest_permission_denied' => 'Authentication required to access this layout. (Required: :required_permissions)',
    'invalid_permission_type' => 'Invalid permission type.',
    'role_denied' => 'Role access denied.',
    'email_not_registered' => 'Email address is not registered.',
    'guest_permission_denied' => 'Guests are not allowed to perform this action. Login required.',
    'scope_denied' => 'You do not have access to this resource.',
    'scope_type_all' => 'All',
    'scope_type_self' => 'Self only',
    'scope_type_role' => 'Shared roles',
    'invalid_token' => 'Invalid authentication token.',
    'token_expired' => 'Authentication token has expired. Please login again.',
    'user_not_found' => 'User not found.',
    'account_inactive' => 'Your account is inactive. Please contact the administrator.',
    'account_blocked' => 'Your account has been blocked. Please contact the administrator.',
    'account_withdrawn' => 'This account has been withdrawn.',

    // Password reset
    'reset_token_invalid' => 'Invalid password reset token.',
    'reset_token_expired' => 'Password reset token has expired. Please request again.',
    'password_reset_email_sent' => 'Password reset email has been sent.',
    'email_send_failed' => 'Failed to send email. Please try again later.',

    // Mail template messages (template body managed in DB)
    'mail_template_not_found' => 'Mail template not found: :type',
    'mail_template_updated' => 'Mail template has been updated.',
    'mail_template_toggled' => 'Mail template active status has been changed.',
    'mail_template_reset' => 'Mail template has been reset to default.',
    'mail_template_reset_not_found' => 'Default template data not found: :type',
];
