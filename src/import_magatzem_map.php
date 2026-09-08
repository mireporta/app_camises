<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// =========================================================
// 1. NOMÉS ACCEPTEM POST
// =========================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['map_message'] = "❌ Accés no permès.";
    $_SESSION['map_message_type'] = "error";

    header("Location: ../public/magatzem_map.php");
    exit;
}


// =========================================================
// 2. VALIDACIÓ CONTRASENYA
// =========================================================

$pwd = $_POST['import_password'] ?? '';

if (!defined('IMPORT_PASSWORD') || $pwd !== IMPORT_PASSWORD) {

    $_SESSION['map_message'] =
        "❌ Contrasenya d'importació incorrecta.";

    $_SESSION['map_message_type'] = "error";

    header("Location: ../public/magatzem_map.php");
    exit;
}


// =========================================================
// 3. VALIDACIÓ FITXER
// =========================================================

if (
    !isset($_FILES['xlsx_file']) ||
    $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK
) {

    $_SESSION['map_message'] =
        "❌ Cal seleccionar un fitxer XLSX vàlid.";

    $_SESSION['map_message_type'] = "error";

    header("Location: ../public/magatzem_map.php");
    exit;
}


$tmpFile = $_FILES['xlsx_file']['tmp_name'];


// =========================================================
// 4. IMPORT
// =========================================================

