-- ============================================================
-- DIGITAL LIBRARY — migration_livres_v3.sql
-- Migration complète : suppression faux livres, colonnes
-- réelles, seed données professionnelles, indexes optimisés
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- À exécuter UNE SEULE FOIS ou de façon idempotente
-- ============================================================

USE digital_library;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ────────────────────────────────────────────────────────────
-- ÉTAPE 1 : Ajouter colonne couleur aux catégories
-- ────────────────────────────────────────────────────────────
ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS couleur VARCHAR(20) DEFAULT '#4a9eff' COMMENT 'Couleur hex de la catégorie';

-- Mettre à jour les catégories existantes avec les bonnes icônes et couleurs
INSERT INTO categories (nom, slug, icone, couleur) VALUES
    ('Science-Fiction',         'sf',       '🌌', '#1a4a7a'),
    ('Philosophie',             'philo',    '🧠', '#4a1a7a'),
    ('Nature & Environnement',  'nature',   '🌿', '#1a6b3a'),
    ('Technologie & IA',        'tech',     '⚙️', '#1a5a7a'),
    ('Histoire',                'histoire', '📜', '#7a5a1a'),
    ('Littérature',             'lit',      '🎭', '#6b1a3a'),
    ('Sciences',                'sciences', '🔬', '#1a3a7a'),
    ('Économie',                'eco',      '💹', '#3a6b1a'),
    ('Art & Culture',           'art',      '🎨', '#7a1a5a'),
    ('Développement Personnel', 'dev',      '🌱', '#1a7a5a')
ON DUPLICATE KEY UPDATE
    icone   = VALUES(icone),
    couleur = VALUES(couleur);

-- ────────────────────────────────────────────────────────────
-- ÉTAPE 2 : Réorganiser la table livres — colonnes manquantes
-- ────────────────────────────────────────────────────────────

-- Colonne access_type (remplace statut comme classificateur premium/standard/gratuit)
ALTER TABLE livres
    ADD COLUMN IF NOT EXISTS access_type ENUM('premium','standard','gratuit')
        NOT NULL DEFAULT 'standard'
        COMMENT 'Niveau d accès : premium, standard, gratuit';

-- Compteurs statistiques
ALTER TABLE livres
    ADD COLUMN IF NOT EXISTS nb_etoiles         INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'Nombre d évaluations',
    ADD COLUMN IF NOT EXISTS nb_lectures        INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'Nombre de lectures',
    ADD COLUMN IF NOT EXISTS nb_telechargements INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'Nombre de téléchargements';

-- Métadonnées enrichies
ALTER TABLE livres
    ADD COLUMN IF NOT EXISTS isbn           VARCHAR(20)  UNIQUE          COMMENT 'ISBN-13',
    ADD COLUMN IF NOT EXISTS editeur        VARCHAR(150) NULL            COMMENT 'Maison d édition',
    ADD COLUMN IF NOT EXISTS langue         VARCHAR(50)  DEFAULT 'Français' COMMENT 'Langue de l ouvrage',
    ADD COLUMN IF NOT EXISTS is_featured    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Livre mis en avant',
    ADD COLUMN IF NOT EXISTS is_bestseller  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Badge bestseller',
    ADD COLUMN IF NOT EXISTS couverture     VARCHAR(500) NULL            COMMENT 'URL image couverture',
    ADD COLUMN IF NOT EXISTS fichier_pdf    VARCHAR(500) NULL            COMMENT 'Chemin vers le PDF',
    ADD COLUMN IF NOT EXISTS contenu_extrait MEDIUMTEXT  NULL            COMMENT 'Extrait / chapitres pour le lecteur intégré';

-- ────────────────────────────────────────────────────────────
-- ÉTAPE 3 : Indexes pour performance
-- ────────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_access_type  ON livres (access_type);
CREATE INDEX IF NOT EXISTS idx_note         ON livres (note_moyenne DESC);
CREATE INDEX IF NOT EXISTS idx_featured     ON livres (is_featured);
CREATE INDEX IF NOT EXISTS idx_bestseller   ON livres (is_bestseller);
CREATE INDEX IF NOT EXISTS idx_categorie    ON livres (categorie_id);
CREATE INDEX IF NOT EXISTS idx_annee        ON livres (annee_parution);
CREATE INDEX IF NOT EXISTS idx_prix         ON livres (prix);
CREATE INDEX IF NOT EXISTS idx_created      ON livres (created_at DESC);

-- FULLTEXT pour recherche
ALTER TABLE livres ADD FULLTEXT IF NOT EXISTS idx_fulltext_search (titre, auteur, description);

