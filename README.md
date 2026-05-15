README — Plateforme de Gestion d’une Bibliothèque Numérique
📚 Présentation du Projet

La Plateforme de Gestion d’une Bibliothèque Numérique est une application web moderne développée en PHP/MySQL permettant la gestion complète d’une bibliothèque numérique avec plusieurs rôles utilisateurs :

Administrateur
Journaliste
Lecteur

Le système permet :

la publication de livres numériques,
l’achat et la lecture de livres,
la gestion des bonus,
les statistiques de vente,
les avis et commentaires,
les notifications en temps réel,
ainsi qu’une administration complète de la plateforme.

Le projet respecte les besoins fonctionnels définis dans le cahier des charges.

🚀 Fonctionnalités Principales
👨‍💼 Administrateur

L’administrateur possède tous les privilèges du système.

Fonctionnalités :
Inscription
Connexion sécurisée
Gestion des journalistes
Ajout / modification / suppression des comptes
Blocage et déblocage des utilisateurs
Gestion des livres
Gestion des bonus
Accès aux statistiques
Gestion des paramètres système
Supervision complète de la plateforme
📰 Journaliste

Le journaliste peut publier et gérer des livres numériques.

Fonctionnalités :
Inscription
Connexion sécurisée
Ajout de livres
Modification des livres
Consultation des ventes
Génération de rapports
Recherche avancée de livres
Lecture des livres publiés
👤 Lecteur

Le lecteur est l’utilisateur principal de la plateforme.

Fonctionnalités :
Inscription
Connexion
Recherche de livres
Achat de livres
Lecture des livres
Ajout aux favoris
Commentaires et avis
Réception de bonus après achats
🎁 Système de Bonus

Le système attribue automatiquement :

un livre bonus
après l’achat de 5 livres

Le bonus est :

enregistré en base de données
visible dans le tableau de bord utilisateur
notifié en temps réel
🔔 Notifications Temps Réel

Le système de notifications surveille :

connexions
inscriptions
achats
commentaires
favoris
téléchargements
bonus
actions administratives

Les notifications sont :

enregistrées en base de données
affichées instantanément
adaptées selon le rôle utilisateur
💬 Avis & Commentaires

Les lecteurs et journalistes peuvent :

commenter les livres
donner une note
modifier leurs commentaires
supprimer leurs commentaires

L’administrateur peut :

modérer les avis
supprimer les contenus inappropriés
📊 Tableau de Bord Intelligent

Le dashboard affiche :

statistiques des ventes
nombre de livres
utilisateurs connectés
livres populaires
activité récente
notifications
revenus
téléchargements
🛠️ Technologies Utilisées
Backend
PHP 8+
MySQL
PDO
Frontend
HTML5
CSS3
JavaScript
jQuery
Bootstrap
Serveur Local
WampServer / XAMPP
Outils Supplémentaires
AJAX
Fetch API
GitHub

Les technologies utilisées correspondent aux spécifications du cahier des charges.

🗄️ Structure de la Base de Données
Tables principales
users

Gestion des utilisateurs.

books

Informations des livres.

purchases

Historique des achats.

comments

Commentaires et avis.

favorites

Livres favoris.

notifications

Notifications système.

bonuses

Bonus utilisateurs.

downloads

Téléchargements.

readings

Historique de lecture.

activity_logs

Journal des activités.

🔐 Sécurité

Le projet intègre :

protection CSRF
protection XSS
requêtes préparées PDO
protection SQL Injection
contrôle des rôles
sessions sécurisées
validation des formulaires
📁 Structure du Projet
/project
│
├── admin/
│   ├── dashboard.php
│   ├── sales.php
│   ├── reviews.php
│
├── journalist/
│
├── reader/
│
├── assets/
│   ├── css/
│   ├── js/
│   ├── images/
│
├── uploads/
│   ├── books/
│   ├── covers/
│
├── config/
│   ├── database.php
│
├── includes/
│
├── ajax/
│
├── database/
│   ├── library.sql
│
├── login.php
├── register.php
├── index.php
└── README.md

⚙️ Guide d’Installation

1. Installer WampServer

Télécharger et installer :

WampServer

2. Cloner le Projet
git clone https://github.com/biblio/biblio_dls.git

Ou copier le projet dans :

C:\wamp64\www\

3. Démarrer Apache et MySQL

Ouvrir WampServer puis :

démarrer Apache
démarrer MySQL

4. Créer la Base de Données

Ouvrir :

phpMyAdmin

Créer une base :

digital_library

Importer :

database/digital_library.sql

5. Configurer la Connexion

Modifier :

config/database.php

Exemple :

<?php

$host = "localhost";
$dbname = "digital_library";
$user = "root";
$password = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $user,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch(PDOException $e) {
    die("Erreur : " . $e->getMessage());
}

6. Lancer le Projet

Ouvrir :

http://localhost/www/biblio/index.php
🔑 Comptes de Test
Administrateur
Email : admin@sopecam.com
Mot de passe : admin123
Journaliste
Email : journaliste@sopecam.com
Mot de passe : journaliste123
🔄 Gestion des Rôles

Lors de la connexion :

si rôle = lecteur → redirection vers /home
si rôle = admin → redirection vers /admin/dashboard

Le système vérifie automatiquement le rôle utilisateur après authentification.

☁️ Fonctionnalités Bonus
IA / Chatbot
assistance utilisateur
recommandations de livres
support intelligent
Paiement Mobile Money
Orange Money
Mobile Money
Hébergement GitHub
versionnage du projet
collaboration
sauvegarde du code

Les bonus prévus sont listés dans le cahier des charges.

🧪 Tests et Validation

Des tests ont été réalisés sur :

authentification
achats
notifications
bonus
commentaires
sécurité
rôles utilisateurs
affichage responsive

Les tests ont permis de confirmer :

la stabilité du système
la cohérence des données
le bon fonctionnement des fonctionnalités
📌 Auteur

Projet réalisé dans le cadre d’un stage académique par MBALA A ZIEM PATRICIA ROXANE.

📜 Licence

Projet académique et éducatif.