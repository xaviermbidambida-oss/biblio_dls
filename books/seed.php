<?php
/**
 * ============================================================
 * DIGITAL LIBRARY — seed.php
 * Génération de livres réalistes
 * ============================================================
 */

declare(strict_types=1);

set_time_limit(0);
ini_set('memory_limit', '512M');


// ─────────────────────────────────────────────────────────────
// MODE CLI ?
 // ─────────────────────────────────────────────────────────────

$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/../config/database.php';
// ─────────────────────────────────────────────────────────────
// CONNEXION MYSQL PDO
// ─────────────────────────────────────────────────────────────

$host = '127.0.0.1';
$db   = 'digital_library';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {

    $pdo = new PDO($dsn, $user, $pass, $options);

    if ($isCli) {
        echo "✅ Connexion MySQL réussie\n";
    }

} catch (PDOException $e) {

    die("❌ Erreur connexion DB : " . $e->getMessage());
}


// ─────────────────────────────────────────────────────────────
// IMPORTANT : STOP si appelé depuis le navigateur
// ─────────────────────────────────────────────────────────────

if (!$isCli) {
    return;
}


// ─────────────────────────────────────────────────────────────
// CONFIG
// ─────────────────────────────────────────────────────────────

define('BATCH_SIZE', 150);
define('TARGET_BOOKS', 5000);

// ── Palette catégories (slug → [id, nom]) ────────────────────
// Les IDs doivent correspondre à ceux insérés par la migration.
// Ajuste si tes IDs diffèrent — le script lit la table en live.
$CATEGORIES = [
    'roman'       => 'Roman',
    'sf'          => 'Science-Fiction',
    'fantasy'     => 'Fantasy',
    'thriller'    => 'Policier / Thriller',
    'histoire'    => 'Histoire',
    'biographie'  => 'Biographie',
    'dev'         => 'Développement personnel',
    'info'        => 'Informatique',
    'education'   => 'Éducation',
    'sante'       => 'Santé',
    'finance'     => 'Finance',
    'religion'    => 'Religion',
    'poesie'      => 'Poésie',
    'philo'       => 'Philosophie',
    'tech'        => 'Technologie',
    'art'         => 'Art & Culture',
    'presse'      => 'Journal / Presse',
    'recherche'   => 'Recherche académique',
];

// ─────────────────────────────────────────────────────────────
// DONNÉES SOURCES
// ─────────────────────────────────────────────────────────────

// Auteurs par catégorie (réels + fictifs crédibles)
$AUTHORS_BY_CAT = [
    'roman'      => ['Émile Zola','Gustave Flaubert','Stendhal','Guy de Maupassant','George Sand','Honoré de Balzac','Colette','André Gide','François Mauriac','Marguerite Yourcenar','Patrick Modiano','Annie Ernaux','Michel Houellebecq','Amélie Nothomb','Marc Lévy','Guillaume Musso','Virginie Grimaldi','Françoise Sagan','Romain Gary','Jean-Paul Sartre'],
    'sf'         => ['Isaac Asimov','Philip K. Dick','Arthur C. Clarke','Frank Herbert','Ray Bradbury','Ursula K. Le Guin','Octavia Butler','Kim Stanley Robinson','William Gibson','Neal Stephenson','Greg Bear','Dan Simmons','Iain M. Banks','Peter F. Hamilton','Alastair Reynolds','Ted Chiang','N.K. Jemisin','Andy Weir','Liu Cixin','Brandon Sanderson'],
    'fantasy'    => ['J.R.R. Tolkien','George R.R. Martin','Terry Pratchett','Patrick Rothfuss','Robert Jordan','Brandon Sanderson','Ursula K. Le Guin','Robin Hobb','Joe Abercrombie','Steven Erikson','Scott Lynch','Michael J. Sullivan','Brent Weeks','Peter V. Brett','Brian McClellan','V.E. Schwab','Naomi Novik','Andrzej Sapkowski','Michael Moorcock','Fritz Leiber'],
    'thriller'   => ['Agatha Christie','Arthur Conan Doyle','Harlan Coben','Michael Connelly','John Grisham','James Patterson','Gillian Flynn','Stieg Larsson','Henning Mankell','Fred Vargas','Pierre Lemaitre','Franck Thilliez','Michel Bussi','Gilles Legardinier','Romain Sardou','Bernard Minier','Maxime Chattam','Camilla Läckberg','Jo Nesbø','Karin Slaughter'],
    'histoire'   => ['Yuval Noah Harari','Fernand Braudel','Georges Duby','Emmanuel Le Roy Ladurie','Marc Bloch','Lucien Febvre','Jacques Le Goff','Michèle Riot-Sarcey','Paul Veyne','Pierre Nora','Alain Corbin','Michelle Perrot','Jean-Pierre Vernant','Moses Finley','Bernard Lewis','John Keegan','Robert Paxton','Tony Judt','Eric Hobsbawm','Niall Ferguson'],
    'biographie' => ['Walter Isaacson','Robert Caro','David McCullough','Sylvain Tesson','Jean Lacouture','Éric Roussel','Diane de Margerie','Stacy Schiff','Catherine Clément','Vladimir Fédorovski','Bernard Violet','Jean Lacouture','Max Gallo','Alain Decaux','Jean-Christian Petitfils','Dominique de Villepin','Philippe Séguin','François-Guillaume Lorrain','Gilles Perrault','Laure Adler'],
    'dev'        => ['Stephen R. Covey','Dale Carnegie','Napoleon Hill','Brian Tracy','Tony Robbins','Brené Brown','Carol Dweck','Daniel Goleman','Malcolm Gladwell','James Clear','Mark Manson','Tim Ferriss','Robin Sharma','Simon Sinek','Adam Grant','Cal Newport','David Allen','Mihaly Csikszentmihalyi','Viktor Frankl','Gary Keller'],
    'info'       => ['Robert C. Martin','Martin Fowler','Kent Beck','Andrew Hunt','David Thomas','Donald Knuth','Jon Bentley','Brian Kernighan','Dennis Ritchie','Bjarne Stroustrup','Grady Booch','Eric Evans','Vaughn Vernon','Michael Feathers','Steve McConnell','Jeff Sutherland','Mike Cohn','Jeffrey Palermo','Mark Pilgrim','Chris Richardson'],
    'education'  => ['Jean Piaget','Lev Vygotski','Maria Montessori','John Dewey','Howard Gardner','Ken Robinson','Paulo Freire','Jerome Bruner','Albert Bandura','Seymour Papert','Lisa Delpit','Ruby Payne','Peter Senge','Alfie Kohn','Daniel Willingham','John Hattie','Robert Marzano','Grant Wiggins','Jay McTighe','Carol Ann Tomlinson'],
    'sante'      => ['Thierry Souccar','Frédéric Saldmann','David Servan-Schreiber','Christophe André','Matthew Walker','Andrew Weil','Deepak Chopra','Mark Hyman','Joel Fuhrman','Michael Greger','Dean Ornish','Sanjay Gupta','Mehmet Oz','Brené Brown','Gabor Maté','Bessel van der Kolk','Peter Levine','Tara Brach','Jon Kabat-Zinn','Herbert Benson'],
    'finance'    => ['Robert T. Kiyosaki','Warren Buffett','Peter Lynch','Benjamin Graham','George Soros','Ray Dalio','Nassim Taleb','Michael Lewis','John Bogle','William Bernstein','Burton Malkiel','Jeremy Siegel','Howard Marks','Seth Klarman','Joel Greenblatt','Philip Fisher','David Swensen','Andrew Tobias','Gus Sauter','Larry Swedroe'],
    'religion'   => ['Huston Smith','Karen Armstrong','Reza Aslan','Bart Ehrman','N.T. Wright','Timothy Keller','John Stott','Henri Nouwen','Thomas Merton','Thich Nhat Hanh','Dalai Lama','Tariq Ramadan','Mohamed Arkoun','Bruno Étienne','Gilles Kepel','Olivier Roy','Jean-Marie Lustiger','Xavier Thévenot','Louis-Marie Chauvet','Albert Rouet'],
    'poesie'     => ['Charles Baudelaire','Arthur Rimbaud','Paul Verlaine','Stéphane Mallarmé','Guillaume Apollinaire','Paul Éluard','Louis Aragon','Jacques Prévert','René Char','Yves Bonnefoy','Philippe Jaccottet','Michel Deguy','Bernard Noël','Salah Stétié','Tahar Ben Jelloun','Léopold Sédar Senghor','Aimé Césaire','Édouard Glissant','Saint-John Perse','Henri Michaux'],
    'philo'      => ['Platon','Aristote','René Descartes','Immanuel Kant','Friedrich Nietzsche','Martin Heidegger','Jean-Paul Sartre','Simone de Beauvoir','Albert Camus','Michel Foucault','Jacques Derrida','Gilles Deleuze','Paul Ricœur','Emmanuel Lévinas','Jürgen Habermas','Hannah Arendt','Ludwig Wittgenstein','Bertrand Russell','John Rawls','Martha Nussbaum'],
    'tech'       => ['Ray Kurzweil','Nick Bostrom','Stuart Russell','Pedro Domingos','Yoshua Bengio','Geoffrey Hinton','Yann LeCun','Elon Musk','Reid Hoffman','Peter Thiel','Andrew Ng','Kai-Fu Lee','Max Tegmark','Jaron Lanier','Kevin Kelly','Steven Levy','Walter Isaacson','John Markoff','Paul Graham','Eric Ries'],
    'art'        => ['Ernst H. Gombrich','John Berger','Robert Hughes','Kenneth Clark','Clement Greenberg','Leo Steinberg','T.J. Clark','Rosalind Krauss','Hal Foster','Benjamin Buchloh','Meyer Schapiro','Michael Fried','Norman Bryson','Griselda Pollock','Linda Nochlin','Anne Higonnet','Whitney Davis','David Carrier','Arthur Danto','Thierry de Duve'],
    'presse'     => ['Albert Londres','Ryszard Kapuściński','Hunter S. Thompson','Tom Wolfe','Joan Didion','Gay Talese','Truman Capote','Michael Herr','John Hersey','Sebastian Junger','Jon Krakauer','Erik Larson','David Grann','Lawrence Wright','Katherine Boo','Matthew Desmond','Adrian Nicole LeBlanc','Susan Orlean','Nora Ephron','Janet Malcolm'],
    'recherche'  => ['Stephen Hawking','Richard Feynman','Carl Sagan','E.O. Wilson','Richard Dawkins','Daniel Dennett','Steven Pinker','Oliver Sacks','V.S. Ramachandran','Antonio Damasio','Eric Kandel','Joseph Ledoux','Michael Gazzaniga','Christof Koch','Stanislas Dehaene','Wolf Singer','Gerald Edelman','Francis Crick','James Watson','Craig Venter'],
];