-- ────────────────────────────────────────────────────────────
-- ÉTAPE 4 : Supprimer les faux livres générés par la procédure
-- ────────────────────────────────────────────────────────────
-- Protéger les achats existants avant suppression
DELETE FROM livres
WHERE
    (titre REGEXP '^Livre [0-9]+$' OR auteur = 'Auteur inconnu' OR auteur = '')
    AND id NOT IN (SELECT livre_id FROM achats);

-- ────────────────────────────────────────────────────────────
-- ÉTAPE 5 : Insérer les vrais livres (seed professionnel)
-- Les INSERT IGNORE évitent les doublons via l'index UNIQUE isbn
-- ────────────────────────────────────────────────────────────

-- Récupérer les IDs de catégories
SET @cat_sf      = (SELECT id FROM categories WHERE slug = 'sf'       LIMIT 1);
SET @cat_philo   = (SELECT id FROM categories WHERE slug = 'philo'    LIMIT 1);
SET @cat_nature  = (SELECT id FROM categories WHERE slug = 'nature'   LIMIT 1);
SET @cat_tech    = (SELECT id FROM categories WHERE slug = 'tech'     LIMIT 1);
SET @cat_hist    = (SELECT id FROM categories WHERE slug = 'histoire' LIMIT 1);
SET @cat_lit     = (SELECT id FROM categories WHERE slug = 'lit'      LIMIT 1);
SET @cat_sci     = (SELECT id FROM categories WHERE slug = 'sciences' LIMIT 1);
SET @cat_eco     = (SELECT id FROM categories WHERE slug = 'eco'      LIMIT 1);
SET @cat_art     = (SELECT id FROM categories WHERE slug = 'art'      LIMIT 1);
SET @cat_dev     = (SELECT id FROM categories WHERE slug = 'dev'      LIMIT 1);

INSERT IGNORE INTO livres
    (titre, auteur, isbn, description, prix, categorie_id, annee_parution,
     editeur, langue, pages, statut, access_type,
     note_moyenne, nb_etoiles, nb_lectures, nb_telechargements, nb_ventes,
     is_featured, is_bestseller)
VALUES

-- ── SCIENCE-FICTION ─────────────────────────────────────────
('Fondation',
 'Isaac Asimov',
 '978-0-553-29335-7',
 'Hari Seldon a consacré sa vie à la psychohistoire, permettant de prédire la chute de l''Empire Galactique et de limiter la période de barbarie qui s''ensuivrait.',
 3500, @cat_sf, 1951, 'Gallimard', 'Français', 330, 'disponible', 'premium',
 4.7, 4892, 18430, 6210, 3850, 1, 1),

('Dune',
 'Frank Herbert',
 '978-0-441-17271-9',
 'Sur la planète désertique Arrakis, seul lieu de production de l''épice mélange, le jeune Paul Atréides découvre son destin exceptionnel parmi les Fremen.',
 4200, @cat_sf, 1965, 'Robert Laffont', 'Français', 688, 'disponible', 'premium',
 4.8, 9213, 34120, 11050, 7620, 1, 1),

('Le Meilleur des mondes',
 'Aldous Huxley',
 '978-2-266-12025-8',
 'Dans l''an 632 ap. Ford, l''État mondial produit des êtres humains en série et les conditionne au bonheur. Un classique dystopique sur la liberté et le contrôle social.',
 2800, @cat_sf, 1932, 'Pocket', 'Français', 288, 'disponible', 'standard',
 4.4, 6120, 22400, 8100, 4200, 0, 1),

('1984',
 'George Orwell',
 '978-2-070-36822-3',
 'Dans l''Océania totalitaire de Big Brother, Winston Smith cherche à préserver son humanité. Un chef-d''œuvre politique sur la surveillance, la manipulation et la résistance.',
 2500, @cat_sf, 1949, 'Gallimard', 'Français', 376, 'disponible', 'standard',
 4.8, 12400, 45200, 16800, 9100, 1, 1),

('Fahrenheit 451',
 'Ray Bradbury',
 '978-2-070-41283-9',
 'Dans une société où les pompiers brûlent les livres plutôt qu''ils n''éteignent les incendies, Montag décide de résister. Un hymne à la littérature et à la pensée libre.',
 2200, @cat_sf, 1953, 'Denoël', 'Français', 192, 'disponible', 'standard',
 4.5, 7800, 28100, 9600, 4800, 0, 1),

