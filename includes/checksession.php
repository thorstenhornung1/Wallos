<?php
require_once 'remember_me.php';
require_once 'auth_lifetime.php';

// Handle OIDC first
$secondsInMonth = wallos_auth_max_session_lifetime();
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

        // A restored OIDC session must prove it is still one the provider
        // accepts before it is granted access, exactly as a live one does above.
        // This is the long-idle gap: the PHP session was collected past the
        // point its access token should have been refreshed, so this first
        // request is where the refresh — and any definitive rejection — has to
        // happen, before the page is served. (Req 4)
        if (isset($_SESSION['from_oidc']) && $_SESSION['from_oidc'] === true) {
            require_once __DIR__ . '/oidc/session_guard.php';
            if (!wallos_oidc_current_session_is_valid($db)) {
                $db->close();
                header('Location: logout.php');
                exit();
            }
        }

        if ($userData['avatar'] == "") {
            $userData['avatar'] = "0";
        }
        $userId = $userData['id'];
        $main_currency = $userData['main_currency'];
    }
}


?>