// Patterns de titres par catégorie
$TITLE_PATTERNS = [
    'roman' => [
        'La {adj} {noun}', 'Les {noun}s de {place}', 'Le {noun} de {name}',
        'Une vie de {adj}', 'Les héritiers de {place}', 'L\'âme du {noun}',
        'Au cœur de {place}', 'La dernière {noun}', 'L\'enfant de {adj}',
        'Les chemins de {name}', 'Le secret des {noun}s', 'Une {adj} saison',
        'Chroniques de {place}', 'La {noun} oubliée', 'Les silences de {name}',
    ],
    'sf' => [
        'L\'empire des {noun}s', 'Voyage vers {planet}', 'Le dernier {noun}',
        'Au-delà de {planet}', 'Chroniques galactiques : {subtitle}',
        'La machine à {noun}', 'Les enfants de {planet}', 'Protocole {code}',
        'Nexus {num}', 'Singularité {noun}', 'L\'ère des {noun}s',
        'Station {planet}', 'Mémoire artificielle', 'Le signal de {planet}',
        'Au temps des {noun}s', 'Horizon {num}', 'La convergence',
    ],
    'fantasy' => [
        'Le trône de {place}', 'La saga des {noun}s', 'L\'épée de {name}',
        'Les chroniques de {place}', 'Le grimoire de {name}', 'La quête du {noun}',
        'Les gardiens de {place}', 'L\'éveil du {noun}', 'La prophétie de {name}',
        'Les ombres de {place}', 'Le dernier mage', 'L\'héritier du {noun}',
        'Les terres de {place}', 'La flamme de {name}', 'Royaumes brisés : {subtitle}',
    ],
    'thriller' => [
        'Le silence des {noun}s', 'La dernière victime', 'Meurtre à {city}',
        'L\'enquête du {noun}', 'Code rouge : {subtitle}', 'La traque',
        'Sans laisser de trace', 'La filière {noun}', 'Mort sur {city}',
        'Le détective de {city}', 'Enquête à {city}', 'La disparition',
        'Double jeu', 'L\'ombre du {noun}', 'Crimes en série : {subtitle}',
        'Suspect numéro {num}', 'À bout portant', 'Le dossier {noun}',
    ],
    'histoire' => [
        'Histoire de {place}', 'Les grandes batailles de {period}',
        'L\'empire {adj}', 'La chute de {place}', 'Révolution : {subtitle}',
        'Les bâtisseurs de {place}', 'L\'âge d\'or de {place}',
        'La civilisation {adj}', 'Mémoires de {period}', 'Le siècle de {name}',
        'La guerre de {period}', 'Aux origines de {place}', 'Portraits d\'{period}',
        'L\'héritage de {name}', 'La naissance de {place}',
    ],
    'biographie' => [
        '{name} : une vie', 'La vie extraordinaire de {name}',
        '{name} : le destin d\'un homme', 'Mémoires d\'un {adj}',
        '{name} : portrait', 'L\'ascension de {name}',
        '{name} vu de l\'intérieur', 'Les secrets de {name}',
        'Dans l\'ombre de {name}', 'Le roman vrai de {name}',
        '{name} : journal intime', 'La chute de {name}',
        'L\'héritage de {name}', '{name} : confessions',
        'Le monde selon {name}',
    ],
    'dev' => [
        'Guide complet de {adj}', 'Les secrets de {noun}',
        'Devenez {adj} en {num} jours', 'Le pouvoir du {noun}',
        'Maîtrisez votre {noun}', 'L\'art de {adj}',
        'Développez votre {noun}', 'Les {num} piliers du succès',
        'Transformez votre {noun}', 'La méthode {noun}',
        'Introduction à {noun}', 'Vivez pleinement',
        'Cultivez votre {noun}', 'Changez de {noun}',
        'Les clés de {noun}',
    ],
    'info' => [
        'Maîtriser {lang}', 'Guide pratique de {lang}',
        'Architecture {noun}', '{lang} en profondeur',
        'Clean {noun}', 'Patterns de {noun}',
        'Introduction à {lang}', '{lang} avancé',
        'Développement {noun} avec {lang}', 'Principes de {noun}',
        'DevOps : {subtitle}', 'Cloud {noun}',
        'Sécurité {noun}', 'API {noun}',
        'Microservices : {subtitle}',
    ],
    'education' => [
        'Apprendre à {noun}', 'Pédagogie {adj}',
        'L\'éducation {adj}', 'Former pour {noun}',
        'L\'école de demain', 'Enseigner autrement',
        'Le cerveau qui apprend', 'Intelligence et {noun}',
        'Méthodes {adj}s', 'L\'élève au cœur',
        'Guide du parent {adj}', 'Repenser l\'école',
        'Les {num} lois de l\'apprentissage', 'Accompagner {noun}',
        'Réussite scolaire : {subtitle}',
    ],
    'sante' => [
        'Vivez en bonne santé', 'Le régime {adj}',
        'Comprendre votre {noun}', 'Guérir par {noun}',
        'La médecine de {adj}', 'Zéro maladie',
        'L\'alimentation {adj}', 'Bien dormir',
        'Stress et {noun}', 'La santé par {noun}',
        'Protocole {noun}', 'Le corps {adj}',
        'Prévenir et guérir', 'L\'intestin, notre {noun}',
        'Guide santé : {subtitle}',
    ],
    'finance' => [
        'Père riche, père pauvre — {subtitle}', 'Investissez malin',
        'L\'argent ne dort pas', 'Guide de l\'investisseur {adj}',
        'Les marchés financiers', 'Cryptomonnaies : {subtitle}',
        'Bourse et {noun}', 'Stratégies {adj}s',
        'Construire votre {noun}', 'Finances personnelles',
        'L\'indépendance financière', 'Trading {noun}',
        'Épargne et {noun}', 'Les {num} règles d\'or',
        'Immobilier : {subtitle}',
    ],
    'religion' => [
        'À la source de {noun}', 'Comprendre l\'Islam',
        'Le christianisme {adj}', 'Bouddhisme et {noun}',
        'Spiritualité {adj}', 'Dieu et {noun}',
        'Les grandes religions', 'La foi en {noun}',
        'Chemin de {noun}', 'Sagesse de {noun}',
        'Le {noun} intérieur', 'Mystiques de {place}',
        'Prière et {noun}', 'L\'âme et {noun}',
        'Initiation à {noun}',
    ],
    'poesie' => [
        'Chants de {adj}', 'Élégies pour {noun}',
        'L\'âme mise à nu', 'Fragments de {noun}',
        'Odes à {noun}', 'Sonnets {adj}s',
        'Le souffle de {noun}', 'Jardins de {noun}',
        'Paroles de {adj}', 'Vertige du {noun}',
        'Silence et {noun}', 'Les mots de {adj}',
        'Cendres et {noun}', 'Lumières de {noun}',
        'Poèmes de {place}',
    ],
    'philo' => [
        'L\'essence de {noun}', 'Penser le {noun}',
        'Introduction à la {noun}', 'Le problème du {noun}',
        'Être et {noun}', 'Raison et {noun}',
        'La question de {noun}', 'Éthique et {noun}',
        'Philosophie de {noun}', 'Le sens de {noun}',
        'Liberté et {noun}', 'Ontologie du {noun}',
        'La vérité sur {noun}', 'Critique de {noun}',
        'Méditations sur {noun}',
    ],
    'tech' => [
        'L\'IA et {noun}', 'Révolution {noun}',
        'Le futur de {noun}', 'Technologie et {noun}',
        'Intelligence artificielle : {subtitle}', 'Numérique et {noun}',
        'Data {noun}', 'Blockchain et {noun}',
        'L\'algorithme de {noun}', 'Robotique et {noun}',
        'Deep Learning : {subtitle}', 'Cybersécurité : {subtitle}',
        'IoT et {noun}', 'Le code de {noun}',
        'Automatisation et {noun}',
    ],
    'art' => [
        'Histoire de l\'art {adj}', 'Peindre avec {noun}',
        'L\'œuvre de {name}', 'Comprendre {noun}',
        'Esthétique {adj}', 'Les maîtres de {place}',
        'Photographie et {noun}', 'Design : {subtitle}',
        'La sculpture {adj}', 'Architecture de {place}',
        'L\'art de {adj}', 'Musique et {noun}',
        'Cinéma : {subtitle}', 'Théâtre et {noun}',
        'La mode comme {noun}',
    ],
    'presse' => [
        'Reportage : {place}', 'Enquête sur {noun}',
        'Journal de bord : {place}', 'Les coulisses de {noun}',
        'La vérité sur {noun}', 'Témoignage : {subtitle}',
        'Chroniques de {place}', 'Dans les entrailles de {noun}',
        'Au cœur de {noun}', 'L\'affaire {name}',
        'Grand reportage : {subtitle}', 'Dossier {noun}',
        'Face à face avec {noun}', 'L\'investigation {adj}',
        'Révélations sur {noun}',
    ],
    'recherche' => [
        'Étude sur {noun}', 'Théorie du {noun}',
        'Introduction à la {noun}', 'Modèles de {noun}',
        'Fondements de {noun}', 'Analyse de {noun}',
        'Paradigmes et {noun}', 'Méthodes en {noun}',
        'Revue critique de {noun}', 'Avancées en {noun}',
        'Synthèse sur {noun}', 'Perspective {adj}',
        'Approches de {noun}', 'Traité de {noun}',
        'Épistémologie et {noun}',
    ],
];

