<?php
require_once __DIR__ . '/config/config.php';

// Redirect to login or dashboard
if (isLoggedIn()) {
    redirect('/dashboard.php');
} else {
    redirect('/login.php');
}
