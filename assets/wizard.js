/* No credentials, customer records or migration state are stored in browser storage. */
(() => {
    "use strict";
    const root = document.getElementById("whmcstofossbilling");
    if (!root) return;
    const $ = id => document.getElementById(`wm-${id}`);
    let source = null, profile = null, defaultProfile = null, run = null, pumping = false, preparing = false;
    let observer = null, replacement = null, watchVersion = 0, startupRecoveryDone = false;
    let chosenKind = null, needUpgrade = false, backup = null, backupFile = null, backupPaused = false;
    const counts = new Map();
    function timing(name, fallback) {
        const value = Number(root.dataset[name]);
        return Number.isFinite(value) && value >= 0 ? value : fallback;
    }
    function batchRequestId() {
        const webcrypto = globalThis.crypto;
        if (typeof webcrypto?.randomUUID === "function") return webcrypto.randomUUID().replace(/-/g, "");
        const bytes = new Uint8Array(16);
        if (typeof webcrypto?.getRandomValues === "function") webcrypto.getRandomValues(bytes);
        else for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        return Array.from(bytes, byte => byte.toString(16).padStart(2, "0")).join("");
    }
    const element = (tag, text, className = "") => {
        const node = document.createElement(tag);
        if (text !== undefined && text !== null) node.textContent = String(text);
        node.className = className;
        return node;
    };
    const notice = (text, danger = false) => {
        $("notice").className = `alert ${danger ? "alert-danger" : "alert-info"}`;
        $("notice").textContent = text;
    };
    function scrollToTop() {
        const behavior = matchMedia("(prefers-reduced-motion: reduce)").matches ? "instant" : "smooth";
        // Admin themes can scroll a content container instead of the document.
        for (let node = root.parentElement; node; node = node.parentElement) {
            if (node.scrollHeight > node.clientHeight && /auto|scroll/.test(getComputedStyle(node).overflowY)) {
                node.scrollTo({ top: 0, left: 0, behavior });
            }
        }
        window.scrollTo({ top: 0, left: 0, behavior });
    }
    function preparation(label, completed = null, total = null, active = true) {
        $("preparation").classList.remove("d-none");
        $("preparation").setAttribute("aria-busy", String(active));
        $("preparation-label").textContent = label;
        const measurable = total !== null && total > 0;
        const percent = measurable ? Math.floor(completed / total * 100) : null;
        $("preparation-percent").textContent = percent === null ? "" : `${percent}%`;
        $("preparation-bar").style.width = `${percent === null ? 100 : percent}%`;
        $("preparation-bar").classList.toggle("wm-working", active);
        $("preparation-bar").classList.toggle("wm-indeterminate", active && !measurable);
        if (percent === null) $("preparation-track").removeAttribute("aria-valuenow");
        else $("preparation-track").setAttribute("aria-valuenow", String(percent));
        $("preparation-track").setAttribute("aria-valuetext", label);
    }
    function stopPreparation() {
        $("preparation").setAttribute("aria-busy", "false");
        $("preparation-bar").classList.remove("wm-working", "wm-indeterminate");
    }
    function repairStatus(text, danger = false) {
        const node = $("repair-status");
        node.className = `small mt-2 ${danger ? "text-danger" : "text-success"}`;
        node.textContent = text;
    }
    function repairProgress(active) {
        $("repair-progress").classList.toggle("d-none", !active);
        $("repair-progress").setAttribute("aria-busy", String(active));
        $("repair-progress-bar").classList.toggle("wm-working", active);
        $("repair-progress-bar").classList.toggle("wm-indeterminate", active);
        $("repair-progress-track").setAttribute("aria-valuetext", active ? "Repair in progress; the server reports one final result, so no percentage is shown" : "Idle");
    }
    function stopWatching() { clearTimeout(observer); observer = null; ++watchVersion; }
    function hideRecovery() { $("recovery-banner").classList.add("d-none"); }
    function showSourceChoice() {
        pumping = false; stopWatching(); hideRecovery();
        chosenKind = null; $("source-choice").classList.remove("d-none");
        root.querySelectorAll("[data-panel]").forEach(panel => panel.classList.add("d-none"));
        $("navigation").classList.add("d-none"); $("limits").classList.add("d-none"); $("schema-warning").classList.add("d-none");
        $("notice").classList.add("d-none"); $("preparation").classList.add("d-none"); $("top-progress").classList.add("d-none");
        scrollToTop();
    }
    function hideOverwrite() {
        $("overwrite-panel").classList.add("d-none");
        $("overwrite-acknowledged").checked = false;
        $("overwrite-confirm").value = "";
        $("overwrite-tables").replaceChildren();
    }
    function overwriteIntent() {
        if ($("overwrite-panel").classList.contains("d-none")) return false;
        const checked = $("overwrite-acknowledged").checked;
        const phrase = $("overwrite-confirm").value.trim();
        if (!checked && phrase === "") return false;
        if (!checked || phrase !== "OVERWRITE FOSSBILLING DATA") throw new Error("Provide both the overwrite acknowledgement and the exact phrase OVERWRITE FOSSBILLING DATA. No run was created.");
        return true;
    }
    function confirmOverwrite() {
        return window.confirm("Permanently delete every row in the listed FOSSBilling business tables, including genuine customer data and failed-migration leftovers? This cannot be undone. Table structures will not change.");
    }
    function watchRun() {
        stopWatching();
        if (!run || run.status !== "running" || pumping) return;
        const id = run.id;
        const version = watchVersion;
        observer = setTimeout(async () => {
            if (pumping || run?.id !== id || run.status !== "running") return;
            try {
                if (!document.hidden) {
                    const updated = await api("run_status", { run_id: id });
                    if (run?.id !== id || pumping || watchVersion !== version) return;
                    run = updated; renderRun();
                }
                watchRun();
            } catch (error) {
                if (watchVersion !== version || run?.id !== id) return;
                $("progress-bar").classList.remove("wm-working");
                if (error.rateLimited) { notice(error.message, true); scrollToTop(); return; }
                notice(`${error.message} Automatic status refresh stopped. Use Refresh status after resolving this.`, true);
            }
        }, Math.max(1000, timing("statusInterval", 10000)));
    }
    function sessionError(message) {
        const error = new Error(message);
        error.kind = "session";
        return error;
    }
    async function api(method, data = {}, download = false) {
        const endpoint = new URL(`${root.dataset.api.replace(/\/$/, "")}/${method}`, location.href);
        if (endpoint.origin !== location.origin) throw sessionError("The wizard API address does not match this FOSSBilling address. Open the wizard using the configured FOSSBilling address and sign in again. No request was sent.");
        let response;
        try {
            response = await fetch(endpoint.href, {
                method: "POST", credentials: "same-origin", cache: "no-store", redirect: "error",
                headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": root.dataset.csrf },
                body: JSON.stringify(data), signal: AbortSignal.timeout(method.startsWith("backup_") ? 120000 : 30000)
            });
        } catch (error) {
            if (error.name === "TimeoutError" || error.name === "AbortError") throw error;
            throw new Error("The FOSSBilling API request could not be completed. Check your connection and open the wizard at the configured FOSSBilling address. Redirects are refused; sign in again if your session expired. No automatic retry was sent.");
        }
        if (download && response.ok && response.headers.get("Content-Disposition")?.startsWith("attachment")) return response.blob();
        let result;
        try { result = await response.json(); } catch {
            throw sessionError("FOSSBilling returned an unexpected response instead of API data. Your administrator session may have expired. Sign in again and reload the wizard; if this continues, check the FOSSBilling URL and server routing.");
        }
        if (response.status === 401 && !result?.error) throw sessionError("FOSSBilling could not authenticate your administrator session. Sign in to FOSSBilling again, reload this wizard, and select your saved source. This is not a WHMCS database password error.");
        if (!result || typeof result !== "object") throw new Error("FOSSBilling returned an invalid API response. Stop and check the server before retrying.");
        if (!response.ok || result.error) {
            const message = result.error?.message || "Request failed. Stop and review the server status before retrying.";
            if (response.status === 429 || /rate limit exceeded/i.test(message)) {
                const seconds = Number(response.headers.get("Retry-After"));
                const wait = Number.isFinite(seconds) && seconds > 0 ? ` Wait about ${Math.ceil(seconds)} seconds, or reset the admin API counter under Security → Rate limits.` : " Wait for the hourly counter to recover, or reset it under Security → Rate limits.";
                const error = new Error(`FOSSBilling's API rate limit was reached. Automatic activity stopped. No further requests were sent.${wait}`);
                error.rateLimited = true;
                error.retryAfter = Number.isFinite(seconds) && seconds > 0 ? Math.ceil(seconds) : null;
                throw error;
            }
            if ([201, 202, 203, 204, 205, 206].includes(Number(result.error?.code)) || /^authentication failed$/i.test(message.trim())) {
                throw sessionError("FOSSBilling could not authenticate your administrator session. Sign in to FOSSBilling again, reload this wizard, and select your saved source. This is not a WHMCS database password error.");
            }
            if (/csrf token invalid|valid csrf token is required/i.test(message)) {
                throw sessionError("Your FOSSBilling security token is no longer valid. Reload the wizard and sign in again if prompted, then select your saved source. No security check was bypassed.");
            }
            const error = new Error(message);
            error.code = result.error?.code;
            throw error;
        }
        return result.result;
    }
    const handle = fn => async event => {
        event?.preventDefault();
        const button = event?.submitter || (event?.currentTarget?.tagName === "BUTTON" ? event.currentTarget : null);
        if (button) button.disabled = true;
        try { await fn(event); } catch (error) {
            pumping = false;
            stopPreparation();
            stopWatching();
            $("progress-bar").classList.remove("wm-working");
            notice(error.name === "TimeoutError" ? "The response timed out. No further batches were scheduled. Refresh run status before resuming." : error.message, true);
            if (error.pendingTables?.length) {
                const details = element("details");
                details.append(element("summary", `${error.pendingTables.length} tables still need count refresh`), element("p", error.pendingTables.join(", ")));
                $("notice").append(details);
            }
            if (error.kind === "session" || error.rateLimited || event?.currentTarget?.id === "wm-authorize") scrollToTop();
        } finally { if (button) button.disabled = false; }
    };
    function step(name) {
        if (name !== "progress") stopWatching();
        root.querySelectorAll("[data-panel]").forEach(panel => panel.classList.toggle("d-none", panel.dataset.panel !== name));
        root.querySelectorAll("[data-step]").forEach(button => {
            button.classList.toggle("btn-primary", button.dataset.step === name);
            button.classList.toggle("btn-outline-secondary", button.dataset.step !== name);
        });
        if (name === "progress") watchRun();
    }
    root.querySelectorAll("[data-step]").forEach(button => button.addEventListener("click", () => step(button.dataset.step)));
    function selectOptions(select, rows, placeholder) {
        select.replaceChildren(element("option", placeholder));
        select.firstChild.value = "";
        rows.forEach(([value, label]) => {
            const option = element("option", label);
            option.value = value;
            select.append(option);
        });
    }
    async function dashboard() {
        const data = await api("dashboard");
        needUpgrade = data.schema_upgrade_required;
        $("schema-warning").classList.toggle("d-none", !needUpgrade || !chosenKind);
        $("blockers").replaceChildren(...data.environment.blockers.map(text => element("li", text)));
        selectOptions($("sources"), data.sources.map(row => [row.id, `${row.label} · tested ${row.tested_at} UTC`]), "Select a tested source…");
        selectOptions($("runs"), data.runs.map(row => [row.id, `${row.created_at} UTC · ${row.mode} · ${row.status} · ${row.label}`]), "Select a run…");
        if (run && !data.runs.some(row => row.id === run.id)) {
            const option = element("option", `${run.mode} · ${run.status} · opened run`);
            option.value = run.id; $("runs").append(option);
        }
        if (source) $("sources").value = String(source.source_id);
        if (run) $("runs").value = run.id;
        if (!startupRecoveryDone) {
            startupRecoveryDone = true;
            const unfinished = data.runs.find(row => row.mode !== "dry_run" && ["running", "paused"].includes(row.status));
            if (unfinished) await openUnfinished(unfinished);
        }
    }
    $("schema-upgrade").addEventListener("click", handle(async () => {
        if (!window.confirm("Create missing whmcstofossbilling_* tables only? No core schema or business records will change.")) return;
        await api("schema_upgrade", { confirmation: "UPGRADE EXTENSION TABLES" });
        await dashboard(); notice("Extension tracking schema upgraded. No source connection or import ran.");
    }));
    async function destinationStatus() {
        const sourceId = source?.source_id || 0, mode = $("mode").value;
        const status = await api("destination_status", { source_id: sourceId, mode });
        if ((source?.source_id || 0) !== sourceId || $("mode").value !== mode) return;
        const existing = Object.entries(status.tables || {}).filter(([, value]) => Number(value.total) > 0);
        const showOverwrite = !status.schema_required && (!status.ready || existing.length > 0);
        $("destination-status").className = `alert ${status.ready ? "alert-success" : "alert-warning"}`;
        $("destination-status").textContent = status.schema_required
            ? "Install or upgrade the extension tracking tables using the button above before preparing a source."
            : status.ready && existing.length === 0
                ? "Ready: FOSSBilling is fresh or contains only migration-owned records. No destination backup reference is needed."
                : status.ready
                    ? "Ready to resume: this FOSSBilling installation contains migration-owned business records. Resume this source, or use the destructive overwrite option to start clean."
                    : `Unrelated business records or records from another migration were found in: ${status.unrelated_tables.join(", ")}. Use a fresh FOSSBilling installation, resume the original source, or confirm the destructive overwrite below. Nothing is deleted until that exact confirmation is submitted.`;
        if (!showOverwrite) { hideOverwrite(); return; }
        $("overwrite-panel").classList.remove("d-none");
        $("overwrite-description").textContent = status.ready
            ? "These rows are owned by this migration and can be resumed. Choose overwrite only if you want them permanently deleted before importing again."
            : "These rows block a new authorized run. Choosing overwrite permanently deletes them before the new paused run is created.";
        $("overwrite-tables").replaceChildren(...(existing.length
            ? existing.map(([name, value]) => element("li", `${name} — ${value.total} rows (${value.unowned} not owned by this migration, ${value.owned} owned by it)`))
            : [element("li", "The server reported blocking records; exact row counts were not returned.")]));
    }
    function showSetup(kind) {
        chosenKind = kind;
        $("source-choice").classList.add("d-none");
        $("navigation").classList.remove("d-none"); $("limits").classList.remove("d-none");
        $("schema-warning").classList.toggle("d-none", !needUpgrade);
        $("database-setup").classList.toggle("d-none", kind !== "database");
        $("upload-setup").classList.toggle("d-none", kind !== "sql_file");
        step("connection");
    }
    for (const [button, kind] of [["choose-database", "database"], ["choose-upload", "sql_file"]]) {
        $(button).addEventListener("click", handle(async () => {
            if (preparing || pumping) throw new Error("Wait for preparation or pause processing before changing source type.");
            if (source && (source.source_kind || "database") !== kind) {
                source = null; profile = null; counts.clear();
                $("next").disabled = true; $("upload-next").disabled = true;
                root.querySelector('[data-step="mapping"]').disabled = true;
                root.querySelector('[data-step="analysis"]').disabled = true;
                $("source-status").replaceChildren();
            }
            showSetup(kind); await destinationStatus();
        }));
    }
    $("change-source").addEventListener("click", handle(async () => {
        if (preparing || pumping) throw new Error("Wait for preparation or pause processing before changing source type.");
        showSourceChoice();
    }));
    $("recovery-choose-source").addEventListener("click", () => { showSourceChoice(); });
    function uploadProgress(data) {
        const phase = data.phase;
        let percent = null, text;
        if (phase === "upload") { percent = Math.floor(data.uploaded / data.size * 100); text = `Uploading backup · ${percent}%`; }
        else if (phase === "parse") { percent = data.sql_size > 0 ? Math.min(100, Math.floor(data.parsed / data.sql_size * 100)) : 0; text = `Reading WHMCS tables · ${percent}% · ${data.tables} definitions found`; }
        else if (phase === "expand") text = `Opening compressed backup · ${Math.floor(data.expanded / 1048576)} MiB prepared`;
        else if (phase === "verify") text = "Checking compressed backup integrity…";
        else if (phase === "sort") text = "Ordering backup record index…";
        else { percent = 100; text = "Backup is ready. Review the mappings before creating a migration run."; }
        $("upload-status").textContent = text;
        $("upload-progress").classList.remove("d-none");
        $("upload-bar").style.width = `${percent === null ? 100 : percent}%`;
        $("upload-bar").classList.toggle("wm-working", phase !== "ready" && !backupPaused);
        if (percent === null) $("upload-progress").removeAttribute("aria-valuenow");
        else $("upload-progress").setAttribute("aria-valuenow", String(percent));
        $("upload-progress").setAttribute("aria-valuetext", text);
    }
    $("upload").addEventListener("submit", handle(async () => {
        if (preparing || pumping) throw new Error("Wait for the current migration operation.");
        preparing = true; backupPaused = false;
        try {
            if (!backup) {
                source = null; profile = null; $("next").disabled = true; $("upload-next").disabled = true;
                root.querySelector('[data-step="mapping"]').disabled = true; root.querySelector('[data-step="analysis"]').disabled = true;
                backupFile = $("backup-file").files[0];
                if (!backupFile) throw new Error("Choose a WHMCS SQL, ZIP or GZIP backup.");
                const values = Object.fromEntries(new FormData($("upload")));
                backup = { ...(await api("backup_begin", { ...values, filename: backupFile.name, size: backupFile.size })), phase: "upload", uploaded: 0, size: backupFile.size, expanded: 0 };
                $("upload-remove").classList.remove("d-none");
                for (const id of ["backup-file", "upload-prefix", "upload-timezone", "upload-version"]) $(id).disabled = true;
            }
            $("upload-pause").classList.remove("d-none");
            while (!backupPaused && backup.uploaded < backup.size) {
                const bytes = new Uint8Array(await backupFile.slice(backup.uploaded, backup.uploaded + backup.chunk_size).arrayBuffer());
                let binary = "";
                for (let at = 0; at < bytes.length; at += 8192) binary += String.fromCharCode(...bytes.subarray(at, at + 8192));
                const next = await api("backup_chunk", { backup_id: backup.backup_id, offset: backup.uploaded, data: btoa(binary) });
                backup = { ...backup, ...next }; uploadProgress(backup);
            }
            while (!backupPaused && backup.phase !== "ready") {
                backup = { ...backup, ...(await api("backup_prepare", { backup_id: backup.backup_id })) };
                uploadProgress(backup);
            }
            if (!backupPaused) {
                useSource(await api("backup_source", { backup_id: backup.backup_id }));
                $("upload-next").disabled = false;
                await destinationStatus();
                await dashboard();
                notice("Backup prepared privately. No SQL was executed and no client records were imported. Review the mappings next.");
            } else $("upload-status").append(" · Paused. Keep this page open and choose Continue upload to resume.");
        } finally {
            preparing = false;
            $("upload-bar").classList.remove("wm-working");
            $("upload-pause").classList.add("d-none");
            $("upload-start").textContent = backup?.phase === "ready" ? "Backup prepared" : backup ? "Continue upload" : "Upload & prepare backup";
        }
    }));
    $("upload-pause").addEventListener("click", () => { backupPaused = true; });
    $("upload-remove").addEventListener("click", handle(async () => {
        if (preparing || !backup) throw new Error("Pause upload or preparation before removing the backup.");
        if (!window.confirm("Remove this private uploaded backup and its prepared files? Archived/imported records are not deleted. Unfinished runs that need this file will block removal.")) return;
        await api("backup_remove", { backup_id: backup.backup_id, confirmation: "REMOVE PRIVATE BACKUP" });
        if (source?.backup_id === backup.backup_id) {
            source = null; profile = null; counts.clear(); $("upload-next").disabled = true; $("next").disabled = true;
            root.querySelector('[data-step="mapping"]').disabled = true; root.querySelector('[data-step="analysis"]').disabled = true;
        }
        backup = null; backupFile = null;
        for (const id of ["backup-file", "upload-prefix", "upload-timezone", "upload-version"]) $(id).disabled = false;
        $("upload-remove").classList.add("d-none"); $("upload-status").textContent = "Private backup removed.";
        $("upload-progress").classList.add("d-none"); $("upload-start").textContent = "Upload & prepare backup";
    }));
    $("upload-next").addEventListener("click", () => step("mapping"));
    let publicIp = "";
    $("ip-lookup").addEventListener("click", handle(async () => {
        // Do not retain a stale address if a refresh fails after a route/VPN change.
        publicIp = "";
        $("public-ip").textContent = "Checking…";
        $("ip-copy").disabled = true;
        $("ip-status").textContent = "Requesting the FOSSBilling server’s public IPv4 address from api.ipify.org…";
        try {
            const result = await api("public_ip", { confirmation: "LOOK UP PUBLIC IP" });
            publicIp = result.ip;
            $("public-ip").textContent = publicIp;
            $("ip-copy").disabled = false;
            $("ip-lookup").textContent = "Refresh public IP";
            $("ip-status").textContent = `Observed by ${result.provider} at ${result.checked_at} UTC. Verify the MySQL connection uses the same outbound route.`;
        } catch (error) {
            $("public-ip").textContent = "Unavailable";
            $("ip-status").textContent = "No IP was guessed. Ask your hosting/network administrator for the outbound MySQL IP.";
            throw error;
        }
    }));
    $("ip-copy").addEventListener("click", handle(async () => {
        if (!publicIp) throw new Error("Look up the current public IP first.");
        try {
            await navigator.clipboard.writeText(publicIp);
            $("ip-status").textContent = "IP copied. Add it to Remote MySQL access, then verify the connection uses this outbound route.";
        } catch {
            throw new Error("Clipboard access is unavailable. Select and copy the displayed IP manually.");
        }
    }));
    function securityControls() {
        const mode = $("security-mode").value, verified = mode === "verified_tls";
        $("tls-settings").classList.toggle("d-none", !verified);
        $("ca").disabled = !verified;
        $("security-risk").classList.toggle("d-none", verified);
        $("security-acknowledged").checked = false;
        $("security-acknowledged").required = !verified;
        $("security-warning").textContent = mode === "unverified_tls"
            ? "Encryption is required, but the server identity is NOT authenticated. An attacker could impersonate the database server and read credentials/customer data. Prefer verified TLS or a trusted tunnel."
            : "No TLS is requested. Credentials and customer data may travel unencrypted. Use only after explicitly accepting this risk or through a separately secured trusted tunnel.";
    }
    $("security-mode").addEventListener("change", securityControls);
    securityControls();
    function useSource(data) {
        source = data;
        profile = structuredClone(data.profile);
        defaultProfile = structuredClone(data.profile);
        counts.clear();
        data.analysis.forEach(row => counts.set(row.table_name, Number(row.expected)));
        const connection = data.connection_status;
        const modeNames = { verified_tls: "Verified TLS", unverified_tls: "TLS without certificate verification", standard: "Standard MySQL (no TLS requested)" };
        const connected = Boolean(connection?.successful);
        const fileSource = data.source_kind === "sql_file";
        const available = data.source_available !== false;
        if (fileSource && available) {
            $("upload-timezone").value = data.source_timezone || "America/Chicago";
            if (data.backup_id && backup?.backup_id !== data.backup_id) backup = { backup_id: data.backup_id, phase: "ready", uploaded: 0, size: 0 };
            $("upload-remove").classList.remove("d-none");
        }
        showSetup(fileSource ? "sql_file" : "database");
        $("source-status").replaceChildren(element("h4", fileSource ? available ? "Backup ready" : "Backup files were removed — upload a new backup to migrate again" : connected ? "Connection successful" : "Previously tested source — retest for current connection diagnostics", connected || (fileSource && available) ? "wm-success" : ""), element("p", `${fileSource ? "Private SQL backup · data only" : "SELECT-only grants checked"} · ${data.label} · WHMCS ${data.version || "manually confirmed 8.x"} · Last tested ${data.tested_at || "unknown"} UTC`));
        if (connection) $("source-status").append(dataTable(["Property", "Result"], [
            ["Selected security", modeNames[connection.security_mode] || connection.security_mode],
            ["TLS encryption active", connection.tls_active ? "Yes" : "No — unencrypted connection"],
            ["Server identity verified", connection.server_identity_verified ? "Yes" : "No"],
            ["Database", connection.database],
            ["Privilege validation", connection.privileges?.summary || "Retest to collect privilege-scope diagnostics"],
            ["Detected privilege scopes", connection.privileges?.detected_scopes?.join(", ") || "Not recorded"],
            ["Database grant matched by", connection.privileges?.matched_by?.join(", ") || "Not recorded"],
            ["WHMCS tables", connection.table_availability?.available ? `Required tables available · ${connection.table_availability.tables_found} tables inspected` : "Not verified"],
            ["WHMCS server timezone", data.source_timezone || "Not selected"]
        ]));
        $("timezone").value = data.source_timezone || "America/Chicago";
        $("next").disabled = !available;
        $("upload-next").disabled = !fileSource || !available;
        root.querySelector('[data-step="mapping"]').disabled = !available;
        root.querySelector('[data-step="analysis"]').disabled = !available;
        renderFields();
        renderTables();
    }
    $("connection").addEventListener("submit", handle(async () => {
        $("connection").setAttribute("aria-busy", "true");
        $("connection-loader").classList.remove("d-none");
        $("connection-button-label").textContent = "Testing connection…";
        notice("Testing the selected source security mode, SELECT-only privileges, schema and selected server timezone. No import is running.");
        const values = Object.fromEntries(new FormData($("connection")));
        values.security_acknowledged = $("security-acknowledged").checked;
        try {
            const result = await api("test_connection", values);
            useSource(result);
            await destinationStatus();
            await dashboard();
            notice("Connection verified. Review every mapping and excluded table before proceeding.");
        } finally {
            $("password").value = "";
            delete values.password;
            $("connection").setAttribute("aria-busy", "false");
            $("connection-loader").classList.add("d-none");
            $("connection-button-label").textContent = "Test connection";
        }
    }));
    $("sources").addEventListener("change", handle(async () => {
        if ($("sources").value) { useSource(await api("mapping_get", { source_id: Number($("sources").value) })); await destinationStatus(); }
    }));
    $("next").addEventListener("click", () => step("mapping"));
    function dropdown(options, value, label) {
        const select = element("select", undefined, "form-select form-select-sm");
        select.setAttribute("aria-label", label);
        options.forEach(([key, title]) => {
            const option = element("option", title);
            option.value = key; option.selected = key === value; select.append(option);
        });
        return select;
    }
    function renderFields() {
        if (!source) return;
        const query = $("field-search").value.toLowerCase();
        const filter = $("field-filter").value;
        const groups = new Map();
        source.fields.forEach(field => {
            const rule = profile.fields[field.table][field.field];
            if ((filter && rule.action !== filter) || !`${field.table} ${field.field} ${rule.target || ""}`.toLowerCase().includes(query)) return;
            if (!groups.has(field.category)) groups.set(field.category, []);
            groups.get(field.category).push({ ...field, ...rule });
        });
        $("fields").replaceChildren();
        groups.forEach((fields, category) => {
            const details = element("details"); details.open = Boolean(query || filter);
            details.append(element("summary", `${category} · ${fields.length} fields`));
            const wrap = element("div", undefined, "table-responsive");
            const table = element("table", undefined, "table table-vcenter wm-mapping-table");
            const head = element("thead"); const hr = element("tr");
            ["WHMCS source", "Type / required", "Disposition", "Destination field", "Transform", "Skip reason"].forEach(text => hr.append(element("th", text)));
            head.append(hr); table.append(head);
            const body = element("tbody");
            fields.forEach(field => {
                const row = element("tr"), rule = profile.fields[field.table][field.field];
                row.append(element("td", `${field.table}.${field.field}`), element("td", `${field.type}${field.required ? " · key" : ""}`));
                const actionCell = element("td");
                if (field.action === "exclude_sensitive") {
                    actionCell.textContent = "Excluded sensitive field";
                    row.append(actionCell, element("td", "Not extracted"), element("td", "—"), element("td", "Policy"));
                } else {
                    const native = field.table.endsWith("clients") && field.field !== "id";
                    const actions = [["archive", "Structured archive"], ["skip", "Skip (reason required)"]];
                    if (native) actions.unshift(["native_and_archive", "Native + archive"]);
                    const action = dropdown(actions, rule.action, `Disposition of ${field.table}.${field.field}`);
                    action.addEventListener("change", () => {
                        rule.action = action.value;
                        rule.target = action.value === "native_and_archive" ? (rule.target || source.native_client_targets[0]) : null;
                        renderFields();
                    });
                    actionCell.append(action); row.append(actionCell);
                    const targetCell = element("td");
                    if (rule.action === "native_and_archive") {
                        const target = dropdown(source.native_client_targets.map(value => [value, `client.${value}`]), rule.target, `Destination for ${field.field}`);
                        target.addEventListener("change", () => { rule.target = target.value; });
                        targetCell.append(target);
                    } else targetCell.textContent = rule.action === "archive" ? (source.native_entities?.[source.schema[field.table].suffix] ? "Archive + fixed entity adapter in supported native modes" : "Private legacy archive") : "Intentionally omitted";
                    row.append(targetCell);
                    const transformCell = element("td");
                    const transform = dropdown([["identity", "Keep original"], ["trim", "Trim"], ["lowercase", "Lowercase + trim"]], rule.transform, `Transformation for ${field.field}`);
                    transform.disabled = rule.action !== "native_and_archive";
                    transform.addEventListener("change", () => { rule.transform = transform.value; });
                    transformCell.append(transform); row.append(transformCell);
                    const reasonCell = element("td");
                    const reason = element("input", undefined, "form-control form-control-sm");
                    reason.value = rule.reason; reason.maxLength = 255; reason.disabled = rule.action !== "skip";
                    reason.setAttribute("aria-label", `Skip reason for ${field.field}`);
                    reason.addEventListener("input", () => { rule.reason = reason.value; });
                    reasonCell.append(reason); row.append(reasonCell);
                }
                body.append(row);
            });
            table.append(body); wrap.append(table); details.append(wrap); $("fields").append(details);
        });
    }
    $("field-search").addEventListener("input", renderFields);
    $("field-filter").addEventListener("change", renderFields);
    $("defaults").addEventListener("click", () => {
        if (!source) return;
        // Reconstruct policy defaults, rather than restoring a possibly customized saved profile.
        profile = structuredClone(defaultProfile);
        const nativeDefaults = { firstname: "first_name", lastname: "last_name", companyname: "company", email: "email", address1: "address_1", address2: "address_2", city: "city", state: "state", postcode: "postcode", country: "country", phonenumber: "phone", tax_id: "company_vat", notes: "notes" };
        source.fields.forEach(field => {
            if (field.action === "exclude_sensitive") return;
            const target = source.schema[field.table].suffix === "clients" ? nativeDefaults[field.field] || null : null;
            profile.fields[field.table][field.field] = { action: target ? "native_and_archive" : "archive", target, transform: "identity", reason: "" };
        });
        renderFields();
    });
    async function saveMapping() {
        if (!source) throw new Error("Test or select a source first.");
        profile = (await api("mapping_save", { source_id: source.source_id, profile })).profile;
        notice("Mapping validated and saved. Existing runs retain their original mappings.");
    }
    $("mapping-save").addEventListener("click", handle(saveMapping));
    $("review").addEventListener("click", handle(async () => { await saveMapping(); step("analysis"); }));
    function download(blob, name) {
        const url = URL.createObjectURL(blob), link = element("a");
        link.href = url; link.download = name; link.click();
        // No browser storage is used. Revoke after the download has been initiated.
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    $("profile-export").addEventListener("click", handle(async () => {
        if (!profile) throw new Error("Select a source first.");
        download(new Blob([JSON.stringify(profile, null, 2)], { type: "application/json" }), "whmcstofossbilling-mapping.json");
    }));
    $("profile-import").addEventListener("change", handle(async event => {
        const file = event.target.files[0];
        if (!source || !file) throw new Error("Select a source and a mapping JSON file.");
        if (file.size > 2 * 1024 * 1024) throw new Error("Mapping profiles must be under 2 MB.");
        const candidate = JSON.parse(await file.text());
        profile = (await api("mapping_save", { source_id: source.source_id, profile: candidate })).profile;
        renderFields(); notice("Imported profile validated and saved.");
    }));
    function renderTables() {
        $("tables").replaceChildren();
        if (!source) return;
        Object.entries(source.schema).forEach(([name, definition]) => {
            const label = element("label", undefined, "wm-table-row");
            label.dataset.tableName = name;
            const checkbox = element("input", undefined, "form-check-input");
            checkbox.type = "checkbox"; checkbox.value = name; checkbox.name = "table";
            checkbox.disabled = definition.classification !== "archive"; checkbox.checked = !checkbox.disabled;
            const copy = element("span", undefined, "wm-table-copy");
            const title = element("span", name, "wm-table-name");
            const status = { archive: "Eligible", excluded_sensitive: "Excluded · sensitive", requires_review: "Needs extractor review", unsupported_key: "Unsupported key" }[definition.classification] || definition.classification;
            label.dataset.status = status;
            const text = element("span", `${status} · ${counts.has(name) ? `${counts.get(name)} records` : "Not analyzed"}`, "wm-table-meta");
            text.dataset.tableCount = name;
            copy.append(title, text); label.append(checkbox, copy); $("tables").append(label);
        });
        filterTables();
    }
    const selectedTables = () => [...$("tables").querySelectorAll('input:checked')].map(node => node.value);
    function filterTables() {
        const query = $("table-search").value.trim().toLowerCase();
        const rows = [...$("tables").querySelectorAll(".wm-table-row")];
        rows.forEach(row => { row.hidden = !`${row.dataset.tableName} ${row.dataset.status}`.toLowerCase().includes(query); });
        const available = rows.filter(row => !row.querySelector("input").disabled).length;
        $("selection-summary").textContent = `${selectedTables().length} / ${available} eligible tables selected · ${rows.filter(row => !row.hidden).length} shown · ${rows.length - available} excluded / need review`;
        $("table-empty").classList.toggle("d-none", rows.some(row => !row.hidden));
    }
    $("table-search").addEventListener("input", filterTables);
    $("tables").addEventListener("change", filterTables);
    function describeMode() {
        const descriptions = {
            historical_native: "Creates supported clients, products, closed support conversations and emails, plus eligible historical invoices, payments, credit and inactive hosting. Other supported selected records stay in the private archive. Includes inactive and closed client history where supported; this is not a complete live billing cutover.",
            reference_native: "Creates supported clients, products, closed support conversations and emails. Financial and service history stays in the private archive rather than becoming normal FOSSBilling billing or service records.",
            archive: "Saves supported selected WHMCS records in the private archive for administrator lookup. Does not create normal FOSSBilling clients, invoices, payments or services."
        };
        $("mode-description").textContent = descriptions[$("mode").value];
        $("historical-mode-note").classList.toggle("d-none", $("mode").value !== "historical_native");
    }
    $("mode").addEventListener("change", handle(async () => { describeMode(); if (source) await destinationStatus(); }));
    describeMode();
    async function analyzeTables(tables, sourceId) {
        preparation(`Analyzing tables · 0 of ${tables.length} finished`, 0, tables.length);
        for (let index = 0; index < tables.length; index++) {
            if (source?.source_id !== sourceId) throw new Error("The selected source changed. Review the source and tables before trying again. No run was created.");
            const table = tables[index];
            notice(`Analyzing ${index + 1}/${tables.length}: ${table}. One read-only request per table.`);
            preparation(`Analyzing ${table} · ${index} of ${tables.length} finished`, index, tables.length);
            let result;
            try {
                result = await api("analyze_table", { source_id: sourceId, table });
            } catch (error) {
                error.pendingTables = tables.slice(index);
                if (error.kind !== "session") error.message = `${error.message} Count refresh stopped at ${table}. No run was created.`;
                throw error;
            }
            counts.set(table, result.count);
            const node = [...$("tables").querySelectorAll("[data-table-count]")].find(item => item.dataset.tableCount === table);
            if (node) node.textContent = `Eligible · ${result.count} records`;
            preparation(`Analyzed ${index + 1} of ${tables.length} tables · ${table}`, index + 1, tables.length);
        }
    }
    async function prepareCounts(selection, target, force = false) {
        const sourceId = source.source_id;
        const prepared = await api("analysis_prepare", { source_id: sourceId, tables: selection, target_mode: target });
        if (source?.source_id !== sourceId) throw new Error("The selected source changed. Review the source and tables before trying again. No run was created.");
        const tables = force ? prepared.tables : Object.keys(prepared.issues);
        const dependencies = prepared.tables.filter(table => !selection.includes(table));
        // Required dependencies remain visible and selected for the administrator's review.
        for (const node of $("tables").querySelectorAll("input")) {
            if (dependencies.includes(node.value) && !node.disabled) node.checked = true;
        }
        filterTables();
        if (tables.length) {
            notice(`Refreshing read-only counts for ${tables.length} tables, including required dependencies. No import is starting.`);
            await analyzeTables(tables, sourceId);
        }
        return { refreshed: tables, dependencies };
    }
    $("analyze").addEventListener("click", handle(async () => {
        if (!source) throw new Error("Select a source first.");
        if (preparing) throw new Error("Wait for the current analysis or run preparation to finish.");
        preparing = true;
        preparation("Checking selected tables");
        try {
            await prepareCounts(selectedTables(), $("mode").value, true);
            notice("Selected table counts captured, including required dependencies. They are not a transactionally consistent whole-database snapshot.");
        } finally { preparing = false; stopPreparation(); }
    }));
    async function openUnfinished(candidate) {
        pumping = false; stopWatching(); replacement = null;
        const status = await api("run_status", { run_id: candidate.id });
        if (!["running", "paused"].includes(status.status)) return;
        run = status;
        $("runs").value = run.id;
        $("existing-run").classList.add("d-none");
        renderRun();
        const running = run.status === "running";
        $("recovery-description").textContent = running
            ? `This ${run.mode} import is still marked running. Another tab may be processing it; this tab will only watch status until you explicitly take over. No batches were scheduled automatically.`
            : `This ${run.mode} import is paused with committed work intact. Review its progress below, then explicitly resume when you are ready. No batches were scheduled automatically.`;
        $("recovery-primary").textContent = running ? "Take over processing here" : "Resume this import";
        $("recovery-banner").classList.remove("d-none");
        $("source-choice").classList.add("d-none");
        $("navigation").classList.remove("d-none");
        $("limits").classList.remove("d-none");
        step("progress");
        scrollToTop();
        notice("An unfinished import was found. You are on Step 4; no automatic resume was attempted.");
    }
    async function openExisting(existing, request) {
        pumping = false; stopWatching(); hideRecovery();
        run = existing.run;
        replacement = { ...request, runId: run.id };
        $("existing-run").classList.remove("d-none");
        const changed = existing.differences.length ? `Your new choices differ: ${existing.differences.join(", ")}. ` : "";
        $("existing-description").textContent = `${changed}This run keeps its saved settings and authorizations. ${run.status === "running" ? "Watching status only; this tab is not scheduling batches. If no other tab is processing, use Resume processing to continue here." : "It remains paused. Use Resume processing when ready."} No records were cancelled or overwritten.`;
        renderRun(); step("progress");
        $("preparation").classList.add("d-none");
        notice("Continuing with your existing run. Review its saved settings and progress below.");
        watchRun(); scrollToTop();
        await dashboard();
    }
    async function createRun(mode, extra = {}) {
        if (preparing) return;
        preparing = true;
        scrollToTop();
        notice("Preparing your paused run. Checking mappings and read-only table counts; no import is running.");
        preparation("Checking for an unfinished run");
        let request = null;
        try {
            if (!source) throw new Error("Select a source first.");
            const sourceId = source.source_id, selection = selectedTables(), target = mode === "dry_run" ? $("mode").value : mode;
            request = { sourceId };
            if (mode !== "dry_run") {
                const existing = await api("run_existing", { source_id: sourceId, tables: selection, mode, ...extra, profile });
                if (source?.source_id !== sourceId) throw new Error("The selected source changed. Review it before trying again.");
                if (existing) { await openExisting(existing, request); return; }
            }
            preparation("Checking settings and field mappings");
            await saveMapping();
            if (source?.source_id !== sourceId) throw new Error("The selected source changed. Review the source and tables before trying again. No run was created.");
            const prepared = await prepareCounts(selection, target);
            if (source?.source_id !== sourceId) throw new Error("The selected source changed. Review the source and tables before trying again. No run was created.");
            preparation("Creating your paused run…");
            run = await api("run_create", { source_id: sourceId, tables: selection, mode, ...extra, target_mode: target });
            replacement = null; stopWatching(); hideRecovery(); $("existing-run").classList.add("d-none");
            $("preparation").classList.add("d-none");
            // Reveal the successful run even if refreshing the dashboard subsequently fails.
            renderRun(); step("progress");
            scrollToTop();
            notice(`Run created paused. Review its mode and expected counts, then explicitly resume.${prepared.refreshed.length ? ` Read-only counts refreshed for: ${prepared.refreshed.join(", ")}.` : ""}${prepared.dependencies.length ? ` Required dependencies included: ${prepared.dependencies.join(", ")}.` : ""}`);
            await dashboard();
        } catch (error) {
            // Another tab can create a run after our early check. Keep the locked
            // backend guard, then recover by opening that run rather than retrying writes.
            if (request && /Another archival\/native run/.test(error.message)) {
                const existing = await api("run_existing", { source_id: request.sourceId, tables: selectedTables(), mode, ...extra, profile });
                if (existing) { await openExisting(existing, request); return; }
            }
            $("preparation-label").textContent = `Preparation stopped · ${$("preparation-label").textContent}. Review the message above.`;
            throw error;
        } finally {
            preparing = false;
            stopPreparation();
            scrollToTop();
        }
    }
    $("dry-run").addEventListener("click", handle(async () => createRun("dry_run", { invalid_date_fallback: $("invalid-date-fallback").checked ? "1900" : "" })));
    $("authorize").addEventListener("submit", handle(async () => {
        const overwrite = overwriteIntent();
        if (overwrite && !confirmOverwrite()) return;
        const data = Object.fromEntries(new FormData($("authorize")));
        await createRun(data.mode, data);
    }));
    $("authorize").addEventListener("invalid", event => {
        event.preventDefault();
        if (preparing) return;
        const explain = () => notice("Complete the required authorization fields: acknowledge the limitations and type PRESERVE HISTORY. No run was created.", true);
        scrollToTop();
        if (!source || replacement) { explain(); return; }
        // Opening saved work needs no new backup/authorization form. Missing
        // fields must still prevent creation if no existing run is found.
        preparing = true;
        preparation("Checking for an unfinished run");
        handle(async () => {
            try {
                const sourceId = source.source_id, data = Object.fromEntries(new FormData($("authorize")));
                const existing = await api("run_existing", { source_id: sourceId, tables: selectedTables(), ...data, profile });
                if (source?.source_id !== sourceId) throw new Error("The selected source changed. Review it before trying again.");
                if (existing) await openExisting(existing, { sourceId });
                else { $("preparation").classList.add("d-none"); explain(); }
            } finally { preparing = false; stopPreparation(); scrollToTop(); }
        })(event);
    }, true);
    $("view-existing").addEventListener("click", () => { renderRun(); scrollToTop(); });
    $("replace-existing").addEventListener("click", handle(async () => {
        if (!replacement || preparing || pumping) return;
        const pending = replacement;
        if (source?.source_id !== pending.sourceId || run?.id !== pending.runId) throw new Error("The source or run changed. Review your selections again.");
        if (!$("authorize").checkValidity()) { step("analysis"); $("authorize").reportValidity(); return; }
        const data = Object.fromEntries(new FormData($("authorize")));
        if (data.confirmation !== "PRESERVE HISTORY" || !data.accept_limitations || (data.mode === "historical_native" && !data.destination_exclusive)) {
            step("analysis");
            throw new Error("Type PRESERVE HISTORY exactly and confirm the required safety checkboxes before cancelling existing work. Historical financial mode also requires the private, idle destination confirmation.");
        }
        const overwrite = overwriteIntent();
        const snapshot = JSON.stringify({ data, tables: selectedTables(), profile });
        if (overwrite && !confirmOverwrite()) return;
        if (!window.confirm(`Cancel future batches for this existing run and create a new paused run using your current reviewed choices? ${overwrite ? "The confirmed overwrite will delete the listed FOSSBilling records." : "Already imported records and history will remain."} Nothing is rolled back.`)) return;
        stopWatching();
        preparing = true;
        try {
            const prior = await api("run_status", { run_id: pending.runId });
            run = ["paused", "running"].includes(prior.status) ? await api("run_control", { run_id: pending.runId, action: "cancel" }) : prior;
            renderRun();
        }
        finally { preparing = false; }
        replacement = null; $("existing-run").classList.add("d-none");
        if (source?.source_id !== pending.sourceId || snapshot !== JSON.stringify({ data: Object.fromEntries(new FormData($("authorize"))), tables: selectedTables(), profile })) {
            throw new Error("The previous run was cancelled, but your choices changed. Review the form and create a new run when ready. Existing imported records remain intact.");
        }
        await createRun(data.mode, data);
    }));
    function dataTable(headers, rows) {
        const wrap = element("div", undefined, "table-responsive");
        const table = element("table", undefined, "table table-vcenter");
        const head = element("thead"), header = element("tr");
        headers.forEach(text => header.append(element("th", text))); head.append(header); table.append(head);
        const body = element("tbody");
        rows.forEach(cells => { const row = element("tr"); cells.forEach(text => row.append(element("td", text ?? "—"))); body.append(row); });
        table.append(body); wrap.append(table); return wrap;
    }
    function renderRun() {
        if (!run) return;
        const expected = run.categories.reduce((sum, row) => sum + Number(row.expected), 0);
        const processed = run.categories.reduce((sum, row) => sum + Number(row.processed), 0);
        // Scanned/expected, not migrated/complete: exceptions and archive-only rows stay visible below.
        const percent = expected > 0 ? Math.min(100, Math.floor(processed / expected * 100)) : 0;
        $("top-progress").classList.remove("d-none");
        $("progress-label").textContent = `${run.mode} · ${run.status} · ${processed}/${expected} records scanned`;
        $("progress-percent").textContent = `${percent}%`;
        $("progress-bar").style.width = `${percent}%`;
        $("progress-bar").classList.toggle("wm-working", run.status === "running");
        const track = $("progress-bar").parentElement;
        track.setAttribute("aria-valuenow", String(percent));
        track.setAttribute("aria-valuetext", `${percent}% · ${processed} of ${expected} records scanned`);
        $("run-summary").replaceChildren(element("h4", `${run.mode} · ${run.status}`), element("p", `Run ${run.id} · ${processed}/${expected} scanned (${percent}%) · Last batch ${run.last_batch_at || "not started"} UTC`), element("p", run.reconciliation.reason, "text-warning"));
        $("progress").value = percent;
        const preview = run.mode === "dry_run";
        $("native-total").textContent = preview ? "Preview only" : String(run.categories.reduce((sum, row) => sum + Number(row.imported), 0));
        $("archive-total").textContent = preview ? "Not saved" : String(run.categories.reduce((sum, row) => sum + Number(row.archived), 0));
        $("failed-total").textContent = String(run.categories.reduce((sum, row) => sum + Number(row.failed), 0));
        $("counts").replaceChildren(dataTable(["Source table", "Expected", "Scanned", "Native imported", preview ? "Previewed" : "Archive only", "Unchanged / linked", "Failed"], run.categories.map(row => [row.table_name, row.expected, row.processed, row.imported, row.archived, row.skipped, row.failed])));
        const fallbacks = run.date_fallbacks || [];
        $("date-fallbacks").replaceChildren(fallbacks.length
            ? dataTable(["Table", "WHMCS ID", "Original value", "Applied native date"], fallbacks.map(row => [row.table_name, row.source_key, row.original_value, "1900-01-01 00:00:00"]))
            : element("p", "None. No invalid date was substituted in this run."));
        $("errors").replaceChildren(dataTable(["Table", "WHMCS ID", "Error", "Attempts"], run.errors.map(row => [row.table_name, row.source_key, row.message, row.attempts])));
        const financial = [];
        Object.entries(run.reconciliation.totals_by_currency || {}).forEach(([metric, totals]) => totals.forEach(total => financial.push([metric, total.currency, total.source_total, total.native_total ?? "Not represented", total.source_records, total.native_records])));
        $("financial").replaceChildren(element("p", `${run.reconciliation.status}: ${run.reconciliation.reason}`), dataTable(["Metric", "Currency", "Extracted source", "Native", "Source records", "Native records"], financial));
        $("credit-results").replaceChildren(dataTable(["WHMCS client ID", "Status", "Diagnostic"], (run.credit_publication || []).map(row => [row.source_key, row.status, row.message])));
    }
    async function refreshRun() {
        if (!run) throw new Error("Select a run first.");
        run = await api("run_status", { run_id: run.id }); renderRun();
    }
    $("runs").addEventListener("change", handle(async () => {
        pumping = false; stopWatching(); replacement = null; hideRecovery(); $("existing-run").classList.add("d-none");
        if (!$("runs").value) return;
        run = { id: $("runs").value }; await refreshRun(); watchRun();
    }));
    $("refresh").addEventListener("click", handle(refreshRun));
    $("retry").addEventListener("click", handle(async () => {
        if (!run || pumping) throw new Error("Select a finished run with exceptions before retrying.");
        const result = await api("run_retry", { run_id: run.id });
        await refreshRun();
        notice(`Retried ${result.attempted} records. Each record is limited to five total attempts; unresolved conflicts require manual action.${result.status === "paused" ? " Resume to refresh the financial assessment after successful retries." : ""}`);
    }));
    $("repair-visibility").addEventListener("click", handle(async () => {
        if (!run || pumping) {
            repairStatus("Select the imported run before repairing visibility. No records were changed.", true);
            throw new Error("Select the imported run before repairing visibility.");
        }
        const phrase = $("repair-confirm").value.trim();
        if (!$("repair-acknowledged").checked || phrase !== "REPAIR IMPORTED VISIBILITY") {
            repairStatus("Acknowledge the limited field change and type REPAIR IMPORTED VISIBILITY exactly. No records were changed.", true);
            throw new Error("Acknowledge the limited field change and type REPAIR IMPORTED VISIBILITY exactly. No records were changed.");
        }
        if (!window.confirm("Repair visibility flags on module-owned records from this run only? No records will be deleted or re-imported.")) {
            repairStatus("Repair cancelled. No records were changed.");
            return;
        }
        const button = $("repair-visibility"), originalLabel = button.textContent;
        button.textContent = "Repairing…";
        repairProgress(true);
        try {
            const result = await api("run_repair_visibility", { run_id: run.id, confirmation: phrase });
            $("repair-acknowledged").checked = false; $("repair-confirm").value = "";
            await refreshRun();
            const message = `Repaired ${result.orders} imported hosting orders and ${result.invoices} imported invoices. No records were deleted or re-imported.`;
            repairStatus(message);
            notice(message);
        } catch (error) {
            repairStatus(error.message, true);
            throw error;
        } finally {
            repairProgress(false);
            button.textContent = originalLabel;
        }
    }));
    ["repair-confirm", "repair-acknowledged"].forEach(id => $(id).addEventListener("input", () => $("repair-status").classList.add("d-none")));
    async function resumeCurrentRun() {
        if (!run || pumping) throw new Error("Select a paused/running run. This tab must not already be processing.");
        stopWatching();
        run = await api("run_control", { run_id: run.id, action: "resume" });
        pumping = true; renderRun();
        while (pumping && run.status === "running") {
            run = await api("run_drive", { run_id: run.id, drive_id: batchRequestId(), budget_ms: 15000, limit: 500 });
            renderRun();
            if (run.status !== "running") break;
            const pause = timing("driveInterval", 8000);
            if (pause > 0) await new Promise(resolve => setTimeout(resolve, pause));
        }
        pumping = false; stopWatching();
        notice("Batch scheduling stopped. Review status and exceptions; this is not a declaration of a complete migration.");
    }
    $("resume").addEventListener("click", handle(resumeCurrentRun));
    $("recovery-primary").addEventListener("click", handle(async () => {
        if (!run || !["running", "paused"].includes(run.status)) throw new Error("Select an unfinished run before resuming.");
        if (run.status === "running" && !window.confirm("Another browser tab may still be processing this run. Take over scheduling batches here? Committed records remain intact and no work is rolled back.")) return;
        await resumeCurrentRun();
        hideRecovery();
    }));
    ["pause", "cancel"].forEach(action => $(action).addEventListener("click", handle(async () => {
        pumping = false;
        if (!run) throw new Error("Select a run first.");
        run = await api("run_control", { run_id: run.id, action });
        renderRun(); notice(action === "pause" ? "Run paused. Committed records remain intact." : "Future batches cancelled. No imported records were deleted.");
    })));
    ["json", "csv"].forEach(format => $(`report-${format}`).addEventListener("click", handle(async () => {
        if (!run) throw new Error("Select a run first.");
        download(await api("report_export", { run_id: run.id, format }, true), `whmcstofossbilling-${run.id}.${format}`);
    })));
    $("archive-search").addEventListener("submit", handle(async () => {
        if (!source) throw new Error("Select a tested source first.");
        const criteria = Object.fromEntries(new FormData($("archive-search")));
        const result = await api("archive_search", { source_id: source.source_id, ...criteria });
        $("archive-results").replaceChildren();
        result.records.forEach(record => {
            const details = element("details"); details.append(element("summary", `${record.table_name} #${record.source_key}`), element("pre", JSON.stringify(record, null, 2)));
            $("archive-results").append(details);
        });
        $("archive-results").append(element("p", result.next_offset === null ? `${result.records.length} records found.` : "Showing the first 25 matches. Narrow your search by table and WHMCS ID."));
    }));
    handle(dashboard)();
})();
