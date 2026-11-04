<?php
require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (current_user()) {
        log_activity('logout', 'Signed out');
    }
    forget_remember_token();
    $_SESSION = [];
    session_destroy();
    if (!empty($_POST['idle'])) {
        redirect('login?idle=1');
    }
}
redirect('login');