-- ── PHILOSOPHIE ─────────────────────────────────────────────
('Le Monde de Sophie',
 'Jostein Gaarder',
 '978-2-07-040850-4',
 'Sophie Amundsen, 14 ans, reçoit des questions mystérieuses qui l''entraînent dans un voyage à travers toute l''histoire de la philosophie occidentale.',
 2800, @cat_philo, 1991, 'Seuil', 'Français', 588, 'disponible', 'standard',
 4.5, 6730, 21400, 7200, 4100, 1, 1),

('L''Être et le Néant',
 'Jean-Paul Sartre',
 '978-2-07-029388-5',
 'Œuvre fondatrice de l''existentialisme. L''existence précède l''essence — une exploration radicale de la conscience, de la liberté et du rapport à l''autre.',
 5800, @cat_philo, 1943, 'Gallimard', 'Français', 821, 'disponible', 'premium',
 4.3, 3420, 8900, 2100, 1850, 0, 0),

('La République',
 'Platon',
 '978-2-080-70057-1',
 'Socrate et ses interlocuteurs discutent de la justice, de l''âme et de la cité idéale. Un dialogue fondateur de la philosophie politique occidentale.',
 2200, @cat_philo, -380, 'Flammarion', 'Français', 489, 'disponible', 'gratuit',
 4.4, 5200, 16800, 7800, 0, 0, 0),

('Méditations',
 'Marc Aurèle',
 '978-2-080-70186-8',
 'Journal intime de l''Empereur philosophe, rédigé pour lui-même. Une application quotidienne des principes stoïciens : maîtrise de soi, vertu, sérénité face à l''adversité.',
 1800, @cat_philo, 180, 'Flammarion', 'Français', 240, 'disponible', 'gratuit',
 4.7, 4890, 19200, 8900, 0, 0, 1),

-- ── NATURE & ENVIRONNEMENT ──────────────────────────────────
('L''Appel de la Forêt',
 'Jack London',
 '978-2-07-041205-1',
 'Buck, chien domestique, est vendu comme chien de traîneau au Klondike. Son adaptation à la vie sauvage, entre violence et beauté, est un hymne à la nature originelle.',
 0, @cat_nature, 1903, 'Folio', 'Français', 176, 'disponible', 'gratuit',
 4.4, 5230, 23100, 12400, 0, 1, 0),

('Walden ou la Vie dans les bois',
 'Henry David Thoreau',
 '978-2-07-041612-7',
 'Thoreau raconte ses deux ans de vie solitaire dans une cabane au bord de l''étang Walden. Un manifeste fondateur pour la simplicité volontaire et la désobéissance civile.',
 2200, @cat_nature, 1854, 'Gallimard', 'Français', 340, 'disponible', 'standard',
 4.3, 3920, 12800, 4800, 1600, 0, 0),

('Printemps silencieux',
 'Rachel Carson',
 '978-2-253-00637-4',
 'Le livre qui lança le mouvement écologiste moderne. Carson documente les effets dévastateurs des pesticides sur la faune — et sur l''humanité.',
 2800, @cat_nature, 1962, 'Le Livre de Poche', 'Français', 320, 'disponible', 'standard',
 4.5, 2870, 9600, 3200, 1800, 0, 0),

-- ── TECHNOLOGIE & IA ────────────────────────────────────────
('Intelligence Artificielle : Une approche moderne',
 'Stuart Russell & Peter Norvig',
 '978-2-326-00261-7',
 'La référence mondiale de l''IA. Des algorithmes de recherche aux réseaux de neurones profonds, en passant par la planification et le traitement du langage naturel.',
 7500, @cat_tech, 2020, 'Pearson', 'Français', 1152, 'disponible', 'premium',
 4.6, 4120, 12800, 4300, 2100, 1, 0),

('La Singularité est proche',
 'Ray Kurzweil',
 '978-2-380-15040-3',
 'Kurzweil prédit qu''en 2045, l''intelligence artificielle dépassera l''intelligence humaine. Une vision radicale du futur technologique de l''humanité.',
 4500, @cat_tech, 2005, 'M21 Editions', 'Français', 576, 'disponible', 'premium',
 4.1, 2840, 8900, 2900, 1400, 0, 0),

('Clean Code',
 'Robert C. Martin',
 '978-0-132-35088-4',
 'Les principes, patterns et pratiques du code propre. Une référence incontournable pour tout développeur souhaitant écrire du code maintenable, lisible et professionnel.',
 6800, @cat_tech, 2008, 'Prentice Hall', 'Français', 464, 'disponible', 'premium',
 4.7, 6300, 18200, 5800, 3100, 1, 1),