// Vocabulaire pour remplir les patterns
$VOCAB = [
    'adj'      => [
        'moderne','secret','perdu','oublié','nouveau','ancien','grand','profond','intime',
        'universel','mystérieux','lumineux','sombre','libre','sacré','vivant','étrange',
        'singulier','complexe','absurde','précieux','fragile','immense','digital','humain',
        'naturel','critique','fondamental','essentiel','radical','invisible','silencieux',
        'brillant','obscur','courageux','brisé','renouvelé','nocturne','céleste','vertigineux',
        'subversif','clandestin','nomade','rebelle','puissant','transcendant','latent','premier',
        'ultime','déchiré','abyssal','ironique','parallèle','terrestre','vivace','archaïque',
    ],
    'noun'     => [
        'silence','temps','mémoire','monde','vie','esprit','corps','chemin','pouvoir',
        'lumière','ombre','destinée','liberté','vérité','justice','beauté','nature','âme',
        'cœur','futur','histoire','langage','savoir','identité','sens','émotion','conscience',
        'raison','désir','bonheur','confiance','force','vision','changement','succès',
        'résilience','cerveau','regard','voix','souffle','frontière','rupture','équilibre',
        'origine','racine','horizon','abîme','vertige','miroir','masque','empreinte','héritage',
        'passage','lien','crépuscule','éveil','fardeau','promesse','doute','mystère','secret',
        'labyrinthe','cycle','fragment','éclat','fracture','renaissance','destin','trajectoire',
        'seuil','exil','erreur','révélation','courage','parole','silence','cycle','faille',
    ],
    'name'     => [
        'Marie','Pierre','Antoine','Sophie','Théodore','Claire','Victor','Isabelle','François',
        'Élisa','Louis','Charlotte','Julien','Mathilde','Gabriel','Lucie','Étienne','Camille',
        'Léon','Adèle','Hugo','Margot','Émile','Inès','Albert','Nathalie','Raphaël','Diane',
        'Paul','Céleste','Ariane','Tristan','Léa','Nathan','Alice','Clément','Sara','Romain',
        'Emma','Simon','Zara','Omar','Karim','Nadia','Amara','Ibrahima','Fatou','Yasmine',
        'Lena','Marco','Elena','Sasha','Noah','Rania','Amir','Cléo','Théa','Axel','Zoé',
        'Tariq','Sophia','Elio','Mila','Bastien','Aurore','Luc','Béatrice','Renaud','Laure',
    ],
    'place'    => [
        'Paris','Lyon','Bordeaux','Marseille','Venise','Rome','Berlin','Londres','New York',
        'Tokyo','Alger','Dakar','Montréal','Genève','Le Caire','Nairobi','Buenos Aires',
        'Shanghai','Mumbai','Istanbul','Bruxelles','Amsterdam','Barcelone','Mexico','Lagos',
        'Dubaï','Singapour','Kyoto','Prague','Vienne','Lisbonne','Athènes','Oslo','Stockholm',
        'Cape Town','Abidjan','Casablanca','Téhéran','Séoul','Bangkok','Bogotá','Lima',
        'Santiago','Accra','Tunis','Rabat','Beyrouth','Hanoï','Jakarta','Manila','Kinshasa',
        'Lomé','Cotonou','Bamako','Ouagadougou','Yaoundé','Douala','Kigali','Antananarivo',
    ],
    'planet'   => [
        'Proxima','Kepler','Solaris','Andromède','Véga','Novus','Arcturus','Cygnus','Lyra',
        'Eridanus','Tau Ceti','Sigma','Omicron','Delta','Epsilon','Sirius','Altair','Deneb',
        'Rigel','Procyon','Orion','Cassiopée','Persée','Draco','Aquila','Auriga','Centauri',
        'Hyperion','Calypso','Titan','Elysium','Nexara','Vortex','Kronos','Helios','Nyx',
    ],
    'code'     => [
        'Alpha','Beta','Gamma','Delta','Omega','Sigma','Zeta','Nexus','Apex','Zero','One',
        'X','Noir','Bleu','Zéro','Seven','Infini','Ultime','Suprême','Final','Écho','Vortex',
        'Phantom','Ghost','Nova','Cipher','Matrix','Vector','Pulse','Helix','Zenith','Abyss',
    ],
    'num'      => ['7','12','21','30','50','100','3','10','5','15','20','365','6','8','9','42','11','99'],
    'period'   => [
        "l'Antiquité","la Renaissance","l'Empire","la Révolution","la Guerre froide",
        "l'Âge des Lumières","la Belle Époque","la Grande Guerre","l'Occupation",
        "la Décolonisation","l'Empire romain","la Grèce antique","la Chine impériale",
        "le Moyen Âge","la Préhistoire","la Seconde Guerre mondiale","l'Entre-deux-guerres",
        "les Trente Glorieuses","la Colonisation","la Postmodernité",
    ],
    'city'     => [
        'Paris','Lyon','New York','Londres','Berlin','Venise','Rome','Madrid','Istanbul',
        'Prague','Lisbonne','Bruxelles','Genève','Vienne','Amsterdam','Barcelone','Dublin',
        'Budapest','Varsovie','Sofia','Athènes','Copenhague','Helsinki','Oslo','Reykjavik',
        'Séoul','Melbourne','Toronto','Chicago','Miami','Los Angeles','San Francisco','Nairobi',
    ],
    'subtitle' => [
        'le début','la rupture','la révolution','le commencement','l\'évolution','la chute',
        'le retour','la résistance','le silence','l\'héritage','les origines','le paradoxe',
        'la décision','la quête','le défi','la renaissance','l\'éclipse','le dernier acte',
        'la convergence','la faille','le tournant','l\'exil','la reconquête','la mémoire',
        'le vertige','le seuil','l\'invisible','la tempête','le miroir','la fracture',
    ],
    'lang'     => [
        'Python','JavaScript','PHP','Java','C++','Rust','Go','TypeScript','SQL','Kotlin',
        'Swift','R','Scala','C#','Ruby','Dart','Lua','Haskell','Elixir','Clojure',
        'Vue.js','React','Node.js','Django','Laravel','Spring Boot','Flutter','Terraform',
        'Kubernetes','Docker','GraphQL','MongoDB','PostgreSQL','Redis','Kafka',
    ],
];


