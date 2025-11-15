// Configuration Page JavaScript
// ==============================

// Change Password Form
document.getElementById('form-change-password').addEventListener('submit', async function(e) {
    e.preventDefault();
    showLoading();

    const formData = new FormData(this);
    const data = Object.fromEntries(formData);

    const result = await fetchAPI('/api/update-password.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
        this.reset();
    } else {
        showToast(result.message || 'Gagal mengupdate kredensial', 'danger');
    }
});

// GenieACS Form
document.getElementById('form-genieacs').addEventListener('submit', async function(e) {
    e.preventDefault();
    showLoading();

    const formData = new FormData(this);
    const data = Object.fromEntries(formData);

    const result = await fetchAPI('/api/test-genieacs.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast(result?.message || 'Koneksi gagal', 'danger');
    }
});

// MikroTik Form
document.getElementById('form-mikrotik').addEventListener('submit', async function(e) {
    e.preventDefault();
    showLoading();

    const formData = new FormData(this);
    const data = Object.fromEntries(formData);

    const result = await fetchAPI('/api/test-mikrotik.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast(result?.message || 'Koneksi gagal', 'danger');
    }
});

// Telegram Form
document.getElementById('form-telegram').addEventListener('submit', async function(e) {
    e.preventDefault();
    showLoading();

    const formData = new FormData(this);
    const data = Object.fromEntries(formData);

    const result = await fetchAPI('/api/test-telegram.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast(result?.message || 'Koneksi gagal', 'danger');
    }
});

// Save GenieACS Configuration
async function saveGenieACS() {
    const form = document.getElementById('form-genieacs');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    showLoading();

    const result = await fetchAPI('/api/save-genieacs.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
    } else {
        showToast(result.message || 'Gagal menyimpan konfigurasi', 'danger');
    }
}

// Save MikroTik Configuration
async function saveMikroTik() {
    const form = document.getElementById('form-mikrotik');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    showLoading();

    const result = await fetchAPI('/api/save-mikrotik.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
    } else {
        showToast(result.message || 'Gagal menyimpan konfigurasi', 'danger');
    }
}

// Save Telegram Configuration
async function saveTelegram() {
    const form = document.getElementById('form-telegram');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    showLoading();

    const result = await fetchAPI('/api/save-telegram.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
    } else {
        showToast(result.message || 'Gagal menyimpan konfigurasi', 'danger');
    }
}
