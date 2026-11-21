/* No credentials, customer records or migration state are stored in browser storage. */
(() => {
    "use strict";
    const root = document.getElementById("whmcsmigrator");
    if (!root) return;
    const $ = id => document.getElementById(`wm-${id}`);
    let source = null, profile = null, defaultProfile = null, run = null, pumping = false;
    const counts = new Map();
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
    async function api(method, data = {}, download = false) {
        const response = await fetch(`${root.dataset.api.replace(/\/$/, "")}/${method}`, {
            method: "POST", credentials: "same-origin", cache: "no-store",
            headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": root.dataset.csrf },
            body: JSON.stringify(data), signal: AbortSignal.timeout(30000)
        });
        if (download && response.ok && response.headers.get("Content-Disposition")?.startsWith("attachment")) return response.blob();
        const result = await response.json();
        if (!response.ok || result.error) throw new Error(result.error?.message || "Request failed. Stop and review the server status before retrying.");
        return result.result;
    }
    const handle = fn => async event => {
        event?.preventDefault();
        const button = event?.submitter || (event?.currentTarget?.tagName === "BUTTON" ? event.currentTarget : null);
        if (button) button.disabled = true;
        try { await fn(event); } catch (error) {
            pumping = false;
            notice(error.name === "TimeoutError" ? "The response timed out. No further batches were scheduled. Refresh run status before resuming." : error.message, true);
        } finally { if (button) button.disabled = false; }
    };
    function step(name) {
        root.querySelectorAll("[data-panel]").forEach(panel => panel.classList.toggle("d-none", panel.dataset.panel !== name));
        root.querySelectorAll("[data-step]").forEach(button => {
            button.classList.toggle("btn-primary", button.dataset.step === name);
            button.classList.toggle("btn-outline-secondary", button.dataset.step !== name);
        });
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
        $("schema-warning").classList.toggle("d-none", !data.schema_upgrade_required);
        $("blockers").replaceChildren(...data.environment.blockers.map(text => element("li", text)));
        selectOptions($("sources"), data.sources.map(row => [row.id, `${row.label} · tested ${row.tested_at} UTC`]), "Select a tested source…");
        selectOptions($("runs"), data.runs.map(row => [row.id, `${row.created_at} UTC · ${row.mode} · ${row.status} · ${row.label}`]), "Select a run…");
        if (source) $("sources").value = String(source.source_id);
        if (run) $("runs").value = run.id;
    }
    $("schema-upgrade").addEventListener("click", handle(async () => {
        if (!window.confirm("Create missing whmcsmigrator_* tables only? No core schema or business records will change.")) return;
        await api("schema_upgrade", { confirmation: "UPGRADE EXTENSION TABLES" });
        await dashboard(); notice("Extension tracking schema upgraded. No source connection or import ran.");
    }));
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
        $("source-status").replaceChildren(element("h4", connected ? "Connection successful" : "Previously tested source — retest for current connection diagnostics", connected ? "wm-success" : ""), element("p", `SELECT-only grants checked · ${data.label} · WHMCS ${data.version || "manually confirmed 8.x"} · Last tested ${data.tested_at || "unknown"} UTC`));
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
        $("next").disabled = false;
        root.querySelector('[data-step="mapping"]').disabled = false;
        root.querySelector('[data-step="analysis"]').disabled = false;
        renderFields();
        renderTables();
    }
    $("connection").addEventListener("submit", handle(async () => {
        notice("Testing the selected source security mode, SELECT-only privileges, schema and selected server timezone. No import is running.");
        const values = Object.fromEntries(new FormData($("connection")));
        values.security_acknowledged = $("security-acknowledged").checked;
        const result = await api("test_connection", values);
        $("password").value = "";
        delete values.password;
        useSource(result);
        await dashboard();
        notice("Connection verified. Review every mapping and excluded table before proceeding.");
    }));
    $("sources").addEventListener("change", handle(async () => {
        if ($("sources").value) useSource(await api("mapping_get", { source_id: Number($("sources").value) }));
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
        download(new Blob([JSON.stringify(profile, null, 2)], { type: "application/json" }), "whmcsmigrator-mapping.json");
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
            const label = element("label", undefined, "form-check mb-2");
            const checkbox = element("input", undefined, "form-check-input");
            checkbox.type = "checkbox"; checkbox.value = name; checkbox.name = "table";
            checkbox.disabled = definition.classification !== "archive"; checkbox.checked = !checkbox.disabled;
            const text = element("span", `${name} · ${definition.classification}${counts.has(name) ? ` · ${counts.get(name)} records` : ""}`, "form-check-label");
            text.dataset.tableCount = name;
            label.append(checkbox, text); $("tables").append(label);
        });
    }
    const selectedTables = () => [...$("tables").querySelectorAll('input:checked')].map(node => node.value);
    $("analyze").addEventListener("click", handle(async () => {
        if (!source) throw new Error("Select a source first.");
        const tables = selectedTables();
        for (let index = 0; index < tables.length; index++) {
            const table = tables[index];
            notice(`Analyzing ${index + 1}/${tables.length}: ${table}. One read-only request per table.`);
            const result = await api("analyze_table", { source_id: source.source_id, table });
            counts.set(table, result.count);
            const node = [...$("tables").querySelectorAll("[data-table-count]")].find(item => item.dataset.tableCount === table);
            if (node) node.textContent = `${table} · archive · ${result.count} records`;
        }
        notice("Selected table counts captured. They are not a transactionally consistent whole-database snapshot.");
    }));
    async function createRun(mode, extra = {}) {
        if (!source) throw new Error("Select a source first.");
        await saveMapping();
        run = await api("run_create", { source_id: source.source_id, tables: selectedTables(), mode, target_mode: $("mode").value, ...extra });
        await dashboard(); renderRun(); step("progress");
        notice("Run created paused. Review its mode and expected counts, then explicitly resume.");
    }
    $("dry-run").addEventListener("click", handle(async () => createRun("dry_run", { invalid_date_fallback: $("invalid-date-fallback").checked ? "1900" : "" })));
    $("authorize").addEventListener("submit", handle(async () => {
        const data = Object.fromEntries(new FormData($("authorize")));
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
        const track = $("progress-bar").parentElement;
        track.setAttribute("aria-valuenow", String(percent));
        track.setAttribute("aria-valuetext", `${percent}% · ${processed} of ${expected} records scanned`);
        $("run-summary").replaceChildren(element("h4", `${run.mode} · ${run.status}`), element("p", `Run ${run.id} · ${processed}/${expected} scanned (${percent}%) · Last batch ${run.last_batch_at || "not started"} UTC`), element("p", run.reconciliation.reason, "text-warning"));
        $("progress").value = percent;
        const preview = run.mode === "dry_run";
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
        pumping = false;
        if (!$("runs").value) return;
        run = { id: $("runs").value }; await refreshRun();
    }));
    $("refresh").addEventListener("click", handle(refreshRun));
    $("retry").addEventListener("click", handle(async () => {
        if (!run || pumping) throw new Error("Select a finished run with exceptions before retrying.");
        const result = await api("run_retry", { run_id: run.id });
        await refreshRun();
        notice(`Retried ${result.attempted} records. Each record is limited to five total attempts; unresolved conflicts require manual action.${result.status === "paused" ? " Resume to refresh the financial assessment after successful retries." : ""}`);
    }));
    $("resume").addEventListener("click", handle(async () => {
        if (!run || pumping) throw new Error("Select a paused/running run. This tab must not already be processing.");
        run = await api("run_control", { run_id: run.id, action: "resume" });
        pumping = true; renderRun();
        while (pumping && run.status === "running") {
            const result = await api("run_batch", { run_id: run.id, request_id: batchRequestId(), limit: 50 });
            await refreshRun();
            if (result.status !== "running") break;
            await new Promise(resolve => setTimeout(resolve, 200));
        }
        pumping = false;
        notice("Batch scheduling stopped. Review status and exceptions; this is not a declaration of a complete migration.");
    }));
    ["pause", "cancel"].forEach(action => $(action).addEventListener("click", handle(async () => {
        pumping = false;
        if (!run) throw new Error("Select a run first.");
        run = await api("run_control", { run_id: run.id, action });
        renderRun(); notice(action === "pause" ? "Run paused. Committed records remain intact." : "Future batches cancelled. No imported records were deleted.");
    })));
    ["json", "csv"].forEach(format => $(`report-${format}`).addEventListener("click", handle(async () => {
        if (!run) throw new Error("Select a run first.");
        download(await api("report_export", { run_id: run.id, format }, true), `whmcsmigrator-${run.id}.${format}`);
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