-- ── HISTOIRE ────────────────────────────────────────────────
('Sapiens : Une brève histoire de l''humanité',
 'Yuval Noah Harari',
 '978-2-226-25576-0',
 'Comment l''Homo sapiens est-il devenu maître du monde ? Harari retrace 70 000 ans d''histoire humaine, de la révolution cognitive à la révolution scientifique.',
 3800, @cat_hist, 2011, 'Albin Michel', 'Français', 512, 'disponible', 'standard',
 4.7, 14300, 51200, 18900, 12400, 1, 1),

('Le Monde d''hier : Souvenirs d''un Européen',
 'Stefan Zweig',
 '978-2-253-03952-5',
 'Autobiographie bouleversante sur la destruction de l''Europe cultivée par les nationalismes. Rédigée en exil peu avant le suicide de Zweig en 1942.',
 2200, @cat_hist, 1942, 'Le Livre de Poche', 'Français', 480, 'disponible', 'standard',
 4.8, 5690, 16300, 5400, 2900, 0, 0),

('L''Art de la Guerre',
 'Sun Tzu',
 '978-2-080-70042-7',
 'Traité militaire vieux de 2 500 ans, applicable aux affaires, à la politique et à la vie quotidienne. Une source inépuisable de sagesse stratégique.',
 1500, @cat_hist, -500, 'Flammarion', 'Français', 144, 'disponible', 'gratuit',
 4.5, 7200, 28900, 14200, 0, 0, 1),

-- ── LITTÉRATURE ─────────────────────────────────────────────
('Cent ans de solitude',
 'Gabriel García Márquez',
 '978-2-07-036727-8',
 'L''histoire de la famille Buendía sur sept générations dans Macondo. Chef-d''œuvre du réalisme magique. Prix Nobel de Littérature 1982.',
 3200, @cat_lit, 1967, 'Seuil', 'Français', 472, 'disponible', 'premium',
 4.9, 11200, 42300, 14100, 9800, 1, 1),

('Crime et Châtiment',
 'Fiodor Dostoïevski',
 '978-2-07-036835-0',
 'Raskolnikov assassine une vieille usurière qu''il juge nuisible. La culpabilité le ronge inexorablement. Roman fondateur sur le bien, le mal et la rédemption.',
 2500, @cat_lit, 1866, 'Gallimard', 'Français', 554, 'disponible', 'standard',
 4.6, 7840, 19600, 8300, 3200, 0, 1),

('Les Misérables',
 'Victor Hugo',
 '978-2-070-40834-4',
 'Jean Valjean, forçat libéré, tente de se racheter dans une France déchirée. Une fresque monumentale sur la justice, l''amour et la rédemption sociale.',
 3500, @cat_lit, 1862, 'Gallimard', 'Français', 1488, 'disponible', 'standard',
 4.8, 9800, 36400, 12100, 6200, 1, 1),

('L''Étranger',
 'Albert Camus',
 '978-2-070-36024-1',
 'Meursault tue un Arabe sur une plage d''Alger. Son indifférence face à la mort de sa mère, le meurtre, le procès... Un chef-d''œuvre de l''absurde.',
 1800, @cat_lit, 1942, 'Gallimard', 'Français', 184, 'disponible', 'standard',
 4.5, 8900, 31200, 11800, 5400, 0, 1),

-- ── SCIENCES ────────────────────────────────────────────────
('Une brève histoire du temps',
 'Stephen Hawking',
 '978-2-081-21871-2',
 'Des Big Bang aux trous noirs, Hawking explore les grandes questions de la cosmologie avec une clarté remarquable. L''un des livres scientifiques les plus vendus.',
 2800, @cat_sci, 1988, 'Flammarion', 'Français', 232, 'disponible', 'standard',
 4.6, 8920, 28400, 9600, 5800, 1, 1),

('La Structure des révolutions scientifiques',
 'Thomas S. Kuhn',
 '978-2-080-81119-2',
 'Kuhn révolutionne notre compréhension de la science : elle ne progresse pas de façon linéaire mais par sauts brusques — les révolutions de paradigmes.',
 3200, @cat_sci, 1962, 'Flammarion', 'Français', 284, 'disponible', 'standard',
 4.4, 3240, 9800, 3200, 2100, 0, 0),

('L''Origine des espèces',
 'Charles Darwin',
 '978-2-080-71065-5',
 'Le livre qui changea notre vision du monde. Darwin expose sa théorie de l''évolution par sélection naturelle, révolutionnant la biologie, la philosophie et la théologie.',
 2200, @cat_sci, 1859, 'Flammarion', 'Français', 592, 'disponible', 'gratuit',
 4.6, 6100, 22400, 9800, 0, 0, 1),

