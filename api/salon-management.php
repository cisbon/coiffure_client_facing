<?php
/**
 * Salon Management API Endpoint
 * Handles CRUD operations for salons (admin and admin_delegate only)
 */

require_once __DIR__ . '/config.php';
// Onboarding invites the salon owner by e-mail rather than mailing a password.
require_once __DIR__ . '/mailer.php';

// Set CORS headers
setCorsHeaders();

// Get database connection
$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// Require authentication
$currentUser = requireAuth($conn);

// Allow admin, admin_delegate, customer_admin, customer_admin_delegate, and customer_facing_tablet_user to access salons
// customer_admin and customer_facing_tablet_user roles can only view their assigned salons (read-only)
$allowedRoles = ['admin', 'admin_delegate', 'customer_admin', 'customer_admin_delegate', 'customer_facing_tablet_user'];
requireRole($currentUser, $allowedRoles);

// Get request method and parse input
$method = $_SERVER['REQUEST_METHOD'];
$salonId = isset($_GET['salon_id']) ? (int)$_GET['salon_id'] : null;

// Parse JSON input for POST/PUT
$requestData = [];
if (in_array($method, ['POST', 'PUT'])) {
    $jsonInput = file_get_contents('php://input');
    $requestData = json_decode($jsonInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendErrorResponse('Invalid JSON: ' . json_last_error_msg(), 400);
    }
}

// Route based on HTTP method
switch ($method) {
    case 'GET':
        handleGetSalons($conn, $currentUser, $salonId);
        break;

    case 'POST':
        // Only admin and admin_delegate can create salons
        requireRole($currentUser, ['admin', 'admin_delegate']);
        handleCreateSalon($conn, $currentUser, $requestData);
        break;

    case 'PUT':
        // Admin and admin_delegate can update all salon fields
        // customer_admin and customer_admin_delegate can only update default_language for their assigned salons
        handleUpdateSalon($conn, $currentUser, $salonId, $requestData);
        break;

    case 'DELETE':
        // Only admin and admin_delegate can delete salons
        requireRole($currentUser, ['admin', 'admin_delegate']);
        handleDeleteSalon($conn, $currentUser, $salonId);
        break;

    default:
        sendErrorResponse('Method not allowed', 405);
}

/**
 * Get salons
 */
