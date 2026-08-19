<?php
require_once 'remember_me.php';

// Handle OIDC first
$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,             
        'httponly' => true,          
        'samesite' => 'Lax'          
    ]);
    session_start();
}

if (isset($_GET['code']) && isset($_GET['state'])) {
    // This request is coming from the OIDC login flow
    require_once __DIR__ . '/oidc/consume_oidc_callback.php';
} else {
    if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
        $username = $_SESSION['username'];
        $main_currency = $_SESSION['main_currency'];
        $sql = "SELECT * FROM \"user\" WHERE username = :username";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $userData = $result->fetchArray(SQLITE3_ASSOC);
        $userId = $userData['id'];

        if ($userData === false) {
            header('Location: logout.php');
            exit();
        } else {
            $_SESSION['userId'] = $userData['id'];
        }

        // An OIDC session the provider has ended must stop working here too,
        // and it has to stop on the next request rather than whenever the
        // session would have expired. The row is what makes it current.
        require_once __DIR__ . '/oidc/session_guard.php';
        if (!wallos_oidc_current_session_is_valid($db)) {
            header('Location: logout.php');
            exit();
        }

        if ($userData['avatar'] == "") {
            $userData['avatar'] = "0";
        }
    } else {
        if (!isset($_COOKIE['wallos_login'])) {
            $db->close();
            header("Location: login.php");
            exit();
        }

        $userData = restoreSessionFromRememberMeCookie($db);
        if ($userData === false) {
            $db->close();
            header("Location: logout.php");
            exit();
        }

        if ($userData['avatar'] == "") {
            $userData['avatar'] = "0";
        }
        $userId = $userData['id'];
        $main_currency = $userData['main_currency'];
    }
}


?>