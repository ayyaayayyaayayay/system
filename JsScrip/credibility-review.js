(function () {
    'use strict';
    let offset = 0;
    let page = null;
    let requestNumber = 0;
    let semesterInitialized = false;
    const selected = new Set();
    const labels = { AUTO_ACCEPTED: 'Automatically accepted', PENDING_HR_REVIEW: 'Pending review', ACCEPTED_BY_HR: 'Accepted by HR', REJECTED_BY_HR: 'Rejected by HR' };
    const el = id => document.getElementById(id);
    const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    const score = value => value == null ? 'N/A' : value + ' / 100';
    const type = value => /student/i.test(value) ? 'Student' : /supervisor/i.test(value) ? 'Supervisor' : 'Peer';
    const date = value => value ? (SharedData.formatDateInPhilippines ? SharedData.formatDateInPhilippines(value) : value) : 'N/A';
    function filters() {
        return { status: el('cred-review-status').value, evaluationType: el('cred-review-type').value,
            semesterId: el('cred-review-semester').value, search: el('cred-review-search').value.trim(), offset, limit: 50 };
    }
    function updateSelection() {
        const boxes = Array.from(document.querySelectorAll('#cred-review-list input[type="checkbox"]'));
        el('cred-review-check-all').checked = boxes.length > 0 && boxes.every(box => box.checked);
        el('cred-review-check-all').indeterminate = boxes.some(box => box.checked) && !boxes.every(box => box.checked);
        el('cred-review-check-all').disabled = !boxes.length;
        el('cred-review-accept').disabled = !selected.size;
        el('cred-review-reject').disabled = !selected.size;
        el('cred-review-selection').textContent = selected.size + ' selected';
    }
    async function refresh() {
        const sequence = ++requestNumber;
        selected.clear();
        el('cred-review-list').innerHTML = '';
        updateSelection();
        el('cred-review-feedback').textContent = 'Loading saved credibility decisions…';
        try {
            const response = await SharedData.listCredibilityReviews(filters());
            if (sequence !== requestNumber) return;
            page = response; selected.clear();
            const counts = response.counts || {};
            el('cred-review-counts').textContent = 'Total submitted: ' + response.total + ' | Automatically accepted: ' + (counts.AUTO_ACCEPTED || 0)
                + ' | Pending: ' + (counts.PENDING_HR_REVIEW || 0) + ' | Accepted by HR: ' + (counts.ACCEPTED_BY_HR || 0)
                + ' | Rejected by HR: ' + (counts.REJECTED_BY_HR || 0) + ' | Eligible: ' + response.eligible;
            el('cred-review-list').innerHTML = (response.items || []).map(row => `<article class="cred-review-row">
                <div>${row.canReview === true ? `<input type="checkbox" data-select="${Number(row.id)}" aria-label="Select Evaluation ${Number(row.id)}">` : ''}
                    <strong>Evaluation #${Number(row.id)}</strong> <span class="ai-insights-tag">${escape(labels[row.credibility_status] || row.credibility_status)}</span></div>
                <p>${escape(row.professor)} · ${escape(type(row.evaluation_type))} · ${escape(row.semester)} · ${escape(date(row.submitted_at))}</p>
                <p>Credibility: <strong>${score(row.credibility_score)}</strong> · Behavior: <strong>${score(row.behavior_score)}</strong></p>
                ${row.behavior_score == null ? '<p>Behavior data unavailable for this evaluation.</p>' : ''}
                ${row.credibility_components && row.credibility_components.legacyPreserved ? '<p>Historical eligibility preserved; no official historical score.</p>' : ''}
                ${row.credibility_reviewed_at ? `<p>Reviewed ${escape(date(row.credibility_reviewed_at))} · ${escape(row.credibility_review_decision)}</p>` : ''}
                ${row.credibility_review_note ? `<p>HR note: ${escape(row.credibility_review_note)}</p>` : ''}
                <button type="button" class="btn-add-user" data-review="${Number(row.id)}">${row.canReview === true ? 'Review' : 'View details'}</button>
            </article>`).join('') || '<p>No evaluations match these filters.</p>';
            el('cred-review-feedback').textContent = `Showing ${response.items.length ? offset + 1 : 0}–${offset + response.items.length} of ${response.filteredTotal}. Counts follow the semester, type and faculty filters.`;
            el('cred-review-prev').disabled = offset === 0;
            el('cred-review-next').disabled = offset + response.items.length >= response.filteredTotal;
            updateSelection();
        } catch (error) { el('cred-review-feedback').textContent = error.message || 'Unable to load credibility review.'; }
    }
    async function review(id) {
        el('cred-review-feedback').textContent = 'Loading evaluation details…';
        try {
            const result = await SharedData.getCredibilityReview(id);
            const row = result.evaluation;
            const components = row.credibilityComponents || {};
            const flags = row.credibilityFlags || [];
            const dialog = el('cred-review-dialog');
            el('cred-review-detail').innerHTML = `<div class="cred-review-detail-heading">
                    <div><span class="cred-review-eyebrow">Evaluation details</span><h3 id="cred-review-title">Evaluation #${Number(id)}</h3></div>
                    <span class="cred-review-status">${escape(labels[row.credibilityStatus])}</span>
                </div>
                <p class="cred-review-meta">${escape(row.targetProfessor)} <span>·</span> ${escape(type(row.evaluationType))} <span>·</span> ${escape(row.semesterId)} <span>·</span> ${escape(date(row.submittedAt))}</p>
                <div class="cred-review-score-grid">
                    <div class="cred-review-score-card"><span>Credibility score</span><strong>${score(row.credibilityScore)}</strong></div>
                    <div class="cred-review-score-card"><span>Behavior score</span><strong>${score(row.behaviorScore)}</strong></div>
                </div>
                ${row.behaviorScore == null ? '<p class="cred-review-notice">Behavior data unavailable for this evaluation.</p>' : ''}
                <div class="cred-review-component-grid"><p><span>Bias component</span><strong>${score(components.bias)}</strong></p><p><span>Cross-source component</span><strong>${score(components.cross)}</strong></p></div>
                ${components.crossStatus ? `<p class="cred-review-comparator">${escape(components.crossStatus)}</p>` : ''}
                <section class="cred-review-detail-section"><h4>Detected flags</h4>${flags.length ? '<ul>' + flags.map(flag => `<li>${escape(flag)}</li>`).join('') + '</ul>' : '<p>No specific flags recorded.</p>'}</section>
                ${row.behaviorMeta ? `<section class="cred-review-detail-section cred-review-timing"><h4>Timing details</h4><div><span>Duration<strong>${escape(row.behaviorMeta.durationSeconds)} seconds</strong></span><span>Seconds per question<strong>${escape(row.behaviorMeta.secondsPerQuestion)}</strong></span><span>Answered<strong>${escape(row.behaviorMeta.answeredCount)} / ${escape(row.behaviorMeta.questionCount)}</strong></span></div></section>` : ''}
                <section class="cred-review-detail-section"><h4>Submitted ratings</h4><div class="cred-review-ratings">${Object.entries(row.ratings || {}).map(([key,value]) => `<span>Question ${escape(key)}<strong>${escape(value)}</strong></span>`).join('') || '<p>No ratings submitted.</p>'}</div></section>
                <section class="cred-review-detail-section"><h4>Submitted comments</h4>${Object.values(row.qualitative || {}).concat(row.comments || []).filter(Boolean).map(text => `<p class="cred-review-comment">${escape(text)}</p>`).join('') || '<p>No written comments.</p>'}</section>`;
            el('cred-review-note').value = '';
            const pending = row.canReview === true;
            el('cred-review-note').hidden = !pending;
            el('cred-review-note').disabled = !pending;
            document.querySelector('label[for="cred-review-note"]').hidden = !pending;
            document.querySelector('#cred-review-dialog .cred-review-dialog-actions').hidden = !pending;
            el('cred-review-individual-accept').hidden = !pending;
            el('cred-review-individual-reject').hidden = !pending;
            el('cred-review-individual-accept').onclick = pending ? () => confirmDecision([id], 'accept', el('cred-review-note').value) : null;
            el('cred-review-individual-reject').onclick = pending ? () => confirmDecision([id], 'reject', el('cred-review-note').value) : null;
            dialog.showModal(); el('cred-review-feedback').textContent = '';
        } catch (error) { el('cred-review-feedback').textContent = error.message; }
    }
    function confirmDecision(ids, decision, note) {
        if (!ids.length) return;
        const confirmDialog = el('cred-review-confirm');
        const accept = decision === 'accept';
        const allFlagged = ids.every(id => (page && page.items || []).some(row => String(row.id) === String(id) && row.credibility_status === 'PENDING_HR_REVIEW'));
        el('cred-review-confirm-text').textContent = accept
            ? (allFlagged ? `Accept ${ids.length} flagged evaluations? These evaluations did not pass the automatic credibility threshold. HR approval will make them eligible for Professor Analytics.` : `Accept ${ids.length} evaluations? This records HR approval and keeps these evaluations eligible for Professor Analytics.`)
            : `Reject ${ids.length} evaluations? These evaluations will remain stored but will be excluded from panel results, comments, reports, and calculations.`;
        const button = el('cred-review-confirm-submit');
        button.textContent = `${accept ? 'Accept' : 'Reject'} ${ids.length} Evaluations`;
        button.disabled = false;
        button.onclick = async () => {
            button.disabled = true;
            try {
                const result = await SharedData.reviewCredibilityEvaluations(ids, decision, note);
                confirmDialog.close(); el('cred-review-dialog').close();
                offset = 0;
                await refresh();
                el('cred-review-feedback').textContent = `${result.updated} evaluations ${decision === 'accept' ? 'accepted' : 'rejected'}. Panel results and reports will exclude rejected evaluations when refreshed.`;
                if (SharedData.refreshEvaluations) SharedData.refreshEvaluations().catch(() => {});
            } catch (error) {
                confirmDialog.close(); el('cred-review-dialog').close();
                await refresh();
                el('cred-review-feedback').textContent = error.message || 'Review failed. No decision was changed.';
            } finally { button.disabled = false; }
        };
        confirmDialog.showModal();
    }
    document.addEventListener('DOMContentLoaded', function () {
        if (!el('cred-review-list')) return;
        el('cred-review-list').addEventListener('change', event => {
            const id = event.target.dataset.select;
            if (id) { event.target.checked ? selected.add(id) : selected.delete(id); updateSelection(); }
        });
        el('cred-review-list').addEventListener('click', event => {
            const button = event.target.closest('[data-review]'); if (button) review(button.dataset.review);
        });
        el('cred-review-check-all').addEventListener('change', event => {
            document.querySelectorAll('#cred-review-list input[data-select]').forEach(box => {
                box.checked = event.target.checked;
                box.checked ? selected.add(box.dataset.select) : selected.delete(box.dataset.select);
            }); updateSelection();
        });
        el('cred-review-accept').onclick = () => confirmDecision(Array.from(selected), 'accept', '');
        el('cred-review-reject').onclick = () => confirmDecision(Array.from(selected), 'reject', '');
        el('cred-review-close').onclick = () => el('cred-review-dialog').close();
        el('cred-review-confirm-cancel').onclick = () => el('cred-review-confirm').close();
        el('cred-review-confirm-close').onclick = () => el('cred-review-confirm').close();
        el('cred-review-prev').onclick = () => { offset = Math.max(0, offset - 50); refresh(); };
        el('cred-review-next').onclick = () => { offset += 50; refresh(); };
        el('cred-review-refresh').onclick = () => { offset = 0; refresh(); };
        ['status','type','semester'].forEach(key => el('cred-review-' + key).addEventListener('change', () => { offset = 0; refresh(); }));
        el('cred-review-search').addEventListener('keydown', event => { if (event.key === 'Enter') { offset = 0; refresh(); } });
        const view = el('ai-insights-view');
        const onVisible = () => {
            if (view.style.display === 'none') return;
            const semesters = SharedData.getSemesterList ? SharedData.getSemesterList() : [];
            const current = SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : '';
            const value = semesterInitialized ? el('cred-review-semester').value : current;
            el('cred-review-semester').innerHTML = '<option value="all">All semesters</option>' + semesters.map(s => `<option value="${escape(s.value || s.slug || s.id)}">${escape(s.label || s.name || s.value || s.slug || s.id)}</option>`).join('');
            if (current && !Array.from(el('cred-review-semester').options).some(o => o.value === current)) el('cred-review-semester').add(new Option(current, current));
            if (Array.from(el('cred-review-semester').options).some(o => o.value === value)) el('cred-review-semester').value = value;
            if (current) semesterInitialized = true;
            refresh();
        };
        new MutationObserver(onVisible).observe(view, { attributes: true, attributeFilter: ['style'] });
        onVisible();
        setInterval(() => { if (view.style.display !== 'none' && !selected.size && !el('cred-review-dialog').open && !el('cred-review-confirm').open) refresh(); }, 60000);
    });
})();