function handleGetSalons($conn, $currentUser, $salonId) {
    // Determine if user is customer_admin, customer_admin_delegate, or customer_facing_tablet_user
    $isCustomerRole = in_array($currentUser['role'], ['customer_admin', 'customer_admin_delegate', 'customer_facing_tablet_user']);

    if ($salonId) {
        // Get single salon
        $query = "SELECT s.*,
                        (SELECT COUNT(*) FROM coiffure_users WHERE salon_id = s.salon_id AND is_active = 1) as user_count,
                        (SELECT COUNT(*) FROM coiffure_customers WHERE salon_id = s.salon_id AND is_deleted = 0) as customer_count
                 FROM coiffure_salons s
                 WHERE salon_id = ?";

        // If customer role, verify they have access to this salon
        if ($isCustomerRole) {
            $query .= " AND EXISTS (
                SELECT 1 FROM coiffure_user_salons us
                WHERE us.salon_id = s.salon_id AND us.user_id = ?
            )";
        }

        $stmt = $conn->prepare($query);

        if (!$stmt) {
            sendErrorResponse('Failed to fetch salon', 500);
        }

        if ($isCustomerRole) {
            $stmt->bind_param("ii", $salonId, $currentUser['user_id']);
        } else {
            $stmt->bind_param("i", $salonId);
        }

        if (!$stmt->execute()) {
            sendErrorResponse('Failed to fetch salon', 500);
        }

        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            sendErrorResponse('Salon not found or access denied', 404);
        }

        $salon = $result->fetch_assoc();
        $stmt->close();

        sendJsonResponse([
            'success' => true,
            'salon' => $salon
        ]);
    } else {
        // Get all salons (filtered by user's assignments for customer roles)
        $query = "SELECT s.*,
                        (SELECT COUNT(*) FROM coiffure_users WHERE salon_id = s.salon_id AND is_active = 1) as user_count,
                        (SELECT COUNT(*) FROM coiffure_customers WHERE salon_id = s.salon_id AND is_deleted = 0) as customer_count
                  FROM coiffure_salons s";

        // Apply filters
        $conditions = [];
        $params = [];
        $types = '';

        // If customer role, only show salons they're assigned to
        if ($isCustomerRole) {
            $conditions[] = "EXISTS (
                SELECT 1 FROM coiffure_user_salons us
                WHERE us.salon_id = s.salon_id AND us.user_id = ?
            )";
            $params[] = $currentUser['user_id'];
            $types .= 'i';
        }

        if (isset($_GET['is_active'])) {
            $conditions[] = "s.is_active = ?";
            $params[] = (int)$_GET['is_active'];
            $types .= 'i';
        }

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $query .= " ORDER BY s.created_at DESC";

        $stmt = $conn->prepare($query);

        if (!$stmt) {
            sendErrorResponse('Failed to fetch salons', 500);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            sendErrorResponse('Failed to fetch salons', 500);
        }

        $result = $stmt->get_result();
        $salons = [];

        while ($row = $result->fetch_assoc()) {
            $salons[] = $row;
        }

        $stmt->close();

        sendJsonResponse([
            'success' => true,
            'salons' => $salons,
            'count' => count($salons)
        ]);
    }
}

/**
 * Create salon
 */
