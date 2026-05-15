<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — TEMPLATE DE PAGE v3.0                       ║
 * ║  Copiez ce fichier comme base pour chaque nouvelle page        ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 * SEULE LIGNE OBLIGATOIRE dans chaque fichier PHP du projet :
 *   require_once __DIR__ . '/../includes/config.php';
 *
 * Elle charge automatiquement :
 *   - La connexion BD (getDB())
 *   - AppSettings (tous les paramètres)
 *   - Tous les helpers (e(), csrfToken(), dls_date(), dls_money()…)
 *   - Les constantes DLS_* (DLS_THEME, DLS_LANG, DLS_CURRENCY…)
 */

// ── SEULE LIGNE À AJOUTER EN HAUT DE CHAQUE FICHIER ──────────────────
require_once __DIR__ . '/../includes/config.php';

// ── Protection de la page (optionnel selon la page) ──────────────────
requireLogin();            // Redirige vers login.php si non connecté
// requireRole('admin');   // Redirige si rôle insuffisant
// requireRole('admin', 'journaliste'); // Accepte plusieurs rôles

// ── Votre logique métier ──────────────────────────────────────────────
// Ex. : Récupérer des livres avec pagination depuis les settings
[$limit, $offset] = dls_paginate((int)($_GET['page'] ?? 1));
$livres = dbFetchAll(
    "SELECT * FROM livres WHERE statut = 'disponible' ORDER BY created_at DESC LIMIT ? OFFSET ?",
    [$limit, $offset]
);
$totalLivres = dbCount("SELECT COUNT(*) FROM livres WHERE statut = 'disponible'");
$totalPages  = (int)ceil($totalLivres / $limit);

?>
<!DOCTYPE html>
<html <?= AppSettings::htmlAttrs() ?>>
<!--
     ^ Injecte automatiquement :
       lang="fr"      (ou "en" selon le paramètre 'language')
       data-theme="dark"  (ou le thème configuré)
-->
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <?= AppSettings::injectHead('Titre de la page') ?>
  <!--
       ^ Injecte automatiquement :
         - <title>Titre de la page — Digital Library</title>
         - <link rel="icon"> si un logo est configuré
         - <style id="dls-settings-vars"> avec toutes les CSS vars
         - <script>window.DLS_SETTINGS = {...};</script>
  -->

  <!-- VOS CSS SUPPLÉMENTAIRES ICI -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    /* Vos styles utilisent automatiquement var(--primary), var(--bg-base), etc.
       Ces variables sont définies par AppSettings::injectHead()
       et changent en temps réel avec les paramètres. */
    body {
      background: var(--bg-base);
      color: var(--text-primary);
      font-family: 'DM Sans', sans-serif;
    }
    .price { color: var(--primary); }
    .card  { background: var(--bg-card); border: 1px solid var(--border); }
  </style>
</head>
<body>

  <!-- EXEMPLE D'UTILISATION DES HELPERS ─────────────────────────── -->
  <h1><?= e(AppSettings::siteName()) ?></h1>

  <!-- Affichage des livres avec pagination depuis settings -->
  <?php foreach ($livres as $livre): ?>
    <div class="card">
      <h3><?= e($livre['titre']) ?></h3>
      <p>Auteur : <?= e($livre['auteur']) ?></p>

      <!-- Prix formaté avec la devise configurée -->
      <span class="price"><?= dls_money((float)$livre['prix']) ?></span>

      <!-- Date formatée selon le format configuré (DD/MM/YYYY, etc.) -->
      <small>Ajouté le <?= dls_date($livre['created_at']) ?></small>
    </div>
  <?php endforeach; ?>

  <!-- Pagination utilisant le nombre d'éléments configuré -->
  <nav>
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a href="?page=<?= $p ?>"><?= $p ?></a>
    <?php endfor; ?>
  </nav>

  <!-- Exemple de formulaire sécurisé avec CSRF -->
  <form method="post" action="traitement.php">
    <?= csrfField() ?>
    <input type="text" name="titre" placeholder="<?= __('search') ?>">
    <button type="submit"><?= __('save') ?></button>
  </form>

  <script>
  // Accès aux settings côté JS (injectés par AppSettings::injectHead())
  console.log('Thème :', window.DLS_SETTINGS.theme);
  console.log('Langue :', window.DLS_SETTINGS.lang);
  console.log('Couleur :', window.DLS_SETTINGS.primaryColor);
  console.log('Devise :', window.DLS_SETTINGS.currency);

  // Exemple : adapter le JS selon la langue
  const lang = window.DLS_SETTINGS.lang;
  const msg  = lang === 'fr' ? 'Chargement...' : 'Loading...';
  </script>
</body>
</html>