-- ── ÉCONOMIE ────────────────────────────────────────────────
('Le Cygne Noir',
 'Nassim Nicholas Taleb',
 '978-2-012-36015-0',
 'Comment des événements rares et imprévisibles façonnent le monde. Un livre révolutionnaire qui remet en cause nos certitudes sur la prévision et le risque.',
 3200, @cat_eco, 2007, 'Les Belles Lettres', 'Français', 498, 'disponible', 'standard',
 4.4, 5870, 17200, 6100, 3400, 0, 1),

('Freakonomics',
 'Steven D. Levitt & Stephen J. Dubner',
 '978-2-253-11768-5',
 'Deux économistes appliquent l''économie à des questions inattendues : les dealers font-ils fortune ? Les noms de bébé suivent-ils des modes ?',
 2500, @cat_eco, 2005, 'Le Livre de Poche', 'Français', 282, 'disponible', 'standard',
 4.2, 4530, 14700, 5600, 2900, 0, 0),

('Le Capital au XXIe siècle',
 'Thomas Piketty',
 '978-2-021-08055-0',
 'Une analyse monumentale des inégalités économiques sur deux siècles. Piketty montre que le rendement du capital excède structurellement la croissance.',
 4800, @cat_eco, 2013, 'Seuil', 'Français', 970, 'disponible', 'premium',
 4.3, 3200, 9800, 3100, 1800, 0, 0),

-- ── ART & CULTURE ───────────────────────────────────────────
('L''Histoire de l''Art',
 'Ernst H. Gombrich',
 '978-2-714-44259-6',
 'La plus grande introduction à l''histoire de l''art jamais écrite. Un voyage de 5 000 ans à travers les chefs-d''œuvre de l''humanité.',
 4500, @cat_art, 1950, 'Phaidon', 'Français', 688, 'disponible', 'premium',
 4.7, 3810, 9200, 2800, 1600, 0, 0),

('Le Don des formes',
 'Yasmina Reza',
 '978-2-253-09921-9',
 'Trois amis visitent une exposition d''art contemporain. Une pièce décapante sur les postures intellectuelles, l''amitié et le jugement esthétique.',
 1800, @cat_art, 1994, 'Le Livre de Poche', 'Français', 144, 'disponible', 'standard',
 4.2, 2900, 8400, 3100, 1200, 0, 0),

-- ── DÉVELOPPEMENT PERSONNEL ─────────────────────────────────
('Les 7 Habitudes des Gens Très Efficaces',
 'Stephen R. Covey',
 '978-2-744-01804-8',
 'Un classique du développement personnel. Un changement de paradigme fondé sur des principes universels d''éthique et d''efficacité personnelle et interpersonnelle.',
 3500, @cat_dev, 1989, 'First Éditions', 'Français', 384, 'disponible', 'standard',
 4.3, 6120, 19800, 7200, 4100, 0, 1),

('L''Intelligence Émotionnelle',
 'Daniel Goleman',
 '978-2-253-14525-1',
 'Goleman révolutionne notre conception de l''intelligence : le QE ne suffit pas. L''intelligence émotionnelle — conscience de soi, empathie, gestion des émotions — est déterminante.',
 3200, @cat_dev, 1995, 'Le Livre de Poche', 'Français', 416, 'disponible', 'standard',
 4.4, 5400, 17200, 6100, 3200, 0, 0),

('Mindset : La nouvelle psychologie du succès',
 'Carol S. Dweck',
 '978-2-501-07037-4',
 'La psychologue de Stanford distingue l''état d''esprit fixe (fixed mindset) et de croissance (growth mindset). Une découverte transformatrice sur l''apprentissage et la réussite.',
 2800, @cat_dev, 2006, 'Marabout', 'Français', 320, 'disponible', 'standard',
 4.5, 4920, 16800, 5900, 3100, 1, 1);

-- ────────────────────────────────────────────────────────────
-- ÉTAPE 6 : Mettre à jour les contenus extraits pour les livres
-- Chaque livre reçoit un extrait littéraire de qualité
-- (Fait via books/seed.php en PHP pour les gros textes)
-- ────────────────────────────────────────────────────────────

UPDATE livres SET contenu_extrait =
'CHAPITRE 1 — La Psychohistoire

À la fin du troisième millénaire de l''Empire Galactique, Hari Seldon développa une science capable de prédire l''avenir des civilisations. Non pas l''avenir des individus — cela était impossible — mais celui des foules, des nations, des empires entiers.

