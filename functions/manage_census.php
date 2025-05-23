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
        
        // Generate ID number
        $id_number = generateCensusId($pdo, $barangay_id);
        
        // Insert into persons table with named parameters
        $stmt = $pdo->prepare("
            INSERT INTO persons (
                id_number, first_name, middle_name, last_name, suffix,
                birth_date, birth_place, gender, civil_status,
                citizenship, religion, education_level, occupation,
                monthly_income, contact_number, user_id
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?
            )
        ");
        
        $stmt->execute([
            $id_number,
            $data['first_name'],
            isset($data['middle_name']) ? trim($data['middle_name']) : null,
            $data['last_name'],
            isset($data['suffix']) ? trim($data['suffix']) : null,
            $data['birth_date'],
            $data['birth_place'],
            $data['gender'],
            $data['civil_status'],
            isset($data['citizenship']) ? trim($data['citizenship']) : 'Filipino',
            isset($data['religion']) ? trim($data['religion']) : null,
            isset($data['education_level']) ? trim($data['education_level']) : null,
            isset($data['occupation']) ? trim($data['occupation']) : null,
            isset($data['monthly_income']) && trim($data['monthly_income']) !== '' ? (float)$data['monthly_income'] : null,
            isset($data['contact_number']) ? trim($data['contact_number']) : null,
            isset($data['user_id']) ? $data['user_id'] : null
        ]);
        
        $person_id = $pdo->lastInsertId();
        
        // Insert address if provided
        if (!empty($data['house_no']) || !empty($data['street'])) {            $stmt = $pdo->prepare("
                INSERT INTO addresses (
                    person_id, barangay_id, house_no, street,
                    subdivision, block_lot, phase,
                    residency_type, years_in_san_rafael, is_primary
                ) VALUES (
                    :person_id, :barangay_id, :house_no, :street,
                    :subdivision, :block_lot, :phase,
                    :residency_type, :years_in_san_rafael, 1
                )
            ");

            $addressData = [
                ':person_id' => $person_id,
                ':barangay_id' => $barangay_id,
                ':house_no' => isset($data['house_no']) ? trim($data['house_no']) : null,
                ':street' => isset($data['street']) ? trim($data['street']) : null,
                ':subdivision' => isset($data['subdivision']) ? trim($data['subdivision']) : null,
                ':block_lot' => isset($data['block_lot']) ? trim($data['block_lot']) : null,
                ':phase' => isset($data['phase']) ? trim($data['phase']) : null,
                ':residency_type' => isset($data['residency_type']) && trim($data['residency_type']) !== '' ? trim($data['residency_type']) : 'Home Owner',
                ':years_in_san_rafael' => isset($data['years_in_san_rafael']) ? (int)$data['years_in_san_rafael'] : null
            ];

            $stmt->execute($addressData);
        }
        
        // Add to household if specified
        if (!empty($data['household_id'])) {
            // If marked as household head, update the household record
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
                
                // Update any existing household heads to non-head
                $stmt = $pdo->prepare("
                    UPDATE household_members 
                    SET is_household_head = 0 
                    WHERE household_id = :household_id AND person_id != :person_id
                ");
                $stmt->execute([
                    ':household_id' => $data['household_id'],
                    ':person_id' => $person_id
                ]);
            }
            
            // Insert into household_members
            $stmt = $pdo->prepare("
                INSERT INTO household_members (
                    household_id, person_id, relationship_to_head, is_household_head
                ) VALUES (
                    :household_id, :person_id, :relationship, :is_head
                )
            ");
            
            $stmt->execute([
                ':household_id' => $data['household_id'],
                ':person_id' => $person_id,
                ':relationship' => $data['relationship'] ?: null,
                ':is_head' => $data['is_household_head'] ?? 0
            ]);
            
            // Update household size
            $stmt = $pdo->prepare("
                UPDATE households 
                SET household_size = (
                    SELECT COUNT(*) FROM household_members 
                    WHERE household_id = :household_id
                )
                WHERE id = :household_id
            ");
            $stmt->execute([':household_id' => $data['household_id']]);
        }
        
        // Handle special resident types
        $birthDate = new DateTime($data['birth_date']);
        $today = new DateTime();
        $age = $birthDate->diff($today)->y;
        
        // Check if senior citizen
        if ($data['resident_type'] === 'senior' || $age >= 60) {
            $stmt = $pdo->prepare("
                INSERT INTO senior_health (person_id) VALUES (:person_id)
            ");
            $stmt->execute([':person_id' => $person_id]);
        }
        
        // Check if child
        if ($age < 18) {
            $stmt = $pdo->prepare("
                INSERT INTO child_information (person_id) VALUES (:person_id)
            ");
            $stmt->execute([':person_id' => $person_id]);
        }
        
        // Log the action in audit trail
        if (isset($_SESSION['user_id'])) {
            $stmt = $pdo->prepare("
                INSERT INTO audit_trails (
                    user_id, action, table_name, record_id, description
                ) VALUES (
                    :user_id, 'INSERT', 'persons', :record_id, :description
                )
            ");
            
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':record_id' => $person_id,
                ':description' => "Added new resident: {$data['first_name']} {$data['last_name']}"
            ]);
        }
        
        $pdo->commit();
        return [
            'success' => true,
            'message' => 'Resident data saved successfully!',
            'person_id' => $person_id
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Error in saveResident: " . $e->getMessage());
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
                contact_number = :contact_number,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :person_id
        ");
        
        $stmt->execute([
            ':person_id' => $person_id,
            ':first_name' => $data['first_name'],
            ':middle_name' => $data['middle_name'] ?: null,
            ':last_name' => $data['last_name'],
            ':suffix' => $data['suffix'] ?: null,
            ':birth_date' => $data['birth_date'],
            ':birth_place' => $data['birth_place'],
            ':gender' => $data['gender'],
            ':civil_status' => $data['civil_status'],
            ':citizenship' => $data['citizenship'] ?: 'Filipino',
            ':religion' => $data['religion'] ?: null,
            ':education_level' => $data['education_level'] ?: null,
            ':occupation' => $data['occupation'] ?: null,
            ':monthly_income' => $data['monthly_income'] ?: null,
            ':contact_number' => $data['contact_number'] ?: null
        ]);
        
        // Update or insert address
        if (!empty($data['house_no']) || !empty($data['street'])) {
            // Check if address exists
            $stmt = $pdo->prepare("
                SELECT id FROM addresses 
                WHERE person_id = :person_id AND is_primary = 1
            ");
            $stmt->execute([':person_id' => $person_id]);
            $address = $stmt->fetch();
            
            if ($address) {
                // Update existing address
                $stmt = $pdo->prepare("
                    UPDATE addresses SET
                        house_no = :house_no,
                        street = :street,
                        subdivision = :subdivision,
                        block_lot = :block_lot,
                        phase = :phase,
                        residency_type = :residency_type,
                        years_in_san_rafael = :years_in_san_rafael,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :address_id
                ");
                
                $stmt->execute([
                    ':address_id' => $address['id'],
                    ':house_no' => $data['house_no'] ?: null,
                    ':street' => $data['street'] ?: null,
                    ':subdivision' => $data['subdivision'] ?: null,
                    ':block_lot' => $data['block_lot'] ?: null,
                    ':phase' => $data['phase'] ?: null,
                    ':residency_type' => $data['residency_type'] ?: null,
                    ':years_in_san_rafael' => $data['years_in_san_rafael'] ?: null
                ]);
            } else {
                // Insert new address
                $stmt = $pdo->prepare("
                    INSERT INTO addresses (
                        person_id, barangay_id, house_no, street,
                        subdivision, block_lot, phase,
                        residency_type, years_in_san_rafael, is_primary
                    ) VALUES (
                        :person_id, :barangay_id, :house_no, :street,
                        :subdivision, :block_lot, :phase,
                        :residency_type, :years_in_san_rafael, 1
                    )
                ");
                
                $stmt->execute([
                    ':person_id' => $person_id,
                    ':barangay_id' => $barangay_id,
                    ':house_no' => $data['house_no'] ?: null,
                    ':street' => $data['street'] ?: null,
                    ':subdivision' => $data['subdivision'] ?: null,
                    ':block_lot' => $data['block_lot'] ?: null,
                    ':phase' => $data['phase'] ?: null,
                    ':residency_type' => $data['residency_type'] ?: null,
                    ':years_in_san_rafael' => $data['years_in_san_rafael'] ?: null
                ]);
            }
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
                        relationship_to_head = :relationship,
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
            hm.household_id, hm.relationship_to_head, hm.is_household_head,
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
    $errors = [];
    
    // Required fields
    $required = [
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'birth_date' => 'Date of Birth',
        'birth_place' => 'Place of Birth',
        'gender' => 'Gender',
        'civil_status' => 'Civil Status',
        'household_id' => 'Household ID'
    ];
    
    foreach ($required as $field => $label) {
        if (empty($data[$field])) {
            $errors[] = "$label is required.";
        }
    }
    
    // Validate date format
    if (!empty($data['birth_date'])) {
        $date = DateTime::createFromFormat('Y-m-d', $data['birth_date']);
        if (!$date || $date->format('Y-m-d') !== $data['birth_date']) {
            $errors[] = "Invalid birth date format.";
        } else {
            // Check if birth date is not in the future
            if ($date > new DateTime()) {
                $errors[] = "Birth date cannot be in the future.";
            }
        }
    }
    
    // Validate phone number if provided
    if (!empty($data['contact_number'])) {
        if (!preg_match('/^(09|\+639)\d{9}$/', $data['contact_number'])) {
            $errors[] = "Invalid contact number format.";
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
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
    $age = $birthDate->diff($today);
    
    return $age->y;
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
            hm.household_id, hm.relationship_to_head,
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