<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

switch (currentUserRole()) {
    case 'super_admin':
        redirect('/UBMSConnect/superadmin/dashboard.php');
        break;
    case 'staff':
        redirect('/UBMSConnect/staff/dashboard.php');
        break;
    default:
        redirect('/UBMSConnect/host/dashboard.php');
}