try {

    $spreadsheet = IOFactory::load($tmpFile);
    $sheet = $spreadsheet->getActiveSheet();

    $rows = $sheet->toArray(
        null,
        true,
        true,
        true
    );


    if (!$rows || count($rows) < 2) {
        throw new Exception(
            "El fitxer està buit o no té dades."
        );
    }


    // =====================================================
    // VARIABLES
    // =====================================================

    $errors = [];
    $importats = 0;

    /*
     * unitId => posició desitjada
     *
     * Exemple:
     * 45 => 01A03
     */
    $desiredPosByUnit = [];

    /*
     * posició => unitId
     *
     * Serveix per detectar que dues camises
     * no vagin a la mateixa posició.
     */
    $desiredUnitByPos = [];

    /*
     * Unitats que participen en aquest import.
     *
     * Serveix per permetre swaps:
     *
     * camisa A: 01A01 -> 01A02
     * camisa B: 01A02 -> 01A01
     */
    $incomingUnitIds = [];


    // =====================================================
    // 5. LLEGIR CAPÇALERA
    // =====================================================

    $headerRow = array_shift($rows);

    $header = array_map(
        fn($h) => strtolower(trim((string)$h)),
        $headerRow
    );


    // Com que toArray(..., true) conserva A/B/C...
    // array_search ens retorna la lletra de columna.

    $colPos = array_search(
        'posicio',
        $header,
        true
    );

    $colSer = array_search(
        'serial',
        $header,
        true
    );


    if ($colPos === false || $colSer === false) {

        throw new Exception(
            "Capçalera incorrecta. " .
            "Calen les columnes: posicio i serial."
        );
    }


    // =====================================================
    // 6. COMENÇAR TRANSACCIÓ
    // =====================================================

    $pdo->beginTransaction();

    $excelRow = 2;


    // =====================================================
    // 7. PRIMERA FASE
    //
    // Llegim l'Excel i construïm el mapa desitjat.
    // Encara NO modifiquem la base de dades.
    // =====================================================

    foreach ($rows as $row) {

        // -------------------------------------------------
        // Ignorar files completament buides
        // -------------------------------------------------

        $isEmpty = true;

        foreach ($row as $v) {

            if (trim((string)$v) !== '') {
                $isEmpty = false;
                break;
            }
        }

        if ($isEmpty) {
            $excelRow++;
            continue;
        }


        // -------------------------------------------------
        // Llegir posició i serial
        // -------------------------------------------------

        $posicio = trim(
            (string)($row[$colPos] ?? '')
        );

        $serial = trim(
            (string)($row[$colSer] ?? '')
        );


        // Treure BOM si existeix
        $posicio = preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            $posicio
        );

        $serial = preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            $serial
        );


        // Normalitzar posició
        $posicio = strtoupper($posicio);


        // -------------------------------------------------
        // La posició és obligatòria
        // -------------------------------------------------

        if ($posicio === '') {

            $errors[] =
                "Fila {$excelRow}: cal indicar posicio.";

            $excelRow++;
            continue;
        }


        // -------------------------------------------------
        // Posició sense serial = posició buida
        //
        // No és cap error.
        // -------------------------------------------------

        if ($serial === '') {

            $excelRow++;
            continue;
        }


        // -------------------------------------------------
        // 7.1 Validar que la posició existeix a MAG01
        // -------------------------------------------------

        $stmt = $pdo->prepare("
            SELECT item_unit_id
            FROM magatzem_posicions
            WHERE magatzem_code = 'MAG01'
              AND codi = ?
            LIMIT 1
        ");

        $stmt->execute([$posicio]);

        $positionRow =
            $stmt->fetch(PDO::FETCH_ASSOC);


        if (!$positionRow) {

            $errors[] =
                "Fila {$excelRow}: " .
                "la posició '{$posicio}' no existeix.";

            $excelRow++;
            continue;
        }


        // -------------------------------------------------
        // 7.2 Buscar la camisa pel serial
        // -------------------------------------------------

        $stmt = $pdo->prepare("
            SELECT
                id,
                serial,
                ubicacio,
                sububicacio,
                magatzem_code,
                estat,
                baixa_motiu
            FROM item_units
            WHERE serial = ?
            LIMIT 1
        ");

        $stmt->execute([$serial]);

        $unit =
            $stmt->fetch(PDO::FETCH_ASSOC);


        if (!$unit) {

            $errors[] =
                "Fila {$excelRow}: " .
                "no s'ha trobat cap unitat " .
                "amb serial '{$serial}'.";

            $excelRow++;
            continue;
        }


        $unitId = (int)$unit['id'];


        // -------------------------------------------------
        // 7.3 Evitar moure descatalogats
        // -------------------------------------------------

        if (
            $unit['estat'] === 'inactiu' &&
            strtolower(
                trim((string)$unit['baixa_motiu'])
            ) === 'descatalogat'
        ) {

            /*
             * Si està exactament a la mateixa posició,
             * simplement el deixem com està.
             *
             * Si intentem moure'l, ho bloquegem.
             */

            $actualPos =
                strtoupper(
                    trim(
                        (string)($unit['sububicacio'] ?? '')
                    )
                );

            if ($actualPos !== $posicio) {

                $errors[] =
                    "Fila {$excelRow}: " .
                    "la unitat '{$serial}' està descatalogada " .
                    "i no es pot moure de " .
                    "'{$actualPos}' a '{$posicio}'.";

                $excelRow++;
                continue;
            }
        }


        // -------------------------------------------------
        // 7.4 Mateixa unitat repetida a dues posicions
        // -------------------------------------------------

        if (
            isset($desiredPosByUnit[$unitId]) &&
            $desiredPosByUnit[$unitId] !== $posicio
        ) {

            $errors[] =
                "Fila {$excelRow}: " .
                "el serial '{$serial}' apareix més d'una vegada " .
                "amb posicions diferents.";

            $excelRow++;
            continue;
        }


        // -------------------------------------------------
        // 7.5 Dues unitats a la mateixa posició
        // -------------------------------------------------

        if (
            isset($desiredUnitByPos[$posicio]) &&
            (int)$desiredUnitByPos[$posicio] !== $unitId
        ) {

            $errors[] =
                "Fila {$excelRow}: " .
                "la posició '{$posicio}' està repetida " .
                "a l'Excel.";

            $excelRow++;
            continue;
        }


        // -------------------------------------------------
        // Guardar intenció
        // -------------------------------------------------

        $desiredPosByUnit[$unitId] = $posicio;

        $desiredUnitByPos[$posicio] = $unitId;

        $incomingUnitIds[$unitId] = true;

        $importats++;

        $excelRow++;
    }


    // =====================================================
    // 8. SI HI HA ERRORS, NO TOQUEM RES
    // =====================================================

    if (!empty($errors)) {

        throw new Exception(
            "Errors trobats:\n" .
            implode("\n", $errors)
        );
    }


    // =====================================================
    // 9. VALIDAR OCUPACIONS ACTUALS
    //
    // Una posició pot estar ocupada:
    //
    // - per la mateixa unitat -> OK
    // - per una altra unitat present a l'import -> OK
    //   perquè pot ser un swap
    // - per una unitat externa a l'import -> ERROR
    // =====================================================

    foreach (
        $desiredPosByUnit as $unitId => $posicio
    ) {

        $stmt = $pdo->prepare("
            SELECT item_unit_id
            FROM magatzem_posicions
            WHERE magatzem_code = 'MAG01'
              AND codi = ?
            LIMIT 1
        ");

        $stmt->execute([$posicio]);

        $positionRow =
            $stmt->fetch(PDO::FETCH_ASSOC);


        if (!$positionRow) {

            $errors[] =
                "La posició '{$posicio}' no existeix.";

            continue;
        }


        $occupiedBy =
            $positionRow['item_unit_id'];


        if ($occupiedBy !== null) {

            $occupiedBy = (int)$occupiedBy;


            if (
                $occupiedBy !== (int)$unitId &&
                !isset($incomingUnitIds[$occupiedBy])
            ) {

                $errors[] =
                    "La posició '{$posicio}' " .
                    "està ocupada per la unitat " .
                    "{$occupiedBy}, que no participa " .
                    "en aquest import.";
            }
        }
    }


    if (!empty($errors)) {

        throw new Exception(
            "Errors de validació:\n" .
            implode("\n", $errors)
        );
    }


    // =====================================================
    // 10. ALLIBERAR POSICIONS ACTUALS
    //
    // IMPORTANT:
    //
    // NOMÉS toquem magatzem_posicions.
    //
    // NO modifiquem:
    // - ubicacio
    // - maquina_actual
    // - estat
    //
    // Això permet mantenir una camisa a:
    // preparacio / maquina / intermig
    // mentre canviem la seva posició permanent.
    // =====================================================

    foreach (
        array_keys($incomingUnitIds) as $unitId
    ) {

        $stmt = $pdo->prepare("
            UPDATE magatzem_posicions
            SET item_unit_id = NULL
            WHERE magatzem_code = 'MAG01'
              AND item_unit_id = ?
        ");

        $stmt->execute([
            (int)$unitId
        ]);
    }


    // =====================================================
    // 11. ASSIGNAR LES NOVES POSICIONS
    //
    // IMPORTANT:
    //
    // NO utilitzem setUnitPosition().
    //
    // setUnitPosition() està pensada per moure físicament
    // una unitat AL MAGATZEM i, per tant, modifica ubicacio.
    //
    // Aquí només estem editant el MAPA / posició permanent.
    // =====================================================

    foreach (
        $desiredPosByUnit as $unitId => $posicio
    ) {

        // ---------------------------------------------
        // 11.1 Ocupar posició al mapa
        // ---------------------------------------------

        $stmt = $pdo->prepare("
            UPDATE magatzem_posicions
            SET item_unit_id = ?
            WHERE magatzem_code = 'MAG01'
              AND codi = ?
              AND item_unit_id IS NULL
        ");

        $stmt->execute([
            (int)$unitId,
            $posicio
        ]);


        if ($stmt->rowCount() !== 1) {

            throw new Exception(
                "No s'ha pogut assignar " .
                "la unitat {$unitId} " .
                "a la posició '{$posicio}'."
            );
        }


        // ---------------------------------------------
        // 11.2 Actualitzar NOMÉS la posició permanent
        //
        // ❗ NO TOQUEM ubicacio
        // ❗ NO TOQUEM maquina_actual
        // ❗ NO TOQUEM estat
        // ---------------------------------------------

        $stmt = $pdo->prepare("
            UPDATE item_units
            SET
                magatzem_code = 'MAG01',
                sububicacio = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $posicio,
            (int)$unitId
        ]);
    }


    // =====================================================
    // 12. COMMIT
    // =====================================================

    $pdo->commit();


    $_SESSION['map_message'] =
        "✅ Import completat: " .
        "{$importats} ubicacions actualitzades.";

    $_SESSION['map_message_type'] =
        "success";


} catch (Throwable $e) {

    // =====================================================
    // SI FALLA QUALSEVOL COSA, DESFEM TOT L'IMPORT
    // =====================================================

    if (
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }


    $_SESSION['map_message'] =
        "❌ Import fallit:\n" .
        $e->getMessage();

    $_SESSION['map_message_type'] =
        "error";
}


// =========================================================
// 13. TORNAR AL MAPA
// =========================================================

header(
    "Location: ../public/magatzem_map.php"
);

exit;