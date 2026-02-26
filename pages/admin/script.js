
    // Portfolio line chart - months vs amounts (Frw)
    const chartEl = document.getElementById("portfolioChart");
    if (chartEl && window.Chart) {
    const months = ["Mut", "Gas", "Wer", "Mata", "Gic", "Kam"];
    const investment = [1200000, 1450000, 1600000, 1750000, 1900000, 2100000];
    const loans = [600000, 720000, 800000, 780000, 760000, 740000];
    const assets = [850000, 900000, 950000, 1000000, 1100000, 1150000];
    const expenses = [80000, 60000, 90000, 70000, 65000, 85000];

    new Chart(chartEl, {
        type: "line",
        data: {
        labels: months,
        datasets: [
            {
            label: "Ishoramari (Imigabane yose)",
            data: investment,
            borderColor: "#2F6B4F",
            backgroundColor: "rgba(47, 107, 79, 0.1)",
            tension: 0.35,
            borderWidth: 2,
            },
            {
            label: "Inguzanyo ziriho",
            data: loans,
            borderColor: "#E89C2C",
            backgroundColor: "rgba(232, 156, 44, 0.08)",
            tension: 0.35,
            borderWidth: 2,
            },
            {
            label: "Assets",
            data: assets,
            borderColor: "#6B4A2D",
            backgroundColor: "rgba(107, 74, 45, 0.08)",
            tension: 0.35,
            borderWidth: 2,
            },
            {
            label: "Expenses",
            data: expenses,
            borderColor: "#DC2626",
            backgroundColor: "rgba(220, 38, 38, 0.06)",
            tension: 0.35,
            borderWidth: 2,
            },
        ],
        },
        options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
            position: "bottom",
            labels: {
                usePointStyle: true,
                boxWidth: 8,
            },
            },
            tooltip: {
            callbacks: {
                label: (ctx) => {
                const value = ctx.parsed.y || 0;
                return `${ctx.dataset.label}: ${value.toLocaleString("rw-RW")} Frw`;
                },
            },
            },
        },
        scales: {
            y: {
            ticks: {
                callback: (v) => `${Number(v).toLocaleString("rw-RW")} Frw`,
            },
            grid: {
                color: "rgba(148, 163, 184, 0.2)",
            },
            },
            x: {
            grid: {
                display: false,
            },
            },
        },
        },
    });
    }

    // Global user management functions (available from any tab)
    const apiUrl = 'users_api.php';
    const viewModal = document.getElementById('user-view-modal');
    const viewModalClose = document.getElementById('view-modal-close');
    const viewClose = document.getElementById('view-close');
    let globalEscapeHtml = (s) => {
    if (s === null || s === undefined) return '';
    const str = String(s);
    return str.replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c]));
    };

    let openViewModal = () => { viewModal.classList.remove('hidden'); viewModal.classList.add('flex'); };
    let closeViewModal = () => { viewModal.classList.add('hidden'); viewModal.classList.remove('flex'); };

    let viewUserDetails = async (id) => {
    try{
        const res = await fetch(`${apiUrl}?id=${encodeURIComponent(id)}`, {cache:'no-store'});
        if (!res.ok) {
        const text = await res.text();
        console.error('View fetch error status', res.status, text);
        alert('Error fetching user details: ' + res.status);
        return;
        }
        const json = await res.json();
        if(json.success && json.data){
        const d = json.data;
        const out = document.getElementById('user-details');
        out.innerHTML = `
            <div><strong>ID:</strong> ${globalEscapeHtml(d.id)}</div>
            <div><strong>Amazina:</strong> ${globalEscapeHtml(d.names)}</div>
            <div><strong>NID/Passport:</strong> ${globalEscapeHtml(d.nid_passport)}</div>
            <div><strong>Email:</strong> ${globalEscapeHtml(d.email)}</div>
            <div><strong>Telefoni 1:</strong> ${globalEscapeHtml(d.phone1)}</div>
            <div><strong>Telefoni 2:</strong> ${globalEscapeHtml(d.phone2 || '')}</div>
            <div><strong>Umwishingira:</strong> ${globalEscapeHtml(d.guarantee_name || '')}</div>
            <div><strong>G. NID:</strong> ${globalEscapeHtml(d.guarantee_nid_passport || '')}</div>
            <div><strong>G. Email:</strong> ${globalEscapeHtml(d.guarantee_email || '')}</div>
            <div><strong>G. Phone1:</strong> ${globalEscapeHtml(d.guarantee_phone1 || '')}</div>
            <div><strong>G. Phone2:</strong> ${globalEscapeHtml(d.guarantee_phone2 || '')}</div>
            <div><strong>Is Member:</strong> ${d.is_member ? 'Yes' : 'No'}</div>
            <div><strong>Is Admin:</strong> ${d.is_admin ? 'Yes' : 'No'}</div>
        `;
        openViewModal();
        } else {
        console.error('View response', json);
        alert(json.message || 'No details available');
        }
    }catch(err){ console.error('View error', err); alert('Network error'); }
    };

    // Global event delegation: handle Reba clicks from any table (works across all tabs)
    document.addEventListener('click', (e) => {
    if (e.target.classList.contains('btn-view')) {
        const id = e.target.dataset.id;
        viewUserDetails(id);
    }
    });

    // View modal close handlers
    viewModalClose.addEventListener('click', closeViewModal);
    viewClose.addEventListener('click', closeViewModal);
    viewModal.addEventListener('click', (e) => { if (e.target === viewModal) closeViewModal(); });

    // Simple tab switching for admin sections
    const menu = document.getElementById("admin-menu");
    const sections = [
    "overview",
    "members",
    "users",
    "loans",
    "accounts",
    "shares",
    "payments",
    "expenses",
    "transactions",
    "assets",
    "notifications",
    "reports",
    "settings",
    ];
    const titles = {
    overview: "Isuzuma rusange ry'Ikimina",
    members: "Urutonde n'imicungire y'abanyamuryango",
    users: "Imicungire y'abakoresha (Create / Edit / Delete)",
    accounts: "Amafaranga (Accounts) - Imicungire y'ama konti",
    loans: "Inguzanyo zose z'Ikimina",
    shares: "Imigabane n'inyungu zayo",
    payments: "Kwishyura kw'inguzanyo",
    expenses: "Expenses z'Ikimina",
    transactions: "Transactions - Izafari z'amafaranga",
    assets: "Imutungo (Assets) y'Ikimina",
    notifications: "Notifications (Ubutumwa bwo kumenyesha)",
    reports: "Raporo z'ingenzi z'Ikimina",
    settings: "Igenamiterere (Settings) z'Ikimina",
    };

    menu.querySelectorAll("button[data-section]").forEach((btn) => {
    btn.addEventListener("click", () => {
        const key = btn.getAttribute("data-section");

        // active link style
        menu.querySelectorAll("button[data-section]").forEach((b) => {
        b.classList.remove("sidebar-link-active");
        });
        btn.classList.add("sidebar-link-active");

        // toggle sections
        sections.forEach((s) => {
        const el = document.getElementById(`section-${s}`);
        if (el) {
            const hide = (s !== key);
            el.classList.toggle("hidden", hide);
            if(s === 'notifications'){
            console.log('notification section visibility set to', !hide);
            }
        }
        });

        const titleEl = document.getElementById("section-title");
        if (titleEl && titles[key]) {
        titleEl.textContent = titles[key];
        }
        // ensure the newly shown section is scrolled into view (prefer the inner .card)
        const sectionEl = document.getElementById(`section-${key}`);
        const targetCard = sectionEl ? sectionEl.querySelector('.card') : null;
        const target = targetCard || sectionEl;
        if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
        window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
    });
    // Users management JS
    (function () {
    const form = document.getElementById('user-form');
    const tbody = document.getElementById('users-tbody');
    const btnNew = document.getElementById('btn-new-user');
    const btnRefresh = document.getElementById('btn-refresh-users');
    const saveBtn = document.getElementById('user-save');
    const modal = document.getElementById('user-modal');
    const modalTitle = document.getElementById('modal-title');
    const modalClose = document.getElementById('modal-close');

    let currentPage = 1;
    let perPage = 10;
    let currentQuery = '';
    let lastTotal = 0;

    // Use escapeHtml from global scope
    function escapeHtml(s){
        return globalEscapeHtml(s);
    }

    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }

    async function fetchUsers(page = currentPage, q = currentQuery){
        try{
        const url = `${apiUrl}?page=${page}&per_page=${perPage}` + (q ? `&q=${encodeURIComponent(q)}` : '');
        const res = await fetch(url, {cache: 'no-store'});
        if (!res.ok) {
            if (res.status === 403) {
            alert('Unauthorized. Please log in as admin.');
            return;
            }
            const txt = await res.text();
            console.error('fetchUsers error', res.status, txt);
            alert('Error loading users: ' + res.status);
            return;
        }
        const json = await res.json();
        if(json.success){
            currentPage = json.page || page;
            perPage = json.per_page || perPage;
            lastTotal = json.total || 0;
            renderUsersTable(json.data || []);
            updatePagination();
        } else {
            alert(json.message || 'Error loading users');
        }
        }catch(e){ console.error(e); alert('Network error'); }
    }

    function renderUsersTable(users){
        tbody.innerHTML = '';
        users.forEach(u => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${tbody.children.length + 1}</td>
            <td>${escapeHtml(u.names)}</td>
            <td>${escapeHtml(u.email)}</td>
            <td>${escapeHtml(u.phone1 || '')}</td>
            <td>${u.is_member ? 'Yes' : 'No'}</td>
            <td>${u.is_admin ? 'Yes' : 'No'}</td>
            <td>
            <button class="btn-ghost btn-view" data-id="${u.id}">Reba</button>
            <button class="btn-ghost btn-edit" data-id="${u.id}">Hindura</button>
            <button class="btn-ghost-danger btn-delete" data-id="${u.id}">Siba</button>
            </td>
        `;
        tbody.appendChild(tr);
        });
        // attach handlers for edit and delete only (view is handled globally via event delegation)
        tbody.querySelectorAll('.btn-edit').forEach(b=>b.addEventListener('click', onEdit));
        tbody.querySelectorAll('.btn-delete').forEach(b=>b.addEventListener('click', onDelete));
    }

    function updatePagination(){
        const pageEl = document.getElementById('users-page');
        const prev = document.getElementById('users-prev');
        const next = document.getElementById('users-next');
        const totalPages = Math.max(1, Math.ceil(lastTotal / perPage));
        pageEl.textContent = `${currentPage} / ${totalPages}`;
        prev.disabled = currentPage <= 1;
        next.disabled = currentPage >= totalPages;
    }

    function clearForm(){
        form.reset();
        document.getElementById('user-id').value = '';
        saveBtn.textContent = 'Bika';
        modalTitle.textContent = 'Umunyamukoresha Mushya';
        // Reset conditional sections
        document.getElementById('has-phone2').checked = false;
        document.getElementById('phone2-section').classList.add('hidden');
        document.getElementById('has-guarantor').checked = false;
        document.getElementById('guarantor-section').classList.add('hidden');
        document.getElementById('has-guarantee-phone2').checked = false;
        document.getElementById('guarantee-phone2-section').classList.add('hidden');
    }

    function fillForm(u){
        document.getElementById('user-id').value = u.id;
        document.getElementById('user-names').value = u.names || '';
        document.getElementById('user-nid').value = u.nid_passport || '';
        document.getElementById('user-email').value = u.email || '';
        document.getElementById('user-phone1').value = u.phone1 || '';
        document.getElementById('user-password').value = '';
        document.getElementById('user-is-member').checked = !!u.is_member;
        document.getElementById('user-is-admin').checked = !!u.is_admin;

        // Handle phone2
        const hasPhone2 = u.phone2 && u.phone2.trim() !== '';
        document.getElementById('has-phone2').checked = hasPhone2;
        document.getElementById('phone2-section').classList.toggle('hidden', !hasPhone2);
        document.getElementById('user-phone2').value = u.phone2 || '';

        // Handle guarantor
        const hasGuarantor = u.guarantee_name && u.guarantee_name.trim() !== '';
        document.getElementById('has-guarantor').checked = hasGuarantor;
        document.getElementById('guarantor-section').classList.toggle('hidden', !hasGuarantor);
        document.getElementById('user-guarantee-name').value = u.guarantee_name || '';
        document.getElementById('user-guarantee-nid').value = u.guarantee_nid_passport || '';
        document.getElementById('user-guarantee-email').value = u.guarantee_email || '';
        document.getElementById('user-guarantee-phone1').value = u.guarantee_phone1 || '';

        // Handle guarantee phone2
        const hasGuaranteePhone2 = u.guarantee_phone2 && u.guarantee_phone2.trim() !== '';
        document.getElementById('has-guarantee-phone2').checked = hasGuaranteePhone2;
        document.getElementById('guarantee-phone2-section').classList.toggle('hidden', !hasGuaranteePhone2);
        document.getElementById('user-guarantee-phone2').value = u.guarantee_phone2 || '';

        saveBtn.textContent = 'Hindura';
        modalTitle.textContent = 'Guhindura Umukoresha';
        openModal();
    }

    async function onEdit(e){
        const id = e.currentTarget.dataset.id;
        try{
        const res = await fetch(`${apiUrl}?id=${encodeURIComponent(id)}`, {cache:'no-store'});
        const json = await res.json();
        if(json.success && json.data){ fillForm(json.data); }
        }catch(e){console.error(e);}
    }

    async function onDelete(e){
        const id = e.currentTarget.dataset.id;
        if(!confirm('Urashaka koko gusiba uyu mukoresha?')) return;
        const fd = new FormData(); fd.append('action','delete'); fd.append('id', id);
        try{
        const res = await fetch(apiUrl, {method:'POST', body: fd});
        const json = await res.json();
        if(json.success){ fetchUsers(1); }
        else alert(json.message || 'Error deleting');
        }catch(err){ console.error(err); alert('Network error'); }
    }

    form.addEventListener('submit', async (ev)=>{
        ev.preventDefault();
        const id = document.getElementById('user-id').value;
        const action = id ? 'update' : 'create';
        const fd = new FormData(form);
        fd.append('action', action);
        try{
        const res = await fetch(apiUrl, {method:'POST', body: fd});
        const json = await res.json();
        if(json.success){ fetchUsers(1); clearForm(); closeModal(); }
        else alert(json.message || 'Error saving user');
        }catch(e){ console.error(e); alert('semaza'); }
    });

    // Modal handlers
    btnNew.addEventListener('click', ()=>{ clearForm(); openModal(); document.getElementById('user-names').focus(); });
    btnRefresh.addEventListener('click', ()=> fetchUsers(1));
    document.getElementById('user-cancel').addEventListener('click', closeModal);
    modalClose.addEventListener('click', closeModal);
    modal.addEventListener('click', (e)=>{ if(e.target === modal) closeModal(); });

    // Ensure save button submits the form (in case it's outside the form)
    if (saveBtn) {
        saveBtn.addEventListener('click', (e) => {
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
        });
    }

    // Conditional visibility toggles
    document.getElementById('has-phone2').addEventListener('change', (e)=>{
        const section = document.getElementById('phone2-section');
        section.classList.toggle('hidden', !e.target.checked);
    });
    document.getElementById('has-guarantor').addEventListener('change', (e)=>{
        const section = document.getElementById('guarantor-section');
        section.classList.toggle('hidden', !e.target.checked);
    });
    document.getElementById('has-guarantee-phone2').addEventListener('change', (e)=>{
        const section = document.getElementById('guarantee-phone2-section');
        section.classList.toggle('hidden', !e.target.checked);
    });

    // Search and pagination controls
    const searchInput = document.getElementById('users-search');
    const searchBtn = document.getElementById('users-search-btn');
    const prevBtn = document.getElementById('users-prev');
    const nextBtn = document.getElementById('users-next');

    let searchTimer = null;
    searchInput.addEventListener('input', (e)=>{
        clearTimeout(searchTimer);
        searchTimer = setTimeout(()=>{
        currentQuery = e.target.value.trim();
        fetchUsers(1, currentQuery);
        }, 300);
    });
    searchBtn.addEventListener('click', ()=>{ currentQuery = searchInput.value.trim(); fetchUsers(1, currentQuery); });

    prevBtn.addEventListener('click', ()=>{ if (currentPage>1){ currentPage--; fetchUsers(currentPage, currentQuery); } });
    nextBtn.addEventListener('click', ()=>{ const totalPages = Math.max(1, Math.ceil(lastTotal / perPage)); if (currentPage<totalPages){ currentPage++; fetchUsers(currentPage, currentQuery); } });

    // Fetch current session info (admin name) and show in header
    (async function loadSession(){
        try{
        const resp = await fetch('../get_session.php', {cache: 'no-store'});
        const js = await resp.json();
        if(js.success && js.data && js.data.names){
            const el = document.getElementById('admin-name');
            if(el) el.textContent = js.data.names;
        }
        }catch(e){ /* ignore */ }
    })();

    // initial load
    fetchUsers(1);
    })();

    // Accounts management JS
    (function(){
    const api = 'accounts_api.php';
    const tbody = document.getElementById('accounts-tbody');
    const btnNew = document.getElementById('btn-new-account');
    const btnRefresh = document.getElementById('btn-refresh-accounts');
    const modal = document.getElementById('account-modal');
    const modalClose = document.getElementById('account-modal-close');
    const saveBtn = document.getElementById('account-save');
    const cancelBtn = document.getElementById('account-cancel');
    const form = document.getElementById('account-form');

    if(!tbody) return; // nothing to do if section not present

    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }

    async function fetchAccounts(){
        try{
        const res = await fetch(`${api}?per_page=200`);
        if(!res.ok){ console.error('fetchAccounts', res.status); return; }
        const json = await res.json();
        if(json.success){ renderAccountsTable(json.data || []); }
        else console.error('accounts load', json);
        }catch(err){ console.error('fetchAccounts error', err); }
    }

    function renderAccountsTable(rows){
        tbody.innerHTML = '';
        rows.forEach(r => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${r.account_id}</td>
            <td>${globalEscapeHtml(r.name)}</td>
            <td>${globalEscapeHtml(r.type)}</td>
            <td>${globalEscapeHtml(r.account_number || '')}</td>
            <td>${globalEscapeHtml(r.created_at)}</td>
            <td>
            <button class="btn-ghost btn-edit-account" data-id="${r.account_id}">Hindura</button>
            <button class="btn-ghost-danger btn-delete-account" data-id="${r.account_id}">Siba</button>
            </td>
        `;
        tbody.appendChild(tr);
        });

        tbody.querySelectorAll('.btn-delete-account').forEach(b => b.addEventListener('click', async (e)=>{
        const id = b.getAttribute('data-id');
        if(!confirm('Urashaka gusiba iyi konti?')) return;
        const fd = new FormData(); fd.append('action','delete'); fd.append('id', id);
        try{
            const res = await fetch(api, {method:'POST', body: fd});
            const json = await res.json();
            if(json.success) fetchAccounts(); else alert(json.message||'Error');
        }catch(err){ console.error(err); alert('Network error'); }
        }));

        tbody.querySelectorAll('.btn-edit-account').forEach(b => b.addEventListener('click', async ()=>{
        const id = b.getAttribute('data-id');
        try{
            const res = await fetch(`${api}?id=${encodeURIComponent(id)}`);
            const json = await res.json();
            if(json.success && json.data){
            const d = json.data;
            document.getElementById('account-id').value = d.account_id;
            document.getElementById('account-name').value = d.name || '';
            document.getElementById('account-type').value = d.type || 'cash';
            document.getElementById('account-number').value = d.account_number || '';
            openModal();
            } else alert(json.message||'Not found');
        }catch(err){ console.error(err); }
        }));
    }

    if(btnNew) btnNew.addEventListener('click', ()=>{ form.reset(); document.getElementById('account-id').value = ''; openModal(); document.getElementById('account-name').focus(); });
    if(btnRefresh) btnRefresh.addEventListener('click', fetchAccounts);
    if(modalClose) modalClose.addEventListener('click', closeModal);
    if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if(modal) modal.addEventListener('click', (e)=>{ if(e.target===modal) closeModal(); });

    if(saveBtn){ saveBtn.addEventListener('click', async ()=>{
        const id = document.getElementById('account-id').value;
        const fd = new FormData(form);
        fd.append('action', id ? 'update' : 'create');
        if(id) fd.append('id', id);
        try{
        const res = await fetch(api, {method:'POST', body: fd});
        const json = await res.json();
        if(json.success){ closeModal(); fetchAccounts(); } else { alert(json.message || 'Error saving'); }
        }catch(err){ console.error(err); alert('Network error'); }
    }); }

    // initial load
    fetchAccounts();
    })();

    // Settings management JS
    (function () {
    const api = 'settings_api.php';

    const GROUP_LABELS = {
        rates:         '💰 Inyungu & Inguzanyo',
        org:           "🏢 Amakuru y'Ikimina",
        contributions: '📅 Imigabane & Inama',
        notifications: '🔔 Notifications',
        custom:        '⚙️ Custom Settings',
    };

    // ── CSS for tabs (injected once) ────────────────────────────────
    const style = document.createElement('style');
    style.textContent = `
        .settings-tab {
        padding: 8px 16px; border-bottom: 2px solid transparent;
        color:#64748b; font-size:0.8rem; background:none;
        cursor:pointer; transition:all .15s; white-space:nowrap;
        }
        .settings-tab:hover { color:#2F6B4F; }
        .settings-tab-active { border-bottom-color:#2F6B4F; color:#2F6B4F; font-weight:600; }
        .toggle-track { width:40px;height:22px;background:#d1d5db;border-radius:999px;position:relative;transition:background .2s;cursor:pointer;display:inline-block;vertical-align:middle; }
        .toggle-track.on { background:#2F6B4F; }
        .toggle-thumb { width:18px;height:18px;background:#fff;border-radius:50%;position:absolute;top:2px;left:2px;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2); }
        .toggle-track.on .toggle-thumb { transform:translateX(18px); }
    `;
    document.head.appendChild(style);

    // ── Helpers ─────────────────────────────────────────────────────
    const esc = s => (s==null?'':String(s)).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c]));
    const fmt = v => Number(v).toLocaleString('rw-RW');

    // ── Tab switching ────────────────────────────────────────────────
    let activeTab = 'rates';
    document.querySelectorAll('.settings-tab').forEach(b => {
        b.addEventListener('click', () => switchTab(b.dataset.stab));
    });
    function switchTab(g) {
        activeTab = g;
        document.querySelectorAll('.settings-tab').forEach(b =>
        b.classList.toggle('settings-tab-active', b.dataset.stab === g));
        ['rates','org','contributions','notifications','custom'].forEach(x => {
        const p = document.getElementById('settings-panel-' + x);
        if (p) p.classList.toggle('hidden', x !== g);
        });
    }

    // ── Load all settings ────────────────────────────────────────────
    async function loadSettings() {
        const loading = document.getElementById('settings-loading');
        if (loading) loading.classList.remove('hidden');
        try {
        const res  = await fetch(api, { cache:'no-store', credentials:'include' });
        const json = await res.json();
        if (!json.success) { alert(json.message); return; }
        renderAll(json.data || {});
        if (loading) loading.classList.add('hidden');
        switchTab(activeTab);
        } catch(e) {
        console.error(e);
        if (loading) loading.textContent = 'Failed to load settings.';
        }
    }

    // ── Render all groups ────────────────────────────────────────────
    function renderAll(groups) {
        ['rates','org','contributions','notifications','custom'].forEach(g => {
        const panel = document.getElementById('settings-panel-' + g);
        if (!panel) return;
        const rows = groups[g] || [];
        panel.innerHTML = '';

        if (g === 'custom' && rows.length === 0) {
            panel.innerHTML = `<div class="card text-center py-8 text-slate-400 text-sm">
            Nta custom settings. Kanda "+ Setting Nshya" wongere.</div>`;
            return;
        }

        // Card wrapper
        const card = document.createElement('div');
        card.className = 'card space-y-0';

        // Header row
        const hdr = document.createElement('div');
        hdr.className = 'flex items-center justify-between mb-4';
        hdr.innerHTML = `
            <div>
            <p class="text-sm font-semibold text-primary-dark">${GROUP_LABELS[g] || g}</p>
            <p class="text-xs text-slate-500">Hindura hano maze ukande "Bika Ibizamuka"</p>
            </div>
            ${g !== 'custom' ? `<button class="btn-primary text-xs settings-save-group" data-group="${g}">Bika Ibizamuka</button>` : ''}
        `;
        card.appendChild(hdr);

        // Table layout for settings rows
        const table = document.createElement('table');
        table.className = 'w-full text-sm border-collapse';
        table.innerHTML = `<thead><tr class="border-b border-gray-100 text-xs text-slate-500">
            <th class="text-left py-2 pr-3 font-medium w-1/3">Setting</th>
            <th class="text-left py-2 pr-3 font-medium w-1/2">Value</th>
            <th class="text-right py-2 font-medium">Ibikorwa</th>
        </tr></thead>`;
        const tbody = document.createElement('tbody');
        tbody.id = 'settings-tbody-' + g;

        rows.forEach(s => tbody.appendChild(buildRow(s)));
        table.appendChild(tbody);
        card.appendChild(table);

        // Last updated
        const upd = rows.find(s => s.updated_at);
        if (upd) {
            const note = document.createElement('p');
            note.className = 'mt-3 text-[10px] text-slate-400 border-t pt-2';
            note.textContent = `Hindura ya nyuma: ${upd.updated_at}`;
            card.appendChild(note);
        }
        panel.appendChild(card);
        });

        // Wire group-save buttons
        document.querySelectorAll('.settings-save-group').forEach(btn =>
        btn.addEventListener('click', () => saveGroup(btn.dataset.group, btn)));
    }

    // ── Build a single table row ─────────────────────────────────────
    function buildRow(s) {
        const tr = document.createElement('tr');
        tr.className = 'border-b border-gray-50 hover:bg-slate-50 group';
        tr.dataset.key = s.setting_key;

        let inputHtml;
        if (s.readonly) {
        inputHtml = `<span class="text-slate-400 text-xs">${esc(s.setting_value)}</span>`;
        } else if (s.type === 'boolean') {
        const on = s.setting_value === '1';
        inputHtml = `
            <span class="toggle-track ${on?'on':''}" data-key="${esc(s.setting_key)}" title="Click to toggle">
            <span class="toggle-thumb"></span>
            </span>
            <input type="hidden" class="settings-field" data-key="${esc(s.setting_key)}" data-type="boolean" value="${on?'1':'0'}" />
            <span class="ml-2 text-xs text-slate-500 toggle-label">${on?'Enabled':'Disabled'}</span>
        `;
        } else {
        inputHtml = `<input type="${s.type==='number'?'number':'text'}"
            class="settings-field w-full rounded border border-transparent px-2 py-1 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary bg-transparent group-hover:border-gray-200"
            data-key="${esc(s.setting_key)}" data-type="${esc(s.type)}"
            value="${esc(s.setting_value)}" ${s.type==='number'?'step="any"':''} />`;
        }

        const canDelete = !s.is_default_key;
        tr.innerHTML = `
        <td class="py-2 pr-3 align-middle">
            <p class="text-xs font-medium text-slate-700">${esc(s.label||s.setting_key)}</p>
            <p class="text-[10px] text-slate-400 font-mono">${esc(s.setting_key)}</p>
        </td>
        <td class="py-2 pr-3 align-middle">${inputHtml}</td>
        <td class="py-2 align-middle text-right whitespace-nowrap">
            <button class="btn-ghost text-xs btn-setting-edit" data-key="${esc(s.setting_key)}">Hindura</button>
            ${canDelete
            ? `<button class="btn-ghost-danger text-xs btn-setting-delete" data-key="${esc(s.setting_key)}">Siba</button>`
            : `<span class="text-[10px] text-slate-300 px-2">default</span>`}
        </td>
        `;

        // Toggle click handler
        const track = tr.querySelector('.toggle-track');
        if (track) {
        track.addEventListener('click', () => {
            const on = track.classList.toggle('on');
            const hidden = tr.querySelector('input[type="hidden"].settings-field');
            const label  = tr.querySelector('.toggle-label');
            if (hidden) hidden.value = on ? '1' : '0';
            if (label)  label.textContent = on ? 'Enabled' : 'Disabled';
        });
        }

        // Inline quick-save on blur for non-boolean, non-readonly
        tr.querySelectorAll('input.settings-field:not([type="hidden"])').forEach(inp => {
        inp.addEventListener('blur', () => quickSave(inp.dataset.key, inp.value));
        });

        // Edit button → open modal pre-filled
        tr.querySelector('.btn-setting-edit')?.addEventListener('click', () => openEditModal(s));

        // Delete button
        tr.querySelector('.btn-setting-delete')?.addEventListener('click', () => deleteSetting(s.setting_key));

        return tr;
    }

    // ── Quick-save single value on blur ─────────────────────────────
    async function quickSave(key, value) {
        const fd = new FormData();
        fd.append('action','update'); fd.append('setting_key',key); fd.append('setting_value',value);
        try {
        const res  = await fetch(api, { method:'POST', body:fd, credentials:'include' });
        const json = await res.json();
        if (!json.success) console.warn('quickSave failed', json.message);
        } catch(e) { console.error(e); }
    }

    // ── Save whole group ─────────────────────────────────────────────
    async function saveGroup(group, btn) {
        const tbody = document.getElementById('settings-tbody-' + group);
        if (!tbody) return;

        const fd = new FormData();
        fd.append('action','update_group'); fd.append('group',group);
        tbody.querySelectorAll('.settings-field').forEach(f => {
        fd.append(f.dataset.key, f.type==='checkbox' ? (f.checked?'1':'0') : f.value);
        });

        const orig = btn.textContent;
        btn.textContent = 'Bitegurwa...'; btn.disabled = true;
        try {
        const res  = await fetch(api, { method:'POST', body:fd, credentials:'include' });
        const json = await res.json();
        if (json.success) {
            btn.textContent = '✓ Byabitswe!';
            btn.classList.add('!bg-green-600');
            setTimeout(() => { btn.textContent=orig; btn.classList.remove('!bg-green-600'); btn.disabled=false; loadSettings(); }, 1800);
        } else { alert(json.message); btn.textContent=orig; btn.disabled=false; }
        } catch(e) { console.error(e); btn.textContent=orig; btn.disabled=false; }
    }

    // ── Delete setting ───────────────────────────────────────────────
    async function deleteSetting(key) {
        if (!confirm(`Urashaka gusiba setting "${key}"?`)) return;
        const fd = new FormData();
        fd.append('action','delete'); fd.append('setting_key',key);
        try {
        const res  = await fetch(api, { method:'POST', body:fd, credentials:'include' });
        const json = await res.json();
        if (json.success) loadSettings(); else alert(json.message);
        } catch(e) { console.error(e); }
    }

    // ── Modal ────────────────────────────────────────────────────────
    const modal       = document.getElementById('setting-modal');
    const modalTitle  = document.getElementById('setting-modal-title');
    const keyInput    = document.getElementById('setting-key-input');
    const labelInput  = document.getElementById('setting-label-input');
    const valueInput  = document.getElementById('setting-value-input');
    const typeInput   = document.getElementById('setting-type-input');
    const groupInput  = document.getElementById('setting-group-input');
    const editKeyEl   = document.getElementById('setting-edit-key');

    function openModal()  { modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal() { modal.classList.add('hidden');    modal.classList.remove('flex'); }

    function clearModal() {
        keyInput.value=''; labelInput.value=''; valueInput.value='';
        typeInput.value='text'; groupInput.value='custom'; editKeyEl.value='';
        keyInput.disabled = false;
        modalTitle.textContent = 'Setting Nshya';
    }

    function openEditModal(s) {
        clearModal();
        editKeyEl.value     = s.setting_key;
        keyInput.value      = s.setting_key;
        keyInput.disabled   = true;           // key is the PK, cannot rename
        labelInput.value    = s.label || '';
        valueInput.value    = s.setting_value;
        typeInput.value     = s.type || 'text';
        groupInput.value    = s.group || 'custom';
        modalTitle.textContent = 'Hindura Setting';
        openModal();
    }

    // New setting button
    document.getElementById('btn-new-setting')?.addEventListener('click', () => { clearModal(); openModal(); keyInput.focus(); });
    document.getElementById('setting-modal-close')?.addEventListener('click',  closeModal);
    document.getElementById('setting-modal-cancel')?.addEventListener('click', closeModal);
    modal?.addEventListener('click', e => { if (e.target===modal) closeModal(); });

    document.getElementById('setting-modal-save')?.addEventListener('click', async () => {
        const editKey = editKeyEl.value;
        const isEdit  = !!editKey;
        const fd = new FormData();

        if (isEdit) {
        fd.append('action','update');
        fd.append('setting_key',   editKey);
        fd.append('setting_value', valueInput.value.trim());
        } else {
        fd.append('action','create');
        fd.append('setting_key',   keyInput.value.trim());
        fd.append('setting_value', valueInput.value.trim());
        fd.append('label',         labelInput.value.trim());
        fd.append('type',          typeInput.value);
        fd.append('group',         groupInput.value);
        }

        try {
        const res  = await fetch(api, { method:'POST', body:fd, credentials:'include' });
        const json = await res.json();
        if (json.success) { closeModal(); loadSettings(); } else alert(json.message);
        } catch(e) { console.error(e); alert('Network error'); }
    });

    // ── Auto-load when tab clicked in sidebar ────────────────────────
    document.querySelector('[data-section="settings"]')
        ?.addEventListener('click', loadSettings);

    })(); // end Settings module


    /* ══════════════════════════════════════════════════════════════════
    OVERVIEW LIVE CARDS + CHART MODULE
    ══════════════════════════════════════════════════════════════════ */
    (function () {
    const api = 'overview_api.php';
    let chartInstance = null;

    const fmt = (v, frw=true) => Number(v||0).toLocaleString('rw-RW') + (frw?' Frw':'');

    async function loadOverview() {
        try {
        const res  = await fetch(api, { cache:'no-store', credentials:'include' });
        const json = await res.json();
        if (!json.success) { console.error('overview', json.message); return; }

        const s = json.stats;

        // ── Cards ─────────────────────────────────────────────────
        const el = id => document.getElementById(id);

        if (el('ov-members'))  el('ov-members').textContent  = s.total_members;
        if (el('ov-loans'))    el('ov-loans').textContent    = s.total_loans;
        if (el('ov-loans-sub'))el('ov-loans-sub').textContent= `Izikora: ${s.active_loans} – Zose: ${s.total_loans}`;
        if (el('ov-interest')) el('ov-interest').textContent = fmt(s.total_interest);
        if (el('ov-expenses')) el('ov-expenses').textContent = fmt(s.total_expenses);

        // ── Pending loans table ────────────────────────────────────
        const pendingTbody = document.querySelector('#section-overview .card table:first-of-type tbody');
        if (pendingTbody && json.pending_loans) {
            pendingTbody.innerHTML = '';
            if (json.pending_loans.length === 0) {
            pendingTbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-sm text-slate-400">Nta nguzanyo zitegereje</td></tr>`;
            } else {
            json.pending_loans.forEach(l => {
                const tr = document.createElement('tr');
                const badge = l.status==='approved'
                ? '<span class="badge badge-success">Yemejwe</span>'
                : '<span class="badge badge-warning">Irategereje</span>';
                tr.innerHTML = `
                <td>${l.borrower_name}</td>
                <td>${Number(l.principal_amount).toLocaleString('rw-RW')}</td>
                <td>${l.start_date||''}</td>
                <td>${badge}</td>
                <td><button class="btn-ghost text-xs">Hindura</button></td>
                `;
                pendingTbody.appendChild(tr);
            });
            }
        }

        // ── Recent payments table ──────────────────────────────────
        const payTbody = document.querySelector('#section-overview .card:last-of-type table tbody');
        if (payTbody && json.recent_payments) {
            payTbody.innerHTML = '';
            if (json.recent_payments.length === 0) {
            payTbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-sm text-slate-400">Nta payments zibonetse</td></tr>`;
            } else {
            json.recent_payments.forEach(p => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                <td>${p.user_name||''}</td>
                <td>${p.tx_date||''}</td>
                <td>${Number(p.amount||0).toLocaleString('rw-RW')}</td>
                <td>${p.loan_id ? '#LN-'+p.loan_id : '—'}</td>
                <td><span class="badge badge-success">Byakiriwe</span></td>
                <td><button class="btn-ghost text-xs">Hindura</button></td>
                `;
                payTbody.appendChild(tr);
            });
            }
        }

        // ── Chart ──────────────────────────────────────────────────
        const chartEl = document.getElementById('portfolioChart');
        if (chartEl && window.Chart && json.chart) {
            const c = json.chart;
            if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
            chartInstance = new Chart(chartEl, {
            type: 'line',
            data: {
                labels: c.labels,
                datasets: [
                { label:'Ishoramari (Imigabane)',  data:c.invest,   borderColor:'#2F6B4F', backgroundColor:'rgba(47,107,79,.1)',  tension:.35, borderWidth:2 },
                { label:'Inguzanyo ziriho',         data:c.loans,    borderColor:'#E89C2C', backgroundColor:'rgba(232,156,44,.08)',tension:.35, borderWidth:2 },
                { label:'Assets',                   data:c.assets,   borderColor:'#6B4A2D', backgroundColor:'rgba(107,74,45,.08)', tension:.35, borderWidth:2 },
                { label:'Expenses',                 data:c.expenses, borderColor:'#DC2626', backgroundColor:'rgba(220,38,38,.06)', tension:.35, borderWidth:2 },
                ],
            },
            options: {
                responsive:true, maintainAspectRatio:false,
                plugins: {
                legend:{ position:'bottom', labels:{ usePointStyle:true, boxWidth:8 } },
                tooltip:{ callbacks:{ label: ctx => `${ctx.dataset.label}: ${Number(ctx.parsed.y||0).toLocaleString('rw-RW')} Frw` } },
                },
                scales: {
                y:{ ticks:{ callback: v=>`${Number(v).toLocaleString('rw-RW')} Frw` }, grid:{ color:'rgba(148,163,184,.2)' } },
                x:{ grid:{ display:false } },
                },
            },
            });
        }

        } catch(e) { console.error('loadOverview', e); }
    }

    // Load on page ready AND when Overview sidebar tab is clicked
    loadOverview();
    document.querySelector('[data-section="overview"]')
        ?.addEventListener('click', loadOverview);

    })(); // end Overview module

    // Assets management JS
    (function(){
    const api = 'assets_api.php';
    const tbody = document.getElementById('assets-tbody');
    const btnNew = document.getElementById('btn-new-asset');
    const btnRefresh = document.getElementById('btn-refresh-assets');
    const searchInput = document.getElementById('assets-search');
    const searchBtn = document.getElementById('assets-search-btn');
    const modal = document.getElementById('asset-modal');
    const modalClose = document.getElementById('asset-modal-close');
    const saveBtn = document.getElementById('asset-save');
    const cancelBtn = document.getElementById('asset-cancel');
    const form = document.getElementById('asset-form');

    if(!tbody) return; // nothing to do if section not present

    let currentQuery = '';
    let searchTimer = null;

    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }

    async function fetchAssets(q = currentQuery){
        try{
        const url = `${api}?per_page=200` + (q ? `&q=${encodeURIComponent(q)}` : '');
        const res = await fetch(url);
        if(!res.ok){ console.error('fetchAssets', res.status); return; }
        const json = await res.json();
        if(json.success){ renderAssetsTable(json.data || []); }
        else console.error('assets load', json);
        }catch(err){ console.error('fetchAssets error', err); }
    }

    function renderAssetsTable(rows){
        tbody.innerHTML = '';
        rows.forEach(r => {
        const tr = document.createElement('tr');
        let certificateHtml = '';
        if (r.certificate_file && r.certificate_mime && r.certificate_file !== null) {
            const mimeType = r.certificate_mime;
            if (mimeType && mimeType.startsWith('image/')) {
            try {
                const hexStr = r.certificate_file;
                const bytes = [];
                for (let i = 0; i < hexStr.length; i += 2) {
                bytes.push(parseInt(hexStr.substr(i, 2), 16));
                }
                const binStr = String.fromCharCode(...bytes);
                const b64 = btoa(binStr);
                certificateHtml = `<img src="data:${mimeType};base64,${b64}" alt="Certificate" class="h-16 w-auto rounded" />`;
            } catch(e) {
                certificateHtml = `<span class="text-xs text-slate-500">📄 ${globalEscapeHtml(r.certificate_name || 'Document')}</span>`;
            }
            } else {
            certificateHtml = `<span class="text-xs text-slate-500">📄 ${globalEscapeHtml(r.certificate_name || 'Document')}</span>`;
            }
        }
        tr.innerHTML = `
            <td>${r.asset_id}</td>
            <td>${globalEscapeHtml(r.name)}</td>
            <td>${globalEscapeHtml(r.purchase_date)}</td>
            <td>${Number(r.purchase_value).toLocaleString('rw-RW')} Frw</td>
            <td>${globalEscapeHtml(r.location || '')}</td>
            <td>${certificateHtml}</td>
            <td>${Number(r.sold_value||0).toLocaleString('rw-RW')} Frw</td>
            <td>
            <button class="btn-ghost btn-edit-asset" data-id="${r.asset_id}">Hindura</button>
            <button class="btn-ghost-danger btn-delete-asset" data-id="${r.asset_id}">Siba</button>
            </td>
        `;
        tbody.appendChild(tr);
        });

        tbody.querySelectorAll('.btn-delete-asset').forEach(b => b.addEventListener('click', async ()=>{
        const id = b.getAttribute('data-id');
        if(!confirm('Urashaka gusiba iyi Imutungo?')) return;
        const fd = new FormData(); fd.append('action','delete'); fd.append('id', id);
        try{
            const res = await fetch(api, {method:'POST', body: fd});
            const json = await res.json();
            if(json.success) fetchAssets(); else alert(json.message||'Error');
        }catch(err){ console.error(err); alert('Network error'); }
        }));

        tbody.querySelectorAll('.btn-edit-asset').forEach(b => b.addEventListener('click', async ()=>{
        const id = b.getAttribute('data-id');
        try{
            const res = await fetch(`${api}?id=${encodeURIComponent(id)}`);
            const json = await res.json();
            if(json.success && json.data){
            const d = json.data;
            document.getElementById('asset-id').value = d.asset_id;
            document.getElementById('asset-name').value = d.name || '';
            document.getElementById('asset-purchase-date').value = d.purchase_date || '';
            document.getElementById('asset-purchase-value').value = d.purchase_value || '';
            document.getElementById('asset-location').value = d.location || '';
            document.getElementById('asset-notes').value = d.notes || '';
            document.getElementById('asset-certificate-name').value = d.certificate_name || '';
            const hasSold = d.sold_value && d.sold_value > 0;
            document.getElementById('has-sold-date').checked = hasSold;
            document.getElementById('sold-section').classList.toggle('hidden', !hasSold);
            document.getElementById('asset-sold-date').value = d.sold_date || '';
            document.getElementById('asset-sold-value').value = d.sold_value || '';
            openModal();
            } else alert(json.message||'Not found');
        }catch(err){ console.error(err); }
        }));
    }

    if(btnNew) btnNew.addEventListener('click', ()=>{ form.reset(); document.getElementById('asset-id').value = ''; document.getElementById('has-sold-date').checked = false; document.getElementById('sold-section').classList.add('hidden'); openModal(); document.getElementById('asset-name').focus(); });
    if(btnRefresh) btnRefresh.addEventListener('click', ()=>{ currentQuery = ''; searchInput.value = ''; fetchAssets(); });

    // Search functionality
    if(searchInput){
        searchInput.addEventListener('input', (e)=>{
        clearTimeout(searchTimer);
        searchTimer = setTimeout(()=>{
            currentQuery = e.target.value.trim();
            fetchAssets(currentQuery);
        }, 300);
        });
    }
    if(searchBtn){
        searchBtn.addEventListener('click', ()=>{
        currentQuery = searchInput.value.trim();
        fetchAssets(currentQuery);
        });
    }

    if(modalClose) modalClose.addEventListener('click', closeModal);
    if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if(modal) modal.addEventListener('click', (e)=>{ if(e.target===modal) closeModal(); });

    // Handle sold checkbox toggle
    if(document.getElementById('has-sold-date')) {
        document.getElementById('has-sold-date').addEventListener('change', (e)=>{
        document.getElementById('sold-section').classList.toggle('hidden', !e.target.checked);
        });
    }

    if(saveBtn){ saveBtn.addEventListener('click', async ()=>{
        const id = document.getElementById('asset-id').value;
        const hasSold = document.getElementById('has-sold-date').checked;
        
        // If not sold, clear the sold date and value fields
        if (!hasSold) {
        document.getElementById('asset-sold-date').value = '';
        document.getElementById('asset-sold-value').value = '';
        }
        
        const fd = new FormData(form);
        fd.append('action', id ? 'update' : 'create');
        if(id) fd.append('id', id);
        try{
        console.log('Submitting asset form with data:', fd);
        const res = await fetch(api, {method:'POST', body: fd});
        const json = await res.json();
        if(json.success){ closeModal(); fetchAssets(); } else { alert(json.message || 'Error saving'); }
        }catch(err){ console.error(err); alert('Network error'); }
    }); }

    // initial load
    fetchAssets();
    })();

    // Notifications management JS
    // Notifications management JS - FIXED VERSION
    (function(){
    console.log('Initializing notifications module...');
    
    const api = 'notifications_api.php';
    
    // IMPORTANT: Get references to NOTIFICATIONS elements, not assets
    const section = document.getElementById('section-notifications');
    const tbody = document.getElementById('notifications-tbody');
    const btnNew = document.getElementById('btn-new-notification');
    const btnRefresh = document.getElementById('btn-refresh-notifications');
    const searchInput = document.getElementById('notifications-search');
    const searchBtn = document.getElementById('notifications-search-btn');
    const filterType = document.getElementById('notifications-filter-type');
    const filterChannel = document.getElementById('notifications-filter-channel');
    const filterStatus = document.getElementById('notifications-filter-status');
    const prevBtn = document.getElementById('notifications-prev');
    const nextBtn = document.getElementById('notifications-next');
    const pageInfo = document.getElementById('notifications-pageinfo');

    const modal = document.getElementById('notification-modal');
    const modalTitle = document.getElementById('notification-modal-title');
    const modalClose = document.getElementById('notification-modal-close');
    const cancelBtn = document.getElementById('notification-cancel');
    const saveBtn = document.getElementById('notification-save');
    const form = document.getElementById('notification-form');
    const userSelect = document.getElementById('notification-user');

    // Verify we have the right elements
    console.log('Notifications tbody found:', !!tbody);
    console.log('Assets tbody should be different:', document.getElementById('assets-tbody'));

    if(!tbody) {
        console.error('Notifications tbody not found! Check if element ID is correct.');
        return;
    }

    let currentPage = 1;
    let perPage = 10;
    let currentQuery = '';
    let lastTotal = 0;
    let searchTimer = null;

    function openModal(){ 
        if(modal) {
        modal.classList.remove('hidden'); 
        modal.classList.add('flex'); 
        }
    }
    
    function closeModal(){ 
        if(modal) {
        modal.classList.add('hidden'); 
        modal.classList.remove('flex'); 
        }
    }

    function statusBadge(s){
        if(s === 'sent' || s === 'read') return '<span class="badge badge-success">'+ (s || '') +'</span>';
        if(s === 'failed') return '<span class="badge badge-danger">failed</span>';
        return '<span class="badge badge-warning">queued</span>';
    }

    async function loadUsers(){
        try{
        const res = await fetch('users_api.php?per_page=500', {credentials: 'include'});
        const json = await res.json();
        if(json.success && userSelect){
            userSelect.innerHTML = '<option value="">-- Hitamo --</option>';
            (json.data || []).forEach(u => {
            const opt = document.createElement('option');
            opt.value = u.id;
            opt.textContent = u.names;
            userSelect.appendChild(opt);
            });
        }
        }catch(err){ console.error('loadUsers', err); }
    }

    async function fetchNotifications(page = currentPage){
        const q = currentQuery.trim();
        const status = filterStatus?.value?.trim() || '';
        const channel = filterChannel?.value?.trim() || '';
        const type = filterType?.value?.trim() || '';

        const url = new URL(api, window.location.href);
        url.searchParams.set('page', page);
        url.searchParams.set('per_page', perPage);
        if(q) url.searchParams.set('q', q);
        if(status) url.searchParams.set('status', status);
        if(channel) url.searchParams.set('channel', channel);
        if(type) url.searchParams.set('type', type);

        console.log('Fetching notifications from:', url.toString());
        
        try{
        const res = await fetch(url.toString(), {credentials: 'include'});
        const json = await res.json();
        
        if(json.success){
            let rows = json.data?.rows || [];
            render(rows);
            
            lastTotal = json.data?.total || rows.length;
            currentPage = json.data?.page || page;
            const totalPages = Math.max(1, Math.ceil(lastTotal / perPage));
            if(pageInfo) pageInfo.textContent = `Page ${currentPage} / ${totalPages} (${lastTotal})`;
        }
        }catch(err){ 
        console.error('Fetch error:', err); 
        }
    }

    function render(rows){
        if(!tbody) return;
        
        tbody.innerHTML = '';

        if (!rows || rows.length === 0){
        const tr = document.createElement('tr');
        tr.innerHTML = `<td colspan="9" class="text-center py-4 text-sm text-slate-500">Nta notifications zabonetse</td>`;
        tbody.appendChild(tr);
        return;
        }

        rows.forEach((r, index) => {
        const tr = document.createElement('tr');
        tr.className = index % 2 === 0 ? 'bg-white' : 'bg-gray-50';
        
        tr.innerHTML = `
            <td class="px-4 py-3 text-sm">#NT-${r.notification_id || ''}</td>
            <td class="px-4 py-3 text-sm">${r.user_name || ''}</td>
            <td class="px-4 py-3 text-sm">${r.type || ''}</td>
            <td class="px-4 py-3 text-sm">${r.channel || ''}</td>
            <td class="px-4 py-3 text-sm">${statusBadge(r.status || '')}</td>
            <td class="px-4 py-3 text-sm">${r.scheduled_for || ''}</td>
            <td class="px-4 py-3 text-sm">${r.sent_at || ''}</td>
            <td class="px-4 py-3 text-sm max-w-[320px] truncate" title="${(r.message||'').replace(/"/g, '&quot;')}">${r.message || ''}</td>
            <td class="px-4 py-3 text-sm">
            <button class="btn-ghost btn-edit-notification" data-id="${r.notification_id}">Hindura</button>
            <button class="btn-ghost-danger btn-delete-notification" data-id="${r.notification_id}">Siba</button>
            </td>
        `;
        tbody.appendChild(tr);
        });
    }

    // Event delegation for edit/delete buttons
    if(tbody) {
        tbody.addEventListener('click', async (e)=>{
        const editBtn = e.target.closest('.btn-edit-notification');
        const delBtn = e.target.closest('.btn-delete-notification');
        
        if(editBtn){
            const id = editBtn.getAttribute('data-id');
            try{
            const res = await fetch(`${api}?id=${encodeURIComponent(id)}`, {credentials: 'include'});
            const json = await res.json();
            if(json.success && json.data){
                const d = json.data;
                if(document.getElementById('notification-id')) {
                document.getElementById('notification-id').value = d.notification_id;
                document.getElementById('notification-user').value = d.user_id;
                document.getElementById('notification-type').value = d.type;
                document.getElementById('notification-channel').value = d.channel || 'in_app';
                document.getElementById('notification-status').value = d.status || 'queued';
                document.getElementById('notification-message').value = d.message || '';
                const sched = d.scheduled_for ? String(d.scheduled_for).replace(' ', 'T').slice(0,16) : '';
                document.getElementById('notification-scheduled').value = sched;
                if(modalTitle) modalTitle.textContent = 'Hindura Notification';
                openModal();
                }
            }
            }catch(err){ console.error(err); }
        }
        
        if(delBtn){
            const id = delBtn.getAttribute('data-id');
            if(!confirm('Urashaka gusiba iyi notification?')) return;
            const fd = new FormData();
            fd.append('action','delete');
            fd.append('id', id);
            try{
            const res = await fetch(api, {method:'POST', body: fd, credentials: 'include'});
            const json = await res.json();
            if(json.success) fetchNotifications(currentPage);
            }catch(err){ console.error(err); }
        }
        });
    }

    // Event listeners
    if(btnNew) {
        btnNew.addEventListener('click', async ()=>{
        if(form) form.reset();
        if(document.getElementById('notification-id')) {
            document.getElementById('notification-id').value = '';
        }
        if(modalTitle) modalTitle.textContent = 'Ongeza Notification';
        await loadUsers();
        openModal();
        if(userSelect) userSelect.focus();
        });
    }
    
    if(btnRefresh) {
        btnRefresh.addEventListener('click', ()=>fetchNotifications(1));
    }
    
    if(modalClose) modalClose.addEventListener('click', closeModal);
    if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if(modal) {
        modal.addEventListener('click', (e)=>{ if(e.target === modal) closeModal(); });
    }

    if(saveBtn) {
        saveBtn.addEventListener('click', async ()=>{
        const id = document.getElementById('notification-id')?.value;
        if(!form) return;
        
        const fd = new FormData(form);
        fd.append('action', id ? 'update' : 'create');
        if(id) fd.append('id', id);
        
        try{
            const res = await fetch(api, {method:'POST', body: fd, credentials: 'include'});
            const json = await res.json();
            if(json.success){ 
            closeModal(); 
            fetchNotifications(id ? currentPage : 1); 
            }
        }catch(err){ console.error(err); }
        });
    }

    if(searchInput){
        searchInput.addEventListener('input', (e)=>{
        clearTimeout(searchTimer);
        searchTimer = setTimeout(()=>{
            currentQuery = (e.target.value || '').trim();
            fetchNotifications(1);
        }, 250);
        });
    }
    
    if(searchBtn){
        searchBtn.addEventListener('click', ()=>{
        currentQuery = (searchInput?.value || '').trim();
        fetchNotifications(1);
        });
    }

    if(filterType) filterType.addEventListener('change', ()=>fetchNotifications(1));
    if(filterChannel) filterChannel.addEventListener('change', ()=>fetchNotifications(1));
    if(filterStatus) filterStatus.addEventListener('change', ()=>fetchNotifications(1));

    if(prevBtn) {
        prevBtn.addEventListener('click', ()=>{ if(currentPage > 1) fetchNotifications(currentPage-1); });
    }
    
    if(nextBtn) {
        nextBtn.addEventListener('click', ()=>{
        const totalPages = Math.max(1, Math.ceil(lastTotal / perPage));
        if(currentPage < totalPages) fetchNotifications(currentPage+1);
        });
    }

    // Initial load
    console.log('Initial notifications load');
    fetchNotifications(1);
    })();
    // Expenses management JS
    (function(){
    const api = 'expenses_api.php';
    const tbody = document.getElementById('expenses-tbody');
    const btnNew = document.getElementById('btn-new-expense');
    const btnRefresh = document.getElementById('btn-refresh-expenses');
    const searchInput = document.getElementById('expenses-search');
    const searchBtn = document.getElementById('expenses-search-btn');
    const modal = document.getElementById('expense-modal');
    const modalClose = document.getElementById('expense-modal-close');
    const saveBtn = document.getElementById('expense-save');
    const cancelBtn = document.getElementById('expense-cancel');
    const form = document.getElementById('expense-form');
    const accountSelect = document.getElementById('expense-account');

    if(!tbody) return;

    let currentQuery = '';
    let searchTimer = null;

    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }

    // Load accounts for dropdown
    async function loadAccounts(){
        try{
        // Use the dedicated accounts API to avoid cross-endpoint output mixing
        const res = await fetch('accounts_api.php');
        const text = await res.text();
        let json = null;
        try{
            json = JSON.parse(text);
        }catch(parseErr){
            console.error('loadAccounts: response not JSON', text);
            alert('Failed to load accounts: server returned non-JSON response. See console for details.');
            return;
        }

        if(!res.ok){
            console.error('loadAccounts HTTP error:', res.status, json);
            alert('Failed to load accounts: ' + (json.message || ('HTTP ' + res.status)) + (json.output ? '\n\nServer output:\n' + json.output : '') + (json.debug_log ? '\n\nDebug log: ' + json.debug_log : ''));
            return;
        }

        if(json.success){
            accountSelect.innerHTML = '<option value="">-- Hitamo Konto --</option>';
            json.data.forEach(acc => {
            const opt = document.createElement('option');
            opt.value = acc.account_id;
            opt.textContent = acc.name;
            accountSelect.appendChild(opt);
            });
            console.log('Accounts loaded:', json.data.length);
        } else {
            console.error('Load accounts error:', json.message, json);
            alert('Failed to load accounts: ' + (json.message || 'Unknown error') + (json.output ? '\n\nServer output:\n' + json.output : '') + (json.debug_log ? '\n\nDebug log: ' + json.debug_log : ''));
        }
        }catch(err){ console.error('Load accounts fetch error', err); alert('Failed to load accounts: ' + err.message); }
    }

    async function fetchExpenses(q = currentQuery){
        try{
        const url = `${api}?per_page=200` + (q ? `&q=${encodeURIComponent(q)}` : '');
        const res = await fetch(url);
        if(!res.ok){ console.error('fetchExpenses', res.status); return; }
        const json = await res.json();
        if(json.success){ renderExpensesTable(json.data || []); }
        else console.error('expenses load', json);
        }catch(err){ console.error('fetchExpenses error', err); }
    }

    function renderExpensesTable(rows){
        tbody.innerHTML = '';
        rows.forEach(r => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${r.expense_id}</td>
            <td>${globalEscapeHtml(r.account_name || '')}</td>
            <td>${globalEscapeHtml(r.expense_date)}</td>
            <td>${globalEscapeHtml(r.category)}</td>
            <td>${Number(r.amount).toLocaleString('rw-RW')} Frw</td>
            <td>
            <button class="btn-ghost btn-edit-expense" data-id="${r.expense_id}">Hindura</button>
            <button class="btn-ghost-danger btn-delete-expense" data-id="${r.expense_id}">Siba</button>
            </td>
        `;
        tbody.appendChild(tr);
        });

        tbody.querySelectorAll('.btn-delete-expense').forEach(b => b.addEventListener('click', async ()=>{
        const id = b.getAttribute('data-id');
        if(!confirm('Urashaka gusiba iyi Expense?')) return;
        const fd = new FormData(); fd.append('action','delete'); fd.append('id', id);
        try{
            const res = await fetch(api, {method:'POST', body: fd});
            const json = await res.json();
            if(json.success) fetchExpenses(); else alert(json.message||'Error');
        }catch(err){ console.error(err); alert('Network error'); }
        }));

        tbody.querySelectorAll('.btn-edit-expense').forEach(b => b.addEventListener('click', async ()=>{
        const id = b.getAttribute('data-id');
        try{
            const res = await fetch(`${api}?id=${encodeURIComponent(id)}`);
            const json = await res.json();
            if(json.success && json.data){
            const d = json.data;
            document.getElementById('expense-id').value = d.expense_id;
            document.getElementById('expense-account').value = d.account_id;
            document.getElementById('expense-date').value = d.expense_date || '';
            document.getElementById('expense-category').value = d.category || '';
            document.getElementById('expense-amount').value = d.amount || '';
            document.getElementById('expense-description').value = d.description || '';
            openModal();
            } else alert(json.message||'Not found');
        }catch(err){ console.error(err); }
        }));
    }

    if(btnNew) btnNew.addEventListener('click', ()=>{ form.reset(); document.getElementById('expense-id').value = ''; openModal(); document.getElementById('expense-account').focus(); });
    if(btnRefresh) btnRefresh.addEventListener('click', ()=>{ currentQuery = ''; searchInput.value = ''; fetchExpenses(); });

    // Search functionality
    if(searchInput){
        searchInput.addEventListener('input', (e)=>{
        clearTimeout(searchTimer);
        searchTimer = setTimeout(()=>{
            currentQuery = e.target.value.trim();
            fetchExpenses(currentQuery);
        }, 300);
        });
    }
    if(searchBtn){
        searchBtn.addEventListener('click', ()=>{
        currentQuery = searchInput.value.trim();
        fetchExpenses(currentQuery);
        });
    }

    if(modalClose) modalClose.addEventListener('click', closeModal);
    if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if(modal) modal.addEventListener('click', (e)=>{ if(e.target===modal) closeModal(); });

    if(saveBtn){ saveBtn.addEventListener('click', async ()=>{
        const id = document.getElementById('expense-id').value;
        const fd = new FormData(form);
        fd.append('action', id ? 'update' : 'create');
        if(id) fd.append('id', id);
        try{
        const res = await fetch(api, {method:'POST', body: fd});
        const text = await res.text();
        // Try to parse JSON; if server returned HTML or an error page, show it
        let json = null;
        try{
            json = JSON.parse(text);
        }catch(parseErr){
            console.error('Response not JSON:', text);
            alert('Server returned non-JSON response. See console for details.');
            console.log('Raw response:', text);
            return;
        }

        if(!res.ok){
            alert(`HTTP Error ${res.status}: ${json.message || text}`);
            return;
        }

        if(json.success){ closeModal(); fetchExpenses(); } else { alert(json.message || 'Error saving'); }
        }catch(err){ console.error('Fetch error:', err); alert('Network error: ' + err.message); }
    }); }

    // initial load
    loadAccounts();
    fetchExpenses();
    })();

    // Loans management JS
    (function(){
    const api = 'loans_api.php';
    const tbodySelector = '#section-loans table tbody';
    const modal = document.getElementById('loan-modal');
    const form = document.getElementById('loan-form');
    const saveBtn = document.getElementById('loan-save');
    const cancelBtn = document.getElementById('loan-cancel');
    const modalClose = document.getElementById('loan-modal-close');
    const accountSelect = document.getElementById('loan-account');
    const borrowerSelect = document.getElementById('loan-borrower');
    const approvedSelect = document.getElementById('loan-approved-by');
    const disbursedSelect = document.getElementById('loan-disbursed-by');
    const btnNewLoan = document.getElementById('btn-new-loan');
    const btnRefreshLoans = document.getElementById('btn-refresh-loans');
    const btnAddGuarantor = document.getElementById('btn-add-guarantor');
    const guarantorsListEl = document.getElementById('loan-guarantors-list');
    let guarantorCounter = 0;

    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }

    async function loadUsersInto(selectEl){
        try{
        const res = await fetch('users_api.php?per_page=500', {credentials: 'include'});
        const json = await res.json();
        if(json.success){
            selectEl.innerHTML = '<option value="">-- Hitamo --</option>';
            json.data.forEach(u => { const opt = document.createElement('option'); opt.value = u.id; opt.textContent = u.names; selectEl.appendChild(opt); });
        } else console.error('loadUsers error', json);
        }catch(err){ console.error('loadUsers fetch error', err); }
    }

    async function loadAccountsLocal(){
        try{
        const res = await fetch('accounts_api.php', {credentials: 'include'});
        const text = await res.text();
        let json = null;
        try{ json = JSON.parse(text); } catch(e){ console.error('accounts not json', text); return; }
        if(json.success){ accountSelect.innerHTML = '<option value="">-- Hitamo Konto --</option>'; json.data.forEach(acc=>{ const opt=document.createElement('option'); opt.value=acc.account_id; opt.textContent=acc.name; accountSelect.appendChild(opt); }); }
        }catch(err){ console.error(err); }
    }

    async function addGuarantorRow(guarantorId='', guaranteeAmount=''){
        guarantorCounter++;
        const idx = guarantorCounter;
        const row = document.createElement('div');
        row.className = 'flex gap-2 guarantor-row';
        row.id = 'guarantor-' + idx;
        row.innerHTML = `
        <select name="guarantor_user_id_${idx}" class="flex-1 rounded-lg border px-3 py-2 text-sm guarantor-select" data-idx="${idx}">
            <option value="">-- Hitamo Umwishingizi --</option>
        </select>
        <input name="guarantee_amount_${idx}" type="number" step="0.01" placeholder="Umubare (Frw)" class="w-32 rounded-lg border px-3 py-2 text-sm guarantee-amount" min="0" />
        <div class="text-xs text-gray-700 bg-blue-50 p-2 rounded flex-1" id="guarantee-details-${idx}" style="display:none;">
            <div class="text-gray-600">
            <span id="detail-contrib-${idx}">0</span> 
            <span> - </span>
            <span id="detail-withdraw-${idx}">0</span>
            <span> - </span>
            <span id="detail-loans-${idx}">0</span>
            <span> - 120,000</span>
            </div>
            <div class="mt-1 pt-1 border-t-2 border-blue-400 font-bold text-blue-700 text-sm">
            Max Inzira: <span id="detail-max-${idx}">0</span>
            </div>
        </div>
        <button type="button" class="btn-ghost-danger btn-remove-guarantor text-xs" data-idx="${idx}">Siba</button>
        `;
        guarantorsListEl.appendChild(row);
        
        // Load eligible guarantors into this select
        const selectEl = row.querySelector('select');
        const guaranteeDetails = row.querySelector(`#guarantee-details-${idx}`);
        const amountInput = row.querySelector(`input[name="guarantee_amount_${idx}"]`);
        
        try {
        const res = await fetch('loans_api.php?action=eligible_guarantors', {credentials: 'include'});
        const json = await res.json();
        if(json.success && json.data){
            const guarantorData = {};
            json.data.forEach(u => { 
            const opt = document.createElement('option'); 
            opt.value = u.id; 
            opt.textContent = u.names;
            opt.dataset.maxGuarantee = u.available_guarantee;
            opt.dataset.contrib = u.total_contributions_and_payments;
            opt.dataset.withdraw = u.total_deductions;
            opt.dataset.loans = u.total_loan_principal;
            selectEl.appendChild(opt);
            guarantorData[u.id] = {
                max: u.available_guarantee,
                contrib: u.total_contributions_and_payments,
                withdraw: u.total_deductions,
                loans: u.total_loan_principal
            };
            });
            selectEl.dataset.guarantorData = JSON.stringify(guarantorData);
        }
        } catch(err) { console.error('Failed to load eligible guarantors:', err); }
        
        // When guarantor is selected, show breakdown
        selectEl.addEventListener('change', function(){
        const selectedId = this.value;
        if(selectedId && this.dataset.guarantorData){
            const guarantorData = JSON.parse(this.dataset.guarantorData);
            const data = guarantorData[selectedId];
            document.getElementById(`detail-contrib-${idx}`).textContent = Number(data.contrib).toLocaleString('rw-RW') + ' Frw';
            document.getElementById(`detail-withdraw-${idx}`).textContent = Number(data.withdraw).toLocaleString('rw-RW') + ' Frw';
            document.getElementById(`detail-loans-${idx}`).textContent = Number(data.loans).toLocaleString('rw-RW') + ' Frw';
            document.getElementById(`detail-max-${idx}`).textContent = Number(data.max).toLocaleString('rw-RW') + ' Frw';
            guaranteeDetails.style.display = 'block';
        } else {
            guaranteeDetails.style.display = 'none';
        }
        });
        
        // Validate guarantee amount doesn't exceed max
        amountInput.addEventListener('change', function(){
        const selectedId = selectEl.value;
        if(selectedId && selectEl.dataset.guarantorData){
            const guarantorData = JSON.parse(selectEl.dataset.guarantorData);
            const maxAmt = guarantorData[selectedId].max;
            const enteredAmt = parseFloat(this.value) || 0;
            if(enteredAmt > maxAmt){
            alert(`Inzira nta mugabane wibura ${Number(maxAmt).toLocaleString('rw-RW')} Frw`);
            this.value = maxAmt;
            }
        }
        });
        
        // Set guarantor if provided with edit
        selectEl.value = guarantorId || '';
        if(guarantorId && selectEl.dataset.guarantorData){
        const guarantorData = JSON.parse(selectEl.dataset.guarantorData);
        const data = guarantorData[guarantorId];
        if(data){
            document.getElementById(`detail-contrib-${idx}`).textContent = Number(data.contrib).toLocaleString('rw-RW') + ' Frw';
            document.getElementById(`detail-withdraw-${idx}`).textContent = Number(data.withdraw).toLocaleString('rw-RW') + ' Frw';
            document.getElementById(`detail-loans-${idx}`).textContent = Number(data.loans).toLocaleString('rw-RW') + ' Frw';
            document.getElementById(`detail-max-${idx}`).textContent = Number(data.max).toLocaleString('rw-RW') + ' Frw';
            guaranteeDetails.style.display = 'block';
        }
        }
        
        // Set amount if provided
        amountInput.value = guaranteeAmount || '';
        
        // Remove button handler
        row.querySelector('.btn-remove-guarantor').addEventListener('click', (e)=>{ e.preventDefault(); row.remove(); });
    }

    async function fetchLoans(q=''){
        try{
        const res = await fetch(api + '?per_page=200' + (q?('&q='+encodeURIComponent(q)):''), {credentials: 'include'});
        if(!res.ok){ console.error('fetchLoans http', res.status); return; }
        const json = await res.json();
        if(json.success) renderLoans(json.data||[]);
        }catch(err){ console.error('fetchLoans', err); }
    }

    function renderLoans(rows){
        const tbody = document.querySelector(tbodySelector);
        if(!tbody) return;
        tbody.innerHTML = '';
        rows.forEach(r=>{
        const tr = document.createElement('tr');
        const statusBadge = r.status === 'approved' ? '<span class="badge badge-success">Yemejwe</span>' 
                            : r.status === 'disbursed' ? '<span class="badge badge-info">Yatanze amafaranga</span>'
                            : r.status === 'closed' ? '<span class="badge badge-gray">Zarangiye</span>'
                            : r.status === 'defaulted' ? '<span class="badge badge-danger">Yarenze</span>'
                            : '<span class="badge badge-warning">Yatanze</span>';
        tr.innerHTML = `
            <td>#LN-${r.loan_id}</td>
            <td>${globalEscapeHtml(r.borrower_name||'')}</td>
            <td>${Number(r.principal_amount||0).toLocaleString('rw-RW')} Frw</td>
            <td>${Number(r.principal_amount||0).toLocaleString('rw-RW')} Frw</td>
            <td>${statusBadge}</td>
            <td>
            <button class="btn-ghost btn-edit-loan" data-id="${r.loan_id}">Hindura</button>
            <button class="btn-ghost-danger btn-delete-loan" data-id="${r.loan_id}">Siba</button>
            </td>
        `;
        tbody.appendChild(tr);
        });
    }

    // Delegated event handlers for dynamically created buttons
    const tbody = document.querySelector(tbodySelector);
    if(tbody){
        tbody.addEventListener('click', async (e)=>{
        const editBtn = e.target.closest('.btn-edit-loan');
        const deleteBtn = e.target.closest('.btn-delete-loan');
        
        if(editBtn){
            const id = editBtn.getAttribute('data-id');
            try{
            const res = await fetch(api + '?id=' + encodeURIComponent(id), {credentials: 'include'});
            if(!res.ok) { alert('HTTP Error: ' + res.status); return; }
            const text = await res.text();
            console.log('Raw response:', text);
            let json = null;
            try{ json = JSON.parse(text); } catch(e){ console.error('Response not JSON:', text); alert('Server error: Invalid response. Check console.'); return; }
            if(json.success && json.data){
                const d = json.data;
                document.getElementById('loan-id').value = d.loan_id;
                document.getElementById('loan-account').value = d.account_id || '';
                document.getElementById('loan-borrower').value = d.borrower_user_id || '';
                document.getElementById('loan-principal').value = d.principal_amount || '';
                document.getElementById('loan-rate').value = d.monthly_rate || '';
                document.getElementById('loan-term').value = d.term_months || '';
                document.getElementById('loan-start').value = d.start_date || '';
                document.getElementById('loan-status').value = d.status || 'requested';
                document.getElementById('loan-notes').value = d.notes || '';
                document.getElementById('loan-approved-by').value = d.approved_by || '';
                document.getElementById('loan-disbursed-by').value = d.disbursed_by || '';
                
                // Load guarantors
                guarantorsListEl.innerHTML = '';
                guarantorCounter = 0;
                if (d.guarantors && Array.isArray(d.guarantors) && d.guarantors.length > 0) {
                for (const g of d.guarantors) {
                    await addGuarantorRow(g.guarantor_user_id, g.guarantee_amount);
                }
                } else {
                await addGuarantorRow();
                }
                
                openModal();
            } else alert(json.message ||'Loan not found');
            }catch(err){ console.error('Edit loan error:', err); alert('Error: ' + err.message); }
        }
        
        if(deleteBtn){
            const id = deleteBtn.getAttribute('data-id');
            if(!confirm('Urashaka gusiba iyi nguzanyo?')) return;
            const fd = new FormData(); 
            fd.append('action','delete'); 
            fd.append('id', id);
            try{ 
            const res = await fetch(api, {method:'POST', body: fd, credentials: 'include'}); 
            const json = await res.json(); 
            if(json.success) fetchLoans(); 
            else alert(json.message||'Error'); 
            }catch(err){ console.error(err); alert('Network error'); }
        }
        });
    }

    if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if(modalClose) modalClose.addEventListener('click', closeModal);
    if(modal) modal.addEventListener('click', (e)=>{ if(e.target===modal) closeModal(); });

    if(btnNewLoan) btnNewLoan.addEventListener('click', ()=>{ 
        form.reset(); 
        document.getElementById('loan-id').value = ''; 
        guarantorsListEl.innerHTML = '';
        guarantorCounter = 0;
        addGuarantorRow();
        openModal(); 
        document.getElementById('loan-borrower').focus(); 
    });
    if(btnRefreshLoans) btnRefreshLoans.addEventListener('click', ()=>{ fetchLoans(); });
    if(btnAddGuarantor) btnAddGuarantor.addEventListener('click', (e)=>{ e.preventDefault(); addGuarantorRow(); });
    
    // fallback: delegated handler in case elements were not present when script ran
    if(!btnNewLoan || !btnRefreshLoans){
        document.addEventListener('click', (e)=>{
        try{
            const newBtn = e.target.closest && e.target.closest('#btn-new-loan');
            if(newBtn){ form.reset(); document.getElementById('loan-id').value = ''; guarantorsListEl.innerHTML = ''; guarantorCounter = 0; addGuarantorRow(); openModal(); document.getElementById('loan-borrower').focus(); return; }
            const refBtn = e.target.closest && e.target.closest('#btn-refresh-loans');
            if(refBtn){ fetchLoans(); return; }
        }catch(_){ }
        });
    }

    if(saveBtn) saveBtn.addEventListener('click', async ()=>{
        const id = document.getElementById('loan-id').value;
        const fd = new FormData(form);
        fd.append('action', id ? 'update' : 'create');
        if(id) fd.append('id', id);
        
        // Collect guarantor data
        const guarantorsArray = [];
        document.querySelectorAll('.guarantor-row').forEach(row => {
        const selects = row.querySelectorAll('select[name^="guarantor_user_id"]');
        const inputs = row.querySelectorAll('input[name^="guarantee_amount"]');
        if(selects.length > 0 && inputs.length > 0){
            const userId = selects[0].value;
            const amount = inputs[0].value;
            if(userId && amount) guarantorsArray.push({user_id: userId, amount: amount});
        }
        });
        fd.append('guarantors', JSON.stringify(guarantorsArray));
        
        try{
        const res = await fetch(api, {method:'POST', body: fd, credentials: 'include'});
        const text = await res.text();
        let json = null;
        try{ json = JSON.parse(text); } catch(e){ console.error('Loans response not JSON', text); alert('Server returned non-JSON response. See console.'); return; }
        if(!res.ok){ alert(`HTTP Error ${res.status}: ${json.message || text}`); return; }
        if(json.success){ closeModal(); fetchLoans(); } else alert(json.message || 'Error saving');
        }catch(err){ console.error(err); alert('Network error'); }
    });

    // initial load for loans dropdowns and list
    loadUsersInto(borrowerSelect);
    loadUsersInto(approvedSelect);
    loadUsersInto(disbursedSelect);
    loadAccountsLocal();
    fetchLoans();
    })();

    // Transactions management JS
    (function(){
    const api = 'transactions_api.php';
    const tbodySelector = '#section-transactions table tbody';
    const modal = document.getElementById('transaction-modal');
    const form = document.getElementById('transaction-form');
    const saveBtn = document.getElementById('transaction-save');
    const cancelBtn = document.getElementById('transaction-cancel');
    const modalClose = document.getElementById('transaction-modal-close');
    const userSelect = document.getElementById('transaction-user');
    const accountSelect = document.getElementById('transaction-account');
    const btnNewTransaction = document.getElementById('btn-new-transaction');
    const btnRefreshTransactions = document.getElementById('btn-refresh-transactions');

    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }

    async function loadTransactionUsers(selectEl){
        try{
        const res = await fetch('users_api.php?per_page=500', {credentials: 'include'});
        const json = await res.json();
        if(json.success){
            selectEl.innerHTML = '<option value="">-- Hitamo --</option>';
            json.data.forEach(u => { const opt = document.createElement('option'); opt.value = u.id; opt.textContent = u.names; selectEl.appendChild(opt); });
        } else console.error('loadUsers error', json);
        }catch(err){ console.error('loadUsers fetch error', err); }
    }

    async function loadTransactionAccounts(){
        try{
        const res = await fetch('accounts_api.php', {credentials: 'include'});
        const text = await res.text();
        let json = null;
        try{ json = JSON.parse(text); } catch(e){ console.error('accounts not json', text); return; }
        if(json.success){ accountSelect.innerHTML = '<option value="">-- Hitamo Konto --</option>'; json.data.forEach(acc=>{ const opt=document.createElement('option'); opt.value=acc.account_id; opt.textContent=acc.name; accountSelect.appendChild(opt); }); }
        }catch(err){ console.error(err); }
    }

    async function fetchTransactions(q=''){
        try{
        const res = await fetch(api + '?per_page=200' + (q?('&q='+encodeURIComponent(q)):''), {credentials: 'include'});
        if(!res.ok){ console.error('fetchTransactions http', res.status); return; }
        const json = await res.json();
        if(json.success) renderTransactions(json.data||[]);
        }catch(err){ console.error('fetchTransactions', err); }
    }

    function renderTransactions(rows){
        const tbody = document.querySelector(tbodySelector);
        if(!tbody) return;
        tbody.innerHTML = '';
        rows.forEach(r=>{
        const tr = document.createElement('tr');
        const typeLabel = r.type === 'contribution' ? 'Contribution' : r.type === 'withdrawal_deduction' ? 'Withdrawal' : 'Loan Payment';
        tr.innerHTML = `
            <td>#TX-${r.trans_id}</td>
            <td>${r.tx_date||''}</td>
            <td>${globalEscapeHtml(r.user_name||'')}</td>
            <td>${globalEscapeHtml(r.account_name||'')}</td>
            <td>${typeLabel}</td>
            <td>${Number(r.amount||0).toLocaleString('rw-RW')} Frw</td>
            <td>
            <button class="btn-ghost btn-edit-transaction" data-id="${r.trans_id}">Hindura</button>
            <button class="btn-ghost-danger btn-delete-transaction" data-id="${r.trans_id}">Siba</button>
            </td>
        `;
        tbody.appendChild(tr);
        });
    }

    // Delegated event handlers for dynamically created buttons
    const tbody = document.querySelector(tbodySelector);
    if(tbody){
        tbody.addEventListener('click', async (e)=>{
        const editBtn = e.target.closest('.btn-edit-transaction');
        const deleteBtn = e.target.closest('.btn-delete-transaction');
        
        if (editBtn) {
            const id = editBtn.getAttribute('data-id');
            try {
                const res = await fetch(`${api}?id=${encodeURIComponent(id)}`, { credentials: 'include' });

                // If server returns 404 or 500
                if (!res.ok) {
                    throw new Error(`Server responded with status ${res.status}`);
                }

                // Check if response is actually JSON
                const contentType = res.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    const rawText = await res.text();
                    console.error("Expected JSON but got:", rawText);
                    throw new Error("Server returned non-JSON response. Check console.");
                }

                const json = await res.json();

                if (json.success && json.data) {
                    const d = json.data;
                    document.getElementById('transaction-id').value = d.trans_id;
                    document.getElementById('transaction-date').value = d.tx_date || '';
                    // ... rest of your mapping ...
                    openModal();
                } else {
                    alert(json.message || 'Transaction not found');
                }
            } catch (err) {
                console.error('Edit transaction error:', err);
                alert(err.message);
            }
        }
        
        if(deleteBtn){
            const id = deleteBtn.getAttribute('data-id');
            if(!confirm('Urashaka gusiba iyi transaction?')) return;
            const fd = new FormData(); 
            fd.append('action','delete'); 
            fd.append('id', id);
            try{ 
            const res = await fetch(api, {method:'POST', body: fd, credentials: 'include'}); 
            const json = await res.json(); 
            if(json.success) fetchTransactions(); 
            else alert(json.message||'Error'); 
            }catch(err){ console.error(err); alert('Network error'); }
        }
        });
    }

    if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if(modalClose) modalClose.addEventListener('click', closeModal);
    if(modal) modal.addEventListener('click', (e)=>{ if(e.target===modal) closeModal(); });

    if(btnNewTransaction) btnNewTransaction.addEventListener('click', ()=>{ 
        form.reset(); 
        document.getElementById('transaction-id').value = ''; 
        openModal(); 
        document.getElementById('transaction-date').focus(); 
    });
    if(btnRefreshTransactions) btnRefreshTransactions.addEventListener('click', ()=>{ fetchTransactions(); });

    if(saveBtn) saveBtn.addEventListener('click', async ()=>{
        const id = document.getElementById('transaction-id').value;
        const fd = new FormData(form);
        fd.append('action', id ? 'update' : 'create');
        if(id) fd.append('id', id);
        
        try{
        const res = await fetch(api, {method:'POST', body: fd, credentials: 'include'});
        const text = await res.text();
        let json = null;
        try{ json = JSON.parse(text); } catch(e){ console.error('Transactions response not JSON', text); alert('Server returned non-JSON response. See console.'); return; }
        if(!res.ok){ alert(`HTTP Error ${res.status}: ${json.message || text}`); return; }
        if(json.success){ closeModal(); fetchTransactions(); } else alert(json.message || 'Error saving');
        }catch(err){ console.error(err); alert('Network error'); }
    });

    // initial load for transactions dropdowns and list
    loadTransactionUsers(userSelect);
    loadTransactionAccounts();
    fetchTransactions();
    })();
