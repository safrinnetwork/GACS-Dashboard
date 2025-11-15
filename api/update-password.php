<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);

$currentPassword = $data['current_password'] ?? '';
$newUsername = $data['new_username'] ?? '';
$newPassword = $data['new_password'] ?? '';
$confirmPassword = $data['confirm_password'] ?? '';

if (empty($currentPassword) || empty($newUsername)) {
    jsonResponse(['success' => false, 'message' => 'Password saat ini dan username baru harus diisi']);
}

// Verify current password
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, password FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    if (!password_verify($currentPassword, $user['password'])) {
        jsonResponse(['success' => false, 'message' => 'Password saat ini salah']);
    }

    // Update credentials
    if (!empty($newPassword)) {
        if ($newPassword !== $confirmPassword) {
            jsonResponse(['success' => false, 'message' => 'Konfirmasi password tidak cocok']);
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?");
        $stmt->bind_param("ssi", $newUsername, $hashedPassword, $_SESSION['user_id']);
    } else {
        $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
        $stmt->bind_param("si", $newUsername, $_SESSION['user_id']);
    }

    if ($stmt->execute()) {
        $_SESSION['username'] = $newUsername;
        jsonResponse(['success' => true, 'message' => 'Kredensial berhasil diupdate']);
    } else {
        jsonResponse(['success' => false, 'message' => 'Gagal mengupdate kredensial']);
    }
} else {
    jsonResponse(['success' => false, 'message' => 'User tidak ditemukan']);
}
