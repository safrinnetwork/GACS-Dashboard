/**
 * Client-Side Logger
 * Captures console logs and sends them to server for debugging
 */

(function() {
    'use strict';

    // Configuration
    const LOG_ENDPOINT = '/api/save-client-log.php';
    const LOG_LEVELS = ['log', 'info', 'warn', 'error', 'debug'];
    const BATCH_SIZE = 10;
    const BATCH_INTERVAL = 5000; // 5 seconds

    // Log buffer for batching
    let logBuffer = [];
    let batchTimer = null;

    // Store original console methods
    const originalConsole = {};
    LOG_LEVELS.forEach(level => {
        originalConsole[level] = console[level];
    });

    /**
     * Send logs to server
     */
    function sendLogs(logs) {
        if (logs.length === 0) return;

        // Send each log individually (to maintain order and prevent data loss)
        logs.forEach(logData => {
            fetch(LOG_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(logData)
            }).catch(err => {
                // Ignore abort errors (normal when navigating away)
                if (err.name === 'AbortError') {
                    return;
                }
                // Silently fail for other errors - don't spam console
                originalConsole.error.call(console, 'Failed to send log to server:', err);
            });
        });
    }

    /**
     * Format log arguments
     */
    function formatArgs(args) {
        return Array.from(args).map(arg => {
            if (typeof arg === 'object') {
                try {
                    // Handle special cases
                    if (arg instanceof Error) {
                        return `${arg.name}: ${arg.message}\n${arg.stack}`;
                    }
                    if (arg instanceof HTMLElement) {
                        return `<${arg.tagName.toLowerCase()}${arg.id ? ' id="' + arg.id + '"' : ''}${arg.className ? ' class="' + arg.className + '"' : ''}>`;
                    }
                    return JSON.stringify(arg, null, 2);
                } catch (e) {
                    return String(arg);
                }
            }
            return String(arg);
        }).join(' ');
    }

    /**
     * Add log to buffer
     */
    function addToBuffer(level, args) {
        const logData = {
            level: level,
            message: formatArgs(args),
            url: window.location.pathname,
            timestamp: new Date().toISOString()
        };

        logBuffer.push(logData);

        // Send immediately if buffer is full
        if (logBuffer.length >= BATCH_SIZE) {
            flushBuffer();
        } else {
            // Schedule batch send
            if (!batchTimer) {
                batchTimer = setTimeout(flushBuffer, BATCH_INTERVAL);
            }
        }
    }

    /**
     * Flush buffer to server
     */
    function flushBuffer() {
        if (logBuffer.length > 0) {
            const logsToSend = [...logBuffer];
            logBuffer = [];
            sendLogs(logsToSend);
        }
        if (batchTimer) {
            clearTimeout(batchTimer);
            batchTimer = null;
        }
    }

    /**
     * Override console methods
     */
    LOG_LEVELS.forEach(level => {
        console[level] = function(...args) {
            // Call original console method
            originalConsole[level].apply(console, args);

            // Send to server (only for warn and error by default to reduce noise)
            if (level === 'error' || level === 'warn') {
                addToBuffer(level, args);
            } else {
                // For info/log/debug, only log if it contains specific keywords
                const message = formatArgs(args).toLowerCase();
                if (message.includes('error') ||
                    message.includes('failed') ||
                    message.includes('cannot') ||
                    message.includes('undefined') ||
                    message.includes('null') ||
                    message.includes('generateponportfields') ||
                    message.includes('generated') ||
                    message.includes('container') ||
                    message.includes('pon') ||
                    message.includes('not found')) {
                    addToBuffer(level, args);
                }
            }
        };
    });

    /**
     * Capture unhandled errors
     */
    window.addEventListener('error', function(event) {
        addToBuffer('error', [
            `Unhandled Error: ${event.message}`,
            `File: ${event.filename}:${event.lineno}:${event.colno}`,
            event.error ? event.error.stack : ''
        ]);
    });

    /**
     * Capture unhandled promise rejections
     */
    window.addEventListener('unhandledrejection', function(event) {
        addToBuffer('error', [
            `Unhandled Promise Rejection: ${event.reason}`,
            event.reason instanceof Error ? event.reason.stack : ''
        ]);
    });

    /**
     * Flush logs before page unload
     */
    window.addEventListener('beforeunload', function() {
        flushBuffer();
    });

    // Expose manual flush method
    window.flushClientLogs = flushBuffer;

    console.info('Client logger initialized - errors and warnings will be sent to server');
})();
