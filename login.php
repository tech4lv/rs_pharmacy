<?php
require_once 'includes/auth.php';
startSession();

if (isLoggedIn()) { header('Location: dashboard.php'); exit; }

$error = '';
$success = '';
$activeTab = $_GET['tab'] ?? 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email && $password) {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['user_role']  = $user['role'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name']  = $user['last_name'];

                // Update last login
                $db->query("UPDATE users SET last_login = NOW() WHERE id = {$user['id']}");
                logAudit($user['id'], 'User login', 'LOGIN', 'users', $user['id'], null, null, 'INFO', 'Login successful');

                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Invalid email or password. Please try again.';
            }
        } else {
            $error = 'Please fill in all fields.';
        }
    } elseif ($action === 'register') {
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName  = sanitize($_POST['last_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $phone     = sanitize($_POST['phone'] ?? '');

        if ($firstName && $lastName && $email && $password) {
            $db = getDB();
            $check = $db->prepare("SELECT id FROM users WHERE email = ?");
            $check->bind_param('s', $email);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = 'Email already registered.';
                $activeTab = 'register';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (first_name, last_name, email, password, phone, role, status) VALUES (?, ?, ?, ?, ?, 'patient', 'active')");
                $stmt->bind_param('sssss', $firstName, $lastName, $email, $hashed, $phone);
                if ($stmt->execute()) {
                    $newUserId = $db->insert_id;
                    $insertPatient = $db->prepare("INSERT INTO patients (user_id, first_name, last_name, phone, status) VALUES (?, ?, ?, ?, 'active')");
                    $insertPatient->bind_param('isss', $newUserId, $firstName, $lastName, $phone);
                    $insertPatient->execute();
                    $success = 'Account created successfully! Please sign in.';
                    $activeTab = 'login';
                } else {
                    $error = 'Registration failed. Please try again.';
                    $activeTab = 'register';
                }
            }
        } else {
            $error = 'Please fill in all required fields.';
            $activeTab = 'register';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In — RS Pharmacy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { margin: 0; background: linear-gradient(180deg, #F8F9FC 0%, #E8EFF7 100%); }
        .login-form-box { background: #fff; border-radius: 24px; box-shadow: 0 24px 74px rgba(36, 58, 92, 0.12); padding: 36px; }
        .login-form-box h3 { margin-bottom: 4px; }
        .login-form-box p { color: var(--gray); line-height: 1.8; }
        .demo-creds { background: var(--gray-ultra); border-radius: var(--radius-sm); padding: 14px 18px; margin-bottom: 20px; font-size: 13px; }
        .demo-creds p { margin: 4px 0; color: var(--gray); }
        .demo-creds strong { color: var(--black); }
        .tab-btns { display: flex; border-bottom: 2px solid var(--gray-ultra); margin-bottom: 24px; border-radius: var(--radius-sm); overflow: hidden; background: #F7F7F9; }
        .tab-btn { flex: 1; padding: 12px 14px; background: none; border: none; font-family: var(--font-body); font-size: 14px; font-weight: 600; color: var(--gray); cursor: pointer; transition: var(--transition); border-bottom: 2px solid transparent; margin-bottom: 0; }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); background: #fff; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .login-brand { min-height: 100vh; }
        .login-brand-logo span { font-size: 24px; letter-spacing: -0.02em; }
        .login-brand-content h2 { font-size: 42px; }
        .login-form-side { padding: 56px; }
        .login-form-box .form-group { margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="login-wrapper">
    <!-- Brand Panel -->
    <div class="login-brand">
        <div class="login-brand-logo">
            <i class="fas fa-pills"></i>
            <span>RS Pharmacy</span>
        </div>
        <div class="login-brand-content">
            <h2>Clinic With Pharmacy Management System</h2>
            <p>A comprehensive web-based platform for managing pharmacy inventory, patient records, prescriptions, point-of-sale, and clinic appointments — all in one secure system.</p>
        </div>
        <div class="login-brand-footer">
            <p>Mindoro State University — BSIT Capstone 2026</p>
        </div>
    </div>

    <!-- Form Panel -->
    <div class="login-form-side">
        <div class="login-form-box">
            <h3>Welcome Back</h3>
            <p>Sign in to access the pharmacy management system</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= sanitize($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= sanitize($success) ?></div>
            <?php endif; ?>

            

            <div class="tab-btns">
                <button class="tab-btn <?= $activeTab === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">Sign In</button>
                <button class="tab-btn <?= $activeTab === 'register' ? 'active' : '' ?>" onclick="switchTab('register')">Create Account</button>
            </div>

            <!-- Login Form -->
            <div class="tab-content <?= $activeTab === 'login' ? 'active' : '' ?>" id="tab-login">
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="you@example.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                            <label class="form-label" style="margin:0;">Password</label>
                            <a href="#" style="font-size:12px;color:var(--teal);text-decoration:none;">Forgot Password?</a>
                        </div>
                        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:20px;">
                        <input type="checkbox" id="remember" name="remember" style="accent-color:var(--teal);">
                        <label for="remember" style="font-size:13px;cursor:pointer;">Remember for 30 days</label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" style="justify-content:center;height:42px;">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                </form>
                <div class="login-divider"><span>or continue with</span></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <button class="btn btn-outline" style="justify-content:center;"><i class="fab fa-google"></i> Google</button>
                    <button class="btn btn-outline" style="justify-content:center;"><i class="fab fa-apple"></i> Apple</button>
                </div>
            </div>

            <!-- Register Form -->
            <div class="tab-content <?= $activeTab === 'register' ? 'active' : '' ?>" id="tab-register">
                <form method="POST">
                    <input type="hidden" name="action" value="register">
                    <div class="form-row form-row-2">
                        <div class="form-group">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-control" placeholder="Juan" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-control" placeholder="Dela Cruz" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address *</label>
                        <input type="email" name="email" class="form-control" placeholder="you@example.com" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" class="form-control" placeholder="09XX XXX XXXX">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password *</label>
                        <input type="password" name="password" class="form-control" placeholder="Min. 8 characters" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-teal w-100" style="justify-content:center;height:42px;">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>
                </form>
            </div>

            <p style="text-align:center;font-size:12px;color:var(--gray);margin-top:20px;">
                <a href="index.php" style="color:var(--teal);text-decoration:none;"><i class="fas fa-arrow-left"></i> Back to Home</a>
            </p>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelector(`#tab-${tab}`).classList.add('active');
    document.querySelectorAll('.tab-btn')[tab === 'login' ? 0 : 1].classList.add('active');
}
</script>
</body>
</html>
