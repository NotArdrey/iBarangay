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
        // First check if person has an account in any barangay
        $accountCheckSql = "
            SELECT u.id, u.email, b.name as barangay_name
            FROM persons p
            JOIN users u ON p.user_id = u.id
            JOIN barangay b ON u.barangay_id = b.id
            WHERE LOWER(TRIM(p.last_name)) = LOWER(TRIM(:last_name))
            AND LOWER(TRIM(p.first_name)) = LOWER(TRIM(:first_name))
            AND p.birth_date = :birth_date
        ";
        
        $accountParams = [
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':birth_date' => $birth_date
        ];
        
        if (!empty($middle_name)) {
            $accountCheckSql .= " AND (p.middle_name = :middle_name OR p.middle_name IS NULL)";
            $accountParams[':middle_name'] = $middle_name;
        }
        
        $accountStmt = $pdo->prepare($accountCheckSql);
        $accountStmt->execute($accountParams);
        $existingAccount = $accountStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingAccount) {
            echo json_encode([
                'status' => 'success',
                'exists' => true,
                'has_account' => true,
                'message' => 'You already have an account in ' . $existingAccount['barangay_name'] . '. Please contact the barangay office for assistance.'
            ]);
            exit;
        }

        // Fetch all census records
        $censusSql = "
            SELECT 'census' as source, p.id, p.first_name, p.middle_name, p.last_name, p.birth_date, pi.other_id_number as id_number, a.barangay_id
            FROM persons p
            LEFT JOIN person_identification pi ON p.id = pi.person_id
            LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
            WHERE LOWER(TRIM(p.last_name)) = LOWER(TRIM(:census_last_name))
            AND LOWER(TRIM(p.first_name)) = LOWER(TRIM(:census_first_name))
            AND p.birth_date = :census_birth_date
        ";
        $tempSql = "
            SELECT 'temporary' as source, id, first_name, middle_name, last_name, date_of_birth as birth_date, id_number, barangay_id
            FROM temporary_records
            WHERE LOWER(TRIM(last_name)) = LOWER(TRIM(:temp_last_name))
            AND LOWER(TRIM(first_name)) = LOWER(TRIM(:temp_first_name))
            AND date_of_birth = :temp_birth_date
            AND (is_archived = 0 OR is_archived IS NULL)
        ";
        // Prepare separate parameter arrays for each query
        $censusParams = [
            ':census_first_name' => $first_name,
            ':census_last_name' => $last_name,
            ':census_birth_date' => $birth_date
        ];
        $tempParams = [
            ':temp_first_name' => $first_name,
            ':temp_last_name' => $last_name,
            ':temp_birth_date' => $birth_date
        ];
        if (!empty($middle_name)) {
            $censusSql .= " AND (p.middle_name = :census_middle_name OR p.middle_name IS NULL)";
            $tempSql .= " AND (middle_name = :temp_middle_name OR middle_name IS NULL)";
            $censusParams[':census_middle_name'] = $middle_name;
            $tempParams[':temp_middle_name'] = $middle_name;
        }
        // Fetch records
        $censusStmt = $pdo->prepare($censusSql);
        $censusStmt->execute($censusParams);
        $censusRecords = $censusStmt->fetchAll(PDO::FETCH_ASSOC);
        $tempStmt = $pdo->prepare($tempSql);
        $tempStmt->execute($tempParams);
        $tempRecords = $tempStmt->fetchAll(PDO::FETCH_ASSOC);

        // If found in multiple barangays, allow selection (prefer census if both types, but not both in same barangay)
        $allRecords = array_merge($censusRecords, $tempRecords);
        if (count($allRecords) > 1) {
            $censusBarangays = array_filter($allRecords, fn($r) => $r['source'] === 'census');
            $tempBarangays = array_filter($allRecords, fn($r) => $r['source'] === 'temporary');
            
            // Check if person exists in both census and temporary in the SAME barangay
            $censusBarangayIds = array_column($censusBarangays, 'barangay_id');
            $tempBarangayIds = array_column($tempBarangays, 'barangay_id');
            $overlap = array_intersect($censusBarangayIds, $tempBarangayIds);
            
            if (!empty($overlap)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Registration cannot proceed because your information exists in both census and temporary records for the same barangay. This usually means your details are pending verification or update. Please visit or contact your barangay office to resolve this before registering online.',
                    'exists' => false
                ]);
                exit;
            }

            // If only census records exist
            if (count($censusBarangays) > 0 && count($tempBarangays) === 0) {
                // Group census records by barangay_id
                $grouped = [];
                foreach ($censusBarangays as $r) {
                    $grouped[$r['barangay_id']][] = $r;
                }
                $filtered = [];
                foreach ($grouped as $barangay_id => $records) {
                    // Try to find a record with both religion and education_level
                    $best = null;
                    foreach ($records as $rec) {
                        // Fetch religion and education_level for this person
                        $stmt = $pdo->prepare("SELECT religion, education_level FROM persons WHERE id = ?");
                        $stmt->execute([$rec['id']]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!empty($row['religion']) && !empty($row['education_level'])) {
                            $best = $rec;
                            break;
                        }
                    }
                    if ($best) {
                        $filtered[] = $best;
                    } else {
                        // Fallback: just use the first record for this barangay
                        $filtered[] = $records[0];
                    }
                }
                $barangay_records = array_map(function($r) use ($pdo) {
                    return [
                        'id' => $r['barangay_id'],
                        'barangay_name' => getBarangayName($pdo, $r['barangay_id']),
                        'source' => 'census',
                        'person_id' => $r['id']
                    ];
                }, $filtered);
                echo json_encode([
                    'status' => 'success',
                    'exists' => true,
                    'person_id' => $filtered[0]['id'],
                    'source' => 'census',
                    'barangay_records' => $barangay_records
                ]);
                exit;
            } 
            // If only temporary records exist
            elseif (count($tempBarangays) > 0 && count($censusBarangays) === 0) {
                $barangay_records = array_map(function($r) use ($pdo) {
                    return [
                        'id' => $r['barangay_id'],
                        'barangay_name' => getBarangayName($pdo, $r['barangay_id']),
                        'source' => 'temporary',
                        'person_id' => $r['id']
                    ];
                }, $tempBarangays);
                echo json_encode([
                    'status' => 'success',
                    'exists' => true,
                    'person_id' => $tempBarangays[0]['id'],
                    'source' => 'temporary',
                    'barangay_records' => $barangay_records
                ]);
                exit;
            } 
            // If found in both census and temporary in DIFFERENT barangays
            elseif (count($censusBarangays) > 0 && count($tempBarangays) > 0) {
                // Only show census records in the dropdown if any census records exist
                $barangay_records = array_map(function($r) use ($pdo) {
                    return [
                        'id' => $r['barangay_id'],
                        'barangay_name' => getBarangayName($pdo, $r['barangay_id']),
                        'source' => 'census',
                        'person_id' => $r['id']
                    ];
                }, $censusBarangays);
                echo json_encode([
                    'status' => 'success',
                    'exists' => true,
                    'person_id' => $censusBarangays[0]['id'], // Default to first census record
                    'source' => 'census',
                    'barangay_records' => $barangay_records
                ]);
                exit;
            }
        }
        // If found in only one record, proceed as normal
        if (count($allRecords) === 1) {
            $r = $allRecords[0];
            $barangay_records = [[
                'id' => $r['barangay_id'],
                'barangay_name' => getBarangayName($pdo, $r['barangay_id']),
                'source' => $r['source'],
                'person_id' => $r['id']
            ]];
            echo json_encode([
                'status' => 'success',
                'exists' => true,
                'person_id' => $r['id'],
                'source' => $r['source'],
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
            AND (is_archived = 0 OR is_archived IS NULL)
        ";
        $partialParams = [
            ':census_first_name' => $first_name,
            ':census_last_name' => $last_name,
            ':census_birth_date' => $birth_date,
            ':temp_first_name' => $first_name,
            ':temp_last_name' => $last_name,
            ':temp_birth_date' => $birth_date
        ];
        if (!empty($id_number)) {
            $partialCensusSql .= " OR LOWER(TRIM(pi.other_id_number)) = LOWER(TRIM(:census_id_number))";
            $partialTempSql .= " OR LOWER(TRIM(id_number)) = LOWER(TRIM(:temp_id_number))";
            $partialParams[':census_id_number'] = $id_number;
            $partialParams[':temp_id_number'] = $id_number;
        }
        $partialSql = $partialCensusSql . " UNION ALL " . $partialTempSql;
        
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

// Helper to get barangay name by ID
function getBarangayName($pdo, $barangay_id) {
    if (!$barangay_id) return null;
    $stmt = $pdo->prepare("SELECT name FROM barangay WHERE id = ?");
    $stmt->execute([$barangay_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['name'] : null;
} 