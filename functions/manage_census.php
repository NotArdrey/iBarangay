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
            ':osca_id' => $data['osca_id'] ?? null,
            ':gsis_id' => $data['gsis_id'] ?? null,
            ':sss_id' => $data['sss_id'] ?? null,
            ':tin_id' => $data['tin_id'] ?? null,
            ':philhealth_id' => $data['philhealth_id'] ?? null,
            ':other_id_type' => $data['other_id_type'] ?? null,
            ':other_id_number' => $data['other_id_number'] ?? null
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
                $living_arrangements[] = [
                    'type' => str_replace('living_', '', $field),
                    'details' => null
                ];
            }
        }
        
        if (isset($data['living_others']) && $data['living_others'] == 1 && !empty($data['living_others_specify'])) {
            $living_arrangements[] = [
                'type' => 'others',
                'details' => $data['living_others_specify']
            ];
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
                $type_id = getTypeIdByName($pdo, 'living_arrangement_types', $arrangement['type']);
                if ($type_id) {
                    $stmt_living->execute([
                        ':person_id' => $person_id,
                        ':arrangement_type_id' => $type_id,
                        ':details' => $arrangement['details']
                    ]);
                }
            }
        }

        // Insert skills
        $skills = [];
        $skill_fields = [
            'skill_medical', 'skill_teaching', 'skill_legal_services', 'skill_dental',
            'skill_counseling', 'skill_evangelization', 'skill_farming', 'skill_fishing',
            'skill_cooking', 'skill_vocational'
        ];
        
        foreach ($skill_fields as $field) {
            if (isset($data[$field]) && $data[$field] == 1) {
                $skills[] = [
                    'type' => str_replace('skill_', '', $field),
                    'details' => null
                ];
            }
        }
        
        if (isset($data['skill_others']) && $data['skill_others'] == 1 && !empty($data['skill_others_specify'])) {
            $skills[] = [
                'type' => 'others',
                'details' => $data['skill_others_specify']
            ];
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
                    ':skill_type_id' => $skill['type'],
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
                $involvements[] = [
                    'type' => str_replace('involvement_', '', $field),
                    'details' => null
                ];
            }
        }
        
        if (isset($data['involvement_others']) && $data['involvement_others'] == 1 && !empty($data['involvement_others_specify'])) {
            $involvements[] = [
                'type' => 'others',
                'details' => $data['involvement_others_specify']
            ];
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
                    ':involvement_type_id' => $involvement['type'],
                    ':details' => $involvement['details']
                ]);
            }
        }

        // Insert problems/needs
        $problems = [];
        $problem_fields = [
            // Economic Problems
            'problem_loss_income', 'problem_skills_training', 'problem_livelihood',
            // Social Problems
            'problem_loneliness', 'problem_recreational', 'problem_senior_friendly',
            // Health Problems
            'problem_condition_illness', 'problem_high_cost_medicine', 'problem_lack_medical_professionals',
            'problem_lack_sanitation', 'problem_lack_health_insurance', 'problem_inadequate_health_services',
            // Housing Problems
            'problem_overcrowding', 'problem_no_permanent_housing', 'problem_independent_living',
            'problem_lost_privacy', 'problem_squatters',
            // Community Service Problems
            'problem_desire_participate', 'problem_skills_to_share'
        ];
        
        foreach ($problem_fields as $field) {
            if (isset($data[$field]) && $data[$field] == 1) {
                $details = null;
                if ($field === 'problem_skills_training' && !empty($data['problem_skills_training_specify'])) {
                    $details = $data['problem_skills_training_specify'];
                } elseif ($field === 'problem_livelihood' && !empty($data['problem_livelihood_specify'])) {
                    $details = $data['problem_livelihood_specify'];
                }
                
                $problems[] = [
                    'type' => str_replace('problem_', '', $field),
                    'details' => $details
                ];
            }
        }
        
        // Handle "Others" fields for each problem category
        $other_problem_fields = [
            'problem_economic_others' => 'problem_economic_others_specify',
            'problem_social_others' => 'problem_social_others_specify',
            'problem_health_others' => 'problem_health_others_specify',
            'problem_housing_others' => 'problem_housing_others_specify',
            'problem_community_others' => 'problem_community_others_specify'
        ];
        
        foreach ($other_problem_fields as $field => $specify_field) {
            if (isset($data[$field]) && $data[$field] == 1 && !empty($data[$specify_field])) {
                $problems[] = [
                    'type' => str_replace('problem_', '', $field),
                    'details' => $data[$specify_field]
                ];
            }
        }

        if (!empty($problems)) {
            $stmt_problems = $pdo->prepare("
                INSERT INTO person_problems (
                    person_id, problem_category_id, details
                ) VALUES (
                    :person_id, :problem_category_id, :details
                )
            ");
            
            foreach ($problems as $problem) {
                $stmt_problems->execute([
                    ':person_id' => $person_id,
                    ':problem_category_id' => $problem['type'],
                    ':details' => $problem['details']
                ]);
            }
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
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :address_id
            ");
            
            $stmt->execute([
                ':address_id' => $data['present_address_id'],
                ':house_no' => $data['present_house_no'] ?: null,
                ':street' => $data['present_street'] ?: null,
                ':municipality' => $data['present_municipality'] ?: '',
                ':province' => $data['present_province'] ?: '',
                ':region' => $data['present_region'] ?: '',
                ':updated_at' => date('Y-m-d H:i:s')
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
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :address_id
            ");
            
            $stmt->execute([
                ':address_id' => $data['permanent_address_id'],
                ':house_no' => $data['permanent_house_no'] ?: null,
                ':street' => $data['permanent_street'] ?: null,
                ':municipality' => $data['permanent_municipality'] ?: '',
                ':province' => $data['permanent_province'] ?: '',
                ':region' => $data['permanent_region'] ?: '',
                ':updated_at' => date('Y-m-d H:i:s')
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
        
        // Update government IDs
        $stmt = $pdo->prepare("
            UPDATE person_identification SET
                osca_id = :osca_id,
                gsis_id = :gsis_id,
                sss_id = :sss_id,
                tin_id = :tin_id,
                philhealth_id = :philhealth_id,
                other_id_type = :other_id_type,
                other_id_number = :other_id_number,
                updated_at = CURRENT_TIMESTAMP
            WHERE person_id = :person_id
        ");

        $stmt->execute([
            ':person_id' => $person_id,
            ':osca_id' => isset($data['osca_id']) ? trim($data['osca_id']) : null,
            ':gsis_id' => isset($data['gsis_id']) ? trim($data['gsis_id']) : null,
            ':sss_id' => isset($data['sss_id']) ? trim($data['sss_id']) : null,
            ':tin_id' => isset($data['tin_id']) ? trim($data['tin_id']) : null,
            ':philhealth_id' => isset($data['philhealth_id']) ? trim($data['philhealth_id']) : null,
            ':other_id_type' => isset($data['other_id_type']) ? trim($data['other_id_type']) : null,
            ':other_id_number' => isset($data['other_id_number']) ? trim($data['other_id_number']) : null
        ]);

        // If no rows were updated, insert new record
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO person_identification (
                    person_id, osca_id, gsis_id, sss_id, tin_id, philhealth_id,
                    other_id_type, other_id_number
                ) VALUES (
                    :person_id, :osca_id, :gsis_id, :sss_id, :tin_id, :philhealth_id,
                    :other_id_type, :other_id_number
                )
            ");

            $stmt->execute([
                ':person_id' => $person_id,
                ':osca_id' => isset($data['osca_id']) ? trim($data['osca_id']) : null,
                ':gsis_id' => isset($data['gsis_id']) ? trim($data['gsis_id']) : null,
                ':sss_id' => isset($data['sss_id']) ? trim($data['sss_id']) : null,
                ':tin_id' => isset($data['tin_id']) ? trim($data['tin_id']) : null,
                ':philhealth_id' => isset($data['philhealth_id']) ? trim($data['philhealth_id']) : null,
                ':other_id_type' => isset($data['other_id_type']) ? trim($data['other_id_type']) : null,
                ':other_id_number' => isset($data['other_id_number']) ? trim($data['other_id_number']) : null
            ]);
        }

        // Update assets - First delete existing then insert new
        $pdo->prepare("DELETE FROM person_assets WHERE person_id = ?")->execute([$person_id]);
        if (isset($data['assets']) && is_array($data['assets'])) {
            $stmt_assets = $pdo->prepare("
                INSERT INTO person_assets (person_id, asset_type_id, details)
                VALUES (:person_id, :asset_type_id, :details)
            ");
            foreach ($data['assets'] as $asset_type_id) {
                $stmt_assets->execute([
                    ':person_id' => $person_id,
                    ':asset_type_id' => $asset_type_id,
                    ':details' => $data['asset_details_' . $asset_type_id] ?? null
                ]);
            }
        }

        // Update income sources
        $pdo->prepare("DELETE FROM person_income_sources WHERE person_id = ?")->execute([$person_id]);
        if (isset($data['income_sources']) && is_array($data['income_sources'])) {
            $stmt_income = $pdo->prepare("
                INSERT INTO person_income_sources (person_id, source_type_id, amount, details)
                VALUES (:person_id, :source_type_id, :amount, :details)
            ");
            foreach ($data['income_sources'] as $source_type_id) {
                $amount = null;
                if (isset($data['income_amount_' . $source_type_id])) {
                    $amount = floatval(str_replace(['₱', ','], '', $data['income_amount_' . $source_type_id]));
                }
                $stmt_income->execute([
                    ':person_id' => $person_id,
                    ':source_type_id' => $source_type_id,
                    ':amount' => $amount,
                    ':details' => $data['income_details_' . $source_type_id] ?? null
                ]);
            }
        }

        // Update skills
        $pdo->prepare("DELETE FROM person_skills WHERE person_id = ?")->execute([$person_id]);
        if (isset($data['skills']) && is_array($data['skills'])) {
            $stmt_skills = $pdo->prepare("
                INSERT INTO person_skills (person_id, skill_type_id, details)
                VALUES (:person_id, :skill_type_id, :details)
            ");
            foreach ($data['skills'] as $skill_type_id) {
                $stmt_skills->execute([
                    ':person_id' => $person_id,
                    ':skill_type_id' => $skill_type_id,
                    ':details' => $data['skill_details_' . $skill_type_id] ?? null
                ]);
            }
        }

        // Update health concerns
        $pdo->prepare("DELETE FROM person_health_concerns WHERE person_id = ?")->execute([$person_id]);
        if (isset($data['health_concerns']) && is_array($data['health_concerns'])) {
            $stmt_health = $pdo->prepare("
                INSERT INTO person_health_concerns (person_id, concern_type_id, details, is_active)
                VALUES (:person_id, :concern_type_id, :details, :is_active)
            ");
            foreach ($data['health_concerns'] as $concern_type_id) {
                $stmt_health->execute([
                    ':person_id' => $person_id,
                    ':concern_type_id' => $concern_type_id,
                    ':details' => $data['health_details_' . $concern_type_id] ?? null,
                    ':is_active' => true
                ]);
            }
        }

        // Update living arrangements
        $pdo->prepare("DELETE FROM person_living_arrangements WHERE person_id = ?")->execute([$person_id]);
        if (isset($data['living_arrangements']) && is_array($data['living_arrangements'])) {
            $stmt_living = $pdo->prepare("
                INSERT INTO person_living_arrangements (person_id, arrangement_type_id, details)
                VALUES (:person_id, :arrangement_type_id, :details)
            ");
            foreach ($data['living_arrangements'] as $arrangement_type_id) {
                $stmt_living->execute([
                    ':person_id' => $person_id,
                    ':arrangement_type_id' => $arrangement_type_id,
                    ':details' => $data['living_arrangement_details_' . $arrangement_type_id] ?? null
                ]);
            }
        }

        // Update community involvements
        $pdo->prepare("DELETE FROM person_involvements WHERE person_id = ?")->execute([$person_id]);
        if (isset($data['involvements']) && is_array($data['involvements'])) {
            $stmt_involvement = $pdo->prepare("
                INSERT INTO person_involvements (person_id, involvement_type_id, details)
                VALUES (:person_id, :involvement_type_id, :details)
            ");
            foreach ($data['involvements'] as $involvement_type_id) {
                $stmt_involvement->execute([
                    ':person_id' => $person_id,
                    ':involvement_type_id' => $involvement_type_id,
                    ':details' => $data['involvement_details_' . $involvement_type_id] ?? null
                ]);
            }
        }

        // Update problems/concerns
        $pdo->prepare("DELETE FROM person_problems WHERE person_id = ?")->execute([$person_id]);
        if (isset($data['problems']) && is_array($data['problems'])) {
            $stmt_problems = $pdo->prepare("
                INSERT INTO person_problems (person_id, problem_category_id, details)
                VALUES (:person_id, :problem_category_id, :details)
            ");
            foreach ($data['problems'] as $problem_category_id) {
                $stmt_problems->execute([
                    ':person_id' => $person_id,
                    ':problem_category_id' => $problem_category_id,
                    ':details' => $data['problem_details_' . $problem_category_id] ?? null
                ]);
            }
        }

        // Update service needs
        $pdo->prepare("DELETE FROM person_service_needs WHERE person_id = ?")->execute([$person_id]);
        if (isset($data['service_needs']) && is_array($data['service_needs'])) {
            $stmt_service = $pdo->prepare("
                INSERT INTO person_service_needs (person_id, service_type_id, details, is_urgent, status)
                VALUES (:person_id, :service_type_id, :details, :is_urgent, 'pending')
            ");
            foreach ($data['service_needs'] as $service_type_id) {
                $stmt_service->execute([
                    ':person_id' => $person_id,
                    ':service_type_id' => $service_type_id,
                    ':details' => $data['service_details_' . $service_type_id] ?? null,
                    ':is_urgent' => isset($data['service_urgent_' . $service_type_id]) ? true : false
                ]);
            }
        }

        // Update other needs
        $pdo->prepare("DELETE FROM person_other_needs WHERE person_id = ?")->execute([$person_id]);
        if (isset($data['other_needs']) && is_array($data['other_needs'])) {
            $stmt_other = $pdo->prepare("
                INSERT INTO person_other_needs (person_id, need_type_id, details, priority_level, status)
                VALUES (:person_id, :need_type_id, :details, :priority_level, 'identified')
            ");
            foreach ($data['other_needs'] as $need_type_id) {
                $stmt_other->execute([
                    ':person_id' => $person_id,
                    ':need_type_id' => $need_type_id,
                    ':details' => $data['other_need_details_' . $need_type_id] ?? null,
                    ':priority_level' => $data['other_need_priority_' . $need_type_id] ?? 'medium'
                ]);
            }
        }

        // Update health information
        $pdo->prepare("DELETE FROM person_health_info WHERE person_id = ?")->execute([$person_id]);
        if (isset($data['health_condition']) || isset($data['has_maintenance'])) {
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
                ':has_maintenance' => isset($data['has_maintenance']) ? 1 : 0,
                ':maintenance_details' => $data['maintenance_details'] ?? null,
                ':high_cost_medicines' => isset($data['high_cost_medicines']) ? 1 : 0,
                ':lack_medical_professionals' => isset($data['lack_medical_professionals']) ? 1 : 0,
                ':lack_sanitation_access' => isset($data['lack_sanitation_access']) ? 1 : 0,
                ':lack_health_insurance' => isset($data['lack_health_insurance']) ? 1 : 0,
                ':lack_medical_facilities' => isset($data['lack_medical_facilities']) ? 1 : 0,
                ':other_health_concerns' => $data['other_health_concerns'] ?? null
            ]);
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
    if (empty($data['household_id'])) {
        $errors[] = "Household ID is required";
    }
    if (empty($data['relationship'])) {
        $errors[] = "Relationship to household head is required";
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
    if (isset($data['health_concerns']) && !is_array($data['health_concerns'])) {
        $errors[] = "Health concerns must be an array";
    }
    if (isset($data['service_needs']) && !is_array($data['service_needs'])) {
        $errors[] = "Service needs must be an array";
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
?>