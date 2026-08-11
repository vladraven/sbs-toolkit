document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('sbs-app-root');
    if (!root) return;

    const state = {
        activeTab: 'license',
        modules: [
            { id: 'license', name: 'License & Health' },
            { id: 'backup', name: 'Smart Backup' },
            { id: 'performance', name: 'Setup & Performance' },
            { id: 'security', name: 'Security Hardening' },
            { id: 'deception', name: 'White Hat Deception' },
            { id: 'analytics', name: 'Smart Analytics' },
            { id: 'devops', name: 'DevOps & Remote' }
        ],
        settings: window.sbsData.settings || {},
        dynamicData: {}
    };

    function render() {
        const badgeClass = `sbs-header__badge--${window.sbsData.licenseStatus}`;
        
        root.innerHTML = `
            <header class="sbs-header">
                <h1 class="sbs-header__title">${state.settings.devops?.wl_plugin_name || 'SBS Toolkit'} <small style="font-size:12px;opacity:0.7;">v1.0.3</small></h1>
                <span class="sbs-header__badge ${badgeClass}">${window.sbsData.licenseStatus.toUpperCase()}</span>
            </header>
            <div class="sbs-layout">
                <nav class="sbs-nav">
                    ${state.modules.map(m => {
                        const isLocked = isModuleLocked(m.id);
                        const activeClass = state.activeTab === m.id ? 'sbs-nav__item--active' : '';
                        return `
                            <div class="sbs-nav__item ${activeClass}" data-tab="${m.id}">
                                <span>${m.name}</span>
                                ${isLocked ? '<span class="sbs-nav__lock-icon" title="Soft-Locked">🔒</span>' : ''}
                            </div>
                        `;
                    }).join('')}
                </nav>
                <main class="sbs-content">
                    ${renderContent()}
                </main>
            </div>
        `;

        bindEvents();
        triggerTabSpecificLogic();
    }

    function isModuleLocked(moduleId) {
        if (moduleId === 'license') return false;
        if (window.sbsData.isProOrTrial) return false;
        return window.sbsData.activeFreeModule !== moduleId;
    }

    function renderContent() {
        const isLocked = isModuleLocked(state.activeTab);
        let html = '';

        if (isLocked) {
            html += `
                <div class="sbs-banner-lock">
                    <div>
                        <strong>🔒 ${window.sbsData.i18n.lockedNotice}</strong>
                        ${window.sbsData.canSwitchModule ? 
                            `<br><button class="sbs-btn sbs-btn--secondary" id="sbs-btn-switch-module" style="margin-top:8px;">Set as Active Free Module</button>` : 
                            `<br><small>You can switch your active module again in ${window.sbsData.switchDaysLeft} days.</small>`
                        }
                    </div>
                </div>
            `;
        }

        switch (state.activeTab) {
            case 'license': html += renderLicenseTab(); break;
            case 'backup': html += renderBackupTab(isLocked); break;
            case 'performance': html += renderPerformanceTab(isLocked); break;
            case 'security': html += renderSecurityTab(isLocked); break;
            case 'deception': html += renderDeceptionTab(isLocked); break;
            case 'analytics': html += renderAnalyticsTab(isLocked); break;
            case 'devops': html += renderDevOpsTab(isLocked); break;
        }

        return html;
    }

    function renderLicenseTab() {
        return `
            <h2>License & Health Panel</h2>
            <div style="display:flex; gap:20px;">
                <div class="sbs-card" style="flex:1;">
                    <h3>License Management</h3>
                    <div class="sbs-form-group">
                        <label>Pro License Key</label>
                        <input type="text" id="sbs-license-key-input" class="sbs-input" placeholder="SBS1-XXXX-XXXX-XXXX-XXXX">
                    </div>
                    <button class="sbs-btn" id="sbs-btn-activate">Activate Pro License</button>
                </div>
                <div class="sbs-card" style="flex:1;">
                    <h3>Configuration</h3>
                    <p>Transfer settings between sites via JSON.</p>
                    <div style="display:flex; gap:10px; margin-top: 15px;">
                        <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-export">Export (JSON)</button>
                        <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-import" style="background:#059669;">Import</button>
                    </div>
                </div>
            </div>
            <div class="sbs-card">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3>Audit & Security Log</h3>
                    <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-refresh-logs" style="padding: 4px 10px; font-size:12px;">Refresh</button>
                </div>
                <div id="sbs-audit-log-container" style="margin-top:15px; max-height: 400px; overflow-y: auto;">
                    Loading logs...
                </div>
            </div>
        `;
    }

    function renderBackupTab(isLocked) {
        const safeUpdates = state.settings.backup?.safe_updates_enabled ? 'checked' : '';
        const ftpEnabled = state.settings.backup?.remote?.ftp_enabled ? 'checked' : '';
        const s3Enabled = state.settings.backup?.remote?.s3_enabled ? 'checked' : '';
        
        return `
            <h2>Smart Backup & Migration</h2>
            <form id="sbs-form-backup" data-module="backup">
                <div class="sbs-card">
                    <div class="sbs-form-group">
                        <label><input type="checkbox" name="safe_updates_enabled" value="1" ${safeUpdates} ${isLocked ? 'disabled' : ''}> Enable Safe Updates</label>
                    </div>
                    <h3 style="margin-top:24px;">Remote Storage (Optional)</h3>
                    <div class="sbs-form-group">
                        <label><input type="checkbox" name="remote[ftp_enabled]" value="1" ${ftpEnabled} ${isLocked ? 'disabled' : ''}> Upload to FTP</label>
                    </div>
                    <div class="sbs-form-group" style="margin-left: 20px;">
                        <input type="text" name="remote[ftp_host]" class="sbs-input" placeholder="FTP Host" value="${state.settings.backup?.remote?.ftp_host || ''}" ${isLocked ? 'disabled' : ''} style="margin-bottom: 5px;">
                        <input type="text" name="remote[ftp_user]" class="sbs-input" placeholder="FTP User" value="${state.settings.backup?.remote?.ftp_user || ''}" ${isLocked ? 'disabled' : ''} style="margin-bottom: 5px;">
                        <input type="password" name="remote[ftp_pass]" class="sbs-input" placeholder="FTP Password" value="${state.settings.backup?.remote?.ftp_pass || ''}" ${isLocked ? 'disabled' : ''} style="margin-bottom: 5px;">
                        <input type="text" name="remote[ftp_dir]" class="sbs-input" placeholder="Remote Directory" value="${state.settings.backup?.remote?.ftp_dir || '/'}" ${isLocked ? 'disabled' : ''}>
                    </div>
                    <div class="sbs-form-group" style="margin-top:15px;">
                        <label><input type="checkbox" name="remote[s3_enabled]" value="1" ${s3Enabled} ${isLocked ? 'disabled' : ''}> Upload to AWS S3 / Compatible</label>
                    </div>
                    <div class="sbs-form-group" style="margin-left: 20px;">
                        <input type="text" name="remote[s3_key]" class="sbs-input" placeholder="Access Key" value="${state.settings.backup?.remote?.s3_key || ''}" ${isLocked ? 'disabled' : ''} style="margin-bottom: 5px;">
                        <input type="password" name="remote[s3_secret]" class="sbs-input" placeholder="Secret Key" value="${state.settings.backup?.remote?.s3_secret || ''}" ${isLocked ? 'disabled' : ''} style="margin-bottom: 5px;">
                        <input type="text" name="remote[s3_bucket]" class="sbs-input" placeholder="Bucket Name" value="${state.settings.backup?.remote?.s3_bucket || ''}" ${isLocked ? 'disabled' : ''} style="margin-bottom: 5px;">
                        <input type="text" name="remote[s3_region]" class="sbs-input" placeholder="Region" value="${state.settings.backup?.remote?.s3_region || ''}" ${isLocked ? 'disabled' : ''}>
                    </div>
                    <button type="submit" class="sbs-btn" style="margin-top:16px;" ${isLocked ? 'disabled' : ''}>Save Settings</button>
                </div>
            </form>
            <div class="sbs-card">
                <h3>Manual Action</h3>
                <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-manual-backup" ${isLocked ? 'disabled' : ''}>Run Full Backup Now</button>
                <div id="sbs-backup-status" style="margin-top:10px; font-weight:bold;"></div>
            </div>
            <div class="sbs-card">
                <h3>Backup Archives</h3>
                <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-refresh-backups" style="margin-bottom:10px;">Refresh List</button>
                <div id="sbs-backup-list" style="margin-top:10px; overflow-x:auto;">Loading...</div>
            </div>
        `;
    }

    function renderPerformanceTab(isLocked) {
        const set = state.settings.performance || {};
        return `
            <h2>Setup & Performance</h2>
            <form id="sbs-form-performance" data-module="performance">
                <div class="sbs-card">
                    <h3>Minification & Cleanup</h3>
                    <div class="sbs-form-group">
                        <label><input type="checkbox" name="clean_head" value="1" ${set.clean_head ? 'checked' : ''} ${isLocked ? 'disabled' : ''}> Clean WordPress &lt;head&gt;</label>
                    </div>
                    <div class="sbs-form-group">
                        <label><input type="checkbox" name="minify_html" value="1" ${set.minify_html ? 'checked' : ''} ${isLocked ? 'disabled' : ''}> Enable HTML Minification</label>
                    </div>
                    <div class="sbs-form-group">
                        <label><input type="checkbox" name="minify_inline_js_css" value="1" ${set.minify_inline_js_css ? 'checked' : ''} ${isLocked ? 'disabled' : ''}> Enable Inline JS & CSS Compression</label>
                    </div>
                    <h3 style="margin-top:24px;">Image Optimization & Lazy Load</h3>
                    <div class="sbs-form-group">
                        <label>On-the-fly Image Conversion Format</label>
                        <select name="image_format" class="sbs-input" ${isLocked ? 'disabled' : ''}>
                            <option value="original" ${set.image_format === 'original' || !set.image_format ? 'selected' : ''}>Original (Keep as JPEG/PNG)</option>
                            <option value="webp" ${set.image_format === 'webp' ? 'selected' : ''}>Convert to WebP</option>
                            <option value="avif" ${set.image_format === 'avif' ? 'selected' : ''}>Convert to AVIF</option>
                        </select>
                    </div>
                    <div class="sbs-form-group">
                        <label><input type="checkbox" name="disable_huge_images" value="1" ${set.disable_huge_images ? 'checked' : ''} ${isLocked ? 'disabled' : ''}> Disable huge thumbnail sizes</label>
                    </div>
                    <h3 style="margin-top:24px;">Custom Code Injection</h3>
                    <div class="sbs-form-group">
                        <label>Custom CSS</label>
                        <textarea name="custom_css" class="sbs-input" style="height:100px; font-family:monospace;" ${isLocked ? 'disabled' : ''}>${set.custom_css || ''}</textarea>
                    </div>
                    <div class="sbs-form-group">
                        <label>Custom JS</label>
                        <textarea name="custom_js" class="sbs-input" style="height:100px; font-family:monospace;" ${isLocked ? 'disabled' : ''}>${set.custom_js || ''}</textarea>
                    </div>
                    <button type="submit" class="sbs-btn" style="margin-top:16px;" ${isLocked ? 'disabled' : ''}>Save Settings</button>
                </div>
            </form>
            <div class="sbs-card">
                <h3>Interactive Media Cleaner</h3>
                <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-scan-media" ${isLocked ? 'disabled' : ''}>Scan Orphaned Media</button>
                <div id="sbs-media-results" style="margin-top:16px;"></div>
            </div>
            <div class="sbs-card">
                <h3>Page Cache Engine</h3>
                <div style="display:flex; gap:10px;">
                    <button class="sbs-btn" id="sbs-btn-toggle-cache" data-enable="1" ${isLocked ? 'disabled' : ''}>Install Cache Drop-in</button>
                    <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-toggle-cache-off" data-enable="0" style="background:#dc2626;" ${isLocked ? 'disabled' : ''}>Remove Drop-in</button>
                    <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-purge-cache" ${isLocked ? 'disabled' : ''}>Purge All Cache</button>
                </div>
            </div>
        `;
    }

    function renderSecurityTab(isLocked) {
        const set = state.settings.security || {};
        return `
            <h2>Security Hardening</h2>
            <form id="sbs-form-security" data-module="security">
                <div class="sbs-card">
                    <div class="sbs-form-group">
                        <label><input type="checkbox" name="disable_xmlrpc" value="1" ${set.disable_xmlrpc ? 'checked' : ''} ${isLocked ? 'disabled' : ''}> Disable XML-RPC</label>
                    </div>
                    <div class="sbs-form-group">
                        <label><input type="checkbox" name="disable_app_passwords" value="1" ${set.disable_app_passwords ? 'checked' : ''} ${isLocked ? 'disabled' : ''}> Disable Application Passwords</label>
                    </div>
                    <div class="sbs-form-group">
                        <label><input type="checkbox" name="block_external_admins" value="1" ${set.block_external_admins !== 0 ? 'checked' : ''} ${isLocked ? 'disabled' : ''}> Block unauthorized Admin creation</label>
                    </div>
                    <div class="sbs-form-group">
                        <label>Custom Login URL Slug</label>
                        <input type="text" name="custom_login_slug" class="sbs-input" value="${set.custom_login_slug || ''}" placeholder="my-secret-login" ${isLocked ? 'disabled' : ''}>
                    </div>
                    <button type="submit" class="sbs-btn" ${isLocked ? 'disabled' : ''}>Save Security Settings</button>
                </div>
            </form>
            <div class="sbs-card">
                <h3>CVE Scanner</h3>
                <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-cve-scan" ${isLocked ? 'disabled' : ''}>Run Vulnerability Scan</button>
                <div id="sbs-cve-results" style="margin-top:16px;"></div>
            </div>
            <div class="sbs-card">
                <h3>Session Management</h3>
                <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-reset-sessions" style="background:#dc2626;" ${isLocked ? 'disabled' : ''}>Reset All Sessions</button>
            </div>
        `;
    }

    function renderDeceptionTab(isLocked) {
        if (window.sbsData.licenseStatus === 'free') {
            return `<h2>White Hat Deception</h2><div class="sbs-card"><p>This module requires a Pro or Trial license to operate.</p></div>`;
        }
        
        const set = state.settings.deception || {};
        return `
            <h2>White Hat Deception</h2>
            <form id="sbs-form-deception" data-module="deception">
                <div class="sbs-card">
                    <div class="sbs-form-group">
                        <label>Honeypot Paths (Comma separated)</label>
                        <input type="text" name="honeypot_paths" class="sbs-input" value="${set.honeypot_paths || '/shell.php,/backup.sql,/admin/config.php,/wp-config.bak'}" ${isLocked ? 'disabled' : ''}>
                    </div>
                    <button type="submit" class="sbs-btn" ${isLocked ? 'disabled' : ''}>Save Settings</button>
                </div>
            </form>
            <div class="sbs-card">
                <h3>Currently Banned IPs</h3>
                <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-load-bans" ${isLocked ? 'disabled' : ''}>Refresh Ban List</button>
                <div id="sbs-ban-list" style="margin-top:16px;"></div>
            </div>
        `;
    }

    function renderAnalyticsTab(isLocked) {
        const set = state.settings.analytics || {};
        return `
            <h2>Smart Analytics</h2>
            <form id="sbs-form-analytics" data-module="analytics">
                <div class="sbs-card">
                    <div class="sbs-form-group">
                        <label><input type="checkbox" name="enabled" value="1" ${set.enabled ? 'checked' : ''} ${isLocked ? 'disabled' : ''}> Enable Frontend JS Tracker</label>
                    </div>
                    <button type="submit" class="sbs-btn" style="margin-bottom: 20px;" ${isLocked ? 'disabled' : ''}>Save Settings</button>
                </div>
            </form>
            <div class="sbs-card">
                <div style="display:flex; gap:10px; margin-bottom:20px; align-items:center;">
                    <select id="sbs-stat-range" class="sbs-input" style="width:150px; margin-bottom:0;">
                        <option value="7">Last 7 Days</option>
                        <option value="30">Last 30 Days</option>
                    </select>
                    <select id="sbs-stat-os" class="sbs-input" style="width:150px; margin-bottom:0;"><option value="">All OS</option></select>
                    <select id="sbs-stat-browser" class="sbs-input" style="width:150px; margin-bottom:0;"><option value="">All Browsers</option></select>
                    <select id="sbs-stat-country" class="sbs-input" style="width:150px; margin-bottom:0;"><option value="">All Countries</option></select>
                    <button class="sbs-btn" id="sbs-btn-apply-filters">Apply Filters</button>
                </div>
                <div id="sbs-analytics-dashboard">Loading dashboard...</div>
            </div>
        `;
    }

    function renderDevOpsTab(isLocked) {
        const set = state.settings.devops || {};
        return `
            <h2>DevOps & Remote Control</h2>
            <form id="sbs-form-devops" data-module="devops">
                <div class="sbs-card">
                    <h3>White-Label</h3>
                    <div class="sbs-form-group">
                        <label>Custom Plugin Name</label>
                        <input type="text" name="wl_plugin_name" class="sbs-input" value="${set.wl_plugin_name || ''}" ${isLocked ? 'disabled' : ''}>
                    </div>
                    <div class="sbs-form-group">
                        <label>Custom Author Name</label>
                        <input type="text" name="wl_author_name" class="sbs-input" value="${set.wl_author_name || ''}" ${isLocked ? 'disabled' : ''}>
                    </div>
                    <h3 style="margin-top: 24px;">Uptime Monitor</h3>
                    <div class="sbs-form-group">
                        <label>Log Slow Requests (TTFB threshold in seconds)</label>
                        <input type="number" step="0.1" name="ttfb_threshold" class="sbs-input" value="${set.ttfb_threshold || 1.5}" ${isLocked ? 'disabled' : ''}>
                    </div>
                    <button type="submit" class="sbs-btn" style="margin-top: 16px;" ${isLocked ? 'disabled' : ''}>Save Settings</button>
                </div>
            </form>
            <div class="sbs-card">
                <h3>Remote REST API Control</h3>
                <button class="sbs-btn sbs-btn--secondary" id="sbs-btn-gen-token" ${isLocked ? 'disabled' : ''}>Generate New Token</button>
                <div id="sbs-token-display" style="margin-top:16px; font-family:monospace; font-weight:bold; word-break:break-all;"></div>
            </div>
        `;
    }

    function bindEvents() {
        root.querySelectorAll('.sbs-nav__item').forEach(el => {
            el.addEventListener('click', () => {
                state.activeTab = el.dataset.tab;
                render();
            });
        });

        const btnActivate = document.getElementById('sbs-btn-activate');
        if (btnActivate) btnActivate.addEventListener('click', () => {
            const key = document.getElementById('sbs-license-key-input').value;
            sendAjax('sbs_activate_license', { license_key: key }, (res) => {
                alert(res.data.message); location.reload();
            });
        });

        const btnSwitch = document.getElementById('sbs-btn-switch-module');
        if (btnSwitch) btnSwitch.addEventListener('click', () => {
            sendAjax('sbs_switch_free_module', { module_id: state.activeTab }, (res) => {
                alert(res.data.message); location.reload();
            });
        });

        const btnExport = document.getElementById('sbs-btn-export');
        if (btnExport) btnExport.addEventListener('click', () => {
            sendAjax('sbs_export_settings', {}, (res) => {
                const a = document.createElement('a');
                a.href = URL.createObjectURL(new Blob([res.data.json], { type: 'application/json' }));
                a.download = `sbs-settings-${new Date().getTime()}.json`;
                a.click();
            });
        });

        const btnImport = document.getElementById('sbs-btn-import');
        if (btnImport) btnImport.addEventListener('click', () => {
            const jsonStr = prompt("Paste your exported JSON settings here:");
            if (jsonStr) sendAjax('sbs_import_settings', { json_data: jsonStr }, (res) => { alert(res.data.message); location.reload(); });
        });

        ['sbs-btn-toggle-cache', 'sbs-btn-toggle-cache-off'].forEach(id => {
            const btn = document.getElementById(id);
            if (btn) btn.addEventListener('click', (e) => {
                sendAjax('sbs_toggle_page_cache', { enable: e.target.getAttribute('data-enable') }, (res) => alert(res.data.message));
            });
        });

        const btnPurge = document.getElementById('sbs-btn-purge-cache');
        if (btnPurge) btnPurge.addEventListener('click', () => {
            sendAjax('sbs_purge_page_cache', {}, (res) => alert(res.data.message));
        });

        const btnResetSessions = document.getElementById('sbs-btn-reset-sessions');
        if (btnResetSessions) btnResetSessions.addEventListener('click', () => {
            if(confirm('Log out everyone except you?')) sendAjax('sbs_reset_all_sessions', {}, (res) => alert(res.data.message));
        });

        root.querySelectorAll('form[id^="sbs-form-"]').forEach(form => {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const moduleId = form.getAttribute('data-module');
                const data = {};
                
                form.querySelectorAll('input, textarea, select').forEach(input => {
                    if (input.name.includes('[')) {
                        const match = input.name.match(/([^\[]+)\[([^\]]+)\]/);
                        if (match) {
                            if (!data[match[1]]) data[match[1]] = {};
                            data[match[1]][match[2]] = input.type === 'checkbox' ? (input.checked ? 1 : 0) : input.value;
                        }
                    } else {
                        data[input.name] = input.type === 'checkbox' ? (input.checked ? 1 : 0) : input.value;
                    }
                });

                sendAjax('sbs_save_module_settings', { module_id: moduleId, settings: data }, (res) => {
                    alert(res.data.message);
                    state.settings[moduleId] = data;
                    if (moduleId === 'devops') location.reload();
                });
            });
        });

        const btnBackup = document.getElementById('sbs-btn-manual-backup');
        if (btnBackup) btnBackup.addEventListener('click', () => {
            const statusDiv = document.getElementById('sbs-backup-status');
            statusDiv.innerText = 'Queuing backup job...';
            sendAjax('sbs_run_manual_backup', {}, (res) => {
                statusDiv.innerText = `Job Queued (ID: ${res.data.job_id}). Building archive in background.`;
                setTimeout(loadBackups, 3000);
            });
        });

        const btnRefreshBackups = document.getElementById('sbs-btn-refresh-backups');
        if (btnRefreshBackups) btnRefreshBackups.addEventListener('click', loadBackups);

        const btnScanMedia = document.getElementById('sbs-btn-scan-media');
        if (btnScanMedia) btnScanMedia.addEventListener('click', () => {
            const resDiv = document.getElementById('sbs-media-results');
            resDiv.innerText = 'Scanning...';
            sendAjax('sbs_scan_orphan_media', {}, (res) => {
                if (res.data.orphans.length === 0) { resDiv.innerText = 'No orphans found.'; return; }
                state.dynamicData.orphans = res.data.orphans.map(o => o.path);
                resDiv.innerHTML = `
                    <p>Found ${res.data.orphans.length} orphaned files.</p>
                    <ul style="max-height:200px; overflow-y:auto; font-size:12px; background:#f3f4f6; padding:10px;">
                        ${res.data.orphans.map(o => `<li>${o.url}</li>`).join('')}
                    </ul>
                    <button class="sbs-btn" id="sbs-btn-delete-media" style="background:#dc2626;">Delete All</button>
                `;
                document.getElementById('sbs-btn-delete-media').addEventListener('click', () => {
                    sendAjax('sbs_delete_orphan_media', { files: state.dynamicData.orphans }, (delRes) => resDiv.innerText = `Deleted ${delRes.data.deleted_count} files.`);
                });
            });
        });

        const btnCve = document.getElementById('sbs-btn-cve-scan');
        if (btnCve) btnCve.addEventListener('click', () => {
            const resDiv = document.getElementById('sbs-cve-results');
            resDiv.innerText = 'Scanning plugins...';
            sendAjax('sbs_run_cve_scan', {}, (res) => {
                resDiv.innerHTML = res.data.vulnerabilities.length === 0 
                    ? '<span style="color:green; font-weight:bold;">All clear.</span>' 
                    : `<ul style="color:#dc2626; font-weight:bold;">${res.data.vulnerabilities.map(v => `<li>${v.name} (Current: ${v.current_version} -> Safe: ${v.new_version})</li>`).join('')}</ul>`;
            });
        });

        const btnLoadBans = document.getElementById('sbs-btn-load-bans');
        if (btnLoadBans) btnLoadBans.addEventListener('click', loadBanList);

        const btnGenToken = document.getElementById('sbs-btn-gen-token');
        if (btnGenToken) btnGenToken.addEventListener('click', () => {
            if(confirm('Invalidate old token?')) sendAjax('sbs_generate_remote_token', {}, (res) => document.getElementById('sbs-token-display').innerText = res.data.token);
        });
    }

    function triggerTabSpecificLogic() {
        if (state.activeTab === 'license') {
            loadAuditLogs();
            const btnRefreshLogs = document.getElementById('sbs-btn-refresh-logs');
            if (btnRefreshLogs) btnRefreshLogs.addEventListener('click', loadAuditLogs);
        }
        if (state.activeTab === 'backup') loadBackups();
        if (state.activeTab === 'analytics' && !isModuleLocked('analytics')) {
            loadAnalyticsDashboard();
            const btnApply = document.getElementById('sbs-btn-apply-filters');
            if (btnApply) btnApply.addEventListener('click', loadAnalyticsDashboard);
        }
        if (state.activeTab === 'deception' && !isModuleLocked('deception')) loadBanList();
    }

    function loadAnalyticsDashboard() {
        const dash = document.getElementById('sbs-analytics-dashboard');
        if (!dash) return;
        
        const payload = {
            range: document.getElementById('sbs-stat-range')?.value || '7',
            os: document.getElementById('sbs-stat-os')?.value || '',
            browser: document.getElementById('sbs-stat-browser')?.value || '',
            country: document.getElementById('sbs-stat-country')?.value || ''
        };

        dash.innerHTML = '<span style="color:#64748b;">Loading data...</span>';

        sendAjax('sbs_get_analytics_dashboard', payload, (res) => {
            const data = res.data;
            
            const fillSelect = (id, options, current) => {
                const sel = document.getElementById(id);
                if (sel && sel.options.length <= 1) {
                    options.forEach(opt => {
                        const optEl = document.createElement('option');
                        optEl.value = opt;
                        optEl.textContent = opt;
                        if (opt === current) optEl.selected = true;
                        sel.appendChild(optEl);
                    });
                }
            };
            fillSelect('sbs-stat-os', data.filters.os, payload.os);
            fillSelect('sbs-stat-browser', data.filters.browser, payload.browser);
            fillSelect('sbs-stat-country', data.filters.country, payload.country);

            const renderTable = (title, rows) => {
                let t = `<div class="sbs-card" style="flex:1; margin-bottom:0; box-shadow:none; border:1px solid #e2e8f0;">
                            <h4 style="margin-top:0; color:#334155;">${title}</h4>
                            <table class="sbs-table"><tbody>`;
                rows.forEach(r => t += `<tr><td style="word-break: break-all;">${r.label || 'Unknown'}</td><td style="text-align:right; font-weight:bold; min-width: 40px;">${r.pv}</td></tr>`);
                if(rows.length === 0) t += `<tr><td colspan="2" style="color:#94a3b8;">No data</td></tr>`;
                t += `</tbody></table></div>`;
                return t;
            };

            dash.innerHTML = `
                <div style="display:flex; gap:20px; margin-bottom: 20px;">
                    <div style="background:#f1f5f9; padding:20px; border-radius:8px; flex:1; text-align:center;">
                        <div style="color:#64748b; font-weight:bold; margin-bottom:10px;">Pageviews</div>
                        <span style="font-size:32px; color:#0f172a;">${data.summary.pageviews}</span>
                    </div>
                    <div style="background:#f1f5f9; padding:20px; border-radius:8px; flex:1; text-align:center;">
                        <div style="color:#64748b; font-weight:bold; margin-bottom:10px;">Unique Sessions</div>
                        <span style="font-size:32px; color:#0f172a;">${data.summary.unique_sessions}</span>
                    </div>
                    <div style="background:#f1f5f9; padding:20px; border-radius:8px; flex:1; text-align:center;">
                        <div style="color:#64748b; font-weight:bold; margin-bottom:10px;">Avg Time (sec)</div>
                        <span style="font-size:32px; color:#0f172a;">${data.summary.avg_time_sec}</span>
                    </div>
                </div>
                <div style="display:flex; gap:20px; flex-wrap: wrap;">
                    ${renderTable('Top Referrers', data.referrers)}
                    ${renderTable('Top Outbound Links', data.outbound)}
                </div>
                <div style="display:flex; gap:20px; flex-wrap: wrap; margin-top:20px;">
                    ${renderTable('Top Countries', data.countries)}
                    ${renderTable('Operating Systems', data.oses)}
                    ${renderTable('Browsers', data.browsers)}
                </div>
            `;
        });
    }

    function loadAuditLogs() {
        const container = document.getElementById('sbs-audit-log-container');
        if (!container) return;
        container.innerHTML = '<span style="color:#64748b;">Fetching records...</span>';
        sendAjax('sbs_get_audit_logs', {}, (res) => {
            if (!res.data.logs || res.data.logs.length === 0) {
                container.innerHTML = '<span style="color:#64748b;">No audit records found.</span>'; return;
            }
            let html = `<table class="sbs-table"><thead><tr><th>Date</th><th>Module</th><th>Level</th><th>Message</th><th>Context</th></tr></thead><tbody>`;
            res.data.logs.forEach(log => {
                let contextStr = '';
                try {
                    const ctx = JSON.parse(log.context);
                    if (Object.keys(ctx).length > 0) contextStr = Object.entries(ctx).map(([k, v]) => `<strong>${k}:</strong> ${v}`).join(' | ');
                } catch(e) {}
                html += `<tr><td style="white-space:nowrap; color:#64748b;">${log.created_at}</td><td><span style="background:#e2e8f0; padding:2px 6px; border-radius:4px; font-size:11px; text-transform:uppercase;">${log.module}</span></td><td class="sbs-log-level--${log.level}">${log.level}</td><td>${log.message}</td><td style="font-size:11px; color:#475569;">${contextStr}</td></tr>`;
            });
            container.innerHTML = html + `</tbody></table>`;
        });
    }

    function loadBackups() {
        const listDiv = document.getElementById('sbs-backup-list');
        if (!listDiv) return;
        listDiv.innerHTML = 'Loading archives...';
        sendAjax('sbs_get_backups', {}, (res) => {
            if (!res.data.backups || res.data.backups.length === 0) {
                listDiv.innerHTML = '<p style="color:#64748b;">No backup archives found in sbs-storage.</p>'; return;
            }
            let html = `<table class="sbs-table"><thead><tr><th>Filename</th><th>Size</th><th>Date</th><th style="text-align:right;">Actions</th></tr></thead><tbody>`;
            res.data.backups.forEach(b => {
                html += `<tr><td style="font-family:monospace;">${b.name}</td><td>${b.size}</td><td>${b.date}</td><td style="text-align:right;">
                    <button class="sbs-btn sbs-btn--secondary sbs-act-dl" data-file="${b.name}" style="padding:4px 8px; font-size:11px; background:#059669;">Download</button>
                    <button class="sbs-btn sbs-btn--secondary sbs-act-ren" data-file="${b.name}" style="padding:4px 8px; font-size:11px;">Rename</button>
                    <button class="sbs-btn sbs-btn--secondary sbs-act-del" data-file="${b.name}" style="padding:4px 8px; font-size:11px; background:#dc2626;">Delete</button>
                </td></tr>`;
            });
            listDiv.innerHTML = html + `</tbody></table>`;
            listDiv.querySelectorAll('.sbs-act-dl').forEach(btn => btn.addEventListener('click', (e) => window.location.href = window.sbsData.ajaxUrl.replace('admin-ajax.php', '') + `admin.php?page=sbs-toolkit&sbs_download_backup=${e.target.getAttribute('data-file')}&nonce=${window.sbsData.nonce}`));
            listDiv.querySelectorAll('.sbs-act-ren').forEach(btn => btn.addEventListener('click', (e) => {
                const oldFile = e.target.getAttribute('data-file');
                const newFile = prompt('Enter new filename (.zip / .tar.gz):', oldFile);
                if (newFile && newFile !== oldFile) sendAjax('sbs_rename_backup', { old_name: oldFile, new_name: newFile }, () => loadBackups());
            }));
            listDiv.querySelectorAll('.sbs-act-del').forEach(btn => btn.addEventListener('click', (e) => {
                if(confirm('Delete backup?')) sendAjax('sbs_delete_backup', { filename: e.target.getAttribute('data-file') }, () => loadBackups());
            }));
        });
    }

    function loadBanList() {
        const listDiv = document.getElementById('sbs-ban-list');
        if (!listDiv) return;
        listDiv.innerText = 'Loading...';
        sendAjax('sbs_get_banned_ips', {}, (res) => {
            if (res.data.banned_ips.length === 0) { listDiv.innerText = 'No IPs currently banned.'; return; }
            listDiv.innerHTML = res.data.banned_ips.map(ip => `<div style="display:flex; justify-content:space-between; padding:8px; border-bottom:1px solid #e5e7eb;"><span>${ip}</span><button class="sbs-btn sbs-btn--secondary sbs-unban-btn" data-ip="${ip}" style="padding:4px 8px; font-size:12px;">Unban</button></div>`).join('');
            listDiv.querySelectorAll('.sbs-unban-btn').forEach(btn => btn.addEventListener('click', (e) => sendAjax('sbs_unban_ip', { ip: e.target.getAttribute('data-ip') }, () => loadBanList())));
        });
    }

    function sendAjax(action, payload, callback) {
        const body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', window.sbsData.nonce);
        for (let key in payload) {
            if (typeof payload[key] === 'object' && payload[key] !== null) {
                for (let subKey in payload[key]) body.append(`${key}[${subKey}]`, payload[key][subKey]);
            } else {
                body.append(key, payload[key]);
            }
        }
        fetch(window.sbsData.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
        .then(r => r.json()).then(res => res.success ? callback(res) : alert(res.data.message || window.sbsData.i18n.error)).catch(() => alert('Request failed.'));
    }

    render();
});