// Éditeurs réels
$PUBLISHERS = [
    'Gallimard','Seuil','Flammarion','Albin Michel','Fayard','Grasset','La Découverte',
    'Odile Jacob','PUF','CNRS Éditions','Belin','Dunod','Eyrolles','Larousse',
    'Le Livre de Poche','Folio','J\'ai Lu','Pocket','Marabout','First Éditions',
    'Hachette','Stock','Calmann-Lévy','Robert Laffont','Actes Sud','L\'École des Loisirs',
    'Nathan','Delagrave','Armand Colin','De Boeck','O\'Reilly','Apress','Packt',
    'Pearson','Manning','Prentice Hall','Springer','Wiley','CRC Press','MIT Press',
    'Oxford University Press','Cambridge University Press','Harvard University Press',
];

// Langues (majorité FR, quelques EN)
$LANGUAGES = array_merge(array_fill(0, 85, 'Français'), array_fill(0, 15, 'Anglais'));

// Descriptions génériques réalistes par catégorie
$DESCRIPTIONS = [
    'roman'      => ["Un roman bouleversant qui explore les profondeurs de l'âme humaine à travers le destin d'un personnage inoubliable. L'auteur, avec une plume précise et sensible, tisse une intrigue dont chaque page révèle une nouvelle facette de la condition humaine.", "Une fiction littéraire d'une rare intensité, portée par une écriture ciselée et des personnages complexes. Ce récit captivant nous plonge dans un univers à la fois familier et étrange, entre lumière et ombre.", "Dans ce roman haletant, l'auteur explore avec finesse les tensions d'une société contemporaine traversée par ses contradictions. Un texte qui questionne notre rapport à l'autre et à nous-mêmes."],
    'sf'         => ["Un récit de science-fiction ambitieux qui interroge notre rapport à la technologie, à l'intelligence et à l'humanité. L'auteur construit un monde d'une cohérence remarquable où chaque détail fait sens.", "Dans un futur proche ou lointain, ce roman explore les conséquences de choix technologiques sur la société humaine. Une œuvre visionnaire qui dépasse les frontières du genre pour toucher à l'universel.", "Un space opera épique ou une dystopie intimiste — ce livre de SF captive autant par ses idées que par ses personnages, confrontés à des dilemmes profondément humains dans un cadre futuriste."],
    'fantasy'    => ["Une épopée fantastique d'une richesse incomparable, portée par une world-building soigné et des personnages aux destinées croisées. L'auteur invente un monde vivant, avec sa géographie, son histoire et sa magie propres.", "Dans ce récit de fantasy, magie et politique s'entremêlent dans un monde médiéval imaginaire d'une grande cohérence. Les héros affrontent des épreuves qui révèlent leur véritable nature.", "L'auteur signe un roman de fantasy captivant, mêlant aventure, humour et profondeur philosophique. Les créatures, les sorts et les royaumes décrits invitent le lecteur dans une immersion totale."],
    'thriller'   => ["Un thriller psychologique ou policier haletant, construit autour d'un mystère dont la résolution tient en haleine jusqu'à la dernière page. L'auteur maîtrise l'art de la tension narrative.", "Une enquête minutieuse au cœur des bas-fonds ou des hautes sphères du pouvoir. Les rebondissements s'enchaînent dans ce polar digne des meilleures plumes du genre.", "Ce roman policier à l'atmosphère sombre et oppressante plonge le lecteur dans une investigation complexe. Les faux semblants et les retournements de situation en font une lecture addictive."],
    'histoire'   => ["Un ouvrage historique rigoureux et accessible qui revisite une période clé de l'histoire humaine sous un angle nouveau. L'auteur, historien reconnu, s'appuie sur des sources inédites.", "Cette synthèse historique offre une lecture vivante et documentée d'une époque ou d'un événement majeur. L'auteur allie profondeur scientifique et clarté de l'écriture pour rendre l'histoire vivante.", "Un essai historique qui remet en question les idées reçues et propose une relecture critique des grands événements qui ont façonné notre monde. Indispensable pour comprendre le présent."],
    'biographie' => ["Une biographie fouillée qui retrace le parcours exceptionnel d'une figure marquante de son temps. L'auteur puise dans des archives inédites et des témoignages exclusifs pour brosser un portrait nuancé.", "Un portrait biographique saisissant qui mêle histoire intime et grande histoire. L'auteur restitue avec précision le contexte de l'époque tout en révélant les ressorts psychologiques du personnage.", "Cette biographie passionnante raconte une vie hors du commun, faite de triomphes et d'épreuves. L'auteur, avec empathie et rigueur, reconstitue un destin qui a marqué son siècle."],
    'dev'        => ["Un guide pratique de développement personnel fondé sur des recherches scientifiques solides et des exemples concrets. L'auteur propose des outils directement applicables pour transformer sa vie quotidienne.", "Ce livre de développement personnel offre une méthode structurée pour atteindre ses objectifs, améliorer ses relations et trouver un équilibre durable. Un ouvrage de référence cité par des milliers de lecteurs.", "L'auteur distille des années d'expérience et de recherche dans cet ouvrage accessible et motivant. Chaque chapitre propose des exercices pratiques pour développer ses compétences et son potentiel."],
    'info'       => ["Un ouvrage technique complet qui couvre les fondements et les techniques avancées du domaine. Rédigé par un expert reconnu, il allie théorie et pratique avec de nombreux exemples de code commentés.", "Un livre de référence pour les développeurs et architectes souhaitant maîtriser un aspect essentiel du développement logiciel moderne. Les explications claires et les cas d'usage réels en font un outil indispensable.", "Ce guide pratique et rigoureux s'adresse aux professionnels souhaitant approfondir leurs compétences techniques. Il présente les meilleures pratiques, les pièges à éviter et les patterns éprouvés de l'industrie."],
    'education'  => ["Un ouvrage pédagogique incontournable qui repense les fondements de l'apprentissage et propose des approches innovantes pour enseigner et former. L'auteur s'appuie sur des études récentes en neurosciences cognitives.", "Cette synthèse éducative offre aux enseignants, formateurs et parents des outils concrets pour favoriser le développement intellectuel et émotionnel des apprenants à tous les âges.", "Un livre qui interroge les pratiques éducatives actuelles et propose un cadre renouvelé pour penser la transmission des savoirs. Indispensable pour tout acteur du monde de l'éducation."],
    'sante'      => ["Un guide santé rigoureux et accessible qui s'appuie sur les dernières avancées médicales pour proposer une approche globale du bien-être physique et mental. L'auteur, professionnel de santé, donne des conseils pratiques et fondés.", "Ce livre offre un programme complet pour prendre en main sa santé au quotidien, de l'alimentation au sommeil en passant par la gestion du stress. Chaque recommandation est étayée par des études scientifiques.", "Un ouvrage de santé préventive et holistique qui aide le lecteur à comprendre son corps et à adopter des habitudes durables. L'auteur combine médecine conventionnelle et approches complémentaires."],
    'finance'    => ["Un guide financier clair et structuré qui démystifie les marchés, les placements et la gestion de patrimoine. L'auteur, expert reconnu, adapte des stratégies éprouvées à la réalité des lecteurs ordinaires.", "Ce livre de finance personnelle propose une méthode concrète pour comprendre, épargner, investir et atteindre l'indépendance financière. Les concepts complexes sont expliqués de façon accessible et illustrée.", "Un ouvrage de référence sur les marchés financiers ou la gestion d'actifs, rédigé par un praticien expérimenté. L'auteur partage ses méthodes d'analyse et ses principes d'investissement disciplinés."],
    'religion'   => ["Un essai érudit et accessible qui explore les fondements, l'histoire et l'actualité d'une tradition religieuse majeure. L'auteur adopte une perspective à la fois académique et respectueuse des croyances.", "Ce livre propose une lecture approfondie des textes sacrés ou des pratiques spirituelles d'une tradition religieuse, en les resituant dans leur contexte historique et culturel. Une contribution au dialogue interreligieux.", "Un ouvrage qui interroge les grandes questions de sens, de foi et de transcendance à travers le prisme d'une tradition spirituelle vivante. L'auteur ouvre un espace de réflexion ouvert et bienveillant."],
    'poesie'     => ["Un recueil poétique d'une grande sensibilité, où chaque poème est une fenêtre ouverte sur l'intime et l'universel. La langue, travaillée jusqu'à l'os, révèle des images d'une beauté saisissante.", "Ces poèmes vibrants explorent le temps, l'amour, la perte et la joie avec une économie de mots qui touche au plus profond. L'auteur renouvelle la forme poétique tout en s'inscrivant dans une grande tradition.", "Un livre de poésie qui fait entendre une voix singulière, entre tradition et modernité. Chaque texte est une invitation à ralentir, à écouter le monde et à retrouver le sens caché des choses."],
    'philo'      => ["Un essai philosophique rigoureux qui explore une question fondamentale de l'existence humaine. L'auteur mobilise les grandes traditions de la pensée pour proposer une réflexion originale et stimulante.", "Ce livre de philosophie accessible sans être superficiel invite le lecteur à penser par lui-même. L'auteur déconstruit les évidences et ouvre des horizons conceptuels insoupçonnés.", "Une œuvre philosophique ambitieuse qui traverse les siècles et les écoles de pensée pour éclairer un problème contemporain sous un jour nouveau. Un texte exigeant et profondément enrichissant."],
    'tech'       => ["Un ouvrage de vulgarisation scientifique sur les grandes révolutions technologiques en cours. L'auteur, expert reconnu, explique avec clarté les enjeux techniques, économiques et sociaux des innovations majeures.", "Ce livre sur la technologie explore les promesses et les risques de l'intelligence artificielle, du numérique ou de la robotique. L'auteur propose une analyse lucide et nuancée des transformations en cours.", "Un essai stimulant sur l'impact des technologies émergentes sur nos sociétés, nos emplois et nos identités. L'auteur mêle expertise technique et réflexion éthique pour ouvrir un débat nécessaire."],
    'art'        => ["Un ouvrage d'histoire de l'art richement illustré qui retrace l'évolution d'un mouvement, d'un artiste ou d'une époque. L'auteur allie érudition et passion pour rendre l'art accessible à tous.", "Ce livre sur l'art propose une lecture critique et sensible des œuvres majeures d'une tradition artistique. L'auteur nous guide avec intelligence à travers les formes, les couleurs et les significations.", "Une exploration passionnante d'un champ artistique — peinture, sculpture, photographie, musique — qui interroge les frontières entre création, technique et sens. Un livre pour voir et comprendre autrement."],
    'presse'     => ["Un grand reportage journalistique qui plonge le lecteur au cœur d'un conflit, d'une société ou d'un phénomène contemporain. L'auteur, journaliste de terrain, mêle récit vivant et analyse rigoureuse.", "Ce livre de journalisme d'investigation révèle les dessous d'une affaire ou d'un système à travers une enquête minutieuse. L'auteur donne la parole aux témoins et reconstitue les faits avec précision.", "Un témoignage journalistique fort qui éclaire une réalité méconnue ou occultée. L'auteur, au plus près du terrain, livre un récit immersif qui force à remettre en question nos certitudes."],
    'recherche'  => ["Un ouvrage académique de référence qui synthétise les avancées d'un champ scientifique ou universitaire. L'auteur, chercheur établi, propose un cadre théorique rigoureux enrichi d'études de cas.", "Cette recherche interdisciplinaire explore la frontière entre plusieurs disciplines pour proposer une approche novatrice d'un problème complexe. Un travail intellectuellement stimulant, exigeant et fondamental.", "Un traité scientifique qui fait le point sur l'état des connaissances dans un domaine en évolution rapide. L'auteur évalue les théories en présence et propose de nouvelles pistes de recherche prometteuses."],
];

