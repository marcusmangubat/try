<?php
// client profile.php
session_start();
require_once '../db.php';
require_once '../auth.php';
date_default_timezone_set('Asia/Manila');

// Require client login
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}

$client_email = $_SESSION['user_email'];
$client_id    = null;
$errors       = [];
$success_msg  = '';
$pw_error   = '';
$pw_success = '';

// Flash message set after a successful password change (Post/Redirect/Get)
if (isset($_SESSION['pw_success'])) {
    $pw_success = $_SESSION['pw_success'];
    unset($_SESSION['pw_success']);
}


// CSRF token for the profile picture forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Get client id
$stmt_c = $database->prepare("SELECT cid FROM clients WHERE email = ? LIMIT 1");
$stmt_c->bind_param("s", $client_email);
$stmt_c->execute();
$c_result = $stmt_c->get_result();

if ($c_result->num_rows === 1) {
    $client_id = (int)$c_result->fetch_assoc()['cid'];
}
$stmt_c->close();

if (!$client_id) {
    header('Location: ../login.php');
    exit;
}

$app_count = 0;
$stmt = $database->prepare("SELECT COUNT(*) as c FROM applications WHERE client_id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$app_count = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Helper to safely echo a value into an input
function val(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
}
// Static option lists
$civil_status_options = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];
$education_options = [
    'Elementary Graduate',
    'High School Graduate',
    'Senior High School Graduate',
    'Vocational/Technical',
    'College Undergraduate',
    'College Graduate',
    'Post Graduate',
];
$classification_options = [
    'Government',
];
// Common country list for the preferred-country autocomplete (freeform text is still allowed)
$country_list = [
    'Afghanistan',
    'Australia',
    'Bahrain',
    'Bangladesh',
    'Brunei',
    'Cambodia',
    'Canada',
    'China',
    'Cyprus',
    'Denmark',
    'Federal Republic of Germany',
    'Finland',
    'France',
    'Greece',
    'Hong Kong',
    'Indonesia',
    'Ireland',
    'Israel',
    'Italy',
    'Japan',
    'Jordan',
    'Kuwait',
    'Lebanon',
    'Libya',
    'Macau',
    'Malaysia',
    'Maldives',
    'Malta',
    'Netherlands',
    'New Zealand',
    'Norway',
    'Oman',
    'Papua New Guinea',
    'Poland',
    'Portugal',
    'Qatar',
    'Republic of Korea (South Korea)',
    'Russia',
    'Saudi Arabia',
    'Singapore',
    'South Africa',
    'Spain',
    'Sweden',
    'Switzerland',
    'Taiwan',
    'Thailand',
    'Turkey',
    'United Arab Emirates',
    'United Kingdom',
    'United States of America',
    'Vietnam',
];

// Reference / lookup tables (fetched before POST handling so
// submitted values can be validated against real records)
$degree_programs = [];
$dp_res = $database->query("SELECT id, degree_name, degree_category FROM degree_programs ORDER BY degree_category, degree_name");
if ($dp_res) {
    while ($row = $dp_res->fetch_assoc()) $degree_programs[] = $row;
}

$job_categories = [];
$jc_res = $database->query("SELECT id, category_name FROM job_categories ORDER BY category_name");
if ($jc_res) {
    while ($row = $jc_res->fetch_assoc()) $job_categories[] = $row;
}

$job_subcategories = [];
$valid_subcategory_ids = [];
$js_res = $database->query("SELECT id, category_id, subcategory_name FROM job_subcategories ORDER BY subcategory_name");
if ($js_res) {
    while ($row = $js_res->fetch_assoc()) {
        $job_subcategories[] = $row;
        $valid_subcategory_ids[(int)$row['id']] = $row;
    }
}

// Salary range options + a lookup keyed by id (for validating a submitted id)
$salary_ranges = [];
$valid_salary_range_ids = [];
$sr_res = $database->query("SELECT id, range_label, min_salary, max_salary FROM salary_ranges ORDER BY sort_order ASC, min_salary ASC");
if ($sr_res) {
    while ($row = $sr_res->fetch_assoc()) {
        $salary_ranges[] = $row;
        $valid_salary_range_ids[(int)$row['id']] = $row;
    }
}

