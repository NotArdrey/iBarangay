<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get type ID by name from a specific type table
 * @param PDO $pdo Database connection
 * @param string $table_name The type table name
 * @param string $type_name The type name to look up
 * @return int|null The type ID or null if not found
 */
function getTypeIdByName($pdo, $table_name, $type_name) {
    $stmt = $pdo->prepare("SELECT id FROM {$table_name} WHERE LOWER(name) = LOWER(?)");
    $stmt->execute([trim($type_name)]);
    return $stmt->fetchColumn() ?: null;
}

/**
 * Save resident data to the database
 * @param PDO $pdo Database connection
 * @param array $data Resident data
 * @param int $barangay_id Barangay ID
 * @return array Result with success status and message
 */
function saveResident($pdo, $data, $barangay_id) {
    // Validate civil_status
    if (!isValidCivilStatus($data['civil_status'] ?? '')) {
        return [
            'success' => false,
            'message' => 'Invalid civil status value.'
        ];
    }
    try {
        $pdo->beginTransaction();

        // Prepare data for persons table
        $person_params = [
            ':first_name' => trim($data['first_name'] ?? ''),
            ':middle_name' => isset($data['middle_name']) ? trim($data['middle_name']) : null,
            ':last_name' => trim($data['last_name'] ?? ''),
            ':suffix' => isset($data['suffix']) ? substr(trim($data['suffix']), 0, 10) : null,
            ':birth_date' => $data['birth_date'] ?? null,
            ':birth_place' => isset($data['birth_place']) ? trim($data['birth_place']) : null,
            ':gender' => $data['gender'] ?? null,
            ':civil_status' => $data['civil_status'] ?? null,
            ':citizenship' => isset($data['citizenship']) ? trim($data['citizenship']) : 'FILIPINO',
            ':religion' => (isset($data['religion']) && strtoupper($data['religion']) === 'OTHERS') ? trim($data['other_religion'] ?? '') : (isset($data['religion']) ? trim($data['religion']) : null),
            ':education_level' => isset($data['education_level']) ? trim($data['education_level']) : null,
            ':occupation' => isset($data['occupation']) ? trim($data['occupation']) : null,
            ':monthly_income' => isset($data['monthly_income']) && trim($data['monthly_income']) !== '' ? (float)trim($data['monthly_income']) : null,
            ':years_of_residency' => isset($data['years_of_residency']) ? (int)trim($data['years_of_residency']) : 0,
            ':nhts_pr_listahanan' => isset($data['nhts_pr_listahanan']) ? 1 : 0,
            ':indigenous_people' => isset($data['indigenous_people']) ? 1 : 0,
            ':pantawid_beneficiary' => isset($data['pantawid_beneficiary']) ? 1 : 0,
            ':resident_type' => isset($data['resident_type']) ? strtolower(trim($data['resident_type'])) : 'regular',
            ':contact_number' => isset($data['contact_number']) ? trim($data['contact_number']) : null,
            ':user_id' => null
        ];

        // SQL for persons table
        $sql_persons = "
            INSERT INTO persons (
                first_name, middle_name, last_name, suffix,
                birth_date, birth_place, gender, civil_status,
                citizenship, religion, education_level,
                occupation, monthly_income, years_of_residency,
                nhts_pr_listahanan, indigenous_people, pantawid_beneficiary,
                resident_type, contact_number, user_id
            ) VALUES (
                :first_name, :middle_name, :last_name, :suffix,
                :birth_date, :birth_place, :gender, :civil_status,
                :citizenship, :religion, :education_level,
                :occupation, :monthly_income, :years_of_residency,
                :nhts_pr_listahanan, :indigenous_people, :pantawid_beneficiary,
                :resident_type, :contact_number, :user_id
            )
        ";
        $stmt_persons = $pdo->prepare($sql_persons);
        $stmt_persons->execute($person_params);
        $person_id = $pdo->lastInsertId();
        
        // Insert government IDs
        $stmt_ids = $pdo->prepare("
            INSERT INTO person_identification (
                person_id, osca_id, gsis_id, sss_id, tin_id, philhealth_id,
                other_id_type, other_id_number
            ) VALUES (
                :person_id, :osca_id, :gsis_id, :sss_id, :tin_id, :philhealth_id,
                :other_id_type, :other_id_number
            )
        ");
        
        $stmt_ids->execute([
            ':person_id' => $person_id,
            ':osca_id' => trim($data['osca_id'] ?? ''),
            ':gsis_id' => trim($data['gsis_id'] ?? ''),
            ':sss_id' => trim($data['sss_id'] ?? ''),
            ':tin_id' => trim($data['tin_id'] ?? ''),
            ':philhealth_id' => trim($data['philhealth_id'] ?? ''),
            ':other_id_type' => trim($data['other_id_type'] ?? ''),
            ':other_id_number' => trim($data['other_id_number'] ?? '')
        ]);

        // Insert present address
        $stmt_address = $pdo->prepare("
                INSERT INTO addresses (
                person_id, barangay_id, house_no, street,
                municipality, province, region, is_primary, is_permanent
                ) VALUES (
                :person_id, :barangay_id, :house_no, :street,
                :municipality, :province, :region, :is_primary, :is_permanent
                )
            ");
        
        // Present address
        $stmt_address->execute([
                ':person_id' => $person_id,
                ':barangay_id' => $barangay_id,
            ':house_no' => trim($data['present_house_no'] ?? ''),
            ':street' => trim($data['present_street'] ?? ''),
            ':municipality' => trim($data['present_municipality'] ?? ''),
            ':province' => trim($data['present_province'] ?? ''),
            ':region' => trim($data['present_region'] ?? ''),
            ':is_primary' => 1,
            ':is_permanent' => 0
        ]);
        
        // Add person to household if household_id is provided
        if (!empty($data['household_id'])) {
            $stmt_household = $pdo->prepare("
                INSERT INTO household_members (
                    person_id, household_id, relationship_type_id, is_household_head
                ) VALUES (
                    :person_id, :household_id, :relationship_type_id, :is_household_head
                )
            ");
            
            // Get relationship type ID if string was provided
            $relationship_type_id = null;
            if (!empty($data['relationship'])) {
                if (is_numeric($data['relationship'])) {
                    $relationship_type_id = $data['relationship'];
                } else {
                    // Try to get relationship type ID from name
                    $stmt_rel = $pdo->prepare("SELECT id FROM relationship_types WHERE LOWER(name) = LOWER(?)");
                    $stmt_rel->execute([trim($data['relationship'])]);
                    $relationship_type_id = $stmt_rel->fetchColumn() ?: null;
                }
            }
            
            $stmt_household->execute([
                ':person_id' => $person_id,
                ':household_id' => $data['household_id'],
                ':relationship_type_id' => $relationship_type_id,
                ':is_household_head' => isset($data['is_household_head']) && $data['is_household_head'] ? 1 : 0
            ]);
            
            // If person is household head, update the household record
            if (isset($data['is_household_head']) && $data['is_household_head']) {
                $stmt_update_household = $pdo->prepare("
                    UPDATE households 
                    SET household_head_person_id = :person_id 
                    WHERE id = :household_id
                ");
                $stmt_update_household->execute([
                    ':person_id' => $person_id,
                    ':household_id' => $data['household_id']
                ]);
            }
        } else if (!empty($data['purok_id'])) {
            // Create new household if purok is specified but no household_id
            $stmt = $pdo->prepare("
                SELECT MAX(CAST(id AS UNSIGNED)) as max_id 
                FROM households 
                WHERE barangay_id = ? AND purok_id = ?
            ");
            $stmt->execute([$barangay_id, $data['purok_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Generate new household ID
            $next_number = ($result['max_id'] ?? 0) + 1;
            $new_household_id = sprintf('%04d', $next_number);
            
            // Create new household
            $stmt = $pdo->prepare("
                INSERT INTO households (id, barangay_id, purok_id, household_head_person_id) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $new_household_id,
                $barangay_id,
                $data['purok_id'],
                isset($data['is_household_head']) && $data['is_household_head'] ? $person_id : null
            ]);
            
            // Add person to the new household
            $stmt_household = $pdo->prepare("
                INSERT INTO household_members (
                    person_id, household_id, relationship_type_id, is_household_head
                ) VALUES (
                    :person_id, :household_id, :relationship_type_id, :is_household_head
                )
            ");
            
            // Get relationship type ID if string was provided
            $relationship_type_id = null;
            if (!empty($data['relationship'])) {
                if (is_numeric($data['relationship'])) {
                    $relationship_type_id = $data['relationship'];
                } else {
                    // Try to get relationship type ID from name
                    $stmt_rel = $pdo->prepare("SELECT id FROM relationship_types WHERE LOWER(name) = LOWER(?)");
                    $stmt_rel->execute([trim($data['relationship'])]);
                    $relationship_type_id = $stmt_rel->fetchColumn() ?: null;
                }
            }
            
            $stmt_household->execute([
                ':person_id' => $person_id,
                ':household_id' => $new_household_id,
                ':relationship_type_id' => $relationship_type_id,
                ':is_household_head' => isset($data['is_household_head']) && $data['is_household_head'] ? 1 : 0
            ]);
        }
        
        // Permanent address (if different from present)
        if (empty($data['same_as_present'])) {
            $stmt_address->execute([
                ':person_id' => $person_id,
                ':barangay_id' => $barangay_id,
                ':house_no' => trim($data['permanent_house_no'] ?? ''),
                ':street' => trim($data['permanent_street'] ?? ''),
                ':municipality' => trim($data['permanent_municipality'] ?? ''),
                ':province' => trim($data['permanent_province'] ?? ''),
                ':region' => trim($data['permanent_region'] ?? ''),
                ':is_primary' => 0,
                ':is_permanent' => 1
            ]);
        }

        // Insert living arrangements
        $living_arrangements = [];
        $living_fields = [
            'living_alone', 'living_spouse', 'living_children', 'living_grandchildren',
            'living_in_laws', 'living_relatives', 'living_househelps', 'living_care_institutions',
            'living_common_law_spouse'
        ];
        
        foreach ($living_fields as $field) {
            if (isset($data[$field]) && $data[$field] == 1) {
                $type_name = str_replace('living_', '', $field);
                $type_id = getTypeIdByName($pdo, 'living_arrangement_types', $type_name);
                if ($type_id) {
                    $living_arrangements[] = [
                        'type_id' => $type_id,
                        'details' => null
                    ];
                }
            }
        }
        
        // Handle "Others" field for living arrangements
        if (isset($data['living_others']) && $data['living_others'] == 1) {
            $type_id = getTypeIdByName($pdo, 'living_arrangement_types', 'others');
            if ($type_id) {
                $living_arrangements[] = [
                    'type_id' => $type_id,
                    'details' => $data['living_others_specify'] ?? null
                ];
            }
        }

        if (!empty($living_arrangements)) {
            $stmt_living = $pdo->prepare("
                INSERT INTO person_living_arrangements (
                    person_id, arrangement_type_id, details
                ) VALUES (
                    :person_id, :arrangement_type_id, :details
                )
            ");
            
            foreach ($living_arrangements as $arrangement) {
                $stmt_living->execute([
                ':person_id' => $person_id,
                    ':arrangement_type_id' => $arrangement['type_id'],
                    ':details' => $arrangement['details']
                ]);
            }
        }

        // Insert skills
        $skills = [];
        $skill_fields = [
            'skill_medical', 'skill_teaching', 'skill_legal_services', 'skill_dental',
            'skill_counseling', 'skill_evangelization', 'skill_farming', 'skill_fishing',
            'skill_cooking', 'skill_vocational', 'skill_arts', 'skill_engineering'
        ];
        
        foreach ($skill_fields as $field) {
            if (isset($data[$field]) && $data[$field] == 1) {
                $type_name = str_replace('skill_', '', $field);
                $type_id = getTypeIdByName($pdo, 'skill_types', $type_name);
                if ($type_id) {
                    $skills[] = [
                        'type_id' => $type_id,
                        'details' => null
                    ];
                }
            }
        }
        
        // Handle "Others" field for skills
        if (isset($data['skill_others']) && $data['skill_others'] == 1) {
            $type_id = getTypeIdByName($pdo, 'skill_types', 'others');
            if ($type_id) {
                $skills[] = [
                    'type_id' => $type_id,
                    'details' => $data['skill_others_specify'] ?? null
                ];
            }
        }

        if (!empty($skills)) {
            $stmt_skills = $pdo->prepare("
                INSERT INTO person_skills (
                    person_id, skill_type_id, details
                ) VALUES (
                    :person_id, :skill_type_id, :details
                )
            ");
            
            foreach ($skills as $skill) {
                $stmt_skills->execute([
                ':person_id' => $person_id,
                    ':skill_type_id' => $skill['type_id'],
                    ':details' => $skill['details']
                ]);
            }
        }

        // Insert community involvements
        $involvements = [];
        $involvement_fields = [
            'involvement_medical', 'involvement_resource_volunteer', 'involvement_community_beautification',
            'involvement_community_leader', 'involvement_dental', 'involvement_friendly_visits',
            'involvement_neighborhood_support', 'involvement_religious', 'involvement_counselling',
            'involvement_sponsorship', 'involvement_legal_services'
        ];
        
        foreach ($involvement_fields as $field) {
            if (isset($data[$field]) && $data[$field] == 1) {
                $type_name = str_replace('involvement_', '', $field);
                $type_id = getTypeIdByName($pdo, 'involvement_types', $type_name);
                if ($type_id) {
                    $involvements[] = [
                        'type_id' => $type_id,
                        'details' => null
                    ];
                }
            }
        }
        
        // Handle "Others" field for involvements
        if (isset($data['involvement_others']) && $data['involvement_others'] == 1) {
            $type_id = getTypeIdByName($pdo, 'involvement_types', 'others');
            if ($type_id) {
                $involvements[] = [
                    'type_id' => $type_id,
                    'details' => $data['involvement_others_specify'] ?? null
                ];
            }
        }

        if (!empty($involvements)) {
            $stmt_involvements = $pdo->prepare("
                INSERT INTO person_involvements (
                    person_id, involvement_type_id, details
                ) VALUES (
                    :person_id, :involvement_type_id, :details
                )
            ");
            
            foreach ($involvements as $involvement) {
                $stmt_involvements->execute([
                    ':person_id' => $person_id,
                    ':involvement_type_id' => $involvement['type_id'],
                    ':details' => $involvement['details']
                ]);
            }
        }

        // Insert problems by category - only insert when at least one checkbox is selected
        
        // Economic Problems
        $has_economic_problems = 
            (isset($data['problem_lack_income']) && $data['problem_lack_income'] == 1) ||
            (isset($data['problem_loss_income']) && $data['problem_loss_income'] == 1) ||
            (isset($data['problem_skills_training']) && $data['problem_skills_training'] == 1) ||
            (isset($data['problem_livelihood']) && $data['problem_livelihood'] == 1) ||
            (isset($data['problem_economic_others']) && $data['problem_economic_others'] == 1) ||
            !empty($data['problem_skills_training_specify']) ||
            !empty($data['problem_livelihood_specify']) ||
            !empty($data['problem_economic_others_specify']);
            
        if ($has_economic_problems) {
            $stmt_economic = $pdo->prepare("
                INSERT INTO person_economic_problems (
                    person_id, loss_income, unemployment, 
                    skills_training, skills_training_details, livelihood, livelihood_details,
                    other_economic, other_economic_details
                ) VALUES (
                    :person_id, :loss_income, :unemployment,
                    :skills_training, :skills_training_details, :livelihood, :livelihood_details,
                    :other_economic, :other_economic_details
                )
            ");
            
            $stmt_economic->execute([
                ':person_id' => $person_id,
                ':loss_income' => isset($data['problem_loss_income']) && $data['problem_loss_income'] == 1 ? 1 : 0,
                ':unemployment' => isset($data['problem_lack_income']) && $data['problem_lack_income'] == 1 ? 1 : 0,
                ':skills_training' => isset($data['problem_skills_training']) && $data['problem_skills_training'] == 1 ? 1 : 0,
                ':skills_training_details' => $data['problem_skills_training_specify'] ?? null,
                ':livelihood' => isset($data['problem_livelihood']) && $data['problem_livelihood'] == 1 ? 1 : 0,
                ':livelihood_details' => $data['problem_livelihood_specify'] ?? null,
                ':other_economic' => isset($data['problem_economic_others']) && $data['problem_economic_others'] == 1 ? 1 : 0,
                ':other_economic_details' => $data['problem_economic_others_specify'] ?? null
            ]);
        }
        
        // Social Problems
        $has_social_problems = 
            (isset($data['problem_neglect_rejection']) && $data['problem_neglect_rejection'] == 1) ||
            (isset($data['problem_helplessness']) && $data['problem_helplessness'] == 1) ||
            (isset($data['problem_loneliness']) && $data['problem_loneliness'] == 1) ||
            (isset($data['problem_recreational']) && $data['problem_recreational'] == 1) ||
            (isset($data['problem_senior_friendly']) && $data['problem_senior_friendly'] == 1) ||
            (isset($data['problem_social_others']) && $data['problem_social_others'] == 1) ||
            !empty($data['problem_social_others_specify']);
            
        if ($has_social_problems) {
            $stmt_social = $pdo->prepare("
                INSERT INTO person_social_problems (
                    person_id, loneliness, isolation, neglect, recreational,
                    senior_friendly, other_social, other_social_details
                ) VALUES (
                    :person_id, :loneliness, :isolation, :neglect, :recreational,
                    :senior_friendly, :other_social, :other_social_details
                )
            ");
            
            $stmt_social->execute([
                ':person_id' => $person_id,
                ':loneliness' => isset($data['problem_loneliness']) && $data['problem_loneliness'] == 1 ? 1 : 0,
                ':isolation' => isset($data['problem_helplessness']) && $data['problem_helplessness'] == 1 ? 1 : 0,
                ':neglect' => isset($data['problem_neglect_rejection']) && $data['problem_neglect_rejection'] == 1 ? 1 : 0,
                ':recreational' => isset($data['problem_recreational']) && $data['problem_recreational'] == 1 ? 1 : 0,
                ':senior_friendly' => isset($data['problem_senior_friendly']) && $data['problem_senior_friendly'] == 1 ? 1 : 0,
                ':other_social' => isset($data['problem_social_others']) && $data['problem_social_others'] == 1 ? 1 : 0,
                ':other_social_details' => $data['problem_social_others_specify'] ?? null
            ]);
        }
        
        // Health Problems
        $has_health_problems = 
            (isset($data['problem_condition_illness']) && $data['problem_condition_illness'] == 1) ||
            (isset($data['problem_high_cost_medicine']) && $data['problem_high_cost_medicine'] == 1) ||
            (isset($data['problem_lack_medical_professionals']) && $data['problem_lack_medical_professionals'] == 1) ||
            (isset($data['problem_lack_sanitation']) && $data['problem_lack_sanitation'] == 1) ||
            (isset($data['problem_lack_health_insurance']) && $data['problem_lack_health_insurance'] == 1) ||
            (isset($data['problem_inadequate_health_services']) && $data['problem_inadequate_health_services'] == 1) ||
            (isset($data['problem_health_others']) && $data['problem_health_others'] == 1) ||
            !empty($data['problem_condition_illness_specify']) ||
            !empty($data['problem_health_others_specify']);
            
        if ($has_health_problems) {
            $stmt_health_problems = $pdo->prepare("
                INSERT INTO person_health_problems (
                    person_id, condition_illness, condition_illness_details,
                    high_cost_medicine, lack_medical_professionals, lack_sanitation,
                    lack_health_insurance, inadequate_health_services,
                    other_health, other_health_details
                ) VALUES (
                    :person_id, :condition_illness, :condition_illness_details,
                    :high_cost_medicine, :lack_medical_professionals, :lack_sanitation,
                    :lack_health_insurance, :inadequate_health_services,
                    :other_health, :other_health_details
                )
            ");
            
            $stmt_health_problems->execute([
                ':person_id' => $person_id,
                ':condition_illness' => isset($data['problem_condition_illness']) && $data['problem_condition_illness'] == 1 ? 1 : 0,
                ':condition_illness_details' => $data['problem_condition_illness_specify'] ?? null,
                ':high_cost_medicine' => isset($data['problem_high_cost_medicine']) && $data['problem_high_cost_medicine'] == 1 ? 1 : 0,
                ':lack_medical_professionals' => isset($data['problem_lack_medical_professionals']) && $data['problem_lack_medical_professionals'] == 1 ? 1 : 0,
                ':lack_sanitation' => isset($data['problem_lack_sanitation']) && $data['problem_lack_sanitation'] == 1 ? 1 : 0,
                ':lack_health_insurance' => isset($data['problem_lack_health_insurance']) && $data['problem_lack_health_insurance'] == 1 ? 1 : 0,
                ':inadequate_health_services' => isset($data['problem_inadequate_health_services']) && $data['problem_inadequate_health_services'] == 1 ? 1 : 0,
                ':other_health' => isset($data['problem_health_others']) && $data['problem_health_others'] == 1 ? 1 : 0,
                ':other_health_details' => $data['problem_health_others_specify'] ?? null
            ]);
        }
        
        // Housing Problems
        $has_housing_problems = 
            (isset($data['problem_overcrowding']) && $data['problem_overcrowding'] == 1) ||
            (isset($data['problem_no_permanent_housing']) && $data['problem_no_permanent_housing'] == 1) ||
            (isset($data['problem_independent_living']) && $data['problem_independent_living'] == 1) ||
            (isset($data['problem_lost_privacy']) && $data['problem_lost_privacy'] == 1) ||
            (isset($data['problem_squatters']) && $data['problem_squatters'] == 1) ||
            (isset($data['problem_housing_others']) && $data['problem_housing_others'] == 1) ||
            !empty($data['problem_housing_others_specify']);
            
        if ($has_housing_problems) {
            $stmt_housing = $pdo->prepare("
                INSERT INTO person_housing_problems (
                    person_id, overcrowding, no_permanent_housing, independent_living,
                    lost_privacy, squatters, other_housing, other_housing_details
                ) VALUES (
                    :person_id, :overcrowding, :no_permanent_housing, :independent_living,
                    :lost_privacy, :squatters, :other_housing, :other_housing_details
                )
            ");
            
            $stmt_housing->execute([
                ':person_id' => $person_id,
                ':overcrowding' => isset($data['problem_overcrowding']) && $data['problem_overcrowding'] == 1 ? 1 : 0,
                ':no_permanent_housing' => isset($data['problem_no_permanent_housing']) && $data['problem_no_permanent_housing'] == 1 ? 1 : 0,
                ':independent_living' => isset($data['problem_independent_living']) && $data['problem_independent_living'] == 1 ? 1 : 0,
                ':lost_privacy' => isset($data['problem_lost_privacy']) && $data['problem_lost_privacy'] == 1 ? 1 : 0,
                ':squatters' => isset($data['problem_squatters']) && $data['problem_squatters'] == 1 ? 1 : 0,
                ':other_housing' => isset($data['problem_housing_others']) && $data['problem_housing_others'] == 1 ? 1 : 0,
                ':other_housing_details' => $data['problem_housing_others_specify'] ?? null
            ]);
        }
        
        // Community Service Problems
        $has_community_problems = 
            (isset($data['problem_desire_participate']) && $data['problem_desire_participate'] == 1) ||
            (isset($data['problem_skills_to_share']) && $data['problem_skills_to_share'] == 1) ||
            (isset($data['problem_community_others']) && $data['problem_community_others'] == 1) ||
            !empty($data['problem_community_others_specify']);
            
        if ($has_community_problems) {
            $stmt_community = $pdo->prepare("
                INSERT INTO person_community_problems (
                    person_id, desire_participate, skills_to_share,
                    other_community, other_community_details
                ) VALUES (
                    :person_id, :desire_participate, :skills_to_share,
                    :other_community, :other_community_details
                )
            ");
            
            $stmt_community->execute([
                ':person_id' => $person_id,
                ':desire_participate' => isset($data['problem_desire_participate']) && $data['problem_desire_participate'] == 1 ? 1 : 0,
                ':skills_to_share' => isset($data['problem_skills_to_share']) && $data['problem_skills_to_share'] == 1 ? 1 : 0,
                ':other_community' => isset($data['problem_community_others']) && $data['problem_community_others'] == 1 ? 1 : 0,
                ':other_community_details' => $data['problem_community_others_specify'] ?? null
            ]);
        }

        // Insert government programs participation
        $stmt_govt = $pdo->prepare("
            INSERT INTO government_programs (
                person_id, nhts_pr_listahanan, indigenous_people, pantawid_beneficiary
            ) VALUES (
                :person_id, :nhts_pr_listahanan, :indigenous_people, :pantawid_beneficiary
            )
        ");
        
        $stmt_govt->execute([
            ':person_id' => $person_id,
            ':nhts_pr_listahanan' => isset($data['nhts_pr_listahanan']) && $data['nhts_pr_listahanan'] == 1 ? 1 : 0,
            ':indigenous_people' => isset($data['indigenous_people']) && $data['indigenous_people'] == 1 ? 1 : 0,
            ':pantawid_beneficiary' => isset($data['pantawid_beneficiary']) && $data['pantawid_beneficiary'] == 1 ? 1 : 0
        ]);

        // Insert assets
        $assets = [];
        $asset_fields = [
            'asset_house', 'asset_house_lot', 'asset_farmland', 'asset_commercial',
            'asset_lot', 'asset_fishpond'
        ];
        
        foreach ($asset_fields as $field) {
            if (isset($data[$field]) && $data[$field] == 1) {
                $type_name = str_replace('asset_', '', $field);
                $type_id = getTypeIdByName($pdo, 'asset_types', $type_name);
                if ($type_id) {
                    $assets[] = [
                        'type_id' => $type_id,
                        'details' => null
                    ];
                }
            }
        }
        
        // Handle "Others" field for assets
        if (isset($data['asset_others']) && $data['asset_others'] == 1) {
            $type_id = getTypeIdByName($pdo, 'asset_types', 'others');
            if ($type_id) {
                $assets[] = [
                    'type_id' => $type_id,
                    'details' => $data['asset_others_specify'] ?? null
                ];
            }
        }

        if (!empty($assets)) {
            $stmt_assets = $pdo->prepare("
                INSERT INTO person_assets (
                    person_id, asset_type_id, details
                ) VALUES (
                    :person_id, :asset_type_id, :details
                )
            ");
            
            foreach ($assets as $asset) {
                $stmt_assets->execute([
                    ':person_id' => $person_id,
                    ':asset_type_id' => $asset['type_id'],
                    ':details' => $asset['details']
                ]);
            }
        }

        // Insert income sources
        $income_sources = [];
        $income_fields = [
            'income_own_earnings' => null,
            'income_own_pension' => 'income_own_pension_amount',
            'income_stocks_dividends' => null,
            'income_dependent_on_children' => null,
            'income_spouse_salary' => null,
            'income_insurances' => null,
            'income_spouse_pension' => 'income_spouse_pension_amount',
            'income_rentals_sharecrops' => null,
            'income_savings' => null,
            'income_livestock_orchards' => null
        ];
        
        foreach ($income_fields as $field => $amount_field) {
            if (isset($data[$field]) && $data[$field] == 1) {
                // Map form field names to database field names
                $type_name = str_replace('income_', '', $field);
                
                // Handle special cases to match the database exactly
                switch ($type_name) {
                    case 'own_earnings':
                        $type_name = 'Own Earnings/Salaries/Wages';
                        break;
                    case 'stocks_dividends':
                        $type_name = 'Stocks/Dividends';
                        break;
                    case 'dependent_on_children':
                        $type_name = 'Dependent on Children/Relatives';
                        break;
                    case 'spouse_salary':
                        $type_name = 'Spouse Salary';
                        break;
                    case 'insurances':
                        $type_name = 'Insurances';
                        break;
                    case 'own_pension':
                        $type_name = 'Own Pension';
                        break;
                    case 'spouse_pension':
                        $type_name = 'Spouse Pension';
                        break;
                    case 'rentals_sharecrops':
                        $type_name = 'Rentals/Sharecrops';
                        break;
                    case 'savings':
                        $type_name = 'Savings';
                        break;
                    case 'livestock_orchards':
                        $type_name = 'Livestock/Orchards';
                        break;
                }
                
                $type_id = getTypeIdByName($pdo, 'income_source_types', $type_name);
                if ($type_id) {
                    $amount = null;
                    if ($amount_field && isset($data[$amount_field])) {
                        $amount = floatval(str_replace(['₱', ','], '', $data[$amount_field]));
                    }
                    $income_sources[] = [
                        'type_id' => $type_id,
                        'amount' => $amount,
                        'details' => null
                    ];
                }
            }
        }
        
        // Handle "Others" field for income sources
        if (isset($data['income_others']) && $data['income_others'] == 1) {
            $type_id = getTypeIdByName($pdo, 'income_source_types', 'Others');
            if ($type_id) {
                $income_sources[] = [
                    'type_id' => $type_id,
                    'amount' => null,
                    'details' => $data['income_others_specify'] ?? null
                ];
            }
        }

        if (!empty($income_sources)) {
            $stmt_income = $pdo->prepare("
                INSERT INTO person_income_sources (
                    person_id, source_type_id, amount, details
                ) VALUES (
                    :person_id, :source_type_id, :amount, :details
                )
            ");
            
            foreach ($income_sources as $source) {
                $stmt_income->execute([
                    ':person_id' => $person_id,
                    ':source_type_id' => $source['type_id'],
                    ':amount' => $source['amount'],
                    ':details' => $source['details']
                ]);
            }
        }

        // Delete and reinsert health information
        $pdo->prepare("DELETE FROM person_health_info WHERE person_id = ?")->execute([$person_id]);
        
        // Only insert health information if at least one checkbox is checked or text field is filled
        $has_health_info = 
            !empty($data['health_condition']) ||
            (isset($data['has_maintenance']) && $data['has_maintenance'] == 1) ||
            !empty($data['maintenance_details']) ||
            (isset($data['high_cost_medicines']) && $data['high_cost_medicines'] == 1) ||
            (isset($data['lack_medical_professionals']) && $data['lack_medical_professionals'] == 1) ||
            (isset($data['lack_sanitation_access']) && $data['lack_sanitation_access'] == 1) ||
            (isset($data['lack_health_insurance']) && $data['lack_health_insurance'] == 1) ||
            (isset($data['lack_medical_facilities']) && $data['lack_medical_facilities'] == 1) ||
            !empty($data['other_health_concerns']);
            
        if ($has_health_info) {
            $stmt_health = $pdo->prepare("
                INSERT INTO person_health_info (
                    person_id, health_condition, has_maintenance, maintenance_details,
                    high_cost_medicines, lack_medical_professionals, lack_sanitation_access,
                    lack_health_insurance, lack_medical_facilities, other_health_concerns
                ) VALUES (
                    :person_id, :health_condition, :has_maintenance, :maintenance_details,
                    :high_cost_medicines, :lack_medical_professionals, :lack_sanitation_access,
                    :lack_health_insurance, :lack_medical_facilities, :other_health_concerns
                )
            ");
            
            $stmt_health->execute([
                ':person_id' => $person_id,
                ':health_condition' => $data['health_condition'] ?? null,
                ':has_maintenance' => isset($data['has_maintenance']) && $data['has_maintenance'] == 1 ? 1 : 0,
                ':maintenance_details' => $data['maintenance_details'] ?? null,
                ':high_cost_medicines' => isset($data['high_cost_medicines']) && $data['high_cost_medicines'] == 1 ? 1 : 0,
                ':lack_medical_professionals' => isset($data['lack_medical_professionals']) && $data['lack_medical_professionals'] == 1 ? 1 : 0, 
                ':lack_sanitation_access' => isset($data['lack_sanitation_access']) && $data['lack_sanitation_access'] == 1 ? 1 : 0,
                ':lack_health_insurance' => isset($data['lack_health_insurance']) && $data['lack_health_insurance'] == 1 ? 1 : 0,
                ':lack_medical_facilities' => isset($data['lack_medical_facilities']) && $data['lack_medical_facilities'] == 1 ? 1 : 0,
                ':other_health_concerns' => $data['other_health_concerns'] ?? null
            ]);
        }

        // Insert other needs (free-form text)
        if (!empty($data['other_specific_needs'])) {
            $stmt_other = $pdo->prepare("
                INSERT INTO person_other_needs (
                    person_id, need_type_id, details
                ) VALUES (
                    :person_id, 
                    (SELECT id FROM other_need_types WHERE name = 'Cultural Activities' LIMIT 1),
                    :details
                )
            ");
            
            $stmt_other->execute([
                ':person_id' => $person_id,
                ':details' => $data['other_specific_needs']
            ]);
        }

        // Log the action
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
        
        // Save family composition data
        if (isset($data['family_member_name']) && is_array($data['family_member_name'])) {
            $stmt_family = $pdo->prepare("
                INSERT INTO family_composition (
                    household_id, person_id, name, relationship, age, 
                    civil_status, occupation, monthly_income
                ) VALUES (
                    :household_id, :person_id, :name, :relationship, :age,
                    :civil_status, :occupation, :monthly_income
                )
            ");
            
            foreach ($data['family_member_name'] as $key => $name) {
                // Skip empty rows
                if (empty($name)) {
                    continue;
                }
                
                // Get values for this family member
                $relationship = $data['family_member_relationship'][$key] ?? '';
                $age = $data['family_member_age'][$key] ?? null;
                $civil_status = $data['family_member_civil_status'][$key] ?? '';
                $occupation = $data['family_member_occupation'][$key] ?? '';
                $income = $data['family_member_income'][$key] ?? null;
                
                // Convert income to numeric if not empty
                if (!empty($income)) {
                    $income = str_replace(['₱', ','], '', $income);
                    $income = is_numeric($income) ? (float)$income : null;
                }
                
                $stmt_family->execute([
                    ':household_id' => $data['household_id'] ?? null,
                    ':person_id' => $person_id,
                    ':name' => trim($name),
                    ':relationship' => trim($relationship),
                    ':age' => $age,
                    ':civil_status' => trim($civil_status),
                    ':occupation' => trim($occupation),
                    ':monthly_income' => $income
                ]);
            }
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
 * Get resident details by person ID
 * @param PDO $pdo Database connection
 * @param int $person_id Person ID
 * @return array|false Person details or false if not found
 */
function getResidentById($pdo, $person_id) {
    // Get the main person data
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
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$person) {
        return false;
    }
    
    // Get family composition data
    $stmt_family = $pdo->prepare("
        SELECT * FROM family_composition
        WHERE person_id = :person_id
        ORDER BY id ASC
    ");
    $stmt_family->execute([':person_id' => $person_id]);
    $person['family_members'] = $stmt_family->fetchAll(PDO::FETCH_ASSOC);
    
    return $person;
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

    // Required fields validation
    if (empty($data['first_name'])) {
        $errors[] = "First name is required";
    }
    if (empty($data['last_name'])) {
        $errors[] = "Last name is required";
    }
    if (empty($data['birth_date'])) {
        $errors[] = "Birth date is required";
    }
    if (empty($data['birth_place'])) {
        $errors[] = "Birth place is required";
    }
    if (empty($data['gender'])) {
        $errors[] = "Gender is required";
    }
    if (empty($data['civil_status'])) {
        $errors[] = "Civil status is required";
    }

    // Validate contact number format if provided
    if (!empty($data['contact_number'])) {
        // Remove any non-numeric characters for validation
        $clean_number = preg_replace('/[^0-9]/', '', $data['contact_number']);
        if (strlen($clean_number) < 10 || strlen($clean_number) > 11) {
            $errors[] = "Contact number must be 10-11 digits";
        }
    }

    // Validate government IDs if provided
    if (!empty($data['osca_id']) && !preg_match('/^[A-Z0-9-]+$/', $data['osca_id'])) {
        $errors[] = "Invalid OSCA ID format";
    }
    if (!empty($data['gsis_id']) && !preg_match('/^[0-9-]+$/', $data['gsis_id'])) {
        $errors[] = "Invalid GSIS ID format";
    }
    if (!empty($data['sss_id']) && !preg_match('/^[0-9-]+$/', $data['sss_id'])) {
        $errors[] = "Invalid SSS ID format";
    }
    if (!empty($data['tin_id']) && !preg_match('/^[0-9-]+$/', $data['tin_id'])) {
        $errors[] = "Invalid TIN ID format";
    }
    if (!empty($data['philhealth_id']) && !preg_match('/^[0-9-]+$/', $data['philhealth_id'])) {
        $errors[] = "Invalid PhilHealth ID format";
    }

    // Validate years of residency if provided
    if (!empty($data['years_of_residency']) && !is_numeric($data['years_of_residency'])) {
        $errors[] = "Years of residency must be a number";
    }

    // Household ID is now optional
    if (!empty($data['household_id']) && empty($data['relationship'])) {
        $errors[] = "Relationship to household head is required when household is specified";
    }

    // Validate arrays of checkbox data
    if (isset($data['assets']) && !is_array($data['assets'])) {
        $errors[] = "Assets must be an array";
    }
    if (isset($data['income_sources']) && !is_array($data['income_sources'])) {
        $errors[] = "Income sources must be an array";
    }
    if (isset($data['living_arrangements']) && !is_array($data['living_arrangements'])) {
        $errors[] = "Living arrangements must be an array";
    }
    if (isset($data['skills']) && !is_array($data['skills'])) {
        $errors[] = "Skills must be an array";
    }
    if (isset($data['involvements']) && !is_array($data['involvements'])) {
        $errors[] = "Community involvements must be an array";
    }
    if (isset($data['problems']) && !is_array($data['problems'])) {
        $errors[] = "Problems and concerns must be an array";
    }
    if (isset($data['other_needs']) && !is_array($data['other_needs'])) {
        $errors[] = "Other needs must be an array";
    }

    // Validate income amounts if provided
    if (isset($data['income_sources']) && is_array($data['income_sources'])) {
        foreach ($data['income_sources'] as $source_id) {
            $amount_key = 'income_amount_' . $source_id;
            if (isset($data[$amount_key]) && !is_numeric(str_replace(['₱', ','], '', $data[$amount_key]))) {
                $errors[] = "Invalid amount format for income source #$source_id";
            }
        }
    }

    // Validate family member data if provided
    if (isset($data['family_member_name']) && is_array($data['family_member_name'])) {
        foreach ($data['family_member_name'] as $key => $name) {
            if (!empty($name)) {
                if (empty($data['family_member_relationship'][$key])) {
                    $errors[] = "Relationship is required for family member: $name";
                }
                if (!isset($data['family_member_age'][$key]) || !is_numeric($data['family_member_age'][$key])) {
                    $errors[] = "Valid age is required for family member: $name";
                }
                if (empty($data['family_member_civil_status'][$key])) {
                    $errors[] = "Civil status is required for family member: $name";
                }
                if (isset($data['family_member_income'][$key]) && 
                    !is_numeric(str_replace(['₱', ','], '', $data['family_member_income'][$key]))) {
                    $errors[] = "Invalid income format for family member: $name";
                }
            }
        }
    }

    return $errors;
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

function isValidCivilStatus($status) {
    $allowed = ['SINGLE', 'MARRIED', 'WIDOW/WIDOWER', 'SEPARATED'];
    return in_array($status, $allowed, true);
}
?>