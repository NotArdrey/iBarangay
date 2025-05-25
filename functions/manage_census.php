<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Save resident data to the database
 * @param PDO $pdo Database connection
 * @param array $data Resident data
 * @param int $barangay_id Barangay ID
 * @return array Result with success status and message
 */
function saveResident($pdo, $data, $barangay_id) {
    try {
        $pdo->beginTransaction();
        
        // Generate ID number for the new resident
        $id_number = generateCensusId($pdo, $barangay_id);

        // Prepare data for persons table
        $person_params = [
            ':id_number' => $id_number,
            ':first_name' => trim($data['first_name'] ?? ''),
            ':middle_name' => isset($data['middle_name']) ? trim($data['middle_name']) : null,
            ':last_name' => trim($data['last_name'] ?? ''),
            ':suffix' => isset($data['suffix']) ? trim($data['suffix']) : null,
            ':birth_date' => $data['birth_date'] ?? null,
            ':birth_place' => isset($data['birth_place']) ? trim($data['birth_place']) : null,
            ':gender' => $data['gender'] ?? null,
            ':civil_status' => $data['civil_status'] ?? null,
            ':citizenship' => isset($data['citizenship']) ? trim($data['citizenship']) : 'FILIPINO',
            ':religion' => (isset($data['religion']) && strtoupper($data['religion']) === 'OTHERS') ? 'OTHERS' : (isset($data['religion']) ? trim($data['religion']) : null),
            ':other_religion' => (isset($data['religion']) && strtoupper($data['religion']) === 'OTHERS') ? (isset($data['other_religion']) ? trim($data['other_religion']) : null) : null,
            ':education_level' => isset($data['education_level']) ? trim($data['education_level']) : null,
            ':occupation' => isset($data['occupation']) ? trim($data['occupation']) : null,
            ':monthly_income' => isset($data['monthly_income']) && trim($data['monthly_income']) !== '' ? (float)trim($data['monthly_income']) : null,
            ':nhts_pr_listahanan' => isset($data['nhts_pr_listahanan']) ? 1 : 0,
            ':indigenous_people' => isset($data['indigenous_people']) ? 1 : 0,
            ':pantawid_beneficiary' => isset($data['pantawid_beneficiary']) ? 1 : 0,
            ':user_id' => null
        ];

        // SQL for persons table
        $sql_persons = "
            INSERT INTO persons (
                id_number, first_name, middle_name, last_name, suffix,
                birth_date, birth_place, gender, civil_status,
                citizenship, religion, other_religion, education_level,
                occupation, monthly_income,
                nhts_pr_listahanan, indigenous_people, pantawid_beneficiary,
                user_id
            ) VALUES (
                :id_number, :first_name, :middle_name, :last_name, :suffix,
                :birth_date, :birth_place, :gender, :civil_status,
                :citizenship, :religion, :other_religion, :education_level,
                :occupation, :monthly_income,
                :nhts_pr_listahanan, :indigenous_people, :pantawid_beneficiary,
                :user_id
            )
        ";
        $stmt_persons = $pdo->prepare($sql_persons);
        $stmt_persons->execute($person_params);
        $person_id = $pdo->lastInsertId();
        
        // Insert Present Address
        if (!empty($data['present_house_no']) || !empty($data['present_street'])) {
            $stmt_present_address = $pdo->prepare("
                INSERT INTO addresses (
                    person_id, barangay_id, house_no, street, municipality, province, region,
                    residency_type, is_primary, is_permanent
                ) VALUES (
                    :person_id, :barangay_id, :house_no, :street, :municipality, :province, :region,
                    :residency_type, 1, 0
                )
            ");
            $stmt_present_address->execute([
                ':person_id' => $person_id,
                ':barangay_id' => $barangay_id,
                ':house_no' => isset($data['present_house_no']) ? trim($data['present_house_no']) : null,
                ':street' => isset($data['present_street']) ? trim($data['present_street']) : null,
                ':municipality' => isset($data['present_municipality']) && trim($data['present_municipality']) !== '' ? trim($data['present_municipality']) : 'SAN RAFAEL',
                ':province' => isset($data['present_province']) && trim($data['present_province']) !== '' ? trim($data['present_province']) : 'BULACAN',
                ':region' => isset($data['present_region']) && trim($data['present_region']) !== '' ? trim($data['present_region']) : 'III',
                ':residency_type' => isset($data['residency_type']) && trim($data['residency_type']) !== '' ? trim($data['residency_type']) : 'Home Owner'
            ]);
        }

        // Insert Permanent Address if different from present
        if (!empty($data['permanent_house_no']) || !empty($data['permanent_street'])) {
            $stmt_permanent_address = $pdo->prepare("
                INSERT INTO addresses (
                    person_id, barangay_id, house_no, street, municipality, province, region,
                    residency_type, is_primary, is_permanent
                ) VALUES (
                    :person_id, :barangay_id, :house_no, :street, :municipality, :province, :region,
                    :residency_type, 0, 1
                )
            ");
            $stmt_permanent_address->execute([
                ':person_id' => $person_id,
                ':barangay_id' => $barangay_id,
                ':house_no' => isset($data['permanent_house_no']) ? trim($data['permanent_house_no']) : null,
                ':street' => isset($data['permanent_street']) ? trim($data['permanent_street']) : null,
                ':municipality' => isset($data['permanent_municipality']) ? trim($data['permanent_municipality']) : null,
                ':province' => isset($data['permanent_province']) ? trim($data['permanent_province']) : null,
                ':region' => isset($data['permanent_region']) ? trim($data['permanent_region']) : null,
                ':residency_type' => isset($data['permanent_residency_type']) ? trim($data['permanent_residency_type']) : null
            ]);
        }

        // Insert into household_members
        if (!empty($data['household_id'])) {
            $stmt_household = $pdo->prepare("
                INSERT INTO household_members (
                    household_id, person_id, relationship_type_id, is_household_head
                ) VALUES (
                    :household_id, :person_id, :relationship_type_id, :is_household_head
                )
            ");
            $stmt_household->execute([
                ':household_id' => $data['household_id'],
                ':person_id' => $person_id,
                ':relationship_type_id' => $data['relationship'] ?? null,
                ':is_household_head' => isset($data['is_household_head']) ? 1 : 0
            ]);
        }

        // Log the action in audit trail
        if (isset($_SESSION['user_id'])) {
            $stmt_audit = $pdo->prepare("
                INSERT INTO audit_trails (
                    user_id, action, table_name, record_id, description
                ) VALUES (
                    :user_id, 'INSERT', 'persons', :record_id, :description
                )
            ");
            $stmt_audit->execute([
                ':user_id' => $_SESSION['user_id'],
                ':record_id' => $person_id,
                ':description' => "Added new resident: {$data['first_name']} {$data['last_name']} (ID: {$id_number})"
            ]);
        }
        
        $pdo->commit();
        return [
            'success' => true,
            'message' => 'Resident data saved successfully!',
            'person_id' => $person_id,
            'id_number' => $id_number
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Error in saveResident: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        error_log("Trace: " . $e->getTraceAsString());
        if ($e instanceof PDOException) {
            error_log("PDO Error Info: " . print_r($e->errorInfo, true));
        }
        return [
            'success' => false,
            'message' => 'Error saving resident data: ' . $e->getMessage()
        ];
    }
}

/**
 * Update resident data
 * @param PDO $pdo Database connection
 * @param int $person_id Person ID to update
 * @param array $data Updated resident data
 * @param int $barangay_id Barangay ID
 * @return array Result with success status and message
 */
function updateResident($pdo, $person_id, $data, $barangay_id) {
    try {
        $pdo->beginTransaction();
        
        // Update persons table
        $stmt = $pdo->prepare("
            UPDATE persons SET
                first_name = :first_name,
                middle_name = :middle_name,
                last_name = :last_name,
                suffix = :suffix,
                birth_date = :birth_date,
                birth_place = :birth_place,
                gender = :gender,
                civil_status = :civil_status,
                citizenship = :citizenship,
                religion = :religion,
                education_level = :education_level,
                occupation = :occupation,
                monthly_income = :monthly_income,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :person_id
        ");
        
        $stmt->execute([
            ':person_id' => $person_id,
            ':first_name' => trim($data['first_name']),
            ':middle_name' => isset($data['middle_name']) ? trim($data['middle_name']) : null,
            ':last_name' => trim($data['last_name']),
            ':suffix' => isset($data['suffix']) ? trim($data['suffix']) : null,
            ':birth_date' => $data['birth_date'],
            ':birth_place' => isset($data['birth_place']) ? trim($data['birth_place']) : null,
            ':gender' => $data['gender'],
            ':civil_status' => $data['civil_status'],
            ':citizenship' => isset($data['citizenship']) ? trim($data['citizenship']) : 'FILIPINO',
            ':religion' => isset($data['religion']) ? trim($data['religion']) : null,
            ':education_level' => isset($data['education_level']) ? trim($data['education_level']) : null,
            ':occupation' => isset($data['occupation']) ? trim($data['occupation']) : null,
            ':monthly_income' => isset($data['monthly_income']) ? trim($data['monthly_income']) : null
        ]);
        
        // Update present address
        if (!empty($data['present_address_id'])) {
            $stmt = $pdo->prepare("
                UPDATE addresses SET
                    house_no = :house_no,
                    street = :street,
                    municipality = :municipality,
                    province = :province,
                    region = :region,
                    residency_type = :residency_type,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :address_id
            ");
            
            $stmt->execute([
                ':address_id' => $data['present_address_id'],
                ':house_no' => $data['present_house_no'] ?: null,
                ':street' => $data['present_street'] ?: null,
                ':municipality' => $data['present_municipality'] ?: 'SAN RAFAEL',
                ':province' => $data['present_province'] ?: 'BULACAN',
                ':region' => $data['present_region'] ?: 'III',
                ':residency_type' => $data['present_residency_type'] ?: null
            ]);
        }

        // Update permanent address
        if (!empty($data['permanent_address_id'])) {
            $stmt = $pdo->prepare("
                UPDATE addresses SET
                    house_no = :house_no,
                    street = :street,
                    municipality = :municipality,
                    province = :province,
                    region = :region,
                    residency_type = :residency_type,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :address_id
            ");
            
            $stmt->execute([
                ':address_id' => $data['permanent_address_id'],
                ':house_no' => $data['permanent_house_no'] ?: null,
                ':street' => $data['permanent_street'] ?: null,
                ':municipality' => $data['permanent_municipality'] ?: null,
                ':province' => $data['permanent_province'] ?: null,
                ':region' => $data['permanent_region'] ?: null,
                ':residency_type' => $data['permanent_residency_type'] ?: null
            ]);
        }
        
        // Update household membership if needed
        if (!empty($data['household_id'])) {
            // Check if household member exists
            $stmt = $pdo->prepare("
                SELECT id FROM household_members 
                WHERE person_id = :person_id
            ");
            $stmt->execute([':person_id' => $person_id]);
            $member = $stmt->fetch();
            
            if ($member) {
                // Update existing membership
                $stmt = $pdo->prepare("
                    UPDATE household_members SET
                        household_id = :household_id,
                        relationship_type_id = :relationship,
                        is_household_head = :is_head
                    WHERE person_id = :person_id
                ");
                
                $stmt->execute([
                    ':household_id' => $data['household_id'],
                    ':relationship' => $data['relationship'] ?: null,
                    ':is_head' => $data['is_household_head'] ?? 0,
                    ':person_id' => $person_id
                ]);
            }
            
            // Update household head if needed
            if (!empty($data['is_household_head'])) {
                $stmt = $pdo->prepare("
                    UPDATE households 
                    SET household_head_person_id = :person_id 
                    WHERE id = :household_id
                ");
                $stmt->execute([
                    ':person_id' => $person_id,
                    ':household_id' => $data['household_id']
                ]);
            }
        }
        
        // Log the action
        if (isset($_SESSION['user_id'])) {
            $stmt = $pdo->prepare("
                INSERT INTO audit_trails (
                    user_id, action, table_name, record_id, description
                ) VALUES (
                    :user_id, 'UPDATE', 'persons', :record_id, :description
                )
            ");
            
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':record_id' => $person_id,
                ':description' => "Updated resident: {$data['first_name']} {$data['last_name']}"
            ]);
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Resident data updated successfully!'
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        
        return [
            'success' => false,
            'message' => 'Error updating resident data: ' . $e->getMessage()
        ];
    }
}

/**
 * Delete resident and all related records
 * @param PDO $pdo Database connection
 * @param int $person_id Person ID to delete
 * @return array Result with success status and message
 */
function deleteResident($pdo, $person_id) {
    try {
        $pdo->beginTransaction();
        
        // Get person details for audit trail
        $stmt = $pdo->prepare("
            SELECT first_name, last_name FROM persons WHERE id = :person_id
        ");
        $stmt->execute([':person_id' => $person_id]);
        $person = $stmt->fetch();
        
        if (!$person) {
            throw new Exception("Resident not found");
        }
        
        // Check if person is a household head
        $stmt = $pdo->prepare("
            SELECT id FROM households WHERE household_head_person_id = :person_id
        ");
        $stmt->execute([':person_id' => $person_id]);
        $household = $stmt->fetch();
        
        if ($household) {
            // Set household head to NULL before deletion
            $stmt = $pdo->prepare("
                UPDATE households SET household_head_person_id = NULL WHERE id = :household_id
            ");
            $stmt->execute([':household_id' => $household['id']]);
        }
        
        // Delete person (cascading deletes will handle related records)
        $stmt = $pdo->prepare("DELETE FROM persons WHERE id = :person_id");
        $stmt->execute([':person_id' => $person_id]);
        
        // Log the action
        if (isset($_SESSION['user_id'])) {
            $stmt = $pdo->prepare("
                INSERT INTO audit_trails (
                    user_id, action, table_name, record_id, description
                ) VALUES (
                    :user_id, 'DELETE', 'persons', :record_id, :description
                )
            ");
            
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':record_id' => $person_id,
                ':description' => "Deleted resident: {$person['first_name']} {$person['last_name']}"
            ]);
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Resident deleted successfully!'
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        
        return [
            'success' => false,
            'message' => 'Error deleting resident: ' . $e->getMessage()
        ];
    }
}

/**
 * Generate a unique census ID
 * @param PDO $pdo Database connection
 * @param int $barangay_id Barangay ID
 * @return string Generated census ID
 */
function generateCensusId($pdo, $barangay_id) {
    try {
        // Get barangay name with row lock
        $stmt = $pdo->prepare("SELECT name FROM barangay WHERE id = :barangay_id FOR UPDATE");
        $stmt->execute([':barangay_id' => $barangay_id]);
        $barangay = $stmt->fetch();
        
        if (!$barangay) {
            throw new Exception("Invalid barangay ID");
        }
        
        // Create barangay code (first 3 letters)
        $code = substr(preg_replace('/[^A-Z]/', '', strtoupper($barangay['name'])), 0, 3);
        
        // Get current year
        $year = date('Y');
        
        // Get the latest sequence number with row lock to prevent concurrent access
        $stmt = $pdo->prepare("
            SELECT MAX(CAST(SUBSTRING_INDEX(id_number, '-', -1) AS UNSIGNED)) as last_seq 
            FROM persons 
            WHERE id_number LIKE :pattern
            FOR UPDATE
        ");
        $stmt->execute([':pattern' => "$code-$year-%"]);
        $result = $stmt->fetch();
        
        $nextSeq = 1;
        if ($result && $result['last_seq']) {
            $nextSeq = $result['last_seq'] + 1;
        }
        
        // Try to find an available sequence number
        do {
            $idNumber = sprintf("%s-%s-%04d", $code, $year, $nextSeq);
            
            // Check if this ID already exists with row lock
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM persons 
                WHERE id_number = :id_number
                FOR UPDATE
            ");
            $stmt->execute([':id_number' => $idNumber]);
            
            if (!$stmt->fetch()) {
                // Found an available ID
                return $idNumber;
            }
            
            $nextSeq++;
        } while ($nextSeq <= 9999); // Limit to 4 digits
        
        // If we get here, we've exhausted all possibilities
        throw new Exception("Could not generate unique ID number - sequence limit reached");
        
    } catch (Exception $e) {
        throw $e; // Re-throw the exception to be handled by the parent transaction
    }
}

/**
 * Get resident details by person ID
 * @param PDO $pdo Database connection
 * @param int $person_id Person ID
 * @return array|false Person details or false if not found
 */
function getResidentById($pdo, $person_id) {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            hm.household_id, hm.relationship_type_id, hm.is_household_head,
            b.name as barangay_name,
            TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age
        FROM persons p
        LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
        LEFT JOIN household_members hm ON p.id = hm.person_id
        LEFT JOIN households h ON hm.household_id = h.id
        LEFT JOIN barangay b ON a.barangay_id = b.id
        WHERE p.id = :person_id
    ");
    
    $stmt->execute([':person_id' => $person_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check if a household exists
 * @param PDO $pdo Database connection
 * @param string $household_id Household ID
 * @param int $barangay_id Barangay ID
 * @return bool True if household exists
 */
function checkHouseholdExists($pdo, $household_id, $barangay_id) {
    $stmt = $pdo->prepare("
        SELECT id FROM households 
        WHERE id = :household_id AND barangay_id = :barangay_id
    ");
    $stmt->execute([
        ':household_id' => $household_id,
        ':barangay_id' => $barangay_id
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * Validate person data
 * @param array $data Person data to validate
 * @return array Validation result with 'valid' boolean and 'errors' array
 */
function validatePersonData($data) {
    $validation_errors = [];

    if (empty($data['first_name'])) {
        $validation_errors[] = "First name is required.";
    }

    if (empty($data['last_name'])) {
        $validation_errors[] = "Last name is required.";
    }

    if (empty($data['birth_date'])) {
        $validation_errors[] = "Birth date is required.";
    } elseif (!strtotime($data['birth_date'])) {
        $validation_errors[] = "Invalid birth date format.";
    }

    if (empty($data['birth_place'])) {
        $validation_errors[] = "Birth place is required.";
    }

    if (empty($data['gender'])) {
        $validation_errors[] = "Gender is required.";
    }

    if (empty($data['civil_status'])) {
        $validation_errors[] = "Civil status is required.";
    }

    if (empty($data['household_id'])) {
        $validation_errors[] = "Household ID is required.";
    }

    return $validation_errors;
}

/**
 * Calculate age from birth date
 * @param string $birth_date Birth date in Y-m-d format
 * @return int Age in years
 */
function calculateAge($birth_date) {
    if (empty($birth_date)) {
        return 0;
    }
    
    $birthDate = new DateTime($birth_date);
    $today = new DateTime();
    $age = $birthDate->diff($today)->y;
    
    return $age;
}

/**
 * Search residents
 * @param PDO $pdo Database connection
 * @param string $search_term Search term
 * @param int $barangay_id Barangay ID
 * @param array $filters Additional filters
 * @return array Search results
 */
function searchResidents($pdo, $search_term, $barangay_id, $filters = []) {
    $query = "
        SELECT 
            p.*,
            a.house_no, a.street,
            hm.household_id, hm.relationship_type_id,
            TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age
        FROM persons p
        JOIN household_members hm ON p.id = hm.person_id
        JOIN households h ON hm.household_id = h.id
        LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
        WHERE h.barangay_id = :barangay_id
    ";
    
    $params = [':barangay_id' => $barangay_id];
    
    // Add search condition
    if (!empty($search_term)) {
        $query .= " AND (
            p.first_name LIKE :search OR 
            p.middle_name LIKE :search OR 
            p.last_name LIKE :search OR
            p.id_number LIKE :search
        )";
        $params[':search'] = "%$search_term%";
    }
    
    // Add age filter
    if (!empty($filters['age_group'])) {
        switch ($filters['age_group']) {
            case 'child':
                $query .= " AND TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) < 18";
                break;
            case 'adult':
                $query .= " AND TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) BETWEEN 18 AND 59";
                break;
            case 'senior':
                $query .= " AND TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) >= 60";
                break;
        }
    }
    
    // Add gender filter
    if (!empty($filters['gender'])) {
        $query .= " AND p.gender = :gender";
        $params[':gender'] = $filters['gender'];
    }
    
    // Add civil status filter
    if (!empty($filters['civil_status'])) {
        $query .= " AND p.civil_status = :civil_status";
        $params[':civil_status'] = $filters['civil_status'];
    }
    
    $query .= " ORDER BY p.last_name, p.first_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>