function handleCreateSalon($conn, $currentUser, $data) {
    // Validate required fields
    $requiredFields = ['salon_name', 'email', 'phone', 'policy_version'];
    $validation = validateRequiredFields($data, $requiredFields);
    if ($validation !== null) {
        sendErrorResponse($validation['message'], 400, ['missing_fields' => $validation['missing_fields']]);
    }

    // Onboarding fields. The owner is invited rather than given an account with
    // a password chosen here -- see inviteSalonOwner() below. The tablet is a
    // shared kiosk login with no mailbox, so it still needs a password.
    $inviteOwner = isset($data['owner_email']) && isset($data['owner_full_name']);
    $createTabletAccount = isset($data['tablet_username']) && isset($data['initial_password']);

    if ($inviteOwner) {
        if (!validateEmail($data['owner_email'])) {
            sendErrorResponse('Invalid owner email address', 400);
        }
        if (strlen(trim($data['owner_full_name'])) < 2) {
            sendErrorResponse('Owner full name must be at least 2 characters', 400);
        }
    }

    if ($createTabletAccount) {
        if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $data['tablet_username'])) {
            sendErrorResponse('Invalid tablet username. Must be 3-50 alphanumeric characters', 400);
        }
        if (strlen($data['initial_password']) < 8) {
            sendErrorResponse('Initial password must be at least 8 characters', 400);
        }
    }

    $salonName = trim($data['salon_name']);
    $email = trim($data['email']);
    $phone = trim($data['phone']);
    $address = isset($data['address']) ? trim($data['address']) : null;
    $googleReviewsUrl = isset($data['google_reviews_url']) ? trim($data['google_reviews_url']) : null;
    $facebookUrl = isset($data['facebook_url']) ? trim($data['facebook_url']) : null;
    $policyVersion = trim($data['policy_version']);
    $cancellationPolicy = isset($data['cancellation_policy']) ? trim($data['cancellation_policy']) : null;
    $dataProcessingPolicy = isset($data['data_processing_policy']) ? trim($data['data_processing_policy']) : null;
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
    $defaultLanguage = isset($data['default_language']) ? trim($data['default_language']) : 'de';

    // Validate language
    if (!in_array($defaultLanguage, ['de', 'en'])) {
        sendErrorResponse('Invalid language. Must be "de" or "en"', 400);
    }

    // Validate email
    if (!validateEmail($email)) {
        sendErrorResponse('Invalid email address', 400);
    }

    // Validate phone
    if (!validatePhone($phone)) {
        sendErrorResponse('Invalid phone number', 400);
    }

    // Start transaction for salon + user creation
    $conn->begin_transaction();

    try {
        // Insert salon
        $stmt = $conn->prepare(
            "INSERT INTO coiffure_salons
            (salon_name, email, phone, address, google_reviews_url, facebook_url,
             policy_version, cancellation_policy, data_processing_policy, is_active, default_language)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            throw new Exception("Failed to prepare salon insert: " . $conn->error);
        }

        // 11 placeholders: nine strings, is_active, default_language.
        $stmt->bind_param(
            "sssssssssis",
            $salonName,
            $email,
            $phone,
            $address,
            $googleReviewsUrl,
            $facebookUrl,
            $policyVersion,
            $cancellationPolicy,
            $dataProcessingPolicy,
            $isActive,
            $defaultLanguage
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create salon: " . $stmt->error);
        }

        $newSalonId = $stmt->insert_id;
        $stmt->close();

        // Create user accounts if onboarding data is provided
        $createdAccounts = [];

        $ownerInvitation = null;
        $ownerLinked = null;

        if ($inviteOwner) {
            $ownerEmail = trim($data['owner_email']);
            $ownerFullName = trim($data['owner_full_name']);

            // An address that already has an account is not an error: one
            // person can own several salons, which is what the many-to-many
            // coiffure_user_salons table is for. Attach them and skip the
            // invitation -- they already have a password.
            $checkStmt = $conn->prepare(
                "SELECT user_id, role, full_name FROM coiffure_users WHERE email = ?"
            );
            $checkStmt->bind_param("s", $ownerEmail);
            $checkStmt->execute();
            $existingOwner = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if ($existingOwner) {
                // Only a salon role can own a salon. A platform administrator
                // or a kiosk account sharing the address is a real conflict.
                if (!in_array($existingOwner['role'], ['customer_admin', 'customer_admin_delegate'], true)) {
                    throw new InvalidArgumentException(
                        'Diese E-Mail-Adresse gehört bereits zu einem Konto, das kein Salon-Konto ist.'
                    );
                }

                $link = $conn->prepare(
                    "INSERT IGNORE INTO coiffure_user_salons (user_id, salon_id) VALUES (?, ?)"
                );
                if ($link) {
                    $link->bind_param("ii", $existingOwner['user_id'], $newSalonId);
                    $link->execute();
                    $link->close();
                }

                $ownerLinked = [
                    'user_id'   => (int)$existingOwner['user_id'],
                    'email'     => $ownerEmail,
                    'full_name' => $existingOwner['full_name'],
                ];
            } else {
                // Creates the invitation row only. The e-mail is sent after the
                // commit below -- talking to an SMTP server while holding an
                // open transaction keeps row locks for the length of a network
                // round-trip to a third party, and a slow server there can run
                // the request past max_execution_time and kill it outright.
                $ownerInvitation = inviteSalonOwner(
                    $conn, $newSalonId, $ownerEmail, $ownerFullName, (int)$currentUser['user_id']
                );
            }
        }

        if ($createTabletAccount) {
            $tabletUsername = trim($data['tablet_username']);
            $initialPassword = $data['initial_password'];

            // Check if username already exists
            $checkStmt = $conn->prepare("SELECT user_id FROM coiffure_users WHERE username = ?");
            $checkStmt->bind_param("s", $tabletUsername);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $checkStmt->close();
                throw new Exception("Tablet username already exists");
            }
            $checkStmt->close();

            // Create tablet account (no email needed for kiosk)
            $passwordHash = password_hash($initialPassword, PASSWORD_ARGON2ID);
            $tabletEmail = $tabletUsername . '@kiosk.local'; // Dummy email for tablets
            $tabletFullName = $salonName . ' - Kiosk';

            $stmt = $conn->prepare(
                "INSERT INTO coiffure_users
                (username, email, password_hash, full_name, role, is_active, force_password_change, created_at)
                VALUES (?, ?, ?, ?, 'customer_facing_tablet_user', 1, 1, NOW())"
            );
            $stmt->bind_param("ssss", $tabletUsername, $tabletEmail, $passwordHash, $tabletFullName);

            if (!$stmt->execute()) {
                throw new Exception("Failed to create tablet account: " . $stmt->error);
            }

            $tabletUserId = $stmt->insert_id;
            $stmt->close();

            // Link tablet to salon
            $stmt = $conn->prepare("INSERT INTO coiffure_user_salons (user_id, salon_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $tabletUserId, $newSalonId);
            $stmt->execute();
            $stmt->close();

            $createdAccounts['tablet'] = [
                'user_id' => $tabletUserId,
                'username' => $tabletUsername,
                'role' => 'customer_facing_tablet_user'
            ];
        }

        // Commit transaction
        $conn->commit();

        // Only now, with the salon safely stored, try to deliver the
        // invitation. A mail failure must never undo a created salon: the
        // response carries the link so it can be passed on by hand, and the
        // Benutzer screen can resend it.
        if ($ownerInvitation) {
            $ownerInvitation['email_sent'] = deliverOwnerInvitation(
                $conn, $newSalonId, $ownerEmail, $ownerFullName, $ownerInvitation['token']
            );
            unset($ownerInvitation['token']);
        }

        // Log audit
        logAudit($conn, 'salon', $newSalonId, 'create', "Salon created: $salonName with " . count($createdAccounts) . " user accounts", $currentUser['username']);

        $response = [
            'success' => true,
            'message' => 'Salon created successfully',
            'salon_id' => $newSalonId
        ];

        if (!empty($createdAccounts)) {
            $response['created_accounts'] = $createdAccounts;
            $response['message'] .= ' with ' . count($createdAccounts) . ' user account(s)';
        }

        if ($ownerInvitation) {
            $response['owner_invitation'] = $ownerInvitation;
        }

        if ($ownerLinked) {
            $response['owner_linked'] = $ownerLinked;
        }

        sendJsonResponse($response, 201);

    } catch (InvalidArgumentException $e) {
        // A rejected input, not a server failure: 422 with the message against
        // the field, rather than a 500 that reads like the server broke.
        $conn->rollback();
        sendErrorResponse($e->getMessage(), 422, [
            'fields' => ['owner_email' => $e->getMessage()],
        ]);
    } catch (Throwable $e) {
        // Throwable, not Exception: an Error (undefined function, wrong
        // argument count, type error) is not an Exception, so catching only
        // Exception let it escape -- which skipped this rollback and left the
        // transaction open, and returned a 500 with no body at all.
        $conn->rollback();
        error_log('Salon creation error: ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
        sendErrorResponse('Failed to create salon: ' . $e->getMessage(), 500, [
            'type' => get_class($e),
        ]);
    }
}