// URLs de couvertures simulées (Picsum + livre spécifique)
function generateCoverUrl(int $id): string {
    $seeds = ['books','library','reading','novel','literature','science','philosophy','history','technology','art'];
    $seed = $seeds[$id % count($seeds)];
    return "https://picsum.photos/seed/book{$id}/300/450";
}

// Chemin PDF simulé
function generatePdfPath(string $slug, int $id): string {
    return "uploads/livres/{$slug}/livre_{$id}.pdf";
}

// ─────────────────────────────────────────────────────────────
// GÉNÉRATEUR DE TITRE
// ─────────────────────────────────────────────────────────────
function generateTitle(string $cat, array $vocab): string {
    global $TITLE_PATTERNS;
    $patterns = $TITLE_PATTERNS[$cat] ?? $TITLE_PATTERNS['roman'];
    $pattern  = $patterns[array_rand($patterns)];

    // Remplace chaque placeholder par un élément aléatoire du vocab correspondant
    return preg_replace_callback('/\{(\w+)\}/', function($m) use ($vocab) {
        $key = $m[1];
        if (isset($vocab[$key])) {
            return $vocab[$key][array_rand($vocab[$key])];
        }
        return $m[0];
    }, $pattern);
}

// ─────────────────────────────────────────────────────────────
// MAIN
// ─────────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    echo "...";
}

