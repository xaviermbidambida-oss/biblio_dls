<?php

/**
 * SESSION MANAGER CLEAN
 * Aucun conflit avec auth.php ou config.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Initialise session si vide (sécurité minimale)
 */
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = null;
}

if (!isset($_SESSION['user_role'])) {
    $_SESSION['user_role'] = 'guest';
}