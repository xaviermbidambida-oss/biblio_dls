<?php
session_start();

// 🔥 Supprimer toutes les variables de session
$_SESSION = [];

// 🔥 Détruire la session
session_destroy();

// 🔁 Redémarrer une session propre (mode invité)
session_start();
$_SESSION['guest'] = true;

// Redirection vers l'accueil
header("Location: index.php");
exit;