// ── S'assurer que les catégories nécessaires existent ─────────
echo "🔧 Vérification / insertion des catégories requises...\n";

$requiredCategories = [
    ['nom' => 'Roman',                 'slug' => 'roman',      'icone' => '📖', 'couleur' => '#8b2252'],
    ['nom' => 'Science-Fiction',       'slug' => 'sf',         'icone' => '🌌', 'couleur' => '#1a4a7a'],
    ['nom' => 'Fantasy',               'slug' => 'fantasy',    'icone' => '🧝', 'couleur' => '#3d1a7a'],
    ['nom' => 'Policier / Thriller',   'slug' => 'thriller',   'icone' => '🔍', 'couleur' => '#1a1a1a'],
    ['nom' => 'Histoire',              'slug' => 'histoire',   'icone' => '📜', 'couleur' => '#7a5a1a'],
    ['nom' => 'Biographie',            'slug' => 'biographie', 'icone' => '👤', 'couleur' => '#5a3a1a'],
    ['nom' => 'Développement personnel','slug' => 'dev',       'icone' => '🌱', 'couleur' => '#1a7a5a'],
    ['nom' => 'Informatique',          'slug' => 'info',       'icone' => '💻', 'couleur' => '#1a3a5a'],
    ['nom' => 'Éducation',             'slug' => 'education',  'icone' => '🎓', 'couleur' => '#5a7a1a'],
    ['nom' => 'Santé',                 'slug' => 'sante',      'icone' => '❤️', 'couleur' => '#7a1a1a'],
    ['nom' => 'Finance',               'slug' => 'finance',    'icone' => '💰', 'couleur' => '#1a5a1a'],
    ['nom' => 'Religion',              'slug' => 'religion',   'icone' => '✝️', 'couleur' => '#5a4a1a'],
    ['nom' => 'Poésie',                'slug' => 'poesie',     'icone' => '🌸', 'couleur' => '#7a1a5a'],
    ['nom' => 'Philosophie',           'slug' => 'philo',      'icone' => '🧠', 'couleur' => '#4a1a7a'],
    ['nom' => 'Technologie',           'slug' => 'tech',       'icone' => '⚙️', 'couleur' => '#1a5a7a'],
    ['nom' => 'Art & Culture',         'slug' => 'art',        'icone' => '🎨', 'couleur' => '#7a1a5a'],
    ['nom' => 'Journal / Presse',      'slug' => 'presse',     'icone' => '📰', 'couleur' => '#4a3a1a'],
    ['nom' => 'Recherche académique',  'slug' => 'recherche',  'icone' => '🔬', 'couleur' => '#1a3a7a'],
];