/**
 * Invite the salon owner instead of creating an account for them.
 *
 * This used to create a customer_admin with a password chosen by whoever filled
 * in the onboarding form, and mail that password in the clear through a bare
 * @mail() call. Now it writes the same invitation row the dashboard's "Einladen"
 * button writes, and the owner picks their own password through
 * set-password.html -- no password is ever transmitted or stored by a third
 * party, and the mail goes through the branded, salon-aware mailer.
 *
 * @return array|null Summary for the API response, or null when invitations are
 *                    unavailable (migration 017 not applied).
 */
function inviteSalonOwner($conn, $salonId, $email, $fullName, $invitedBy) {
    $check = $conn->query("SHOW TABLES LIKE 'coiffure_user_invitations'");
    if (!$check || $check->num_rows === 0) {
        error_log('salon-management: cannot invite owner, migration 017 is not applied');
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    $permissions = json_encode([]);
    $role = 'customer_admin';

    $stmt = $conn->prepare(
        "INSERT INTO coiffure_user_invitations
            (token, email, full_name, role, salon_id, permissions, invited_by, status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
    );
    if (!$stmt) {
        throw new Exception('Failed to prepare the owner invitation: ' . $conn->error);
    }
    $stmt->bind_param(
        'ssssisis',
        $token, $email, $fullName, $role, $salonId, $permissions, $invitedBy, $expiresAt
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Failed to create the owner invitation');
    }
    $invitationId = $stmt->insert_id;
    $stmt->close();

    $base = rtrim(getenv('DASHBOARD_URL') ?: 'https://coiffureai.com', '/');

    // No mail is sent here on purpose -- see deliverOwnerInvitation(), which the
    // caller runs after committing. The token travels back so that call can use
    // it; handleCreateSalon() strips it before the response goes out.
    return [
        'invitation_id' => $invitationId,
        'email' => $email,
        'email_sent' => false,
        'token' => $token,
        'accept_url' => $base . '/set-password.html?token=' . urlencode($token),
    ];
}

/**
 * Send the owner invitation, after the salon has been committed.
 *
 * Always best-effort: the invitation row already exists, the link is already in
 * the response, and the Benutzer screen can resend it. A mail server that is
 * down must cost nothing more than a warning.
 */
function deliverOwnerInvitation($conn, $salonId, $email, $fullName, $token) {
    $result = $conn->query('SELECT * FROM coiffure_salons WHERE salon_id = ' . (int)$salonId);
    $salon = $result ? $result->fetch_assoc() : null;
    if (!$salon) {
        return false;
    }

    try {
        return (bool)sendInvitationEmail(
            $conn, $salon, ['email' => $email, 'full_name' => $fullName], $token
        );
    } catch (Throwable $e) {
        error_log('salon-management: owner invitation mail failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Update salon
 */
function handleUpdateSalon($conn, $currentUser, $salonId, $data) {
    if (!$salonId) {
        sendErrorResponse('Salon ID required', 400);
    }

    $isCustomerRole = in_array($currentUser['role'], ['customer_admin', 'customer_admin_delegate']);

    // Check if salon exists and if customer role, verify they have access
    $query = "SELECT salon_id FROM coiffure_salons WHERE salon_id = ?";
    if ($isCustomerRole) {
        $query .= " AND EXISTS (
            SELECT 1 FROM coiffure_user_salons us
            WHERE us.salon_id = coiffure_salons.salon_id AND us.user_id = ?
        )";
    }

    $checkStmt = $conn->prepare($query);
    if (!$checkStmt) {
        sendErrorResponse('Failed to fetch salon', 500);
    }

    if ($isCustomerRole) {
        $checkStmt->bind_param("ii", $salonId, $currentUser['user_id']);
    } else {
        $checkStmt->bind_param("i", $salonId);
    }

    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        $checkStmt->close();
        sendErrorResponse($isCustomerRole ? 'Salon not found or access denied' : 'Salon not found', 404);
    }
    $checkStmt->close();

    // If customer role, they can only update default_language
    if ($isCustomerRole) {
        $allowedKeys = array_keys($data);
        $disallowedKeys = array_diff($allowedKeys, ['default_language']);
        if (!empty($disallowedKeys)) {
            sendErrorResponse('Customer admin can only update default_language. Attempted to update: ' . implode(', ', $disallowedKeys), 403);
        }
    }

    // Build update query dynamically
    $updates = [];
    $params = [];
    $types = '';

    $allowedFields = [
        'salon_name' => 's',
        'email' => 's',
        'phone' => 's',
        'address' => 's',
        'google_reviews_url' => 's',
        'facebook_url' => 's',
        'policy_version' => 's',
        'cancellation_policy' => 's',
        'data_processing_policy' => 's',
        'is_active' => 'i',
        'default_language' => 's'
    ];

    foreach ($allowedFields as $field => $type) {
        if (isset($data[$field])) {
            // Validate email
            if ($field === 'email' && !validateEmail($data[$field])) {
                sendErrorResponse('Invalid email address', 400);
            }

            // Validate phone
            if ($field === 'phone' && !validatePhone($data[$field])) {
                sendErrorResponse('Invalid phone number', 400);
            }

            // Validate language
            if ($field === 'default_language' && !in_array($data[$field], ['de', 'en'])) {
                sendErrorResponse('Invalid language. Must be "de" or "en"', 400);
            }

            $updates[] = "$field = ?";
            $params[] = $type === 'i' ? (int)$data[$field] : trim($data[$field]);
            $types .= $type;
        }
    }

    if (empty($updates)) {
        sendErrorResponse('No fields to update', 400);
    }

    // Add salon_id to params
    $params[] = $salonId;
    $types .= 'i';

    // Execute update
    $query = "UPDATE coiffure_salons SET " . implode(', ', $updates) . " WHERE salon_id = ?";
    $updateStmt = $conn->prepare($query);

    if (!$updateStmt) {
        error_log("Failed to prepare salon update: " . $conn->error);
        sendErrorResponse('Failed to update salon', 500);
    }

    $updateStmt->bind_param($types, ...$params);

    if (!$updateStmt->execute()) {
        $error_msg = "Failed to update salon: " . $updateStmt->error;
        error_log($error_msg);
        error_log("Query: " . $query);
        error_log("Params: " . json_encode($params));
        error_log("Types: " . $types);
        sendErrorResponse('Failed to update salon: ' . $updateStmt->error, 500);
    }

    $updateStmt->close();

    // Log audit
    logAudit($conn, 'salon', $salonId, 'update', "Salon updated", $currentUser['username']);

    sendJsonResponse([
        'success' => true,
        'message' => 'Salon updated successfully'
    ]);
}

/**
 * Delete (deactivate) salon
 */
function handleDeleteSalon($conn, $currentUser, $salonId) {
    if (!$salonId) {
        sendErrorResponse('Salon ID required', 400);
    }

    // Check if salon exists
    $checkStmt = $conn->prepare("SELECT salon_name FROM coiffure_salons WHERE salon_id = ?");
    if (!$checkStmt) {
        sendErrorResponse('Failed to fetch salon', 500);
    }

    $checkStmt->bind_param("i", $salonId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        $checkStmt->close();
        sendErrorResponse('Salon not found', 404);
    }

    $salon = $checkResult->fetch_assoc();
    $checkStmt->close();

    // Deactivate salon instead of hard delete
    $deleteStmt = $conn->prepare("UPDATE coiffure_salons SET is_active = 0 WHERE salon_id = ?");

    if (!$deleteStmt) {
        sendErrorResponse('Failed to delete salon', 500);
    }

    $deleteStmt->bind_param("i", $salonId);

    if (!$deleteStmt->execute()) {
        error_log("Failed to delete salon: " . $deleteStmt->error);
        sendErrorResponse('Failed to delete salon', 500);
    }

    $deleteStmt->close();

    // Also deactivate all users from this salon
    $userStmt = $conn->prepare("UPDATE coiffure_users SET is_active = 0 WHERE salon_id = ?");
    if ($userStmt) {
        $userStmt->bind_param("i", $salonId);
        $userStmt->execute();
        $userStmt->close();
    }

    // Log audit
    logAudit($conn, 'salon', $salonId, 'delete', "Salon deactivated: " . $salon['salon_name'], $currentUser['username']);

    sendJsonResponse([
        'success' => true,
        'message' => 'Salon deleted successfully'
    ]);
}
