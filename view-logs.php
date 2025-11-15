<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Client Logs Viewer';
$currentPage = 'logs';

include __DIR__ . '/views/layouts/header.php';
?>

<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Client-Side Logs</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label>Lines to show:</label>
                        <select id="lines-select" class="form-control">
                            <option value="50">Last 50 lines</option>
                            <option value="100" selected>Last 100 lines</option>
                            <option value="200">Last 200 lines</option>
                            <option value="500">Last 500 lines</option>
                            <option value="1000">Last 1000 lines</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Filter by level:</label>
                        <select id="level-select" class="form-control">
                            <option value="">All Levels</option>
                            <option value="ERROR">ERROR only</option>
                            <option value="WARN">WARN only</option>
                            <option value="INFO">INFO only</option>
                            <option value="LOG">LOG only</option>
                            <option value="DEBUG">DEBUG only</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button class="btn btn-primary me-2" onclick="loadLogs()">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                        <button class="btn btn-success me-2" onclick="autoRefresh()">
                            <i class="bi bi-play-fill"></i> <span id="auto-refresh-text">Start Auto-Refresh</span>
                        </button>
                        <button class="btn btn-danger" onclick="clearLogs()">
                            <i class="bi bi-trash"></i> Clear Logs
                        </button>
                    </div>
                </div>

                <div class="alert alert-info">
                    <small><i class="bi bi-info-circle"></i> Logs are captured from browser console automatically. Only errors, warnings, and important messages are sent to server.</small>
                </div>

                <div id="logs-container" style="max-height: 600px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; font-family: 'Consolas', 'Monaco', monospace; font-size: 13px;">
                    <p class="text-center text-muted">Loading logs...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.log-entry {
    padding: 4px 0;
    border-bottom: 1px solid #333;
}
.log-entry:last-child {
    border-bottom: none;
}
.log-timestamp {
    color: #858585;
    font-weight: normal;
}
.log-level-ERROR {
    color: #f48771;
    font-weight: bold;
}
.log-level-WARN {
    color: #dcdcaa;
    font-weight: bold;
}
.log-level-INFO {
    color: #4fc1ff;
}
.log-level-LOG {
    color: #d4d4d4;
}
.log-level-DEBUG {
    color: #b5cea8;
}
.log-url {
    color: #9cdcfe;
}
.log-message {
    color: #ce9178;
    white-space: pre-wrap;
    word-break: break-all;
}
</style>

<script>
let autoRefreshInterval = null;

function loadLogs() {
    const lines = document.getElementById('lines-select').value;
    const level = document.getElementById('level-select').value;

    let url = '/api/get-client-log.php?lines=' + lines;
    if (level) {
        url += '&level=' + level;
    }

    fetch(url)
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('logs-container');

            if (!data.success) {
                container.innerHTML = '<p class="text-danger">Error: ' + data.message + '</p>';
                return;
            }

            if (data.logs.length === 0) {
                container.innerHTML = '<p class="text-muted">No logs found</p>';
                return;
            }

            let html = '<div style="margin-bottom: 10px; color: #858585; border-bottom: 1px solid #444; padding-bottom: 5px;">';
            html += '<strong>Log File:</strong> ' + data.logFile + ' | ';
            html += '<strong>Total Lines:</strong> ' + data.totalLines;
            html += '</div>';

            data.logs.forEach(log => {
                html += '<div class="log-entry">';
                if (log.timestamp) {
                    html += '<span class="log-timestamp">[' + log.timestamp + ']</span> ';
                }
                html += '<span class="log-level-' + log.level + '">[' + log.level + ']</span> ';
                if (log.url) {
                    html += '<span class="log-url">[' + log.url + ']</span> ';
                }
                html += '<span class="log-message">' + escapeHtml(log.message) + '</span>';
                html += '</div>';
            });

            container.innerHTML = html;

            // Scroll to bottom
            container.scrollTop = container.scrollHeight;
        })
        .catch(err => {
            document.getElementById('logs-container').innerHTML = '<p class="text-danger">Error loading logs: ' + err.message + '</p>';
        });
}

function autoRefresh() {
    const btn = document.getElementById('auto-refresh-text');

    if (autoRefreshInterval) {
        // Stop auto-refresh
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
        btn.textContent = 'Start Auto-Refresh';
        btn.parentElement.classList.remove('btn-danger');
        btn.parentElement.classList.add('btn-success');
        btn.previousElementSibling.className = 'bi bi-play-fill';
    } else {
        // Start auto-refresh
        autoRefreshInterval = setInterval(loadLogs, 3000); // Refresh every 3 seconds
        btn.textContent = 'Stop Auto-Refresh';
        btn.parentElement.classList.remove('btn-success');
        btn.parentElement.classList.add('btn-danger');
        btn.previousElementSibling.className = 'bi bi-stop-fill';
        loadLogs(); // Load immediately
    }
}

function clearLogs() {
    if (!confirm('Are you sure you want to clear all client logs? This action cannot be undone.')) {
        return;
    }

    fetch('/api/clear-client-log.php', {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Logs cleared successfully');
            loadLogs();
        } else {
            alert('Failed to clear logs: ' + data.message);
        }
    })
    .catch(err => {
        alert('Error clearing logs: ' + err.message);
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load logs on page load
document.addEventListener('DOMContentLoaded', function() {
    loadLogs();
});
</script>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
