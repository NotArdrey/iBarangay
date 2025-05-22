<?php
session_start();
require __DIR__ . '/../config/dbconn.php'; 
if (!function_exists('processSeniorData')) {

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        global $pdo, $barangay_id;
        try {
            $pdo->beginTransaction();

            // Validate required fields
            $required = ['first_name', 'last_name', 'birth_date', 'gender', 'civil_status', 'household_id'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            // Check household exists
            $stmt = $pdo->prepare("SELECT household_id, household_size FROM Household WHERE household_id = ? AND barangay_id = ?");
            $stmt->execute([$_POST['household_id'], $barangay_id]);
            $household = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$household) {
                throw new Exception("Invalid Household ID");
            }

            // Calculate age to determine if senior citizen or child
            $birthDate = new DateTime($_POST['birth_date']);
            $today = new DateTime();
            $age = $birthDate->diff($today)->y;
            $is_senior = $age >= 60;
            $is_child = $age < 18;

            // Insert person
            $personData = [
                'first_name' => $_POST['first_name'],
                'middle_name' => $_POST['middle_name'] ?? null,
                'last_name' => $_POST['last_name'],
                'suffix' => $_POST['suffix'] ?? null,
                'birth_date' => $_POST['birth_date'],
                'birth_place' => $_POST['birth_place'] ?? null,
                'gender' => $_POST['gender'],
                'civil_status' => $_POST['civil_status'],
                'citizenship' => $_POST['citizenship'] ?? 'Filipino',
                'religion' => $_POST['religion'] ?? null,
                'education_level' => $_POST['education_level'] ?? null,
                'occupation' => $_POST['occupation'] ?? null,
                'monthly_income' => $_POST['monthly_income'] ?? null,
                'contact_number' => $_POST['contact_number'] ?? null
            ];

            $stmt = $pdo->prepare("
                INSERT INTO Person 
                (first_name, middle_name, last_name, suffix, birth_date, birth_place, gender, 
                 civil_status, citizenship, religion, education_level, occupation, monthly_income, contact_number)
                VALUES 
                (:first_name, :middle_name, :last_name, :suffix, :birth_date, :birth_place, :gender, 
                 :civil_status, :citizenship, :religion, :education_level, :occupation, :monthly_income, :contact_number)
            ");
            $stmt->execute($personData);
            $person_id = $pdo->lastInsertId();

            // Determine if this person is the household head
            $is_head = isset($_POST['is_household_head']) ? 1 : 0;

            // Add to household
            $householdData = [
                'household_id' => $_POST['household_id'],
                'person_id' => $person_id,
                'relationship' => $_POST['relationship'] ?? 'Member',
                'is_head' => $is_head
            ];

            $stmt = $pdo->prepare("
                INSERT INTO HouseholdMember 
                (household_id, person_id, relationship_to_head, is_household_head)
                VALUES (:household_id, :person_id, :relationship, :is_head)
            ");
            $stmt->execute($householdData);

            // Update household information if this is the head
            if ($is_head) {
                $stmt = $pdo->prepare("
                    UPDATE Household 
                    SET household_head_person_id = ? 
                    WHERE household_id = ?
                ");
                $stmt->execute([$person_id, $_POST['household_id']]);
            }

            // Update household size
            $new_size = $household['household_size'] + 1;
            $stmt = $pdo->prepare("
                UPDATE Household 
                SET household_size = ? 
                WHERE household_id = ?
            ");
            $stmt->execute([$new_size, $_POST['household_id']]);

            // Add address if provided
            if (!empty($_POST['house_no']) || !empty($_POST['street']) || !empty($_POST['subdivision'])) {
                $addressData = [
                    'person_id' => $person_id,
                    'barangay_id' => $barangay_id,
                    'house_no' => $_POST['house_no'] ?? null,
                    'street' => $_POST['street'] ?? null,
                    'subdivision' => $_POST['subdivision'] ?? null,
                    'block_lot' => $_POST['block_lot'] ?? null,
                    'phase' => $_POST['phase'] ?? null,
                    'residency_type' => $_POST['residency_type'] ?? 'Home Owner',
                    'years_in_san_rafael' => $_POST['years_in_san_rafael'] ?? null,
                    'is_primary' => 1
                ];

                $stmt = $pdo->prepare("
                    INSERT INTO Address 
                    (person_id, barangay_id, house_no, street, subdivision, block_lot, phase, 
                     residency_type, years_in_san_rafael, is_primary)
                    VALUES 
                    (:person_id, :barangay_id, :house_no, :street, :subdivision, :block_lot, :phase, 
                     :residency_type, :years_in_san_rafael, :is_primary)
                ");
                $stmt->execute($addressData);
            }

            // Process senior-specific or child-specific information based on age
            if ($is_senior) {
                processSeniorData($pdo, $person_id, $_POST);
            } elseif ($is_child) {
                processChildData($pdo, $person_id, $_POST);
            }

            $pdo->commit();
            $_SESSION['success'] = "Census record added successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header("Location: manage_census.php");
        exit;
    }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    global $pdo, $barangay_id;
    try {
        $pdo->beginTransaction();

        // Validate required fields
        $required = ['first_name', 'last_name', 'birth_date', 'gender', 'civil_status', 'household_id'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        // Check household exists
        $stmt = $pdo->prepare("SELECT household_id, household_size FROM Household WHERE household_id = ? AND barangay_id = ?");
        $stmt->execute([$_POST['household_id'], $barangay_id]);
        $household = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$household) {
            throw new Exception("Invalid Household ID");
        }

        // Calculate age to determine if senior citizen or child
        $birthDate = new DateTime($_POST['birth_date']);
        $today = new DateTime();
        $age = $birthDate->diff($today)->y;
        $is_senior = $age >= 60;
        $is_child = $age < 18;

        // Insert person
        $person_id = insertPerson($pdo, $_POST);

        // Determine if this person is the household head
        $is_head = isset($_POST['is_household_head']) ? 1 : 0;

        // Add to household
        addToHousehold($pdo, $_POST['household_id'], $person_id, $_POST['relationship'] ?? 'Member', $is_head);

        // Update household information if this is the head
        if ($is_head) {
            updateHouseholdHead($pdo, $_POST['household_id'], $person_id);
        }

        // Update household size
        updateHouseholdSize($pdo, $_POST['household_id'], $household['household_size'] + 1);

        // Add address if provided
        if (!empty($_POST['house_no']) || !empty($_POST['street']) || !empty($_POST['subdivision'])) {
            addAddress($pdo, $person_id, $barangay_id, $_POST);
        }

        // Process senior-specific or child-specific information based on age
        if ($is_senior) {
            processSeniorData($pdo, $person_id, $_POST);
        } elseif ($is_child) {
            processChildData($pdo, $person_id, $_POST);
        }

        $pdo->commit();
        $_SESSION['success'] = "Census record added successfully!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: manage_census.php");
    exit;
}

// --- Helper functions ---

function insertPerson($pdo, $data) {
    $personData = [
        'first_name' => $data['first_name'],
        'middle_name' => $data['middle_name'] ?? null,
        'last_name' => $data['last_name'],
        'suffix' => $data['suffix'] ?? null,
        'birth_date' => $data['birth_date'],
        'birth_place' => $data['birth_place'] ?? null,
        'gender' => $data['gender'],
        'civil_status' => $data['civil_status'],
        'citizenship' => $data['citizenship'] ?? 'Filipino',
        'religion' => $data['religion'] ?? null,
        'education_level' => $data['education_level'] ?? null,
        'occupation' => $data['occupation'] ?? null,
        'monthly_income' => $data['monthly_income'] ?? null,
        'contact_number' => $data['contact_number'] ?? null
    ];
    $stmt = $pdo->prepare("
        INSERT INTO Person 
        (first_name, middle_name, last_name, suffix, birth_date, birth_place, gender, 
         civil_status, citizenship, religion, education_level, occupation, monthly_income, contact_number)
        VALUES 
        (:first_name, :middle_name, :last_name, :suffix, :birth_date, :birth_place, :gender, 
         :civil_status, :citizenship, :religion, :education_level, :occupation, :monthly_income, :contact_number)
    ");
    $stmt->execute($personData);
    return $pdo->lastInsertId();
}

function addToHousehold($pdo, $household_id, $person_id, $relationship, $is_head) {
    $stmt = $pdo->prepare("
        INSERT INTO HouseholdMember 
        (household_id, person_id, relationship_to_head, is_household_head)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$household_id, $person_id, $relationship, $is_head]);
}

function updateHouseholdHead($pdo, $household_id, $person_id) {
    $stmt = $pdo->prepare("
        UPDATE Household 
        SET household_head_person_id = ? 
        WHERE household_id = ?
    ");
    $stmt->execute([$person_id, $household_id]);
}

function updateHouseholdSize($pdo, $household_id, $new_size) {
    $stmt = $pdo->prepare("
        UPDATE Household 
        SET household_size = ? 
        WHERE household_id = ?
    ");
    $stmt->execute([$new_size, $household_id]);
}

function addAddress($pdo, $person_id, $barangay_id, $data) {
    $addressData = [
        'person_id' => $person_id,
        'barangay_id' => $barangay_id,
        'house_no' => $data['house_no'] ?? null,
        'street' => $data['street'] ?? null,
        'subdivision' => $data['subdivision'] ?? null,
        'block_lot' => $data['block_lot'] ?? null,
        'phase' => $data['phase'] ?? null,
        'residency_type' => $data['residency_type'] ?? 'Home Owner',
        'years_in_san_rafael' => $data['years_in_san_rafael'] ?? null,
        'is_primary' => 1
    ];
    $stmt = $pdo->prepare("
        INSERT INTO Address 
        (person_id, barangay_id, house_no, street, subdivision, block_lot, phase, 
         residency_type, years_in_san_rafael, is_primary)
        VALUES 
        (:person_id, :barangay_id, :house_no, :street, :subdivision, :block_lot, :phase, 
         :residency_type, :years_in_san_rafael, :is_primary)
    ");
    $stmt->execute($addressData);
}

/**
 * Process senior citizen specific data and store in database
 * 
 * @param PDO $pdo Database connection
 * @param int $person_id The ID of the person record
 * @param array $data Form data from $_POST
 * @return void
 */
function processSeniorData($pdo, $person_id, $data) {
    // Basic senior data
    $seniorData = [
        'person_id' => $person_id,
        'osca_id' => $data['osca_id'] ?? null,
        'tin' => $data['tin'] ?? null,
        'philhealth' => $data['philhealth'] ?? null,
        'gsis' => $data['gsis'] ?? null,
        'sss' => $data['sss'] ?? null,
        'other_id' => $data['other_id'] ?? null,
        'has_maintenance' => $data['has_maintenance'] ?? 'No',
        'maintenance_details' => $data['maintenance_details'] ?? null,
        'health_condition' => $data['health_condition'] ?? null,
        'other_specific_needs' => $data['other_specific_needs'] ?? null
    ];

    // Insert senior basic data
    $stmt = $pdo->prepare("
        INSERT INTO SeniorCitizen 
        (person_id, osca_id, tin, philhealth, gsis, sss, other_id, 
         has_maintenance, maintenance_details, health_condition, other_specific_needs)
        VALUES 
        (:person_id, :osca_id, :tin, :philhealth, :gsis, :sss, :other_id,
         :has_maintenance, :maintenance_details, :health_condition, :other_specific_needs)
    ");
    $stmt->execute($seniorData);
    $senior_id = $pdo->lastInsertId();

    // Process skills
    $skills = [];
    $skillFields = [
        'skill_medical', 'skill_farming', 'skill_teaching', 'skill_fishing', 
        'skill_legal', 'skill_cooking', 'skill_dental', 'skill_vocational', 
        'skill_counseling', 'skill_arts', 'skill_evangelization', 'skill_engineering'
    ];

    foreach ($skillFields as $field) {
        if (isset($data[$field]) && $data[$field] == 1) {
            $skills[] = str_replace('skill_', '', $field);
        }
    }

    // Add other skills if specified
    if (isset($data['skill_others']) && $data['skill_others'] == 1 && !empty($data['skill_others_details'])) {
        $skills[] = 'other:' . $data['skill_others_details'];
    }

    // Store skills in database
    if (!empty($skills)) {
        $stmt = $pdo->prepare("
            UPDATE SeniorCitizen 
            SET skills = ? 
            WHERE person_id = ?
        ");
        $stmt->execute([json_encode($skills), $person_id]);
    }

    // Process community involvement
    $involvements = [];
    $involvementFields = [
        'involvement_medical', 'involvement_neighborhood', 'involvement_resource',
        'involvement_religious', 'involvement_beautification', 'involvement_counseling',
        'involvement_leader', 'involvement_sponsorship', 'involvement_dental',
        'involvement_legal', 'involvement_visits'
    ];

    foreach ($involvementFields as $field) {
        if (isset($data[$field]) && $data[$field] == 1) {
            $involvements[] = str_replace('involvement_', '', $field);
        }
    }

    // Add other involvements if specified
    if (isset($data['involvement_others']) && $data['involvement_others'] == 1 && !empty($data['involvement_others_details'])) {
        $involvements[] = 'other:' . $data['involvement_others_details'];
    }

    // Store involvements in database
    if (!empty($involvements)) {
        $stmt = $pdo->prepare("
            UPDATE SeniorCitizen 
            SET community_involvements = ? 
            WHERE person_id = ?
        ");
        $stmt->execute([json_encode($involvements), $person_id]);
    }

    // Process Economic Problems/Needs
    $economicProblems = [];
    $economicFields = ['econ_lack', 'econ_loss'];
    
    foreach ($economicFields as $field) {
        if (isset($data[$field]) && $data[$field] == 1) {
            $economicProblems[] = str_replace('econ_', '', $field);
        }
    }
    
    // Add skills training if specified
    if (isset($data['econ_skills']) && $data['econ_skills'] == 1) {
        $detail = !empty($data['econ_skills_details']) ? 
            'skills_training:' . $data['econ_skills_details'] : 'skills_training';
        $economicProblems[] = $detail;
    }
    
    // Add livelihood opportunities if specified
    if (isset($data['econ_livelihood']) && $data['econ_livelihood'] == 1) {
        $detail = !empty($data['econ_livelihood_details']) ? 
            'livelihood:' . $data['econ_livelihood_details'] : 'livelihood';
        $economicProblems[] = $detail;
    }
    
    // Add other economic issues if specified
    if (isset($data['econ_others']) && $data['econ_others'] == 1 && !empty($data['econ_others_details'])) {
        $economicProblems[] = 'other:' . $data['econ_others_details'];
    }

    // Process Social/Emotional Problems
    $emotionalProblems = [];
    $emotionalFields = [
        'emotional_neglect', 'emotional_leisure', 'emotional_helpless',
        'emotional_environment', 'emotional_lonely'
    ];
    
    foreach ($emotionalFields as $field) {
        if (isset($data[$field]) && $data[$field] == 1) {
            $emotionalProblems[] = str_replace('emotional_', '', $field);
        }
    }
    
    // Add other emotional issues if specified
    if (isset($data['emotional_others']) && $data['emotional_others'] == 1 && !empty($data['emotional_others_details'])) {
        $emotionalProblems[] = 'other:' . $data['emotional_others_details'];
    }

    // Process Health Problems
    $healthProblems = [];
    $healthFields = [
        'high_cost_medicines', 'lack_medical_professionals', 'lack_sanitation_access',
        'lack_health_insurance', 'lack_medical_facilities'
    ];
    
    foreach ($healthFields as $field) {
        if (isset($data[$field]) && $data[$field] == 1) {
            $healthProblems[] = $field;
        }
    }
    
    // Add other health issues if specified
    if (isset($data['other_health_concerns']) && $data['other_health_concerns'] == 1 && !empty($data['other_health_concerns_details'])) {
        $healthProblems[] = 'other:' . $data['other_health_concerns_details'];
    }

    // Process Housing Problems
    $housingProblems = [];
    $housingFields = [
        'housing_overcrowding', 'housing_privacy', 'housing_no_permanent',
        'housing_squatter', 'housing_independent', 'housing_high_rent'
    ];
    
    foreach ($housingFields as $field) {
        if (isset($data[$field]) && $data[$field] == 1) {
            $housingProblems[] = str_replace('housing_', '', $field);
        }
    }
    
    // Add other housing issues if specified
    if (isset($data['housing_others']) && $data['housing_others'] == 1 && !empty($data['housing_others_details'])) {
        $housingProblems[] = 'other:' . $data['housing_others_details'];
    }

    // Process Community Service Needs
    $communityNeeds = [];
    $communityFields = ['community_desire', 'community_skills'];
    
    foreach ($communityFields as $field) {
        if (isset($data[$field]) && $data[$field] == 1) {
            $communityNeeds[] = str_replace('community_', '', $field);
        }
    }
    
    // Add other community needs if specified
    if (isset($data['community_others']) && $data['community_others'] == 1 && !empty($data['community_others_details'])) {
        $communityNeeds[] = 'other:' . $data['community_others_details'];
    }

    // Store all problems/needs in database
    $stmt = $pdo->prepare("
        UPDATE SeniorCitizen 
        SET economic_problems = ?,
            emotional_problems = ?,
            health_problems = ?,
            housing_problems = ?,
            community_needs = ?
        WHERE person_id = ?
    ");
    $stmt->execute([
        !empty($economicProblems) ? json_encode($economicProblems) : null,
        !empty($emotionalProblems) ? json_encode($emotionalProblems) : null,
        !empty($healthProblems) ? json_encode($healthProblems) : null,
        !empty($housingProblems) ? json_encode($housingProblems) : null,
        !empty($communityNeeds) ? json_encode($communityNeeds) : null,
        $person_id
    ]);
}

/**
 * Process child-specific data and store in database
 * 
 * @param PDO $pdo Database connection
 * @param int $person_id The ID of the person record
 * @param array $data Form data from $_POST
 * @return void
 */
function processChildData($pdo, $person_id, $data) {
    // Basic child data
    $childData = [
        'person_id' => $person_id,
        'birth_certificate' => isset($data['birth_certificate']) ? 1 : 0,
        'school_status' => $data['school_status'] ?? null,
        'grade_level' => $data['grade_level'] ?? null,
        'school_name' => $data['school_name'] ?? null,
        'special_needs' => $data['special_needs'] ?? null,
        'health_conditions' => $data['health_conditions'] ?? null,
        'living_with_parent' => isset($data['living_with_parent']) ? 1 : 0,
        'guardian_name' => $data['guardian_name'] ?? null,
        'guardian_relationship' => $data['guardian_relationship'] ?? null,
        'guardian_contact' => $data['guardian_contact'] ?? null
    ];

    // Insert child basic data
    $stmt = $pdo->prepare("
        INSERT INTO ChildData 
        (person_id, birth_certificate, school_status, grade_level, school_name, 
         special_needs, health_conditions, living_with_parent, guardian_name, 
         guardian_relationship, guardian_contact)
        VALUES 
        (:person_id, :birth_certificate, :school_status, :grade_level, :school_name,
         :special_needs, :health_conditions, :living_with_parent, :guardian_name,
         :guardian_relationship, :guardian_contact)
    ");
    $stmt->execute($childData);
}

/**
 * Process community concerns/issues
 * 
 * @param PDO $pdo Database connection
 * @param int $household_id The ID of the household
 * @param array $data Form data from $_POST
 * @return void
 */
function processCommunityConcerns($pdo, $household_id, $data) {
    // Check if community concerns were submitted
    if (empty($data['community_concerns'])) {
        return;
    }
    
    // Insert each concern
    foreach ($data['community_concerns'] as $concern_type => $details) {
        if (empty($details)) continue;
        
        $stmt = $pdo->prepare("
            INSERT INTO CommunityConcerns 
            (household_id, concern_type, details, reported_date)
            VALUES (?, ?, ?, CURRENT_DATE())
        ");
        $stmt->execute([$household_id, $concern_type, $details]);
    }
}

/**
 * Generate a new household ID for the barangay
 * 
 * @param PDO $pdo Database connection
 * @param int $barangay_id The ID of the barangay
 * @return string The generated household ID
 */
function generateHouseholdId($pdo, $barangay_id) {
    // Get barangay code
    $stmt = $pdo->prepare("SELECT barangay_code FROM Barangay WHERE barangay_id = ?");
    $stmt->execute([$barangay_id]);
    $barangay = $stmt->fetch(PDO::FETCH_ASSOC);
    $barangay_code = $barangay['barangay_code'] ?? 'BR';
    
    // Get current year
    $year = date('Y');
    
    // Get count of households in this barangay for this year
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM Household 
        WHERE barangay_id = ? AND household_id LIKE ?
    ");
    $stmt->execute([$barangay_id, "$barangay_code-$year-%"]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $count = $result['count'] + 1;
    
    // Format: BR-YYYY-NNNN (BR = Barangay Code, YYYY = Year, NNNN = Sequence)
    return sprintf("%s-%s-%04d", $barangay_code, $year, $count);
}

/**
 * Calculate age based on birthdate
 * 
 * @param string $birthDate Birthdate in Y-m-d format
 * @return int Age in years
 */
function calculateAge($birthDate) {
    $birth = new DateTime($birthDate);
    $today = new DateTime();
    $age = $birth->diff($today);
    return $age->y;
}
}