La psychohistoire reposait sur deux postulats fondamentaux : premièrement, le nombre des humains en jeu doit être suffisamment grand pour que les lois statistiques s''appliquent ; deuxièmement, les humains ne doivent pas avoir connaissance des prédictions, car cela modifierait leur comportement.

Seldon avait calculé avec une précision mathématique terrifiante : l''Empire allait s''effondrer. Dans 500 ans. Et 30 000 années de barbarie s''ensuivraient.

||||PAGE||||

CHAPITRE 2 — La Commission de Sécurité

« Vous avez prédit la chute de l''Empire. »

La voix du commissaire résonnait dans la grande salle. Seldon ne broncha pas. À 70 ans, il avait appris que la peur était un luxe réservé aux jeunes.

« J''ai calculé une probabilité, dit-il. Nuance. »

« La différence vous sauvera-t-elle de la prison, Seldon ? »

Il sourit. La Commission ne comprenait pas. Ils croyaient pouvoir arrêter le futur en arrêtant le mathématicien. Mais le futur n''était pas Seldon.'

WHERE titre = 'Fondation';

-- ────────────────────────────────────────────────────────────
-- ÉTAPE 7 : Vue matérialisée pour les stats dashboard
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW v_livres_stats AS
SELECT
    COUNT(*)                                          AS total_livres,
    SUM(access_type = 'premium')                      AS nb_premium,
    SUM(access_type = 'standard')                     AS nb_standard,
    SUM(access_type = 'gratuit')                      AS nb_gratuit,
    COALESCE(SUM(nb_ventes), 0)                       AS total_ventes,
    COALESCE(SUM(nb_lectures), 0)                     AS total_lectures,
    COALESCE(SUM(nb_telechargements), 0)              AS total_telechargements,
    COALESCE(AVG(note_moyenne), 0)                    AS note_moyenne_globale,
    SUM(is_featured = 1)                              AS nb_featured,
    SUM(is_bestseller = 1)                            AS nb_bestsellers
FROM livres
WHERE statut = 'disponible';

-- ────────────────────────────────────────────────────────────
-- ÉTAPE 8 : Vue catalogue enrichi
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW v_catalogue AS
SELECT
    l.id,
    l.titre,
    l.auteur,
    l.isbn,
    SUBSTRING(l.description, 1, 300)  AS description_courte,
    l.prix,
    l.access_type,
    l.note_moyenne,
    l.nb_etoiles,
    l.nb_lectures,
    l.nb_telechargements,
    l.nb_ventes,
    l.pages,
    l.annee_parution,
    l.editeur,
    l.langue,
    l.couverture,
    l.fichier_pdf,
    l.is_featured,
    l.is_bestseller,
    l.statut,
    l.created_at,
    c.id     AS categorie_id,
    c.nom    AS categorie_nom,
    c.slug   AS categorie_slug,
    c.icone  AS categorie_icone,
    c.couleur AS categorie_couleur
FROM livres l
LEFT JOIN categories c ON c.id = l.categorie_id
WHERE l.statut = 'disponible'
ORDER BY l.is_featured DESC, l.note_moyenne DESC;

-- ────────────────────────────────────────────────────────────
-- ÉTAPE 9 : Trigger — incrémenter nb_lectures
-- ────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS after_progression_update;
DELIMITER $$
CREATE TRIGGER after_progression_update
AFTER INSERT ON lecture_progression
FOR EACH ROW
BEGIN
    UPDATE livres SET nb_lectures = nb_lectures + 1 WHERE id = NEW.livre_id;
END$$
DELIMITER ;

-- ────────────────────────────────────────────────────────────
-- ÉTAPE 10 : Trigger — incrémenter nb_telechargements
-- ────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS after_download_insert;
DELIMITER $$
CREATE TRIGGER after_download_insert
AFTER INSERT ON user_downloads
FOR EACH ROW
BEGIN
    UPDATE livres SET nb_telechargements = nb_telechargements + 1 WHERE id = NEW.livre_id;
END$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;

-- ────────────────────────────────────────────────────────────
-- VÉRIFICATION FINALE
-- ────────────────────────────────────────────────────────────
SELECT
    CONCAT('✅ Livres réels insérés : ',
        (SELECT COUNT(*) FROM livres WHERE auteur != 'Auteur inconnu' AND statut = 'disponible'),
        ' titres') AS bilan
