(() => {
    'use strict';

    const app = document.getElementById('rishe-operations-app');
    if (!app || !window.wp || !window.wp.apiFetch || !window.risheOperations) {
        return;
    }

    const apiFetch = window.wp.apiFetch;
    apiFetch.use(apiFetch.createNonceMiddleware(window.risheOperations.nonce));
    const root = window.risheOperations.root;
    let importPackage = null;
    let importChecksum = null;

    const role = (name) => app.querySelector(`[data-rishe-role="${name}"]`);
    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const message = (type, text) => {
        const box = role(type);
        if (!box) return;
        box.querySelector('p').textContent = text;
        box.classList.remove('hidden');
        window.setTimeout(() => box.classList.add('hidden'), 6000);
    };

    const request = async (path, options = {}) => {
        try {
            return await apiFetch({ path: `${root}${path}`, ...options });
        } catch (error) {
            const text = error?.message || error?.error || 'Unexpected request failure.';
            message('error', text);
            throw error;
        }
    };

    const statusPill = (status) => `<span class="rishe-ops__pill rishe-ops__pill--${escapeHtml(status)}">${escapeHtml(status)}</span>`;

    const renderMetrics = (metrics = {}) => {
        const labels = {
            jobs_pending: 'Pending jobs',
            jobs_running: 'Running jobs',
            jobs_failed: 'Failed jobs',
            incidents_open: 'Open incidents',
            tax_rejected: 'Rejected invoices',
            shipment_exceptions: 'Delivery exceptions',
        };
        role('metrics').innerHTML = Object.entries(labels).map(([key, label]) => `
            <article class="rishe-ops__card">
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(metrics[key] ?? 0)}</strong>
            </article>
        `).join('');
    };

    const renderJobTypes = (types = []) => {
        const select = role('job-types');
        const current = select.value;
        select.innerHTML = types.map((type) => `<option value="${escapeHtml(type)}">${escapeHtml(type)}</option>`).join('');
        if (types.includes(current)) select.value = current;
    };

    const renderJobs = (jobs = []) => {
        const target = role('jobs');
        if (!jobs.length) {
            target.innerHTML = '<tr><td colspan="7" class="rishe-ops__empty">No operation jobs yet.</td></tr>';
            return;
        }
        target.innerHTML = jobs.map((job) => {
            const canRetry = ['failed', 'retry_wait'].includes(job.status) && Number(job.attempts) < Number(job.max_attempts);
            const canCancel = ['pending', 'retry_wait', 'failed'].includes(job.status);
            return `<tr>
                <td>#${escapeHtml(job.id)}</td>
                <td><code>${escapeHtml(job.job_type)}</code></td>
                <td>${escapeHtml(job.aggregate_type)}:${escapeHtml(job.aggregate_id)}</td>
                <td>${statusPill(job.status)}</td>
                <td>${escapeHtml(job.attempts)}/${escapeHtml(job.max_attempts)}</td>
                <td>${escapeHtml(job.scheduled_at)}</td>
                <td>
                    ${canRetry ? `<button class="button button-small" data-job-action="retry" data-job-id="${job.id}">Retry</button>` : ''}
                    ${canCancel ? `<button class="button button-small" data-job-action="cancel" data-job-id="${job.id}">Cancel</button>` : ''}
                </td>
            </tr>`;
        }).join('');
    };

    const renderDiagnostics = (diagnostics = {}) => {
        const status = diagnostics.status || 'critical';
        const statusNode = role('health-status');
        statusNode.textContent = status;
        statusNode.className = `rishe-ops__status rishe-ops__status--${status}`;
        const checks = diagnostics.checks || [];
        role('diagnostics').innerHTML = checks.map((check) => `
            <div class="rishe-ops__diagnostic">
                <div><strong>${escapeHtml(check.key)}</strong><br><code>${escapeHtml(check.message)}</code></div>
                ${statusPill(check.status)}
            </div>
        `).join('') || '<div class="rishe-ops__empty">No diagnostics available.</div>';
    };

    const renderIncidents = (incidents = []) => {
        const target = role('incidents');
        if (!incidents.length) {
            target.innerHTML = '<div class="rishe-ops__empty">No open incidents.</div>';
            return;
        }
        target.innerHTML = incidents.map((incident) => `
            <article class="rishe-ops__incident">
                <h3>${escapeHtml(incident.code)} · ${escapeHtml(incident.severity)}</h3>
                <p>${escapeHtml(incident.message)}</p>
                <small>${escapeHtml(incident.source)} · ${escapeHtml(incident.occurrences)} occurrence(s) · ${escapeHtml(incident.last_seen_at)}</small>
                <div class="rishe-ops__incident-actions">
                    ${incident.status === 'open' ? `<button class="button button-small" data-incident-status="acknowledged" data-incident-id="${incident.id}">Acknowledge</button>` : ''}
                    <button class="button button-small" data-incident-status="resolved" data-incident-id="${incident.id}">Resolve</button>
                </div>
            </article>
        `).join('');
    };

    const renderAudit = (events = []) => {
        const target = role('audit');
        if (!events.length) {
            target.innerHTML = '<tr><td colspan="5" class="rishe-ops__empty">No audit events.</td></tr>';
            return;
        }
        target.innerHTML = events.map((event) => `<tr>
            <td>${escapeHtml(event.created_at)}</td>
            <td><code>${escapeHtml(event.event_type)}</code></td>
            <td>${escapeHtml(event.aggregate_type)}:${escapeHtml(event.aggregate_id)}</td>
            <td>${escapeHtml(event.actor_user_id ?? 'system')}</td>
            <td><code>${escapeHtml(event.correlation_id ?? '—')}</code></td>
        </tr>`).join('');
    };

    const load = async () => {
        const data = await request('/dashboard');
        renderMetrics(data.metrics);
        renderJobTypes(data.job_types);
        renderJobs(data.jobs);
        renderDiagnostics(data.diagnostics);
        renderIncidents(data.incidents);
        renderAudit(data.audit);
        role('scheduler').textContent = data.scheduler || 'unknown';
    };

    const aggregateForType = (type) => type.startsWith('tax.') ? 'tax_invoice' : 'shipment';
    const payloadForType = (type, id) => type.startsWith('tax.') ? { invoice_id: id } : { shipment_id: id };
    const defaultIdempotency = (type, id) => `${type}:${id}:${Date.now()}`;

    role('job-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const type = String(form.get('job_type') || '');
        const id = Number(form.get('aggregate_id'));
        let key = String(form.get('idempotency_key') || '').trim();
        if (!key) key = defaultIdempotency(type, id);
        await request('/jobs', {
            method: 'POST',
            data: {
                job_type: type,
                aggregate_type: aggregateForType(type),
                aggregate_id: String(id),
                idempotency_key: key,
                payload: payloadForType(type, id),
            },
        });
        event.currentTarget.reset();
        message('success', 'Operation job was queued.');
        await load();
    });

    role('jobs').addEventListener('click', async (event) => {
        const button = event.target.closest('[data-job-action]');
        if (!button) return;
        button.disabled = true;
        await request(`/jobs/${button.dataset.jobId}/${button.dataset.jobAction}`, { method: 'POST', data: {} });
        message('success', `Job ${button.dataset.jobAction} request was applied.`);
        await load();
    });

    role('incidents').addEventListener('click', async (event) => {
        const button = event.target.closest('[data-incident-status]');
        if (!button) return;
        button.disabled = true;
        await request(`/incidents/${button.dataset.incidentId}/${button.dataset.incidentStatus}`, { method: 'POST', data: {} });
        message('success', 'Incident status was updated.');
        await load();
    });

    app.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-rishe-action]');
        if (!button) return;
        if (button.dataset.risheAction === 'refresh') {
            button.disabled = true;
            await load();
            button.disabled = false;
        }
        if (button.dataset.risheAction === 'export-config') {
            const packageData = await request('/configuration/export');
            const blob = new Blob([JSON.stringify(packageData, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `rishe-config-${packageData.checksum.slice(0, 12)}.json`;
            link.click();
            URL.revokeObjectURL(url);
            message('success', 'Safe configuration package was exported.');
        }
    });

    role('import-file').addEventListener('change', async (event) => {
        const file = event.target.files?.[0];
        if (!file) return;
        try {
            importPackage = JSON.parse(await file.text());
        } catch (error) {
            message('error', 'Selected configuration file is not valid JSON.');
            return;
        }
        const preview = await request('/configuration/import', {
            method: 'POST',
            data: { mode: 'preview', package: importPackage },
        });
        importChecksum = preview.checksum;
        const changes = preview.changes || [];
        role('config-preview').innerHTML = `
            <strong>${escapeHtml(preview.change_count)} change(s) ready</strong>
            <ul>${changes.map((change) => `<li><code>${escapeHtml(change.key)}</code></li>`).join('')}</ul>
            ${changes.length ? '<button type="button" class="button button-primary" data-rishe-action="apply-config">Apply confirmed package</button>' : ''}
        `;
    });

    role('config-preview').addEventListener('click', async (event) => {
        const button = event.target.closest('[data-rishe-action="apply-config"]');
        if (!button || !importPackage || !importChecksum) return;
        if (!window.confirm('Apply the previewed non-secret configuration changes?')) return;
        button.disabled = true;
        const result = await request('/configuration/import', {
            method: 'POST',
            data: { mode: 'apply', package: importPackage, checksum: importChecksum },
        });
        role('config-preview').innerHTML = `<strong>${escapeHtml(result.change_count)} change(s) applied.</strong>`;
        message('success', 'Configuration package was imported.');
        await load();
    });

    const typeSelect = role('job-types');
    const idInput = role('job-form').querySelector('[name="aggregate_id"]');
    const keyInput = role('job-form').querySelector('[name="idempotency_key"]');
    const refreshKey = () => {
        if (typeSelect.value && idInput.value) {
            keyInput.value = defaultIdempotency(typeSelect.value, Number(idInput.value));
        }
    };
    typeSelect.addEventListener('change', refreshKey);
    idInput.addEventListener('change', refreshKey);

    load().catch(() => {});
})();
