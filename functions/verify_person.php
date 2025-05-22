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
    $gender = trim($_POST['gender'] ?? '');
    
    // Basic validation
    if (empty($first_name) || empty($last_name) || empty($birth_date)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'First name, last name, and birth date are required.'
        ]);
        exit;
    }
    
    try {
        // Prepare the base SQL query
        $sql = "SELECT id FROM persons WHERE 
                first_name = :first_name AND 
                last_name = :last_name AND 
                birth_date = :birth_date";
        
        // Initialize parameters array
        $params = [
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':birth_date' => $birth_date
        ];
        
        // If middle name is provided, add it to the query
        if (!empty($middle_name)) {
            $sql .= " AND (middle_name = :middle_name OR middle_name IS NULL)";
            $params[':middle_name'] = $middle_name;
        }
        
        // If ID number is provided, add it to the query
        if (!empty($id_number)) {
            $sql .= " AND (id_number = :id_number OR id_number IS NULL)";
            $params[':id_number'] = $id_number;
        }
        
        // If gender is provided, add it to the query
        if (!empty($gender)) {
            $sql .= " AND gender = :gender";
            $params[':gender'] = $gender;
        }
        
        // Prepare and execute the query
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Check if the person exists
        if ($stmt->rowCount() > 0) {
            $person = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                'status' => 'success',
                'exists' => true,
                'message' => 'Person verification successful.',
                'person_id' => $person['id']
            ]);
        } else {
            echo json_encode([
                'status' => 'success',
                'exists' => false,
                'message' => 'Person not found in our database. Registration is not allowed.'
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
} 