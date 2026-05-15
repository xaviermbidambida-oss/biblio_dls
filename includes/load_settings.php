<?php

if (!isset($pdo)) {
    return;
}

$settings = [];

try {

    // Vérifie si la table existe
    $check = $pdo->query("SHOW TABLES LIKE 'settings'");

    if ($check->rowCount() > 0) {

        // Récupère toutes les colonnes disponibles
        $columns = $pdo->query("DESCRIBE settings")
                       ->fetchAll(PDO::FETCH_COLUMN);

        /*
        Adapter automatiquement les noms de colonnes
        selon ta vraie structure SQL
        */

        $keyColumn = null;
        $valueColumn = null;

        // Colonnes possibles
        $possibleKeys = ['setting_key', 'key_name', 'name', 'config_key'];
        $possibleValues = ['setting_value', 'value', 'config_value'];

        foreach ($possibleKeys as $col) {
            if (in_array($col, $columns)) {
                $keyColumn = $col;
                break;
            }
        }

        foreach ($possibleValues as $col) {
            if (in_array($col, $columns)) {
                $valueColumn = $col;
                break;
            }
        }

        // Charger les paramètres seulement si colonnes trouvées
        if ($keyColumn && $valueColumn) {

            $stmt = $pdo->query("
                SELECT `$keyColumn`, `$valueColumn`
                FROM settings
            ");

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

                $settings[$row[$keyColumn]] = $row[$valueColumn];
            }
        }
    }

} catch (Exception $e) {

    error_log($e->getMessage());

    $settings = [];
}


if (!isset($pdo)) {
    return;
}

$settings = [];

$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}