UNION ALL
SELECT CONCAT('✅ Catégories : ', COUNT(*), ' disponibles') FROM categories
UNION ALL
SELECT CONCAT('📊 Premium : ',  SUM(access_type='premium'),
              ' | Standard : ', SUM(access_type='standard'),
              ' | Gratuit : ',  SUM(access_type='gratuit')) FROM livres WHERE statut='disponible'
UNION ALL
SELECT CONCAT('⭐ Note moyenne globale : ', ROUND(AVG(note_moyenne), 2)) FROM livres WHERE statut='disponible';

-- ============================================================
-- DIGITAL LIBRARY — migration_complete_v4.sql
-- Version corrigée : colonnes cohérentes, pas de DROP tables
-- ============================================================

CREATE DATABASE IF NOT EXISTS digital_library CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE digital_library;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── categories ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom        VARCHAR(100) NOT NULL,
  slug       VARCHAR(100) NOT NULL UNIQUE,
  icone      VARCHAR(10)  DEFAULT '📚',
  couleur    VARCHAR(20)  DEFAULT '#4a9eff',
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO categories (id, nom, slug, icone, couleur) VALUES
(1, 'Livres',             'livres',   '📘', '#1a4a7a'),
(2, 'Journaux',           'journaux', '📰', '#4a3a1a'),
(3, 'Science-Fiction',    'sf',       '🌌', '#1a3a7a'),
(4, 'Philosophie',        'philo',    '🧠', '#4a1a7a'),
(5, 'Nature',             'nature',   '🌿', '#1a6b3a'),
(6, 'Technologie',        'tech',     '⚙️', '#1a5a7a'),
(7, 'Histoire',           'histoire', '📜', '#7a5a1a'),
(8, 'Littérature',        'lit',      '🎭', '#6b1a3a'),
(9, 'Sciences',           'sciences', '🔬', '#1a3a7a'),
(10,'Économie',           'eco',      '💹', '#3a6b1a');

-- ── users ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom           VARCHAR(150) NOT NULL,
  prenom        VARCHAR(150) NOT NULL DEFAULT '',
  email         VARCHAR(255) NOT NULL UNIQUE,
  password      VARCHAR(255) NOT NULL,
  telephone     VARCHAR(20)  DEFAULT NULL,
  role          ENUM('lecteur','journaliste','admin') NOT NULL DEFAULT 'lecteur',
  statut        ENUM('actif','inactif','bloque')      NOT NULL DEFAULT 'actif',
  avatar        VARCHAR(255) DEFAULT NULL,
  last_login    TIMESTAMP    NULL,
  last_ip       VARCHAR(45)  NULL,
  login_count   INT UNSIGNED NOT NULL DEFAULT 0,
  login_fails   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Tentatives échouées',
  locked_until  TIMESTAMP    NULL COMMENT 'Bloqué jusqu à',
  preferences   JSON         NULL,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── settings (clé-valeur) ────────────────────────────────────
