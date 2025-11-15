<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Configuration';
$currentPage = 'configuration';

// Get existing configurations
$conn = getDBConnection();

$genieacs = $conn->query("SELECT * FROM genieacs_credentials LIMIT 1")->fetch_assoc();
$mikrotik = $conn->query("SELECT * FROM mikrotik_credentials LIMIT 1")->fetch_assoc();
$telegram = $conn->query("SELECT * FROM telegram_config LIMIT 1")->fetch_assoc();

include __DIR__ . '/views/layouts/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Konfigurasi kredensial untuk terhubung ke berbagai layanan.
        </div>
    </div>
</div>

<!-- Tab Navigation -->
<div class="row">
    <div class="col-12">
        <ul class="nav nav-tabs" id="configTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="site-tab" data-bs-toggle="tab" data-bs-target="#site-config" type="button" role="tab">
                    <i class="bi bi-person-lock"></i> Site Config
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="acs-tab" data-bs-toggle="tab" data-bs-target="#acs-config" type="button" role="tab">
                    <i class="bi bi-hdd-network"></i> ACS Config
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="mikrotik-tab" data-bs-toggle="tab" data-bs-target="#mikrotik-config" type="button" role="tab">
                    <i class="bi bi-ethernet"></i> MikroTik Config
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="bot-tab" data-bs-toggle="tab" data-bs-target="#bot-config" type="button" role="tab">
                    <i class="fab fa-telegram"></i> Bot Config
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="configTabsContent">

            <!-- Site Config Tab -->
            <div class="tab-pane fade show active" id="site-config" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header">
                        <i class="bi bi-person-lock"></i> Ganti Kredensial Login
                    </div>
                    <div class="card-body">
                        <form id="form-change-password">
                            <div class="form-group">
                                <label>Password Saat Ini</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Username Baru</label>
                                <input type="text" name="new_username" class="form-control"
                                       value="<?php echo $_SESSION['username']; ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Password Baru (kosongkan jika tidak ingin mengubah)</label>
                                <input type="password" name="new_password" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Konfirmasi Password Baru</label>
                                <input type="password" name="confirm_password" class="form-control">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Simpan Perubahan
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ACS Config Tab -->
            <div class="tab-pane fade" id="acs-config" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header">
                        <i class="bi bi-hdd-network"></i> Konfigurasi GenieACS
                        <?php if ($genieacs && $genieacs['is_connected']): ?>
                            <span class="badge online float-end">
                                Connected<?php if (!empty($genieacs['role'])): ?> / Role [<?php echo htmlspecialchars($genieacs['role']); ?>]<?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form id="form-genieacs">
                            <div class="form-group">
                                <label>Host</label>
                                <input type="text" name="host" class="form-control"
                                       value="<?php echo $genieacs['host'] ?? '192.168.1.1'; ?>"
                                       placeholder="192.168.1.1" required>
                            </div>
                            <div class="form-group">
                                <label>Port</label>
                                <input type="number" name="port" class="form-control"
                                       value="<?php echo $genieacs['port'] ?? '7557'; ?>"
                                       placeholder="7557" required>
                            </div>
                            <div class="form-group">
                                <label>Username (opsional)</label>
                                <input type="text" name="username" class="form-control"
                                       value="<?php echo $genieacs['username'] ?? ''; ?>"
                                       placeholder="Username">
                            </div>
                            <div class="form-group">
                                <label>Password (opsional)</label>
                                <input type="password" name="password" class="form-control"
                                       value="<?php echo $genieacs['password'] ?? ''; ?>"
                                       placeholder="Password">
                            </div>
                            <button type="submit" class="btn btn-success me-2">
                                <i class="bi bi-check-circle"></i> Test Connection
                            </button>
                            <button type="button" class="btn btn-primary" onclick="saveGenieACS()">
                                <i class="bi bi-save"></i> Simpan
                            </button>
                            <?php if ($genieacs && $genieacs['last_test']): ?>
                                <small class="text-muted d-block mt-2">
                                    Last test: <?php echo timeAgo($genieacs['last_test']); ?>
                                </small>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <!-- MikroTik Config Tab -->
            <div class="tab-pane fade" id="mikrotik-config" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header">
                        <i class="bi bi-ethernet"></i> Konfigurasi MikroTik
                        <?php if ($mikrotik && $mikrotik['is_connected']): ?>
                            <span class="badge online float-end">Connected</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form id="form-mikrotik">
                            <div class="form-group">
                                <label>Host</label>
                                <input type="text" name="host" class="form-control"
                                       value="<?php echo $mikrotik['host'] ?? ''; ?>"
                                       placeholder="192.168.1.1" required>
                            </div>
                            <div class="form-group">
                                <label>Port API</label>
                                <input type="number" name="port" class="form-control"
                                       value="<?php echo $mikrotik['port'] ?? '8728'; ?>"
                                       placeholder="8728" required>
                            </div>
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" name="username" class="form-control"
                                       value="<?php echo $mikrotik['username'] ?? ''; ?>"
                                       placeholder="admin" required>
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" name="password" class="form-control"
                                       value="<?php echo $mikrotik['password'] ?? ''; ?>"
                                       placeholder="Password" required>
                            </div>
                            <button type="submit" class="btn btn-success me-2">
                                <i class="bi bi-check-circle"></i> Test Connection
                            </button>
                            <button type="button" class="btn btn-primary" onclick="saveMikroTik()">
                                <i class="bi bi-save"></i> Simpan
                            </button>
                            <?php if ($mikrotik && $mikrotik['last_test']): ?>
                                <small class="text-muted d-block mt-2">
                                    Last test: <?php echo timeAgo($mikrotik['last_test']); ?>
                                </small>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Bot Config Tab -->
            <div class="tab-pane fade" id="bot-config" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header">
                        <i class="fab fa-telegram"></i> Konfigurasi Telegram Bot
                        <?php if ($telegram && $telegram['is_connected']): ?>
                            <span class="badge online float-end">Connected</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form id="form-telegram">
                            <div class="form-group">
                                <label>Bot Token</label>
                                <input type="text" name="bot_token" class="form-control"
                                       value="<?php echo $telegram['bot_token'] ?? ''; ?>"
                                       placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11" required>
                                <small class="text-muted">Dapatkan dari @BotFather</small>
                            </div>
                            <div class="form-group">
                                <label>Chat ID</label>
                                <input type="text" name="chat_id" class="form-control"
                                       value="<?php echo $telegram['chat_id'] ?? ''; ?>"
                                       placeholder="123456789" required>
                                <small class="text-muted">Dapatkan dari @userinfobot</small>
                            </div>
                            <button type="submit" class="btn btn-success me-2">
                                <i class="bi bi-check-circle"></i> Test Connection
                            </button>
                            <button type="button" class="btn btn-primary" onclick="saveTelegram()">
                                <i class="bi bi-save"></i> Simpan
                            </button>
                            <?php if ($telegram && $telegram['last_test']): ?>
                                <small class="text-muted d-block mt-2">
                                    Last test: <?php echo timeAgo($telegram['last_test']); ?>
                                </small>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Load external JavaScript -->
<script src="/assets/js/configuration.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
