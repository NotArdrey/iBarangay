<?php
header('Content-Type: application/json');
session_start();
require '../config/dbconn.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get the form data
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name']);
    $birth_date = trim($_POST['birth_date']);
    $id_number = trim($_POST['id_number'] ?? '');
    
    // Basic validation
    if (empty($first_name) || empty($last_name) || empty($birth_date)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'First name, last name, and birth date are required.'
        ]);
        exit;
    }
    
    try {
        // Build the census part of the query (no gender)
        $censusSql = "
            SELECT 'census' as source, p.id, p.first_name, p.middle_name, p.last_name, p.birth_date,
                   pi.other_id_number as id_number
            FROM persons p
            LEFT JOIN person_identification pi ON p.id = pi.person_id
            WHERE LOWER(TRIM(p.last_name)) = LOWER(TRIM(:census_last_name))
            AND LOWER(TRIM(p.first_name)) = LOWER(TRIM(:census_first_name))
            AND p.birth_date = :census_birth_date
        ";
        
        // Build the temporary records part (no gender)
        $tempSql = "
            SELECT 'temporary' as source, id, first_name, middle_name, last_name, date_of_birth as birth_date,
                   id_number
            FROM temporary_records
            WHERE LOWER(TRIM(last_name)) = LOWER(TRIM(:temp_last_name))
            AND LOWER(TRIM(first_name)) = LOWER(TRIM(:temp_first_name))
            AND date_of_birth = :temp_birth_date
        ";
        
        // Prepare parameters for exact match
        $params = [
            ':census_first_name' => $first_name,
            ':census_last_name' => $last_name,
            ':census_birth_date' => $birth_date,
            ':temp_first_name' => $first_name,
            ':temp_last_name' => $last_name,
            ':temp_birth_date' => $birth_date
        ];
        
        // Add middle name condition if provided
        if (!empty($middle_name)) {
            $censusSql .= " AND (p.middle_name = :census_middle_name OR p.middle_name IS NULL)";
            $tempSql .= " AND (middle_name = :temp_middle_name OR middle_name IS NULL)";
            $params[':census_middle_name'] = $middle_name;
            $params[':temp_middle_name'] = $middle_name;
        }
        
        // Combine the queries
        $sql = $censusSql . " UNION ALL " . $tempSql;
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new PDOException(
                "Error in exact match query: " . $e->getMessage() . 
                "\nSQL: " . $sql . 
                "\nParameters: " . json_encode($params)
            );
        }
        
        // Check if person exists in both records
        if (count($records) > 1) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Person found in both census and temporary records. Please contact the barangay office for assistance.',
                'exists' => false
            ]);
            exit;
        }
        
        // If person exists in exactly one record
        if (count($records) === 1) {
            $record = $records[0];
            
            // Check if ID number matches if provided
            if (!empty($id_number) && strtolower(trim($record['id_number'])) !== strtolower(trim($id_number))) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'ID number does not match our records.',
                    'exists' => false
                ]);
                exit;
            }
            
            // Get all barangay records for this person
            $stmt = $pdo->prepare("
                SELECT DISTINCT b.id, b.name as barangay_name
                FROM persons p
                LEFT JOIN household_members hm ON p.id = hm.person_id
                LEFT JOIN households h ON hm.household_id = h.id
                LEFT JOIN barangay b ON h.barangay_id = b.id
                WHERE p.id = ?
                UNION
                SELECT DISTINCT b.id, b.name as barangay_name
                FROM persons p
                LEFT JOIN addresses a ON p.id = a.person_id
                LEFT JOIN barangay b ON a.barangay_id = b.id
                WHERE p.id = ? AND a.is_primary = 1
                UNION
                SELECT DISTINCT b.id, b.name as barangay_name
                FROM temporary_records t
                LEFT JOIN barangay b ON t.barangay_id = b.id
                WHERE t.id = ?
            ");
            $stmt->execute([$record['id'], $record['id'], $record['id']]);
            $barangay_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'exists' => true,
                'message' => 'Person verification successful.',
                'person_id' => $record['id'],
                'source' => $record['source'],
                'barangay_records' => $barangay_records
            ]);
            exit;
        }
        
        // If not found in either table, check for partial matches (no gender)
        $partialCensusSql = "
            SELECT 'census' as source, first_name, middle_name, last_name, birth_date, pi.other_id_number as id_number
            FROM persons p
            LEFT JOIN person_identification pi ON p.id = pi.person_id
            WHERE LOWER(TRIM(last_name)) = LOWER(TRIM(:census_last_name))
            OR LOWER(TRIM(first_name)) = LOWER(TRIM(:census_first_name))
            OR birth_date = :census_birth_date
        ";
        
        $partialTempSql = "
            SELECT 'temporary' as source, first_name, middle_name, last_name, date_of_birth as birth_date, id_number
            FROM temporary_records
            WHERE LOWER(TRIM(last_name)) = LOWER(TRIM(:temp_last_name))
            OR LOWER(TRIM(first_name)) = LOWER(TRIM(:temp_first_name))
            OR date_of_birth = :temp_birth_date
        ";
        
        // Add ID number condition if provided
        if (!empty($id_number)) {
            $partialCensusSql .= " OR LOWER(TRIM(pi.other_id_number)) = LOWER(TRIM(:census_id_number))";
            $partialTempSql .= " OR LOWER(TRIM(id_number)) = LOWER(TRIM(:temp_id_number))";
        }
        
        // Combine partial match queries
        $partialSql = $partialCensusSql . " UNION ALL " . $partialTempSql;
        
        // Prepare parameters for partial match
        $partialParams = [
            ':census_first_name' => $first_name,
            ':census_last_name' => $last_name,
            ':census_birth_date' => $birth_date,
            ':temp_first_name' => $first_name,
            ':temp_last_name' => $last_name,
            ':temp_birth_date' => $birth_date
        ];
        
        if (!empty($id_number)) {
            $partialParams[':census_id_number'] = $id_number;
            $partialParams[':temp_id_number'] = $id_number;
        }
        
        try {
            $stmt = $pdo->prepare($partialSql);
            $stmt->execute($partialParams);
            $partialMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new PDOException(
                "Error in partial match query: " . $e->getMessage() . 
                "\nSQL: " . $partialSql . 
                "\nParameters: " . json_encode($partialParams)
            );
        }
        
        if (!empty($partialMatches)) {
            $mismatches = [];
            foreach ($partialMatches as $match) {
                if (strtolower(trim($match['last_name'])) !== strtolower(trim($last_name))) {
                    $mismatches[] = "Last name mismatch in " . $match['source'] . " records";
                }
                if (strtolower(trim($match['first_name'])) !== strtolower(trim($first_name))) {
                    $mismatches[] = "First name mismatch in " . $match['source'] . " records";
                }
                if (!empty($middle_name) && strtolower(trim($match['middle_name'])) !== strtolower(trim($middle_name))) {
                    $mismatches[] = "Middle name mismatch in " . $match['source'] . " records";
                }
                if ($match['birth_date'] !== $birth_date) {
                    $mismatches[] = "Birth date mismatch in " . $match['source'] . " records";
                }
                if (!empty($id_number) && strtolower(trim($match['id_number'])) !== strtolower(trim($id_number))) {
                    $mismatches[] = "ID number mismatch in " . $match['source'] . " records";
                }
            }
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Partial matches found but information does not match exactly. Please verify your details.',
                'exists' => false,
                'mismatches' => $mismatches
            ]);
            exit;
        }
        
        // If no matches found at all
        echo json_encode([
            'status' => 'success',
            'exists' => false,
            'message' => 'Person not found in our database. Registration is not allowed.'
        ]);
        exit;
        
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage(),
            'debug_info' => [
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        ]);
    }
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
} 