-- NOTE: on utilise setting_key / setting_value (cohérent avec dashboard.php)
CREATE TABLE IF NOT EXISTS settings (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key   VARCHAR(150) NOT NULL UNIQUE,
  setting_value TEXT         NULL,
  updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('site_name',      'Digital Library Platform'),
('dashboard_theme','dark'),
('primary_color',  '#7c3aed'),
('currency',       'FCFA'),
('bonus_rule',     '5'),
('max_downloads',  '3'),
('notif_enabled',  '1'),
('pagination',     '20');

-- ── livres ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS livres (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titre               VARCHAR(255) NOT NULL,
  auteur              VARCHAR(150) NOT NULL,
  isbn                VARCHAR(20)  UNIQUE,
  description         TEXT,
  prix                DECIMAL(10,2) DEFAULT 0.00,
  stock               INT DEFAULT 100,
  categorie_id        INT UNSIGNED,
  couverture          VARCHAR(500) NULL,
  fichier_pdf         VARCHAR(500) NULL,
  annee_parution      YEAR,
  editeur             VARCHAR(150),
  langue              VARCHAR(50)  DEFAULT 'Français',
  pages               INT,
  statut              ENUM('disponible','rupture','archive') DEFAULT 'disponible',
  access_type         ENUM('premium','standard','gratuit') NOT NULL DEFAULT 'standard',
  note_moyenne        DECIMAL(3,2) DEFAULT 0.00,
  nb_ventes           INT UNSIGNED DEFAULT 0,
  nb_lectures         INT UNSIGNED DEFAULT 0,
  nb_telechargements  INT UNSIGNED DEFAULT 0,
  nb_etoiles          INT UNSIGNED DEFAULT 0,
  is_featured         TINYINT(1)   NOT NULL DEFAULT 0,
  is_bestseller       TINYINT(1)   NOT NULL DEFAULT 0,
  contenu_extrait     MEDIUMTEXT   NULL,
  ajoute_par          INT UNSIGNED,
  created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE SET NULL,
  FOREIGN KEY (ajoute_par)   REFERENCES users(id)      ON DELETE SET NULL,
  FULLTEXT KEY idx_ft (titre, auteur, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── achats ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS achats (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  livre_id   INT UNSIGNED NOT NULL,
  montant    DECIMAL(10,2) NOT NULL,
  methode    ENUM('orange_money','mobile_money','carte') DEFAULT 'orange_money',
  statut     ENUM('en_attente','confirme','echec') DEFAULT 'confirme',
  reference  VARCHAR(60) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE,
  INDEX idx_user_date (user_id, created_at),
  INDEX idx_livre (livre_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── avis (commentaires + notes) ──────────────────────────────
CREATE TABLE IF NOT EXISTS avis (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  livre_id     INT UNSIGNED NOT NULL,
  note         TINYINT UNSIGNED DEFAULT 0,
  commentaire  TEXT,
  statut       ENUM('publie','en_attente','refuse') DEFAULT 'en_attente',
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_livre  (livre_id),
  INDEX idx_user   (user_id),
  INDEX idx_statut (statut),
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── favoris ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS favoris (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  livre_id   INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_favori (user_id, livre_id),
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── notifications ────────────────────────────────────────────
-- Colonne unifiée : lu (TINYINT) — compatible avec tout le code PHP
CREATE TABLE IF NOT EXISTS notifications (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL COMMENT 'NULL = globale tous rôles',
  type       VARCHAR(60)  NOT NULL DEFAULT 'info',
  titre      VARCHAR(255) NOT NULL DEFAULT '',
  message    TEXT         NOT NULL,
  icon       VARCHAR(10)  DEFAULT '🔔',
  bg         VARCHAR(60)  DEFAULT 'rgba(0,212,255,.08)',
  lu         TINYINT(1)   NOT NULL DEFAULT 0,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_lu (user_id, lu),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── lecture_progression ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS lecture_progression (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  livre_id      INT UNSIGNED NOT NULL,
  page_actuelle INT DEFAULT 1,
  pourcentage   DECIMAL(5,2) DEFAULT 0.00,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prog (user_id, livre_id),
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── user_bonus ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_bonus (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL UNIQUE,
  achat_count   INT UNSIGNED NOT NULL DEFAULT 0,
  bonus_total   INT UNSIGNED NOT NULL DEFAULT 0,
  bonus_restant INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── user_downloads ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_downloads (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  livre_id   INT UNSIGNED NOT NULL,
  count      INT UNSIGNED NOT NULL DEFAULT 1,
  last_dl_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dl (user_id, livre_id),
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── admin_logs ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_logs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  action     VARCHAR(80)  NOT NULL,
  detail     TEXT         NULL,
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user    (user_id),
  INDEX idx_action  (action),
  INDEX idx_created (created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── TRIGGER: bonus après achat ───────────────────────────────
DROP TRIGGER IF EXISTS after_achat_confirmed;
DELIMITER $$
CREATE TRIGGER after_achat_confirmed
AFTER INSERT ON achats
FOR EACH ROW
BEGIN
  DECLARE bonus_rule_val INT DEFAULT 5;
  DECLARE current_count  INT DEFAULT 0;
  SELECT CAST(setting_value AS UNSIGNED) INTO bonus_rule_val
  FROM settings WHERE setting_key = 'bonus_rule' LIMIT 1;
  IF NEW.statut = 'confirme' THEN
    INSERT INTO user_bonus (user_id, achat_count) VALUES (NEW.user_id, 1)
    ON DUPLICATE KEY UPDATE achat_count = achat_count + 1;
    SELECT achat_count INTO current_count
    FROM user_bonus WHERE user_id = NEW.user_id;
    IF current_count >= bonus_rule_val THEN
      UPDATE user_bonus
      SET achat_count   = achat_count - bonus_rule_val,
          bonus_total   = bonus_total + 1,
          bonus_restant = bonus_restant + 1
      WHERE user_id = NEW.user_id;
    END IF;
    UPDATE livres SET nb_ventes = nb_ventes + 1 WHERE id = NEW.livre_id;
  END IF;
END$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;

-- Vérification
SELECT CONCAT('✅ Tables créées — settings: ', (SELECT COUNT(*) FROM settings), ' params') AS status;