// Group subcategories by category, for the cascading dropdown (client-side)
$subcategories_by_category = [];
foreach ($job_subcategories as $sc) {
    $subcategories_by_category[(int)$sc['category_id']][] = [
        'id'   => (int)$sc['id'],
        'name' => $sc['subcategory_name'],
    ];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Section 0: Profile Picture Upload → clients.profile_picture ──
    if (isset($_POST['upload_picture'])) {

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $errors[] = 'Invalid form submission. Please refresh and try again.';
        }

        if (empty($errors)) {
            if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Please choose an image to upload.';
            } elseif ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'File upload failed. Please try again.';
            } else {
                $file = $_FILES['profile_picture'];

                if ($file['size'] > 2 * 1024 * 1024) {
                    $errors[] = 'Profile picture must not exceed 2MB.';
                }

                // Verify actual MIME type server-side — never trust $_FILES['type']
                $allowed_mimes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                ];
                $finfo     = finfo_open(FILEINFO_MIME_TYPE);
                $real_mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!isset($allowed_mimes[$real_mime])) {
                    $errors[] = 'Only JPG, PNG, or WEBP images are allowed.';
                }

                if (empty($errors) && @getimagesize($file['tmp_name']) === false) {
                    $errors[] = 'The uploaded file is not a valid image.';
                }

                if (empty($errors)) {
                    $ext          = $allowed_mimes[$real_mime];
                    $new_filename = $client_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

                    $upload_dir = __DIR__ . '/../uploads/profile_pictures/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $destination   = $upload_dir . $new_filename;
                    $relative_path = 'uploads/profile_pictures/' . $new_filename;

                    if (move_uploaded_file($file['tmp_name'], $destination)) {

                        // Grab old picture path so we can delete it after DB update succeeds
                        $stmt_old = $database->prepare("SELECT profile_picture FROM clients WHERE cid = ?");
                        $stmt_old->bind_param("i", $client_id);
                        $stmt_old->execute();
                        $old_picture = $stmt_old->get_result()->fetch_assoc()['profile_picture'] ?? null;
                        $stmt_old->close();

                        $stmt_up = $database->prepare("UPDATE clients SET profile_picture = ? WHERE cid = ?");
                        $stmt_up->bind_param("si", $relative_path, $client_id);

                        if ($stmt_up->execute()) {
                            $success_msg = 'Profile picture updated successfully.';

                            if (!empty($old_picture)) {
                                $old_full_path = __DIR__ . '/../' . $old_picture;
                                if (is_file($old_full_path)) {
                                    @unlink($old_full_path);
                                }
                            }
                        } else {
                            @unlink($destination); // rollback saved file if DB write failed
                            $errors[] = 'Something went wrong while saving your profile picture. Please try again.';
                        }
                        $stmt_up->close();
                    } else {
                        $errors[] = 'Could not save the uploaded file. Please try again.';
                    }
                }
            }
        }
    }

    //  Remove Profile Picture 
    if (isset($_POST['remove_picture'])) {

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $errors[] = 'Invalid form submission. Please refresh and try again.';
        }

        if (empty($errors)) {
            $stmt_old = $database->prepare("SELECT profile_picture FROM clients WHERE cid = ?");
            $stmt_old->bind_param("i", $client_id);
            $stmt_old->execute();
            $old_picture = $stmt_old->get_result()->fetch_assoc()['profile_picture'] ?? null;
            $stmt_old->close();

            $stmt_rm = $database->prepare("UPDATE clients SET profile_picture = NULL WHERE cid = ?");
            $stmt_rm->bind_param("i", $client_id);

            if ($stmt_rm->execute()) {
                if (!empty($old_picture)) {
                    $old_full_path = __DIR__ . '/../' . $old_picture;
                    if (is_file($old_full_path)) {
                        @unlink($old_full_path);
                    }
                }
                $success_msg = 'Profile picture removed.';
            } else {
                $errors[] = 'Something went wrong while removing your profile picture.';
            }
            $stmt_rm->close();
        }
    }

    // Section 1: Personal Information → clients table
    if (isset($_POST['save_personal'])) {

        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $suffix     = trim($_POST['suffix'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $address    = trim($_POST['address'] ?? '');
        $barangay   = trim($_POST['barangay'] ?? '');
        $city       = trim($_POST['city_municipality'] ?? '');
        $province   = trim($_POST['province'] ?? '');
        $dob        = trim($_POST['dob'] ?? '');
        $gender     = trim($_POST['gender'] ?? '');

        if ($first_name === '') $errors[] = 'First name is required.';
        if ($last_name === '')  $errors[] = 'Last name is required.';
        if ($barangay === '')   $errors[] = 'Barangay is required.';
        if ($city === '')       $errors[] = 'City/Municipality is required.';
        if ($province === '')   $errors[] = 'Province is required.';
        if ($phone !== '' && !preg_match('/^\d{11}$/', $phone)) {
            $errors[] = 'Phone number must be exactly 11 digits (numbers only).';
        }
        if ($dob !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $dob);
            if (!$d || $d->format('Y-m-d') !== $dob) {
                $errors[] = 'Date of birth is invalid.';
            }
        }

        if (empty($errors)) {
            $stmt = $database->prepare("
                UPDATE clients SET
                    first_name = ?,
                    middle_name = ?,
                    last_name = ?,
                    suffix = ?,
                    phone = ?,
                    address = ?,
                    barangay = ?,
                    city_municipality = ?,
                    province = ?,
                    dob = ?,
                    gender = ?
                WHERE cid = ?
            ");
            $dob_param    = $dob === '' ? null : $dob;
            $gender_param = $gender === '' ? null : $gender;
            $stmt->bind_param(
                "sssssssssssi",
                $first_name,
                $middle_name,
                $last_name,
                $suffix,
                $phone,
                $address,
                $barangay,
                $city,
                $province,
                $dob_param,
                $gender_param,
                $client_id
            );

            if ($stmt->execute()) {
                $success_msg = 'Personal information updated successfully.';
            } else {
                $errors[] = 'Something went wrong while saving your personal information. Please try again.';
            }
            $stmt->close();
        }
    }

    // Section 2: Applicant Profile → client_profiles table 
    if (isset($_POST['save_profile'])) {

        $citizenship  = trim($_POST['citizenship'] ?? '');
        $civil_status = trim($_POST['civil_status'] ?? '');
        $religion     = trim($_POST['religion'] ?? '');
        $height_cm    = trim($_POST['height_cm'] ?? '');
        $weight_kg    = trim($_POST['weight_kg'] ?? '');
        $education    = trim($_POST['educational_attainment'] ?? '');
        $degree_id    = trim($_POST['degree_id'] ?? '');
        $course       = trim($_POST['course_degree'] ?? '');
        $school_name  = trim($_POST['school_name'] ?? '');
        $school_addr  = trim($_POST['school_address'] ?? '');
        $year_grad    = trim($_POST['year_graduated'] ?? '');
        $work_years   = trim($_POST['work_experience_years'] ?? '');
        $last_employer = trim($_POST['last_employer'] ?? '');
        $last_position = trim($_POST['last_position'] ?? '');

        if ($citizenship === '')  $errors[] = 'Citizenship is required.';
        if ($civil_status === '') $errors[] = 'Civil status is required.';
        if ($education === '')    $errors[] = 'Educational attainment is required.';
        if ($civil_status !== '' && !in_array($civil_status, $civil_status_options, true)) {
            $errors[] = 'Invalid civil status selected.';
        }
        if ($education !== '' && !in_array($education, $education_options, true)) {
            $errors[] = 'Invalid educational attainment selected.';
        }
        if ($height_cm !== '' && (!is_numeric($height_cm) || (float)$height_cm <= 0)) {
            $errors[] = 'Height must be a valid positive number.';
        }
        if ($weight_kg !== '' && (!is_numeric($weight_kg) || (float)$weight_kg <= 0)) {
            $errors[] = 'Weight must be a valid positive number.';
        }
        if ($year_grad !== '' && (!ctype_digit($year_grad) || (int)$year_grad < 1950 || (int)$year_grad > (int)date('Y') + 1)) {
            $errors[] = 'Year graduated is invalid.';
        }
        if ($work_years !== '' && (!ctype_digit($work_years) || (int)$work_years < 0)) {
            $errors[] = 'Work experience years must be a valid non-negative number.';
        }
        if ($degree_id !== '' && !array_key_exists((int)$degree_id, array_column($degree_programs, null, 'id'))) {
            $errors[] = 'Invalid degree program selected.';
        }

        // Normalize empty strings to NULL for nullable numeric/enum/year fields
        $height_cm  = $height_cm === '' ? null : (float)$height_cm;
        $weight_kg  = $weight_kg === '' ? null : (float)$weight_kg;
        $year_grad  = $year_grad === '' ? null : (int)$year_grad;
        $work_years = $work_years === '' ? null : (int)$work_years;
        $degree_id  = $degree_id === '' ? null : (int)$degree_id;
        $civil_status = $civil_status === '' ? null : $civil_status;
        $education    = $education === '' ? null : $education;

        if (empty($errors)) {
            // client_id has a UNIQUE KEY, so this inserts a new profile row
            // the first time, or updates the existing one on subsequent saves.
            $stmt = $database->prepare("
                INSERT INTO client_profiles (
                    client_id, citizenship, civil_status, religion,
                    height_cm, weight_kg, educational_attainment, degree_id, course_degree,
                    school_name, school_address, year_graduated,
                    work_experience_years, last_employer, last_position,
                    profile_completed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    citizenship = VALUES(citizenship),
                    civil_status = VALUES(civil_status),
                    religion = VALUES(religion),
                    height_cm = VALUES(height_cm),
                    weight_kg = VALUES(weight_kg),
                    educational_attainment = VALUES(educational_attainment),
                    degree_id = VALUES(degree_id),
                    course_degree = VALUES(course_degree),
                    school_name = VALUES(school_name),
                    school_address = VALUES(school_address),
                    year_graduated = VALUES(year_graduated),
                    work_experience_years = VALUES(work_experience_years),
                    last_employer = VALUES(last_employer),
                    last_position = VALUES(last_position),
                    profile_completed_at = COALESCE(profile_completed_at, NOW())
            ");
            $stmt->bind_param(
                "isssddsisssiiss",
                $client_id,
                $citizenship,
                $civil_status,
                $religion,
                $height_cm,
                $weight_kg,
                $education,
                $degree_id,
                $course,
                $school_name,
                $school_addr,
                $year_grad,
                $work_years,
                $last_employer,
                $last_position
            );

            if ($stmt->execute()) {
                $success_msg = 'Applicant profile updated successfully.';
            } else {
                $errors[] = 'Something went wrong while saving your profile information. Please try again.';
            }
            $stmt->close();
        }
    }

    // Section 3: Career & Salary Preferences → client_preferences table
    if (isset($_POST['save_preferences'])) {

        $preferred_classification = trim($_POST['preferred_classification'] ?? '');
        $salary_range_id_raw = trim($_POST['salary_range_id'] ?? '');

        if ($preferred_classification === '') $errors[] = 'Preferred job classification is required.';

        // Validate the submitted salary range against the real lookup table
        // (salary is optional — an empty selection is allowed).
        $salary_range_id = null;
        if ($salary_range_id_raw !== '') {
            if (!ctype_digit($salary_range_id_raw) || !array_key_exists((int)$salary_range_id_raw, $valid_salary_range_ids)) {
                $errors[] = 'Please select a valid salary range.';
            } else {
                $salary_range_id = (int) $salary_range_id_raw;
            }
        }

        if (empty($errors)) {
            $stmt = $database->prepare("
    INSERT INTO client_preferences (
        client_id, preferred_classification, salary_range_id
    ) VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
        preferred_classification = VALUES(preferred_classification),
        salary_range_id = VALUES(salary_range_id)
");
            $stmt->bind_param(
                "isi",
                $client_id,
                $preferred_classification,
                $salary_range_id
            );

            if ($stmt->execute()) {
                $success_msg = 'Career & salary preferences updated successfully.';
            } else {
                $errors[] = 'Something went wrong while saving your preferences. Please try again.';
            }
            $stmt->close();
        }
    }

    // Section 4: Preferred Countries → client_preferred_countries table
    if (isset($_POST['save_countries'])) {

        $countries_in  = $_POST['country'] ?? [];
        $priorities_in = $_POST['country_priority'] ?? [];

        $clean_countries = [];
        $seen = [];

        if (is_array($countries_in)) {
            foreach ($countries_in as $idx => $c) {
                $c = trim((string)$c);
                if ($c === '') continue;
                if (strlen($c) > 100) {
                    $errors[] = "Country name \"$c\" is too long.";
                    continue;
                }
                $key = mb_strtolower($c);
                if (isset($seen[$key])) continue; // skip duplicate entries
                $seen[$key] = true;

                $priority = isset($priorities_in[$idx]) ? (int)$priorities_in[$idx] : (count($clean_countries) + 1);
                if ($priority < 1) $priority = 1;

                $clean_countries[] = ['country' => $c, 'priority_order' => $priority];
            }
        }

        if (count($clean_countries) > 20) {
            $errors[] = 'You can list a maximum of 20 preferred countries.';
        }

        if (empty($errors)) {
            $database->begin_transaction();
            $ok = true;

            $del = $database->prepare("DELETE FROM client_preferred_countries WHERE client_id = ?");
            $del->bind_param("i", $client_id);
            if (!$del->execute()) $ok = false;
            $del->close();

            if ($ok && !empty($clean_countries)) {
                $ins = $database->prepare("INSERT INTO client_preferred_countries (client_id, country, priority_order) VALUES (?, ?, ?)");
                foreach ($clean_countries as $row) {
                    $ins->bind_param("isi", $client_id, $row['country'], $row['priority_order']);
                    if (!$ins->execute()) {
                        $ok = false;
                        break;
                    }
                }
                $ins->close();
            }

            if ($ok) {
                $database->commit();
                $success_msg = 'Preferred countries updated successfully.';
            } else {
                $database->rollback();
                $errors[] = 'Something went wrong while saving your preferred countries. Please try again.';
            }
        }
    }

    // Section 5: Preferred Job Positions → client_preferred_positions table
    if (isset($_POST['save_positions'])) {

        $subcats_in    = $_POST['subcategory_id'] ?? [];
        $priorities_in = $_POST['position_priority'] ?? [];

        $clean_positions = [];
        $seen = [];

        if (is_array($subcats_in)) {
            foreach ($subcats_in as $idx => $sid) {
                $sid = trim((string)$sid);
                if ($sid === '') continue;
                if (!ctype_digit($sid) || !array_key_exists((int)$sid, $valid_subcategory_ids)) {
                    $errors[] = 'One of the selected job positions is invalid.';
                    continue;
                }
                $sid = (int)$sid;
                if (isset($seen[$sid])) continue; // skip duplicate entries
                $seen[$sid] = true;

                $priority = isset($priorities_in[$idx]) ? (int)$priorities_in[$idx] : (count($clean_positions) + 1);
                if ($priority < 1) $priority = 1;

                $clean_positions[] = ['subcategory_id' => $sid, 'priority_order' => $priority];
            }
        }

        if (count($clean_positions) > 20) {
            $errors[] = 'You can list a maximum of 20 preferred job positions.';
        }

        if (empty($errors)) {
            $database->begin_transaction();
            $ok = true;

            $del = $database->prepare("DELETE FROM client_preferred_positions WHERE client_id = ?");
            $del->bind_param("i", $client_id);
            if (!$del->execute()) $ok = false;
            $del->close();

            if ($ok && !empty($clean_positions)) {
                $ins = $database->prepare("INSERT INTO client_preferred_positions (client_id, subcategory_id, priority_order) VALUES (?, ?, ?)");
                foreach ($clean_positions as $row) {
                    $ins->bind_param("iii", $client_id, $row['subcategory_id'], $row['priority_order']);
                    if (!$ins->execute()) {
                        $ok = false;
                        break;
                    }
                }
                $ins->close();
            }

            if ($ok) {
                $database->commit();
                $success_msg = 'Preferred job positions updated successfully.';
            } else {
                $database->rollback();
                $errors[] = 'Something went wrong while saving your preferred job positions. Please try again.';
            }
        }
    }

    // Section 5.5: Change Password → webuser table
    if (isset($_POST['change_password'])) {

        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $pw_error = 'Invalid form submission. Please refresh and try again.';
        } elseif ($current === '' || $new === '' || $confirm === '') {
            $pw_error = 'Please fill in all password fields.';
        } elseif (strlen($new) < 8) {
            $pw_error = 'New password must be at least 8 characters.';
        } elseif (
            !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) ||
            !preg_match('/[0-9]/', $new) || !preg_match('/[^A-Za-z0-9]/', $new)
        ) {
            $pw_error = 'New password must include an uppercase letter, a lowercase letter, a number, and a special character.';
        } elseif ($new !== $confirm) {
            $pw_error = 'New password and confirmation do not match.';
        } else {
            $stmt = $database->prepare("SELECT password FROM webuser WHERE email = ? AND usertype = 'client' LIMIT 1");
            $stmt->bind_param("s", $client_email);
            $stmt->execute();
            $pw_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$pw_row || !password_verify($current, $pw_row['password'])) {
                $pw_error = 'Current password is incorrect.';
            } elseif (password_verify($new, $pw_row['password'])) {
                $pw_error = 'New password must be different from your current password.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $database->prepare("
                    UPDATE webuser
                    SET password = ?, remember_token = NULL, remember_expires = NULL
                    WHERE email = ? AND usertype = 'client'
                ");
                $stmt->bind_param("ss", $hash, $client_email);
                $pw_ok = $stmt->execute();
                $stmt->close();

                if ($pw_ok) {
                    session_regenerate_id(true);
                    $_SESSION['pw_success'] = 'Password changed successfully.';
                    header('Location: profile.php#sec-password');
                    exit;
                } else {
                    $pw_error = 'Could not update password. Please try again.';
                }
            }
        }
    }

    // Section 6: Account Deactivation → webuser table (self-contained, mirrors evaluator's inline logic)
    if (isset($_POST['deactivate_account'])) {

        // Preset reasons available for applicant self-deactivation (mirrors evaluator's pattern)
        $self_deactivation_reasons = [
            'Deployment secured / no longer job hunting',
            'Taking a break / temporary pause',
            'Privacy or security concern',
            'No longer interested in overseas employment',
            'Other',
        ];

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $errors[] = 'Invalid form submission. Please refresh and try again.';
        }

        $current_password = $_POST['deactivate_current_password'] ?? '';
        $reason_preset     = trim($_POST['deactivate_reason_preset'] ?? '');
        $reason_other      = trim($_POST['deactivate_reason_other'] ?? '');

        if (empty($errors) && !in_array($reason_preset, $self_deactivation_reasons, true)) {
            $errors[] = 'Please select a reason for deactivation.';
        }
        if (empty($errors) && $reason_preset === 'Other' && mb_strlen($reason_other) < 5) {
            $errors[] = 'Please specify a reason (at least 5 characters).';
        }
        if (empty($errors) && $current_password === '') {
            $errors[] = 'Please enter your current password to confirm account deactivation.';
        }

        if (empty($errors)) {
            $stmt = $database->prepare("SELECT password FROM webuser WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $client_email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($current_password, $row['password'])) {
                $errors[] = 'Current password is incorrect.';
            }
        }

        if (empty($errors)) {
            $final_reason = $reason_preset === 'Other' ? $reason_other : $reason_preset;

            $database->begin_transaction();
            try {
                $stmt = $database->prepare("UPDATE webuser 
                    SET is_active = 0, deactivated_at = NOW(), deactivated_by = NULL, deactivation_reason = ?, remember_token = NULL, remember_expires = NULL 
                    WHERE email = ?");
                $stmt->bind_param("ss", $final_reason, $client_email);
                $stmt->execute();
                $stmt->close();

                $stmt = $database->prepare("INSERT INTO account_status_history 
                    (email, usertype, action, reason, performed_by, previous_status, new_status) 
                    VALUES (?, 'client', 'Account Deactivated', ?, ?, 'active', 'deactivated')");
                $stmt->bind_param("sss", $client_email, $final_reason, $client_email);
                $stmt->execute();
                $stmt->close();

                // Notify all admins
                $admin_stmt = $database->prepare("SELECT email FROM admins");
                $admin_stmt->execute();
                $admins = $admin_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $admin_stmt->close();

                $notif_title = "Applicant Self-Deactivated";
                $stmt_name = $database->prepare("SELECT first_name, last_name FROM clients WHERE cid = ? LIMIT 1");
                $stmt_name->bind_param("i", $client_id);
                $stmt_name->execute();
                $name_row = $stmt_name->get_result()->fetch_assoc();
                $stmt_name->close();
                $applicant_display_name = trim(($name_row['first_name'] ?? '') . ' ' . ($name_row['last_name'] ?? '')) ?: $client_email;

                $notif_msg   = htmlspecialchars($applicant_display_name) . " (" . $client_email . ") deactivated their own account. Reason: " . $final_reason;
                foreach ($admins as $adm) {
                    $nstmt = $database->prepare("INSERT INTO notifications (user_email, usertype, title, message, type) VALUES (?, 'admin', ?, ?, 'account_status')");
                    $nstmt->bind_param("sss", $adm['email'], $notif_title, $notif_msg);
                    $nstmt->execute();
                    $nstmt->close();
                }

                $database->commit();
            } catch (\Throwable $e) {
                $database->rollback();
                $errors[] = 'Something went wrong while deactivating your account. Please try again.';
            }

            if (empty($errors)) {
                setcookie('remember_token', '', time() - 3600, '/', '', false, true);
                $_SESSION = [];
                session_destroy();
                session_start();
                $_SESSION['login_message'] = 'Your account has been deactivated. You may submit a reactivation request for admin approval.';
                header('Location: ../login.php');
                exit;
            }
        }
    }
}

// Fetch current data (after any save, so the form reflects latest)
$stmt = $database->prepare("SELECT * FROM clients WHERE cid = ? LIMIT 1");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $database->prepare("SELECT * FROM client_profiles WHERE client_id = ? LIMIT 1");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$profile_res = $stmt->get_result();
$profile = $profile_res->num_rows === 1 ? $profile_res->fetch_assoc() : [];
$stmt->close();

$stmt = $database->prepare("
    SELECT cp.*, sr.range_label AS salary_range_label
    FROM client_preferences cp
    LEFT JOIN salary_ranges sr ON sr.id = cp.salary_range_id
    WHERE cp.client_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$pref_res = $stmt->get_result();
$preferences = $pref_res->num_rows === 1 ? $pref_res->fetch_assoc() : [];
$stmt->close();

$stmt = $database->prepare("SELECT id, country, priority_order FROM client_preferred_countries WHERE client_id = ? ORDER BY priority_order ASC, id ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$countries_res = $stmt->get_result();
$preferred_countries = [];
while ($row = $countries_res->fetch_assoc()) $preferred_countries[] = $row;
$stmt->close();

$stmt = $database->prepare("
    SELECT cpp.id, cpp.subcategory_id, cpp.priority_order,
           js.subcategory_name, js.category_id, jc.category_name
    FROM client_preferred_positions cpp
    JOIN job_subcategories js ON js.id = cpp.subcategory_id
    JOIN job_categories jc ON jc.id = js.category_id
    WHERE cpp.client_id = ?
    ORDER BY cpp.priority_order ASC, cpp.id ASC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$positions_res = $stmt->get_result();
$preferred_positions = [];
while ($row = $positions_res->fetch_assoc()) $preferred_positions[] = $row;
$stmt->close();

// Greeting avatar (same pattern as evaluator pages)
$full_name  = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
$first_name = $client['first_name'] ?? 'Client';

$parts    = explode(' ', $full_name);
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($full_name ?: 'C', 0, 2));

// Profile completeness meter (purely informational, not saved anywhere)
$completeness_fields = [
    $client['phone'] ?? null,
    $client['address'] ?? null,
    $client['dob'] ?? null,
    $client['gender'] ?? null,
    $profile['citizenship'] ?? null,
    $profile['civil_status'] ?? null,
    $profile['educational_attainment'] ?? null,
    $profile['school_name'] ?? null,
    $preferences['preferred_classification'] ?? null,
    !empty($preferred_countries) ? 1 : null,
    !empty($preferred_positions) ? 1 : null,
];
$filled_count = count(array_filter($completeness_fields, fn($v) => $v !== null && $v !== ''));
$completeness_pct = (int)round(($filled_count / count($completeness_fields)) * 100);

$has_picture = !empty($client['profile_picture']);
$picture_url = $has_picture ? '../' . $client['profile_picture'] : null;

// Aliases so the shared avatar-menu markup works on this page
$client_name     = $full_name !== '' ? $full_name : 'Client';
$client_initials = $initials ?: 'U';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Applicant Settings – DMW Client Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        :root {
            --settings-emerald: #059669;
            --settings-amber: #d97706;
            --settings-violet: #7c3aed;
            --settings-rose: #e11d48;
        }

        body {
            font-family: 'DM Sans', sans-serif;
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* ── Fixed subbar (same as Admin Settings) ── */
        .subbar {
            position: fixed;
            left: 0;
            right: 0;
            z-index: 40;
            background: #fff;
        }

        .subbar .filter-scroll {
            display: none;
        }

        /* ── Shell: side nav + panel ── */
        .settings-shell {
            display: grid;
            grid-template-columns: 224px minmax(0, 1fr);
            gap: 22px;
            padding: 140px 24px 110px;
            max-width: 1440px;
            margin: 0 auto;
        }

        .settings-panel {
            min-width: 0;
        }

        .settings-nav {
            width: 100%;
            min-width: 0;
            position: sticky;
            top: var(--sticky-top, 160px);
            align-self: start;
        }

        .settings-nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 13px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s, color .15s;
            margin-bottom: 2px;
        }

        .settings-nav-item i {
            width: 16px;
            text-align: center;
            font-size: 12px;
        }

        .settings-nav-item:hover {
            background: #f1f5f9;
        }

        .settings-nav-item.active {
            background: #1d4ed8;
            color: #fff;
        }

        .settings-nav-group-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #94a3b8;
            margin: 14px 0 6px 13px;
        }

        .settings-crumb-pill {
            cursor: pointer;
            user-select: none;
            text-decoration: none;
            transition: background .15s, border-color .15s;
        }

        .settings-crumb-pill.active {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .settings-crumb-pill.active span,
        .settings-crumb-pill.active i {
            color: #fff !important;
        }

        /* ── Cards (match .s-card) ── */
        .s-card,
        .settings-panel .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 20px 22px;
            margin: 0 0 16px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
            height: fit-content;
        }

        .settings-panel>.card:last-child {
            margin-bottom: 0;
        }

        .card-section {
            scroll-margin-top: var(--sticky-top, 160px);
        }

        .card-head {
            display: block;
            margin-bottom: 16px;
        }

        .card-title-group {
            display: block;
        }

        .settings-panel .card-title {
            display: block;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 2px;
        }

        .settings-panel .ctdot {
            display: none;
        }

        .card-subtitle {
            display: block;
            font-size: 12px;
            color: #64748b;
            font-weight: 400;
        }

        /* No entrance animations (Admin has none) */
        .fade-in {
            opacity: 1;
            transform: none;
            animation: none;
        }

        /* ── Profile summary ── */
        .profile-header {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .profile-avatar-lg {
            width: 60px;
            height: 60px;
            border-radius: 14px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 19px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .profile-header h3 {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            font-family: 'DM Sans', sans-serif;
        }

        .profile-header p {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
            margin-top: 2px;
        }

        .completeness-wrap {
            margin-left: auto;
            min-width: 200px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .completeness-label {
            display: flex;
            justify-content: space-between;
            font-size: 10.5px;
            font-weight: 700;
            color: #475569;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .completeness-track {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
        }

        .completeness-fill {
            height: 100%;
            border-radius: 999px;
            background: #1d4ed8;
        }

        .avatar-upload-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .avatar-camera-btn {
            position: absolute;
            bottom: -4px;
            right: -4px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #1d4ed8;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            cursor: pointer;
            border: 2px solid #fff;
            transition: opacity .15s;
        }

        .avatar-camera-btn:hover {
            opacity: .88;
        }

        .avatar-remove-btn {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #dc2626;
            color: #fff;
            border: 2px solid #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            cursor: pointer;
            padding: 0;
        }

        .avatar-remove-btn:hover {
            background: #b91c1c;
        }

        .profile-avatar-lg.has-photo,
        .topbar-avatar.has-photo {
            background-color: transparent !important;
            filter: none !important;
            opacity: 1 !important;
            mix-blend-mode: normal !important;
        }

        /* ── Form fields (match .s-field) ── */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0 16px;
            margin-top: 0;
        }

        .form-grid.cols-3 {
            grid-template-columns: repeat(3, 1fr);
        }

        .form-grid .full {
            grid-column: 1 / -1;
        }

        .f-group {
            display: flex;
            flex-direction: column;
            gap: 0;
            margin-bottom: 14px;
            min-width: 0;
        }

        .f-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 5px;
        }

        .f-label .req {
            color: #ef4444;
            margin-left: 2px;
        }

        .f-input,
        .f-select,
        .f-textarea {
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            color: #0f172a;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 9px 12px;
            outline: none;
            transition: border-color .15s;
            width: 100%;
        }

        .f-textarea {
            resize: vertical;
            min-height: 64px;
        }

        .f-input:focus,
        .f-select:focus,
        .f-textarea:focus {
            border-color: #1d4ed8;
            box-shadow: none;
        }

        .f-input:disabled,
        .f-input[readonly] {
            background: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
        }

        .f-hint {
            font-size: 11px;
            color: #94a3b8;
            font-weight: 400;
            margin-top: 4px;
        }

        /* ── Buttons (match .s-btn) ── */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 16px;
        }

        .btn-save {
            background: #1d4ed8;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 9px 18px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: opacity .15s;
        }

        .btn-save:hover {
            opacity: .88;
            background: #1d4ed8;
            transform: none;
        }

        .btn-save:active {
            transform: none;
        }

        .btn-ghost {
            background: #fff;
            color: #334155;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 9px 18px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: opacity .15s;
        }

        .btn-ghost:hover {
            background: #f1f5f9;
            opacity: .9;
        }

        .btn-icon-remove {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #475569;
            font-size: 11px;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .btn-icon-remove:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        /* ── Alerts ── */
        .alert-box {
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 12.5px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .settings-alerts .alert-box:last-child {
            margin-bottom: 16px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-error ul {
            margin: 4px 0 0 16px;
            padding: 0;
        }

        /* ── Dynamic rows ── */
        .dyn-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 0;
        }

        .dyn-row {
            display: grid;
            grid-template-columns: 1fr 100px 40px;
            gap: 10px 12px;
            align-items: end;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px;
        }

        .dyn-row .f-group {
            margin-bottom: 0;
        }

        .dyn-row.dyn-row-cascade {
            grid-template-columns: 1fr 1fr 100px 40px;
        }

        .dyn-row-rank {
            display: flex;
            flex-direction: column;
        }

        .dyn-row-rank label {
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            text-transform: none;
            margin-bottom: 5px;
        }

        .dyn-row-remove-wrap {
            display: flex;
            align-items: center;
            height: 38px;
        }

        .dyn-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 26px 16px;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            color: #94a3b8;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }

        .dyn-empty i {
            font-size: 20px;
            color: #cbd5e1;
        }

        .dmw-swal-popup {
            font-family: 'DM Sans', sans-serif !important;
            border-radius: 14px !important;
        }

        /* ── Custom dropdown (same behaviour, Admin field styling) ── */
        .ps-wrap {
            position: relative;
            width: 100%;
        }

        .ps-native {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            pointer-events: none;
        }

        .ps-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            width: 100%;
            text-align: left;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            color: #0f172a;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 9px 12px;
            cursor: pointer;
            outline: none;
            transition: border-color .15s;
        }

        .ps-trigger:hover {
            border-color: #94a3b8;
        }

        .ps-trigger:focus-visible,
        .ps-trigger[aria-expanded="true"] {
            border-color: #1d4ed8;
            box-shadow: none;
        }

        .ps-trigger.is-placeholder .ps-label {
            color: #94a3b8;
        }

        .ps-trigger:disabled {
            background: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
        }

        .ps-wrap.is-invalid .ps-trigger {
            border-color: #dc2626;
        }

        .ps-label {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ps-chevron {
            font-size: 10px;
            opacity: .6;
            flex-shrink: 0;
            transition: transform .15s;
        }

        .ps-trigger[aria-expanded="true"] .ps-chevron {
            transform: rotate(180deg);
        }

        .ps-panel {
            position: fixed;
            z-index: 999;
            box-sizing: border-box;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .14);
            padding: 4px;
            overflow-y: auto;
            overscroll-behavior: contain;
            font-family: 'DM Sans', sans-serif;
        }

        .ps-panel::-webkit-scrollbar {
            width: 6px;
        }

        .ps-panel::-webkit-scrollbar-track {
            background: transparent;
        }

        .ps-panel::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .ps-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 8px 10px;
            border-radius: 7px;
            font-size: 12.5px;
            line-height: 1.35;
            color: #334155;
            cursor: pointer;
        }

        .ps-option.is-placeholder {
            color: #94a3b8;
        }

        .ps-option.is-active {
            background: #f1f5f9;
        }

        .ps-option[aria-selected="true"] {
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 700;
        }

        .ps-option[aria-disabled="true"] {
            opacity: .45;
            cursor: not-allowed;
        }

        .ps-check {
            font-size: 10px;
            flex-shrink: 0;
        }

        .ps-group {
            position: sticky;
            top: -4px;
            z-index: 1;
            padding: 8px 10px 4px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #94a3b8;
            background: #fff;
        }

        .ps-option.in-group {
            padding-left: 20px;
        }

        /* ── Responsive (same breakpoints as Admin Settings) ── */
        @media (max-width: 860px) {
            .subbar .filter-scroll {
                display: flex;
            }

            .settings-shell {
                grid-template-columns: 1fr;
                padding: 160px 14px 110px;
                gap: 14px;
            }

            .settings-nav {
                display: none;
            }

            .settings-panel .card {
                padding: 16px;
            }
        }

        @media (max-width: 720px) {

            .dyn-row,
            .dyn-row.dyn-row-cascade {
                grid-template-columns: 1fr;
            }

            .dyn-row-remove-wrap {
                justify-content: flex-end;
                height: auto;
            }
        }

        @media (max-width: 640px) {
            .subbar-greeting h2 {
                font-size: 16px;
            }

            .subbar-greeting p {
                font-size: 11px;
            }

            .settings-panel .card {
                padding: 14px;
                border-radius: 12px;
            }

            .settings-panel .card-title {
                font-size: 14px;
            }

            .form-grid,
            .form-grid.cols-3 {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .completeness-wrap {
                margin-left: 0;
                width: 100%;
            }

            .form-actions,
            .form-actions[style] {
                flex-direction: column;
                gap: 10px;
            }

            .btn-save,
            .btn-ghost {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 420px) {
            .topbar-date {
                display: none;
            }
        }

        /* ── Change Password (mirrors evaluator) ── */
        .form-err {
            font-size: 11.5px;
            color: #b91c1c;
            margin-top: 8px;
            display: none;
        }

        .form-err.show {
            display: block;
        }

        .password-requirements {
            margin-top: 8px;
            font-size: 11px;
            line-height: 1.8;
        }

        .password-requirements.hidden {
            display: none;
        }

        .req-item {
            color: #94a3b8;
            transition: color .15s;
        }

        .req-item.valid {
            color: #059669;
        }

        .password-match-msg {
            font-size: 11.5px;
            font-weight: 700;
            margin-top: 6px;
            min-height: 15px;
        }

        .password-match-msg.match {
            color: #059669;
        }

        .password-match-msg.no-match {
            color: #b91c1c;
        }

        [data-theme="dark"] .form-err,
        [data-theme="dark"] .password-match-msg.no-match {
            color: #f87171;
        }

        [data-theme="dark"] .req-item {
            color: #64748b;
        }

        [data-theme="dark"] .req-item.valid,
        [data-theme="dark"] .password-match-msg.match {
            color: #34d399;
        }

        /* ── Dark mode (same palette as Admin Settings) ── */
        [data-theme="dark"] body {
            background: #0f172a;
        }

        [data-theme="dark"] .subbar {
            background: #1e293b;
        }

        [data-theme="dark"] .settings-nav-item {
            color: #94a3b8;
        }

        [data-theme="dark"] .settings-nav-item:hover {
            background: #263449;
        }

        [data-theme="dark"] .settings-nav-item.active,
        [data-theme="dark"] .settings-crumb-pill.active {
            background: #3b82f6;
            border-color: #3b82f6;
            color: #fff;
        }

        [data-theme="dark"] .settings-nav-group-label {
            color: #64748b;
        }

        [data-theme="dark"] .s-card,
        [data-theme="dark"] .settings-panel .card {
            background: #1e293b;
            border-color: #2d3748;
            box-shadow: none;
        }

        [data-theme="dark"] .settings-panel #sec-account-status {
            border-color: rgba(220, 38, 38, .35) !important;
        }

        [data-theme="dark"] .settings-panel .card-title,
        [data-theme="dark"] .profile-header h3 {
            color: #e2e8f0;
        }

        [data-theme="dark"] .card-subtitle,
        [data-theme="dark"] .profile-header p {
            color: #94a3b8;
        }

        [data-theme="dark"] .f-label,
        [data-theme="dark"] .dyn-row-rank label,
        [data-theme="dark"] .completeness-label {
            color: #cbd5e1;
        }

        [data-theme="dark"] .f-input,
        [data-theme="dark"] .f-select,
        [data-theme="dark"] .f-textarea,
        [data-theme="dark"] .ps-trigger {
            background: #0f172a;
            color: #e2e8f0;
            border-color: #334155;
        }

        [data-theme="dark"] .f-input:focus,
        [data-theme="dark"] .f-select:focus,
        [data-theme="dark"] .ps-trigger:focus-visible,
        [data-theme="dark"] .ps-trigger[aria-expanded="true"] {
            border-color: #3b82f6;
        }

        [data-theme="dark"] .f-input:disabled,
        [data-theme="dark"] .f-input[readonly],
        [data-theme="dark"] .ps-trigger:disabled {
            background: #1a2332;
            color: #64748b;
        }

        [data-theme="dark"] .f-hint,
        [data-theme="dark"] .ps-trigger.is-placeholder .ps-label {
            color: #64748b;
        }

        [data-theme="dark"] .profile-avatar-lg {
            background: rgba(59, 130, 246, .18);
            color: #93c5fd;
        }

        [data-theme="dark"] .profile-avatar-lg.has-photo,
        [data-theme="dark"] .topbar-avatar.has-photo {
            background-color: transparent !important;
            color: transparent !important;
        }

        [data-theme="dark"] .avatar-camera-btn {
            background: #3b82f6;
            border-color: #1e293b;
        }

        [data-theme="dark"] .avatar-remove-btn {
            border-color: #1e293b;
        }

        [data-theme="dark"] .completeness-track {
            background: #334155;
        }

        [data-theme="dark"] .completeness-fill {
            background: #3b82f6;
        }

        [data-theme="dark"] .btn-save {
            background: #3b82f6;
        }

        [data-theme="dark"] .btn-ghost {
            background: #1e293b;
            color: #cbd5e1;
            border-color: #334155;
        }

        [data-theme="dark"] .btn-ghost:hover {
            background: #263449;
        }

        [data-theme="dark"] .btn-icon-remove {
            border-color: #334155;
            background: #1e293b;
            color: #94a3b8;
        }

        [data-theme="dark"] .btn-icon-remove:hover {
            background: rgba(220, 38, 38, .18);
            color: #f87171;
        }

        [data-theme="dark"] .alert-success {
            background: rgba(16, 185, 129, .18);
            color: #34d399;
            border-color: rgba(16, 185, 129, .35);
        }

        [data-theme="dark"] .alert-error {
            background: rgba(220, 38, 38, .18);
            color: #f87171;
            border-color: rgba(220, 38, 38, .35);
        }

        [data-theme="dark"] .dyn-row {
            background: #1a2332;
            border-color: #2d3748;
        }

        [data-theme="dark"] .dyn-empty {
            border-color: #334155;
            color: #64748b;
        }

        [data-theme="dark"] .dyn-empty i {
            color: #475569;
        }

        [data-theme="dark"] .req {
            color: #f87171;
        }

        [data-theme="dark"] .ps-panel {
            background: #1e293b;
            border-color: #2d3748;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .45);
        }

        [data-theme="dark"] .ps-option {
            color: #cbd5e1;
        }

        [data-theme="dark"] .ps-option.is-active {
            background: #263449;
        }

        [data-theme="dark"] .ps-option[aria-selected="true"] {
            background: rgba(59, 130, 246, .2);
            color: #93c5fd;
        }

        [data-theme="dark"] .ps-group {
            background: #1e293b;
            color: #64748b;
        }

        [data-theme="dark"] .dmw-swal-popup {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .dmw-swal-popup .swal2-title,
        [data-theme="dark"] .dmw-swal-popup .swal2-html-container,
        [data-theme="dark"] .dmw-swal-popup .swal2-html-container div {
            color: #e2e8f0 !important;
        }

        /* Dark mode: keep password fields dark while typing / on autofill */
        [data-theme="dark"] .f-input[type="password"],
        [data-theme="dark"] .f-input[type="password"]:hover,
        [data-theme="dark"] .f-input[type="password"]:active {
            background-color: #0f172a;
            color: #e2e8f0;
            border-color: #334155;
            caret-color: #e2e8f0;
        }

        [data-theme="dark"] .f-input[type="password"]:focus {
            background-color: #0f172a;
            color: #e2e8f0;
            border-color: #3b82f6;
        }

        /* Override Chrome/Edge/Safari autofill (this is what causes the white background) */
        [data-theme="dark"] .f-input:-webkit-autofill,
        [data-theme="dark"] .f-input:-webkit-autofill:hover,
        [data-theme="dark"] .f-input:-webkit-autofill:focus,
        [data-theme="dark"] .f-input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px #0f172a inset !important;
            box-shadow: 0 0 0 1000px #0f172a inset !important;
            -webkit-text-fill-color: #e2e8f0 !important;
            caret-color: #e2e8f0;
            border-color: #334155;
            transition: background-color 9999s ease-in-out 0s;
        }

        [data-theme="dark"] .f-input:-webkit-autofill:focus {
            border-color: #3b82f6;
        }

        /* Firefox autofill */
        [data-theme="dark"] .f-input:autofill {
            background-color: #0f172a;
            color: #e2e8f0;
        }

        /* ═══ READABILITY SCALE (older users) — ~+1.5px per size.
           Keep this block LAST in <style>: it re-declares existing selectors and relies on source order. ═══ */

        /* A) Page classes */
        .settings-nav-item {
            font-size: 14.5px;
        }

        .settings-nav-item i {
            font-size: 13px;
        }

        .settings-nav-group-label {
            font-size: 11.5px;
        }

        .settings-panel .card-title {
            font-size: 16.5px;
        }

        .card-subtitle {
            font-size: 13.5px;
        }

        .profile-header h3 {
            font-size: 16.5px;
        }

        .profile-header p {
            font-size: 13.5px;
        }

        .completeness-label {
            font-size: 12px;
        }

        .f-label,
        .dyn-row-rank label {
            font-size: 13.5px;
        }

        .f-input,
        .f-select,
        .f-textarea,
        .ps-trigger {
            font-size: 14.5px;
        }

        .f-hint {
            font-size: 12.5px;
        }

        .btn-save,
        .btn-ghost {
            font-size: 13.5px;
        }

        .alert-box {
            font-size: 14px;
        }

        .dyn-empty {
            font-size: 13.5px;
        }

        .ps-option {
            font-size: 14px;
        }

        .ps-group {
            font-size: 11.5px;
        }

        .form-err,
        .password-match-msg {
            font-size: 13px;
        }

        .password-requirements {
            font-size: 12.5px;
        }

        @media (max-width: 640px) {
            .subbar-greeting h2 {
                font-size: 17.5px;
            }

            .subbar-greeting p {
                font-size: 12.5px;
            }

            .settings-panel .card-title {
                font-size: 15.5px;
            }
        }

        /* B) Tailwind arbitrary sizes (avatar menu) — !important because the CDN sheet loads after this block */
        .text-\[10\.5px\] {
            font-size: 12px !important
        }

        .text-\[11px\] {
            font-size: 12.5px !important
        }

        .text-\[11\.5px\] {
            font-size: 13px !important
        }

        .text-\[12px\],
        .text-xs {
            font-size: 13.5px !important
        }

        .text-\[12\.5px\] {
            font-size: 14px !important
        }

        .text-\[13px\] {
            font-size: 14.5px !important
        }

        .text-sm {
            font-size: 15.5px !important
        }

        /* C) Inline style="font-size:…" attributes (subbar pills, SweetAlert popup text) */
        [style*="font-size:11px"] {
            font-size: 12.5px !important
        }

        [style*="font-size:12px"] {
            font-size: 13.5px !important
        }

        [style*="font-size:13px"] {
            font-size: 14.5px !important
        }
    </style>
    <script>
        (function() {
            const t = localStorage.getItem('dmw_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body>

    <!--TOPBAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; APPLICANT PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>

            <!-- Dark mode toggle -->
            <button type="button" class="theme-toggle" title="Toggle dark mode">
                <i class="fas fa-moon"></i>
                <i class="fas fa-sun"></i>
            </button>

            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <div class="relative" id="avatarMenuWrap">
                <button type="button" id="avatarMenuBtn"
                    class="topbar-avatar cursor-pointer border-0 p-0"
                    aria-haspopup="menu" aria-expanded="false" aria-controls="avatarMenu" aria-label="Account menu"
                    <?= $has_picture ? "style=\"background-image:url('" . htmlspecialchars($picture_url, ENT_QUOTES) . "');background-size:cover;background-position:center;\"" : '' ?>>
                    <?= $has_picture ? '' : htmlspecialchars($client_initials) ?>
                </button>

                <div id="avatarMenu" role="menu"
                    class="hidden absolute right-0 top-[calc(100%+10px)] w-[240px] bg-[var(--card)] border border-[var(--border)] rounded-[14px] shadow-[0_16px_40px_rgba(11,31,75,.18)] overflow-hidden z-[700]">

                    <!-- Header: who is signed in -->
                    <div class="py-3 px-4 border-b border-[var(--border)] bg-[var(--surface)]">
                        <div class="text-[12.5px] font-bold text-[var(--text1)] truncate"><?= htmlspecialchars($client_name) ?></div>
                        <div class="text-[10.5px] text-[var(--text3)] truncate"><?= htmlspecialchars($client_email) ?></div>
                    </div>

                    <!-- Navigation -->
                    <div class="py-1.5">
                        <a href="profile.php" role="menuitem"
                            class="flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[var(--text2)] no-underline transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-user-gear w-4 text-center text-[var(--blue-lt)]"></i> My Profile
                        </a>
                        <a href="my-applications.php" role="menuitem"
                            class="flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[var(--text2)] no-underline transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-file-lines w-4 text-center text-[var(--blue-lt)]"></i> My Applications
                        </a>
                        <a href="jobs.php" role="menuitem"
                            class="flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[var(--text2)] no-underline transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-briefcase w-4 text-center text-[var(--blue-lt)]"></i> Browse Jobs
                        </a>
                    </div>

                    <!-- Logout (reuses your existing logout() function) -->
                    <div class="border-t border-[var(--border)] py-1.5">
                        <button type="button" role="menuitem" onclick="logout('Applicant')"
                            class="w-full flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[#dc2626] bg-transparent border-0 cursor-pointer text-left transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-arrow-right-from-bracket w-4 text-center"></i> Logout
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!--SUBBAR -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Applicant Settings</h2>
                <p>Manage your personal information, applicant profile, and job preferences</p>
            </div>
        </div>
        <div class="filter-scroll">
            <a href="#sec-personal" class="filter-pill settings-crumb-pill active">
                <i class="fas fa-id-card" style="color:var(--blue-lt)"></i>
                <span style="font-weight:600;font-size:11px">Personal</span>
            </a>
            <a href="#sec-profile" class="filter-pill settings-crumb-pill">
                <i class="fas fa-user-graduate" style="color:var(--indigo)"></i>
                <span style="font-weight:600;font-size:11px">Applicant Profile</span>
            </a>
            <a href="#sec-preferences" class="filter-pill settings-crumb-pill">
                <i class="fas fa-sliders" style="color:var(--gold)"></i>
                <span style="font-weight:600;font-size:11px">Career &amp; Salary</span>
            </a>
            <a href="#sec-countries" class="filter-pill settings-crumb-pill">
                <i class="fas fa-earth-asia" style="color:var(--amber)"></i>
                <span style="font-weight:600;font-size:11px">Countries</span>
            </a>
            <a href="#sec-positions" class="filter-pill settings-crumb-pill">
                <i class="fas fa-briefcase" style="color:var(--amber)"></i>
                <span style="font-weight:600;font-size:11px">Positions</span>
            </a>
            <a href="#sec-password" class="filter-pill settings-crumb-pill">
                <i class="fas fa-key" style="color:var(--amber)"></i>
                <span style="font-weight:600;font-size:11px">Password</span>
            </a>
            <a href="#sec-account-status" class="filter-pill settings-crumb-pill">
                <i class="fas fa-user-slash" style="color:#f87171"></i>
                <span style="font-weight:600;font-size:11px">Account</span>
            </a>
        </div>
    </div>

    <!--MAIN CONTENT -->
    <div class="settings-shell">

        <!-- Side nav (hidden on mobile, the subbar pills act as the nav there) -->
        <nav class="settings-nav">
            <div class="s-card" style="padding:12px">
                <div class="settings-nav-group-label" style="margin-top:0">Profile</div>
                <a href="#sec-personal" class="settings-nav-item active"><i class="fas fa-id-card"></i> Personal Information</a>
                <a href="#sec-profile" class="settings-nav-item"><i class="fas fa-user-graduate"></i> Applicant Profile</a>

                <div class="settings-nav-group-label">Preferences</div>
                <a href="#sec-preferences" class="settings-nav-item"><i class="fas fa-sliders"></i> Career &amp; Salary</a>
                <a href="#sec-countries" class="settings-nav-item"><i class="fas fa-earth-asia"></i> Preferred Countries</a>
                <a href="#sec-positions" class="settings-nav-item"><i class="fas fa-briefcase"></i> Preferred Positions</a>

                <div class="settings-nav-group-label">Account</div>
                <a href="#sec-password" class="settings-nav-item"><i class="fas fa-key"></i> Change Password</a>
                <a href="#sec-account-status" class="settings-nav-item"><i class="fas fa-user-slash"></i> Account Status</a>
            </div>
        </nav>

        <div class="settings-panel">

            <?php if (!empty($success_msg) || !empty($errors)): ?>
                <div class="settings-alerts">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert-box alert-success fade-in">
                            <i class="fas fa-circle-check" style="margin-top:1px"></i>
                            <span><?= val($success_msg) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <div class="alert-box alert-error fade-in">
                            <i class="fas fa-triangle-exclamation" style="margin-top:1px"></i>
                            <div>
                                Please fix the following:
                                <ul>
                                    <?php foreach ($errors as $err): ?>
                                        <li><?= val($err) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="card profile-summary-card fade-in d1">
                <div class="profile-header">
                    <div class="avatar-upload-wrap">
                        <div class="profile-avatar-lg<?= $has_picture ? ' has-photo' : '' ?>" <?= $has_picture ? "style=\"background-image:url('" . val($picture_url) . "') !important;background-size:cover !important;background-position:center !important;background-color:transparent !important;\"" : '' ?>>
                            <?= $has_picture ? '' : val($initials) ?>
                        </div>

                        <form method="POST" action="profile.php" enctype="multipart/form-data" id="pictureForm">
                            <input type="hidden" name="csrf_token" value="<?= val($csrf_token) ?>">
                            <input type="hidden" name="upload_picture" value="1">
                            <label for="pictureInput" class="avatar-camera-btn" title="Change profile picture">
                                <i class="fas fa-camera"></i>
                            </label>
                            <input type="file" name="profile_picture" id="pictureInput" accept="image/jpeg,image/png,image/webp" hidden>
                        </form>

                        <?php if ($has_picture): ?>
                            <form method="POST" action="profile.php" style="display:inline;" id="removePictureForm">
                                <input type="hidden" name="csrf_token" value="<?= val($csrf_token) ?>">
                                <input type="hidden" name="remove_picture" value="1">
                                <button type="button" class="avatar-remove-btn" title="Remove profile picture" id="removePictureBtn">
                                    <i class="fas fa-xmark"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3><?= val($full_name) ?></h3>
                        <p><?= val($client['email'] ?? '') ?></p>
                        <span class="f-hint">JPG, PNG, or WEBP · Max 2MB</span>
                    </div>
                    <div class="completeness-wrap">
                        <div class="completeness-label">
                            <span>Profile Completeness</span>
                            <span><?= $completeness_pct ?>%</span>
                        </div>
                        <div class="completeness-track">
                            <div class="completeness-fill" style="width: <?= $completeness_pct ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!--SECTION 1: PERSONAL INFORMATION -->
            <div class="card card-section fade-in d2" id="sec-personal">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--blue-lt)"></span>
                            Personal Information
                        </span>
                        <span class="card-subtitle">Core identity and contact details on file with DMW</span>
                    </div>
                </div>

                <form method="POST" action="profile.php">
                    <div class="form-grid">

                        <div class="f-group">
                            <label class="f-label">Email Address</label>
                            <input class="f-input" type="email" value="<?= val($client['email'] ?? '') ?>" disabled>
                            <span class="f-hint">Contact support to change your login email.</span>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Phone Number</label>
                            <input class="f-input" type="text" name="phone" value="<?= val($client['phone'] ?? '') ?>" placeholder="09XXXXXXXXX"
                                maxlength="11" inputmode="numeric" pattern="\d{11}"
                                oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)">
                        </div>

                        <div class="f-group">
                            <label class="f-label">First Name<span class="req">*</span></label>
                            <input class="f-input" type="text" name="first_name" value="<?= val($client['first_name'] ?? '') ?>" required>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Middle Name</label>
                            <input class="f-input" type="text" name="middle_name" value="<?= val($client['middle_name'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Last Name<span class="req">*</span></label>
                            <input class="f-input" type="text" name="last_name" value="<?= val($client['last_name'] ?? '') ?>" required>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Suffix</label>
                            <input class="f-input" type="text" name="suffix" value="<?= val($client['suffix'] ?? '') ?>" placeholder="Jr, Sr, III, etc.">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Date of Birth</label>
                            <input class="f-input" type="date" name="dob" value="<?= val($client['dob'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Gender</label>
                            <select class="f-select" name="gender">
                                <option value="">Select gender</option>
                                <?php foreach (['Male', 'Female'] as $g): ?>
                                    <option value="<?= val($g) ?>" <?= ($client['gender'] ?? '') === $g ? 'selected' : '' ?>>
                                        <?= val($g) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="f-group full">
                            <label class="f-label">Street Address</label>
                            <input class="f-input" type="text" name="address" value="<?= val($client['address'] ?? '') ?>" placeholder="House no., Street">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Barangay<span class="req">*</span></label>
                            <input class="f-input" type="text" name="barangay" value="<?= val($client['barangay'] ?? '') ?>" required>
                        </div>

                        <div class="f-group">
                            <label class="f-label">City / Municipality<span class="req">*</span></label>
                            <input class="f-input" type="text" name="city_municipality" value="<?= val($client['city_municipality'] ?? '') ?>" required>
                        </div>

                        <div class="f-group full">
                            <label class="f-label">Province<span class="req">*</span></label>
                            <input class="f-input" type="text" name="province" value="<?= val($client['province'] ?? '') ?>" required>
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" name="save_personal" class="btn-save">
                            <i class="fas fa-floppy-disk"></i> Save Personal Info
                        </button>
                    </div>
                </form>
            </div>

            <!-- SECTION 2: APPLICANT PROFILE-->
            <div class="card card-section fade-in d3" id="sec-profile">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--indigo)"></span>
                            Applicant Profile
                        </span>
                        <span class="card-subtitle">Physical, civil, and educational background used for job matching</span>
                    </div>
                </div>

                <form method="POST" action="profile.php">
                    <div class="form-grid">

                        <div class="f-group">
                            <label class="f-label">Citizenship<span class="req">*</span></label>
                            <input class="f-input" type="text" name="citizenship" value="<?= val($profile['citizenship'] ?? '') ?>" placeholder="e.g. Filipino" required>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Civil Status<span class="req">*</span></label>
                            <select class="f-select" name="civil_status" required>
                                <option value="">Select civil status</option>
                                <?php foreach ($civil_status_options as $cs): ?>
                                    <option value="<?= val($cs) ?>" <?= ($profile['civil_status'] ?? '') === $cs ? 'selected' : '' ?>>
                                        <?= val($cs) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Religion</label>
                            <input class="f-input" type="text" name="religion" value="<?= val($profile['religion'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Height (cm)</label>
                            <input class="f-input" type="number" step="0.01" min="0" name="height_cm" value="<?= val($profile['height_cm'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Weight (kg)</label>
                            <input class="f-input" type="number" step="0.01" min="0" name="weight_kg" value="<?= val($profile['weight_kg'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Educational Attainment<span class="req">*</span></label>
                            <select class="f-select" name="educational_attainment" required>
                                <option value="">Select educational attainment</option>
                                <?php foreach ($education_options as $ed): ?>
                                    <option value="<?= val($ed) ?>" <?= ($profile['educational_attainment'] ?? '') === $ed ? 'selected' : '' ?>>
                                        <?= val($ed) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Degree Program</label>
                            <select class="f-select" name="degree_id">
                                <option value="">— Not listed / not applicable —</option>
                                <?php
                                $current_group = null;
                                foreach ($degree_programs as $dp):
                                    $group = $dp['degree_category'] ?? '';
                                    if ($group !== $current_group) {
                                        if ($current_group !== null && $current_group !== '') echo '</optgroup>';
                                        if ($group !== '') echo '<optgroup label="' . val($group) . '">';
                                        $current_group = $group;
                                    }
                                ?>
                                    <option value="<?= val($dp['id']) ?>" <?= (string)($profile['degree_id'] ?? '') === (string)$dp['id'] ? 'selected' : '' ?>>
                                        <?= val($dp['degree_name']) ?>
                                    </option>
                                <?php endforeach;
                                if ($current_group !== null && $current_group !== '') echo '</optgroup>';
                                ?>
                            </select>
                            <span class="f-hint">Pick the closest match, if applicable.</span>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Course / Degree (as written on diploma)</label>
                            <input class="f-input" type="text" name="course_degree" value="<?= val($profile['course_degree'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Year Graduated</label>
                            <input class="f-input" type="number" min="1950" max="<?= (int)date('Y') + 1 ?>" name="year_graduated" value="<?= val($profile['year_graduated'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">School Name</label>
                            <input class="f-input" type="text" name="school_name" value="<?= val($profile['school_name'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">School Address</label>
                            <input class="f-input" type="text" name="school_address" value="<?= val($profile['school_address'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Work Experience (years)</label>
                            <input class="f-input" type="number" min="0" name="work_experience_years" value="<?= val($profile['work_experience_years'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Last Employer</label>
                            <input class="f-input" type="text" name="last_employer" value="<?= val($profile['last_employer'] ?? '') ?>">
                        </div>

                        <div class="f-group full">
                            <label class="f-label">Last Position</label>
                            <input class="f-input" type="text" name="last_position" value="<?= val($profile['last_position'] ?? '') ?>">
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" name="save_profile" class="btn-save">
                            <i class="fas fa-floppy-disk"></i> Save Applicant Profile
                        </button>
                    </div>
                </form>
            </div>

            <!-- SECTION 3: CAREER & SALARY PREFERENCES-->
            <div class="card card-section fade-in d4" id="sec-preferences">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--settings-emerald)"></span>
                            Career & Salary Preferences
                        </span>
                        <span class="card-subtitle">How you'd like to be matched with job orders</span>
                    </div>
                </div>

                <form method="POST" action="profile.php">
                    <div class="form-grid cols-3">

                        <div class="f-group">
                            <label class="f-label">Preferred Job Classification<span class="req">*</span></label>
                            <select class="f-select" name="preferred_classification" required>
                                <option value="">Select classification</option>
                                <?php
                                $current_classification = $preferences['preferred_classification'] ?? '';
                                $classification_list = $classification_options;
                                if ($current_classification !== '' && !in_array($current_classification, $classification_list, true)) {
                                    array_splice($classification_list, count($classification_list) - 1, 0, [$current_classification]);
                                }
                                foreach ($classification_list as $cl):
                                ?>
                                    <option value="<?= val($cl) ?>" <?= $current_classification === $cl ? 'selected' : '' ?>>
                                        <?= val($cl) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Expected Salary Range</label>
                            <select class="f-select" name="salary_range_id">
                                <option value="">— Not specified —</option>
                                <?php foreach ($salary_ranges as $sr): ?>
                                    <option value="<?= val($sr['id']) ?>" <?= (string)($preferences['salary_range_id'] ?? '') === (string)$sr['id'] ? 'selected' : '' ?>>
                                        <?= val($sr['range_label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($preferences['salary_range_label'])): ?>
                                <span class="f-hint">
                                    <i class="fas fa-circle-check" style="color:var(--settings-emerald)"></i>
                                    Currently selected: <strong><?= val($preferences['salary_range_label']) ?></strong>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Salary Currency</label>
                            <input class="f-input" type="text" value="PHP (Philippine Peso)" disabled readonly
                                style="opacity:.75; cursor:not-allowed;">
                            <small style="color:var(--text3); display:block; margin-top:4px;">
                                All applicant salary expectations are recorded in Philippine Peso.
                            </small>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="save_preferences" class="btn-save">
                            <i class="fas fa-floppy-disk"></i> Save Preferences
                        </button>
                    </div>
                </form>
            </div>

            <!-- SECTION 4: PREFERRED COUNTRIES-->
            <div class="card card-section fade-in d5" id="sec-countries">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--settings-amber)"></span>
                            Preferred Countries
                        </span>
                        <span class="card-subtitle">Rank the countries you'd like to work in, in order of priority</span>
                    </div>
                </div>

                <datalist id="countryList">
                    <?php foreach ($country_list as $cn): ?>
                        <option value="<?= val($cn) ?>"></option>
                    <?php endforeach; ?>
                </datalist>

                <form method="POST" action="profile.php" id="countriesForm">
                    <div class="dyn-list" id="countriesList">
                        <?php if (empty($preferred_countries)): ?>
                            <div class="dyn-empty" id="countriesEmpty">
                                <i class="fas fa-earth-asia"></i>
                                No preferred countries added yet. Click "Add Country" to get started.
                            </div>
                        <?php else: ?>
                            <?php foreach ($preferred_countries as $i => $pc): ?>
                                <div class="dyn-row" data-row>
                                    <div class="f-group">
                                        <label class="f-label">Country</label>
                                        <input class="f-input" type="text" name="country[]" list="countryList" value="<?= val($pc['country']) ?>" placeholder="e.g. Taiwan" required>
                                    </div>
                                    <div class="dyn-row-rank">
                                        <label>Priority</label>
                                        <input class="f-input" type="number" min="1" name="country_priority[]" value="<?= val($pc['priority_order']) ?>">
                                    </div>
                                    <div class="dyn-row-remove-wrap">
                                        <button type="button" class="btn-icon-remove" data-remove-row title="Remove">
                                            <i class="fas fa-trash-can"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions" style="justify-content: space-between; margin-top: 14px;">
                        <button type="button" class="btn-ghost" id="addCountryBtn">
                            <i class="fas fa-plus"></i> Add Country
                        </button>
                        <button type="submit" name="save_countries" class="btn-save">
                            <i class="fas fa-floppy-disk"></i> Save Preferred Countries
                        </button>
                    </div>
                </form>
            </div>

            <!--SECTION 5: PREFERRED JOB POSITIONS-->
            <div class="card card-section fade-in d6" id="sec-positions">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--settings-rose)"></span>
                            Preferred Job Positions
                        </span>
                        <span class="card-subtitle">Choose a category, then the specific role you're interested in</span>
                    </div>
                </div>

                <form method="POST" action="profile.php" id="positionsForm">
                    <div class="dyn-list" id="positionsList">
                        <?php if (empty($preferred_positions)): ?>
                            <div class="dyn-empty" id="positionsEmpty">
                                <i class="fas fa-briefcase"></i>
                                No preferred job positions added yet. Click "Add Position" to get started.
                            </div>
                        <?php else: ?>
                            <?php foreach ($preferred_positions as $pp): ?>
                                <div class="dyn-row dyn-row-cascade" data-row>
                                    <div class="f-group">
                                        <label class="f-label">Job Category</label>
                                        <select class="f-select" data-category-select>
                                            <?php foreach ($job_categories as $jc): ?>
                                                <option value="<?= val($jc['id']) ?>" <?= (int)$pp['category_id'] === (int)$jc['id'] ? 'selected' : '' ?>>
                                                    <?= val($jc['category_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="f-group">
                                        <label class="f-label">Specific Position</label>
                                        <select class="f-select" name="subcategory_id[]" data-subcategory-select data-selected="<?= val($pp['subcategory_id']) ?>" required></select>
                                    </div>
                                    <div class="dyn-row-rank">
                                        <label>Priority</label>
                                        <input class="f-input" type="number" min="1" name="position_priority[]" value="<?= val($pp['priority_order']) ?>">
                                    </div>
                                    <div class="dyn-row-remove-wrap">
                                        <button type="button" class="btn-icon-remove" data-remove-row title="Remove">
                                            <i class="fas fa-trash-can"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions" style="justify-content: space-between; margin-top: 14px;">
                        <button type="button" class="btn-ghost" id="addPositionBtn">
                            <i class="fas fa-plus"></i> Add Position
                        </button>
                        <button type="submit" name="save_positions" class="btn-save">
                            <i class="fas fa-floppy-disk"></i> Save Preferred Positions
                        </button>
                    </div>
                </form>
            </div>

            <!-- SECTION 5.5: CHANGE PASSWORD -->
            <div class="card card-section fade-in d6" id="sec-password">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--settings-amber)"></span>
                            Change Password
                        </span>
                        <span class="card-subtitle">Update the password you use to log in to the applicant portal</span>
                    </div>
                </div>

                <form method="POST" action="profile.php#sec-password" id="password-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= val($csrf_token) ?>">
                    <input type="hidden" name="change_password" value="1">
                    <div class="form-grid">
                        <div class="f-group full">
                            <label class="f-label" for="current_password">Current Password</label>
                            <input class="f-input" type="password" id="current_password" name="current_password" required autocomplete="current-password">
                        </div>

                        <div class="f-group">
                            <label class="f-label" for="new_password">New Password</label>
                            <input class="f-input" type="password" id="new_password" name="new_password" required autocomplete="new-password">
                            <div id="password-requirements" class="password-requirements hidden">
                                <div id="req-length" class="req-item">• At least 8 characters</div>
                                <div id="req-upper" class="req-item">• At least one uppercase letter</div>
                                <div id="req-lower" class="req-item">• At least one lowercase letter</div>
                                <div id="req-number" class="req-item">• At least one number</div>
                                <div id="req-special" class="req-item">• At least one special character</div>
                            </div>
                        </div>

                        <div class="f-group">
                            <label class="f-label" for="confirm_password">Confirm New Password</label>
                            <input class="f-input" type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                            <div id="password-match" class="password-match-msg"></div>
                        </div>
                    </div>

                    <div id="password-err" class="form-err<?= $pw_error !== '' ? ' show' : '' ?>"><?= val($pw_error) ?></div>

                    <div class="form-actions">
                        <button type="submit" class="btn-save" id="password-save-btn">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>

            <!--SECTION 6: ACCOUNT STATUS (danger zone) -->
            <div class="card card-section fade-in d6" id="sec-account-status" style="border-color:#fecaca;">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:#dc2626"></span>
                            Account Status
                        </span>
                        <span class="card-subtitle">Deactivate your account if you no longer wish to use the portal. You can reactivate anytime by logging back in.</span>
                    </div>
                </div>

                <form method="POST" action="profile.php" id="deactivateForm">
                    <input type="hidden" name="csrf_token" value="<?= val($csrf_token) ?>">
                    <div class="form-grid">
                        <div class="f-group full">
                            <label class="f-label">Reason<span class="req">*</span></label>
                            <select class="f-select" name="deactivate_reason_preset" id="deactivateReasonPreset" required onchange="toggleDeactivateOtherReason()">
                                <option value="">— Select a reason —</option>
                                <option value="Deployment secured / no longer job hunting">Deployment secured / no longer job hunting</option>
                                <option value="Taking a break / temporary pause">Taking a break / temporary pause</option>
                                <option value="Privacy or security concern">Privacy or security concern</option>
                                <option value="No longer interested in overseas employment">No longer interested in overseas employment</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="f-group full" id="deactivateOtherReasonWrap" style="display:none;">
                            <label class="f-label">Please Specify<span class="req">*</span></label>
                            <input class="f-input" type="text" name="deactivate_reason_other" id="deactivateReasonOther" placeholder="Enter specific reason...">
                        </div>
                        <div class="f-group full">
                            <label class="f-label">Confirm Current Password<span class="req">*</span></label>
                            <input class="f-input" type="password" name="deactivate_current_password" autocomplete="current-password" required>
                        </div>
                    </div>
                    <input type="hidden" name="deactivate_account" value="1">
                    <div class="form-actions">
                        <button type="button" id="deactivateBtn" class="btn-save" style="background:#dc2626;">
                            <i class="fas fa-user-slash"></i> Deactivate My Account
                        </button>
                    </div>
                </form>
            </div>

        </div><!-- /settings-panel -->
    </div><!-- /settings-shell -->


    <!-- ══════════════════════════════════════════
         FLOATING DOCK
    ══════════════════════════════════════════ -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="jobs.php" class="dk ">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">Jobs</span>
            </a>

            <div class="dk-div"></div>

            <a href="my-applications.php" class="dk ">
                <i class="fas fa-file-lines"></i>
                <span class="dk-lbl">Applications</span>
                <?php if ($app_count > 0): ?>
                    <span class="dk-badge"><?= $app_count > 99 ? '99+' : $app_count ?></span>
                <?php endif; ?>
            </a>

            <!--<a href="notifications.php" class="dk">
                <i class="fas fa-bell"></i>
                <span class="dk-lbl">Alerts</span>
            </a>-->
            <div class="dk-div"></div>

            <a href="profile.php" class="dk active">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>

            <div class="dk" onclick="logout('Applicant')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>

        </nav>
    </div>

    <script>
        /*  Live date + time  */
        function updateLiveDateTime() {
            const el = document.getElementById('live-date');
            if (!el) return;

            const now = new Date();

            const datePart = now.toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();

            const timePart = now.toLocaleTimeString('en-PH', {
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            }).toLowerCase();

            el.textContent = `${datePart} | ${timePart}`;
        }

        updateLiveDateTime();
        setInterval(updateLiveDateTime, 1000); // refresh every second
    </script>

    <script>
        // Subcategories grouped by category id, rendered server-side into JSON
        // so the "Preferred Job Positions" category/position dropdowns can
        // cascade without any extra AJAX endpoints.
        const SUBCATS_BY_CATEGORY = <?= json_encode($subcategories_by_category, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        //  Generic empty-state toggler 
        function toggleEmptyState(listEl, emptyEl) {
            if (!emptyEl) return;
            emptyEl.style.display = listEl.querySelectorAll('[data-row]').length ? 'none' : 'flex';
        }

        //  Remove-row wiring (event delegation) 
        function wireRemoveButtons(listEl, emptyEl) {
            listEl.addEventListener('click', function(e) {
                const btn = e.target.closest('[data-remove-row]');
                if (!btn) return;
                const row = btn.closest('[data-row]');
                if (row) row.remove();
                toggleEmptyState(listEl, emptyEl);
            });
        }

        // PREFERRED COUNTRIES — add row
        const countriesList = document.getElementById('countriesList');
        const countriesEmpty = document.getElementById('countriesEmpty');
        wireRemoveButtons(countriesList, countriesEmpty);

        document.getElementById('addCountryBtn').addEventListener('click', function() {
            const row = document.createElement('div');
            row.className = 'dyn-row';
            row.setAttribute('data-row', '');
            const nextPriority = countriesList.querySelectorAll('[data-row]').length + 1;
            row.innerHTML = `
                <div class="f-group">
                    <label class="f-label">Country</label>
                    <input class="f-input" type="text" name="country[]" list="countryList" placeholder="e.g. Taiwan" required>
                </div>
                <div class="dyn-row-rank">
                    <label>Priority</label>
                    <input class="f-input" type="number" min="1" name="country_priority[]" value="${nextPriority}">
                </div>
                <div class="dyn-row-remove-wrap">
                    <button type="button" class="btn-icon-remove" data-remove-row title="Remove">
                        <i class="fas fa-trash-can"></i>
                    </button>
                </div>
            `;
            countriesList.appendChild(row);
            toggleEmptyState(countriesList, countriesEmpty);
            row.querySelector('input[type=text]').focus();
        });

        // PREFERRED JOB POSITIONS — cascading dropdowns
        const positionsList = document.getElementById('positionsList');
        const positionsEmpty = document.getElementById('positionsEmpty');
        wireRemoveButtons(positionsList, positionsEmpty);

        function populateSubcategorySelect(categorySelect, subcategorySelect, selectedId) {
            const catId = categorySelect.value;
            const options = SUBCATS_BY_CATEGORY[catId] || [];
            subcategorySelect.innerHTML = '';
            options.forEach(function(opt) {
                const optionEl = document.createElement('option');
                optionEl.value = opt.id;
                optionEl.textContent = opt.name;
                if (selectedId && String(selectedId) === String(opt.id)) {
                    optionEl.selected = true;
                }
                subcategorySelect.appendChild(optionEl);
            });
            if (subcategorySelect._pillRefresh) subcategorySelect._pillRefresh();
        }

        function wireCascade(row, selectedSubcategoryId) {
            const categorySelect = row.querySelector('[data-category-select]');
            const subcategorySelect = row.querySelector('[data-subcategory-select]');
            populateSubcategorySelect(categorySelect, subcategorySelect, selectedSubcategoryId);
            categorySelect.addEventListener('change', function() {
                populateSubcategorySelect(categorySelect, subcategorySelect, null);
            });
        }

        // Initialize cascades for rows already rendered from the database
        positionsList.querySelectorAll('[data-row]').forEach(function(row) {
            const subcategorySelect = row.querySelector('[data-subcategory-select]');
            const preselected = subcategorySelect.getAttribute('data-selected');
            wireCascade(row, preselected);
        });

        document.getElementById('addPositionBtn').addEventListener('click', function() {
            const row = document.createElement('div');
            row.className = 'dyn-row dyn-row-cascade';
            row.setAttribute('data-row', '');
            const nextPriority = positionsList.querySelectorAll('[data-row]').length + 1;

            const categoryOptionsHtml = <?= json_encode(implode('', array_map(fn($jc) => '<option value="' . htmlspecialchars((string)$jc['id'], ENT_QUOTES) . '">' . htmlspecialchars($jc['category_name'], ENT_QUOTES) . '</option>', $job_categories))) ?>;

            row.innerHTML = `
                <div class="f-group">
                    <label class="f-label">Job Category</label>
                    <select class="f-select" data-category-select>${categoryOptionsHtml}</select>
                </div>
                <div class="f-group">
                    <label class="f-label">Specific Position</label>
                    <select class="f-select" name="subcategory_id[]" data-subcategory-select required></select>
                </div>
                <div class="dyn-row-rank">
                    <label>Priority</label>
                    <input class="f-input" type="number" min="1" name="position_priority[]" value="${nextPriority}">
                </div>
                <div class="dyn-row-remove-wrap">
                    <button type="button" class="btn-icon-remove" data-remove-row title="Remove">
                        <i class="fas fa-trash-can"></i>
                    </button>
                </div>
            `;
            positionsList.appendChild(row);
            wireCascade(row, null);
            row.querySelectorAll('select.f-select').forEach(enhanceFieldSelect);
            toggleEmptyState(positionsList, positionsEmpty);
        });

        // Require at least one non-empty row before submitting each dynamic form
        // (still allowed to save an intentionally empty list to clear it out).
        document.getElementById('countriesForm').addEventListener('submit', function() {
            countriesList.querySelectorAll('[data-row] input[type=text]').forEach(function(input) {
                if (input.value.trim() === '') {
                    input.closest('[data-row]').remove();
                }
            });
        });
        document.getElementById('pictureInput').addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                document.getElementById('pictureForm').submit();
            }
        });

        /* Custom dropdown — ported from dashboard.php's enhancePillSelect, restyled
           as a full-width form field. The native <select> stays in the DOM, so
           name/value, `required`, the 'change' event and inline onchange handlers
           (e.g. toggleDeactivateOtherReason) keep working unchanged. */
        let _psUid = 0;
        let _psCloseOpen = null;

        function enhanceFieldSelect(select) {
            if (!select || select._pillRefresh) return; // already enhanced
            const uid = 'ps' + (++_psUid);

            const wrapper = document.createElement('div');
            wrapper.className = 'ps-wrap';
            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);
            select.classList.add('ps-native');
            select.tabIndex = -1;

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.id = uid + '-btn';
            trigger.className = 'ps-trigger';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.innerHTML = '<span class="ps-label"></span><i class="fas fa-chevron-down ps-chevron"></i>';
            wrapper.appendChild(trigger);
            const labelEl = trigger.querySelector('.ps-label');

            const fieldLabel = select.closest('.f-group')?.querySelector('.f-label');
            if (fieldLabel) {
                fieldLabel.id = fieldLabel.id || uid + '-lbl';
                trigger.setAttribute('aria-labelledby', fieldLabel.id + ' ' + trigger.id);
                fieldLabel.addEventListener('click', () => trigger.focus());
            }

            let panel = null;
            let activeIdx = -1;

            function rows() {
                return panel ? Array.from(panel.querySelectorAll('.ps-option')) : [];
            }

            function refresh() {
                const opt = select.options[select.selectedIndex];
                labelEl.textContent = opt ? opt.textContent : '';
                trigger.classList.toggle('is-placeholder', !opt || opt.value === '');
                trigger.disabled = select.disabled;
                wrapper.classList.remove('is-invalid');
            }

            function setActive(i, scroll) {
                activeIdx = i;
                rows().forEach((r, n) => r.classList.toggle('is-active', n === i));
                const r = rows()[i];
                if (r) {
                    trigger.setAttribute('aria-activedescendant', r.id);
                    if (scroll) r.scrollIntoView({
                        block: 'nearest'
                    });
                }
            }

            function choose(i) {
                const opt = select.options[i];
                if (!opt || opt.disabled) return;
                select.selectedIndex = i;
                closePanel();
                refresh();
                trigger.focus();
                select.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            }

            function positionPanel() {
                const rect = trigger.getBoundingClientRect();
                if (rect.bottom < 0 || rect.top > window.innerHeight) {
                    closePanel();
                    return;
                }
                const below = window.innerHeight - rect.bottom - 12;
                const above = rect.top - 12;
                const flip = below < 180 && above > below;
                panel.style.width = rect.width + 'px';
                panel.style.left = rect.left + 'px';
                panel.style.maxHeight = Math.max(120, Math.min(260, flip ? above : below)) + 'px';
                if (flip) {
                    panel.style.top = '';
                    panel.style.bottom = (window.innerHeight - rect.top + 6) + 'px';
                } else {
                    panel.style.bottom = '';
                    panel.style.top = (rect.bottom + 6) + 'px';
                }
            }

            function onReposition(e) {
                if (!panel) return;
                if (e && e.target instanceof Node && panel.contains(e.target)) return;
                positionPanel();
            }

            function onOutside(e) {
                if (panel && !panel.contains(e.target) && !trigger.contains(e.target)) closePanel();
            }

            function openPanel() {
                if (panel || trigger.disabled) return;
                if (_psCloseOpen) _psCloseOpen();

                panel = document.createElement('div');
                panel.id = uid + '-list';
                panel.className = 'ps-panel';
                panel.setAttribute('role', 'listbox');
                panel.addEventListener('mousedown', e => e.preventDefault()); // keep focus on trigger

                let lastGroup = null;
                Array.from(select.options).forEach((opt, i) => {
                    const group = opt.parentElement.tagName === 'OPTGROUP' ? opt.parentElement.label : null;
                    if (group !== lastGroup) {
                        if (group) {
                            const head = document.createElement('div');
                            head.className = 'ps-group';
                            head.setAttribute('role', 'presentation');
                            head.textContent = group;
                            panel.appendChild(head);
                        }
                        lastGroup = group;
                    }
                    const row = document.createElement('div');
                    const selected = i === select.selectedIndex;
                    row.id = uid + '-opt-' + i;
                    row.className = 'ps-option' + (opt.value === '' ? ' is-placeholder' : '') + (group ? ' in-group' : '');
                    row.setAttribute('role', 'option');
                    row.setAttribute('aria-selected', selected ? 'true' : 'false');
                    if (opt.disabled) row.setAttribute('aria-disabled', 'true');

                    const text = document.createElement('span');
                    text.textContent = opt.textContent;
                    row.appendChild(text);

                    if (selected) {
                        const check = document.createElement('i');
                        check.className = 'fas fa-check ps-check';
                        row.appendChild(check);
                    }
                    row.addEventListener('click', () => choose(i));
                    row.addEventListener('mousemove', () => {
                        if (activeIdx !== i) setActive(i, false);
                    });
                    panel.appendChild(row);
                });

                document.body.appendChild(panel);
                trigger.setAttribute('aria-expanded', 'true');
                trigger.setAttribute('aria-controls', panel.id);
                positionPanel();
                setActive(select.selectedIndex, true);

                window.addEventListener('scroll', onReposition, true);
                window.addEventListener('resize', onReposition);
                document.addEventListener('pointerdown', onOutside);
                _psCloseOpen = closePanel;
            }

            function closePanel() {
                if (!panel) return;
                panel.remove();
                panel = null;
                activeIdx = -1;
                trigger.setAttribute('aria-expanded', 'false');
                trigger.removeAttribute('aria-controls');
                trigger.removeAttribute('aria-activedescendant');
                window.removeEventListener('scroll', onReposition, true);
                window.removeEventListener('resize', onReposition);
                document.removeEventListener('pointerdown', onOutside);
                if (_psCloseOpen === closePanel) _psCloseOpen = null;
            }

            trigger.addEventListener('click', () => panel ? closePanel() : openPanel());
            trigger.addEventListener('keyup', e => {
                if (e.key === ' ') e.preventDefault(); // stop Firefox's synthetic click
            });
            trigger.addEventListener('keydown', e => {
                if (!panel) {
                    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
                        e.preventDefault();
                        openPanel();
                    }
                    return;
                }
                const count = select.options.length;
                const step = dir => {
                    let i = activeIdx;
                    do {
                        i += dir;
                    } while (i >= 0 && i < count && select.options[i].disabled);
                    if (i >= 0 && i < count) setActive(i, true);
                };
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    step(1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    step(-1);
                } else if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (activeIdx >= 0) choose(activeIdx);
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    closePanel();
                } else if (e.key === 'Tab') {
                    closePanel();
                }
            });

            // Native `required` validation targets the hidden <select>; send it to the trigger.
            select.addEventListener('focus', () => trigger.focus());
            select.addEventListener('invalid', () => wrapper.classList.add('is-invalid'));

            select._pillRefresh = refresh; // same hook name as dashboard.php
            refresh();
        }

        document.querySelectorAll('select.f-select').forEach(enhanceFieldSelect);
    </script>

    <script>
        // Pin subbar under the topbar and reserve content space (same as Admin Settings)
        function pinSubbar() {
            const topbar = document.querySelector('.topbar');
            const subbar = document.querySelector('.subbar');
            const shell = document.querySelector('.settings-shell');
            if (!topbar || !subbar || !shell) return;
            const topbarHeight = topbar.offsetHeight;
            subbar.style.top = topbarHeight + 'px';
            const totalOffset = topbarHeight + subbar.offsetHeight + 16;
            shell.style.paddingTop = totalOffset + 'px';
            document.documentElement.style.setProperty('--sticky-top', totalOffset + 'px');
        }
        window.addEventListener('load', pinSubbar);
        window.addEventListener('resize', pinSubbar);
        setTimeout(pinSubbar, 300);

        // Highlight the nav item / pill for the section currently in view
        (function() {
            const links = document.querySelectorAll('.settings-nav-item, .settings-crumb-pill');
            const sections = Array.from(document.querySelectorAll('.card-section'));
            if (!sections.length) return;

            function spy() {
                const offset = (parseInt(getComputedStyle(document.documentElement).getPropertyValue('--sticky-top')) || 160) + 24;
                let current = sections[0].id;
                sections.forEach(s => {
                    if (s.getBoundingClientRect().top <= offset) current = s.id;
                });
                if (window.innerHeight + window.scrollY >= document.body.scrollHeight - 4) {
                    current = sections[sections.length - 1].id;
                }
                links.forEach(l => l.classList.toggle('active', l.getAttribute('href') === '#' + current));
            }
            window.addEventListener('scroll', spy, {
                passive: true
            });
            window.addEventListener('resize', spy);
            spy();
        })();
    </script>
    <script>
        function toggleDeactivateOtherReason() {
            const val = document.getElementById('deactivateReasonPreset').value;
            document.getElementById('deactivateOtherReasonWrap').style.display = (val === 'Other') ? 'block' : 'none';
        }

        const removePictureBtn = document.getElementById('removePictureBtn');
        if (removePictureBtn) {
            removePictureBtn.addEventListener('click', function() {
                Swal.fire({
                    title: 'Remove profile picture?',
                    text: 'This will permanently delete your current profile picture. You can upload a new one anytime.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-trash-can"></i> Yes, remove it',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#64748b',
                    reverseButtons: true,
                    focusCancel: true,
                    customClass: {
                        popup: 'dmw-swal-popup'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.getElementById('removePictureForm').submit();
                    }
                });
            });
        }

        const deactivateBtn = document.getElementById('deactivateBtn');
        if (deactivateBtn) {
            deactivateBtn.addEventListener('click', function() {
                const form = document.getElementById('deactivateForm');

                // Keep the existing required-field validation before asking for confirmation
                if (!form.reportValidity()) return;

                const preset = document.getElementById('deactivateReasonPreset').value;
                const other = document.getElementById('deactivateReasonOther').value.trim();
                const reason = preset === 'Other' ? other : preset;

                if (preset === 'Other' && other.length < 5) {
                    Swal.fire({
                        icon: 'info',
                        title: 'Reason required',
                        text: 'Please specify a reason (at least 5 characters).',
                        confirmButtonColor: '#1d4ed8',
                        customClass: {
                            popup: 'dmw-swal-popup'
                        }
                    });
                    return;
                }

                const esc = s => String(s).replace(/[&<>"']/g, c => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [c]));

                Swal.fire({
                    title: 'Deactivate your account?',
                    icon: 'warning',
                    html: `
                <div style="text-align:left;font-size:13px;line-height:1.6;color:#475569">
                    <p style="margin:0 0 10px">Your account will be deactivated and you will be <strong>logged out immediately</strong>.</p>
                    <ul style="margin:0 0 10px 18px;padding:0">
                        <li>You will no longer be able to log in while your account is deactivated.</li>
                        <li>You will not be able to submit new applications while deactivated.</li>
                        <li>Your existing applications and records are kept.</li>
                        <li>If you change your mind, you can submit a reactivation request. The DMW administrator will review it and either approve or reject it.</li>
                        <li>You will receive an email once your request is approved or rejected.</li>
                    </ul>
                    <p style="margin:0"><strong>Reason:</strong> ${esc(reason)}</p>
                </div>`,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-user-slash"></i> Yes, deactivate',
                    cancelButtonText: 'Keep my account',
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#64748b',
                    reverseButtons: true,
                    focusCancel: true,
                    customClass: {
                        popup: 'dmw-swal-popup'
                    }
                }).then(result => {
                    if (result.isConfirmed) form.submit();
                });
            });
        }
    </script>
    <script>
        (function() {
            const form = document.getElementById('password-form');
            if (!form) return;

            const pwdInput = document.getElementById('new_password');
            const confirmInput = document.getElementById('confirm_password');
            const requirementsBox = document.getElementById('password-requirements');
            const matchMsg = document.getElementById('password-match');
            const errBox = document.getElementById('password-err');

            const reqElements = {
                length: document.getElementById('req-length'),
                upper: document.getElementById('req-upper'),
                lower: document.getElementById('req-lower'),
                number: document.getElementById('req-number'),
                special: document.getElementById('req-special')
            };

            function checkRules(value) {
                return {
                    length: value.length >= 8,
                    upper: /[A-Z]/.test(value),
                    lower: /[a-z]/.test(value),
                    number: /[0-9]/.test(value),
                    special: /[^A-Za-z0-9]/.test(value)
                };
            }

            function updateRequirements(value) {
                const checks = checkRules(value);
                for (const [key, ok] of Object.entries(checks)) {
                    reqElements[key].className = ok ? 'req-item valid' : 'req-item';
                }
                requirementsBox.classList.remove('hidden');
            }

            function checkMatch() {
                if (!confirmInput.value) {
                    matchMsg.textContent = '';
                    matchMsg.className = 'password-match-msg';
                    return;
                }
                const same = pwdInput.value === confirmInput.value;
                matchMsg.textContent = same ? '✓ Passwords match' : '✗ Passwords do not match';
                matchMsg.className = 'password-match-msg ' + (same ? 'match' : 'no-match');
            }

            function showErr(msg) {
                errBox.textContent = msg;
                errBox.classList.toggle('show', !!msg);
            }

            pwdInput.addEventListener('focus', () => requirementsBox.classList.remove('hidden'));
            confirmInput.addEventListener('focus', () => requirementsBox.classList.remove('hidden'));

            [pwdInput, confirmInput].forEach(input => {
                input.addEventListener('blur', () => {
                    setTimeout(() => {
                        if (document.activeElement !== pwdInput && document.activeElement !== confirmInput) {
                            requirementsBox.classList.add('hidden');
                        }
                    }, 100);
                });
            });

            pwdInput.addEventListener('input', () => {
                updateRequirements(pwdInput.value);
                checkMatch();
            });
            confirmInput.addEventListener('input', checkMatch);

            form.addEventListener('submit', function(ev) {
                showErr('');
                const cur = document.getElementById('current_password').value;
                const n = pwdInput.value;
                const c = confirmInput.value;

                if (!cur || !n || !c) {
                    ev.preventDefault();
                    return showErr('Please fill in all password fields.');
                }
                if (!Object.values(checkRules(n)).every(Boolean)) {
                    ev.preventDefault();
                    updateRequirements(n);
                    return showErr('New password does not meet all the requirements.');
                }
                if (n !== c) {
                    ev.preventDefault();
                    return showErr('New password and confirmation do not match.');
                }
                document.getElementById('password-save-btn').disabled = true;
            });

            <?php if ($pw_success !== ''): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'Password updated',
                    text: <?= json_encode($pw_success) ?>,
                    confirmButtonColor: '#3b82f6',
                    timer: 2200,
                    timerProgressBar: true
                });
            <?php endif; ?>
        })();
    </script>
    <script type="module" src="../js/main.js"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>