// ── ÉTAPE 1 : Insérer / mettre à jour les catégories requises ──
// On utilise INSERT ... ON DUPLICATE KEY UPDATE pour être
// idempotent même si la table contient déjà d'autres catégories
// avec des slugs différents (lit, sciences, livres, etc.).
$stmtCat = $pdo->prepare(
    "INSERT INTO categories (nom, slug, icone, couleur)
     VALUES (:nom, :slug, :icone, :couleur)
     ON DUPLICATE KEY UPDATE
         nom    = VALUES(nom),
         icone  = VALUES(icone),
         couleur = VALUES(couleur)"
);
foreach ($requiredCategories as $cat) {
    $stmtCat->execute($cat);
}

// ── ÉTAPE 2 : Charger le mapping slug → id (TOUTES les catégories) ──
$catMap = $pdo->query("SELECT slug, id FROM categories")->fetchAll(PDO::FETCH_KEY_PAIR);
echo "📂 Catégories en base après upsert : " . count($catMap) . "\n";

// ── ÉTAPE 3 : Vérifier que chaque slug requis est bien présent ──
// Si un slug est encore manquant (contrainte UNIQUE sur slug échouée
// à cause d'une collation ou d'un doublon), on l'insère en force.
$slugsRequired = array_keys($CATEGORIES);
$stillMissing  = array_diff($slugsRequired, array_keys($catMap));

