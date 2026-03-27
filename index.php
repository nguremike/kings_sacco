<?php
require_once 'config/config.php';
require_once 'password_functions.php';


// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
} else {
    header('Location: login.php');
    exit();
}