if (!empty($stillMissing)) {
    echo "⚠️  Slugs introuvables après upsert, tentative d'insertion directe : "
         . implode(', ', $stillMissing) . "\n";

    // Construire un index nom → catégorie requise pour retrouver les données
    $reqIdx = [];
    foreach ($requiredCategories as $rc) { $reqIdx[$rc['slug']] = $rc; }

    $stmtForce = $pdo->prepare(
        "INSERT IGNORE INTO categories (nom, slug, icone, couleur)
         VALUES (:nom, :slug, :icone, :couleur)"
    );
    foreach ($stillMissing as $slug) {
        if (isset($reqIdx[$slug])) {
            $stmtForce->execute($reqIdx[$slug]);
            echo "  ✔ Inséré : {$slug}\n";
        }
    }
    // Recharger le mapping
    $catMap = $pdo->query("SELECT slug, id FROM categories")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ── ÉTAPE 4 : Dernier contrôle — abort seulement si réellement absent ──
$finalMissing = array_diff($slugsRequired, array_keys($catMap));
if (!empty($finalMissing)) {
    // Dernier recours : tenter de retrouver via le nom (migration ancienne)
    $slugNomFallback = [
        'sf'         => 'Science-Fiction',
        'philo'      => 'Philosophie',
        'histoire'   => 'Histoire',
        'tech'       => 'Technologie',
        'art'        => 'Art & Culture',
        'dev'        => 'Développement Personnel',
    ];
    $stmtByNom = $pdo->prepare("SELECT id FROM categories WHERE nom = ? LIMIT 1");
    foreach ($finalMissing as $slug) {
        $nom = $slugNomFallback[$slug] ?? $CATEGORIES[$slug];
        $stmtByNom->execute([$nom]);
        $row = $stmtByNom->fetchColumn();
        if ($row) {
            // Mettre à jour le slug pour correspondre à ce que le seed attend
            $pdo->prepare("UPDATE categories SET slug = ? WHERE id = ?")->execute([$slug, $row]);
            $catMap[$slug] = (int)$row;
            echo "  🔧 Slug corrigé : '{$nom}' → slug='{$slug}'\n";
        }
    }
    // Recharger une dernière fois
    $catMap = $pdo->query("SELECT slug, id FROM categories")->fetchAll(PDO::FETCH_KEY_PAIR);
    $finalMissing = array_diff($slugsRequired, array_keys($catMap));
    if (!empty($finalMissing)) {
        die("❌ Impossible de résoudre les catégories : " . implode(', ', $finalMissing)
            . "\nVérifiez votre table 'categories'.\n");
    }
}

echo "✅ Toutes les catégories requises sont disponibles (" . count($slugsRequired) . " slugs résolus).\n";

// ── Compter les livres existants ──────────────────────────────
$existingCount = (int) $pdo->query("SELECT COUNT(*) FROM livres WHERE auteur != 'Auteur inconnu'")->fetchColumn();
echo "📚 Livres existants en base : {$existingCount}\n";

$toInsert = max(0, TARGET_BOOKS - $existingCount);
if ($toInsert === 0) {
    echo "✅ La base contient déjà " . TARGET_BOOKS . "+ livres réels. Rien à insérer.\n";
    exit(0);
}
echo "🚀 Génération de {$toInsert} livres...\n";

// ── Préparer la requête d'insertion ──────────────────────────
$sql = "INSERT IGNORE INTO livres
    (titre, auteur, description, prix, categorie_id, annee_parution,
     editeur, langue, pages, statut, access_type,
     note_moyenne, nb_etoiles, nb_lectures, nb_telechargements, nb_ventes,
     is_featured, is_bestseller, couverture, fichier_pdf, created_at)
VALUES
    (:titre, :auteur, :description, :prix, :categorie_id, :annee_parution,
     :editeur, :langue, :pages, :statut, :access_type,
     :note_moyenne, :nb_etoiles, :nb_lectures, :nb_telechargements, :nb_ventes,
     :is_featured, :is_bestseller, :couverture, :fichier_pdf, :created_at)";

$stmt = $pdo->prepare($sql);

// ── Index de déduplication local ─────────────────────────────
$usedTitles = [];
// Charger les titres existants (évite doublons avec seed précédents)
foreach ($pdo->query("SELECT titre FROM livres") as $row) {
    $usedTitles[mb_strtolower($row['titre'])] = true;
}

$slugList    = array_keys($CATEGORIES);
$catCount    = count($slugList);
$inserted    = 0;
$batchCount  = 0;
$skipped     = 0;
$startTime   = microtime(true);

// Précalcul des probabilités de prix par catégorie
$priceFree = ['roman','poesie','presse'];       // plus souvent gratuit
$pricePrem = ['info','tech','recherche','finance']; // plus souvent premium

$pdo->beginTransaction();

for ($i = 0; $i < $toInsert + ($skipped * 2) && $inserted < $toInsert; $i++) {
    // Catégorie en rotation équilibrée (round-robin + légère variation)
    $catIndex = ($i + mt_rand(0, 2)) % $catCount;
    $catSlug  = $slugList[$catIndex];
    $catId    = $catMap[$catSlug];

    // Générer un titre unique
    $attempts = 0;
    do {
        $titre = generateTitle($catSlug, $VOCAB);
        // Ajouter suffixe numérique si collision (rare)
        if ($attempts > 0) {
            $titre .= ' — Vol. ' . ($attempts + 1);
        }
        $attempts++;
        if ($attempts > 20) {
            $titre .= ' (' . substr(md5(uniqid()), 0, 4) . ')';
            break;
        }
    } while (isset($usedTitles[mb_strtolower($titre)]));

    $usedTitles[mb_strtolower($titre)] = true;

    // Auteur
    $authors = $AUTHORS_BY_CAT[$catSlug] ?? $AUTHORS_BY_CAT['roman'];
    $auteur  = $authors[array_rand($authors)];

    // Description
    $descs = $DESCRIPTIONS[$catSlug] ?? $DESCRIPTIONS['roman'];
    $description = $descs[array_rand($descs)];

    // Prix
    if (in_array($catSlug, $priceFree) && mt_rand(0, 2) === 0) {
        $prix = 0;
    } elseif (in_array($catSlug, $pricePrem)) {
        $prix = mt_rand(3500, 12000); // FCFA
    } else {
        $prix = mt_rand(0, 10) < 2 ? 0 : mt_rand(1500, 8000);
    }

    // Access type déduit du prix
    if ($prix === 0) {
        $accessType = 'gratuit';
        $statut     = 'disponible';
    } elseif ($prix >= 5000) {
        $accessType = 'premium';
        $statut     = 'disponible';
    } else {
        $accessType = 'standard';
        $statut     = 'disponible';
    }

    // Année cohérente (anciens classiques + récents)
    $yearRoll = mt_rand(1, 100);
    if ($yearRoll <= 5)       { $annee = mt_rand(1800, 1950); }
    elseif ($yearRoll <= 20)  { $annee = mt_rand(1950, 1990); }
    elseif ($yearRoll <= 60)  { $annee = mt_rand(1990, 2015); }
    else                       { $annee = mt_rand(2015, 2025); }

    // Éditeur
    $editeur = $PUBLISHERS[array_rand($PUBLISHERS)];

    // Langue
    $langue = $LANGUAGES[array_rand($LANGUAGES)];

    // Pages
    $pages = mt_rand(80, 1200);

    // Stats simulées réalistes
    $note       = round(mt_rand(28, 50) / 10, 1); // 2.8 – 5.0
    $nbEtoiles  = mt_rand(0, 15000);
    $nbLectures = (int) ($nbEtoiles * mt_rand(2, 8));
    $nbDl       = (int) ($nbLectures * mt_rand(1, 4) / 10);
    $nbVentes   = $prix > 0 ? mt_rand(0, 8000) : 0;

    // Featured / bestseller (rare)
    $isFeatured   = (mt_rand(1, 20) === 1) ? 1 : 0;
    $isBestseller = ($nbVentes > 5000) ? 1 : 0;

    // URLs et chemins
    $coverSeed = $inserted + $existingCount + 1000;
    $couverture = generateCoverUrl($coverSeed);
    $fichierPdf = generatePdfPath($catSlug, $coverSeed);

    // Date de création distribuée dans le temps
    $daysAgo   = mt_rand(0, 1825); // jusqu'à 5 ans
    $createdAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));

    try {
        $stmt->execute([
            ':titre'              => $titre,
            ':auteur'             => $auteur,
            ':description'        => $description,
            ':prix'               => $prix,
            ':categorie_id'       => $catId,
            ':annee_parution'     => $annee,
            ':editeur'            => $editeur,
            ':langue'             => $langue,
            ':pages'              => $pages,
            ':statut'             => $statut,
            ':access_type'        => $accessType,
            ':note_moyenne'       => $note,
            ':nb_etoiles'         => $nbEtoiles,
            ':nb_lectures'        => $nbLectures,
            ':nb_telechargements' => $nbDl,
            ':nb_ventes'          => $nbVentes,
            ':is_featured'        => $isFeatured,
            ':is_bestseller'      => $isBestseller,
            ':couverture'         => $couverture,
            ':fichier_pdf'        => $fichierPdf,
            ':created_at'         => $createdAt,
        ]);

        $inserted++;
        $batchCount++;

        // Commit par batch
        if ($batchCount >= BATCH_SIZE) {
            $pdo->commit();
            $pdo->beginTransaction();
            $batchCount = 0;
            $elapsed = round(microtime(true) - $startTime, 1);
            $rate    = $elapsed > 0 ? round($inserted / $elapsed) : '—';
            echo "  ✔ {$inserted}/{$toInsert} insérés ({$elapsed}s, ~{$rate} livres/s)\n";
        }
    } catch (PDOException $e) {
        // Doublon ISBN ou contrainte — on passe
        $skipped++;
        continue;
    }
}

// Commit du dernier batch
if ($pdo->inTransaction()) {
    $pdo->commit();
}

// ── Rapport final ─────────────────────────────────────────────
$elapsed = round(microtime(true) - $startTime, 2);
$total   = (int) $pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn();

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║              SEED TERMINÉ — RAPPORT FINAL               ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
echo "║  ✅ Livres insérés cette session : " . str_pad(number_format($inserted), 24) . " ║\n";
echo "║  ⏩ Doublons ignorés            : " . str_pad(number_format($skipped), 24) . " ║\n";
echo "║  📚 Total livres en base        : " . str_pad(number_format($total), 24) . " ║\n";
echo "║  ⏱  Durée                       : " . str_pad(number_format($elapsed, 2) . 's', 24) . " ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
// Répartition par catégorie
echo "\n📊 Répartition par catégorie :\n";
$stats = $pdo->query(
    "SELECT c.nom, COUNT(l.id) AS nb,
            SUM(l.access_type='gratuit') AS gratuit,
            SUM(l.access_type='standard') AS standard,
            SUM(l.access_type='premium') AS premium
     FROM livres l
     JOIN categories c ON c.id = l.categorie_id
     WHERE l.statut = 'disponible'
     GROUP BY c.id, c.nom
     ORDER BY nb DESC"
)->fetchAll();

foreach ($stats as $row) {
    printf("  %-28s %4d livres  (G:%-4d S:%-4d P:%-4d)\n",
        $row['nom'], $row['nb'], $row['gratuit'], $row['standard'], $row['premium']);
}
echo "\n✅ Base prête. Bonne lecture !\n";