
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

/* OVERVIEW MODULE */
(function () {
  const api = 'overview_api.php';

  let portfolioChartInstance = null;
  let incomeExpenseChartInstance = null;
  let loanStatusChartInstance = null;

  const money = (v) => `${Number(v || 0).toLocaleString('rw-RW')} Frw`;
  const num = (v) => `${Number(v || 0).toLocaleString('rw-RW')}`;
  const pct = (v) => `${Number(v || 0).toLocaleString('rw-RW', { maximumFractionDigits: 1 })}%`;
  const el = (id) => document.getElementById(id);

  const detailModal = el('overview-detail-modal');
  const detailTitle = el('overview-detail-title');
  const detailSubtitle = el('overview-detail-subtitle');
  const detailBody = el('overview-detail-body');
  const detailClose = el('overview-detail-close');
  const detailPdf = el('overview-detail-download-pdf');
  const detailExcel = el('overview-detail-download-excel');

  let currentDetailType = '';

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setText(id, value) {
    const node = el(id);
    if (node) node.textContent = value;
  }

  function safeDivide(a, b) {
    a = Number(a || 0);
    b = Number(b || 0);
    if (!b) return 0;
    return (a / b) * 100;
  }

  function destroyChart(instanceRef) {
    if (instanceRef) instanceRef.destroy();
    return null;
  }

  function openDetailModal() {
    if (!detailModal) return;
    detailModal.classList.remove('hidden');
    detailModal.classList.add('flex');
  }

  function closeDetailModal() {
    if (!detailModal) return;
    detailModal.classList.add('hidden');
    detailModal.classList.remove('flex');
  }

  function downloadChart(canvasId, filename) {
    const canvas = el(canvasId);
    if (!canvas) return;
    const url = canvas.toDataURL('image/png');
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  function renderRecentActivity(items) {
    const box = el('ov-recent-activity');
    if (!box) return;

    if (!Array.isArray(items) || items.length === 0) {
      box.innerHTML = `<div class="rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-500">Nta bikorwa biheruka kuboneka.</div>`;
      return;
    }

    box.innerHTML = items.map(item => `
      <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-xs font-semibold text-slate-700">${escapeHtml(item.title || '')}</p>
            <p class="mt-1 text-[11px] text-slate-500">${escapeHtml(item.description || '')}</p>
          </div>
          <div class="text-[10px] text-slate-400 whitespace-nowrap">${escapeHtml(item.when || '')}</div>
        </div>
      </div>
    `).join('');
  }

  function renderTable(headers, rows) {
    return `
      <div class="table-wrapper overflow-x-auto">
        <table class="table w-full">
          <thead><tr>${headers.map(h => `<th>${escapeHtml(h)}</th>`).join('')}</tr></thead>
          <tbody>
            ${rows.length ? rows.join('') : `<tr><td colspan="${headers.length}" class="py-6 text-center text-sm text-slate-400">Nta makuru abonetse</td></tr>`}
          </tbody>
        </table>
      </div>
    `;
  }

  function renderMembersDetail(data) {
    const rows = (data.items || []).map(item => `
      <tr>
        <td>${item.has_profile_image ? `<img src="${api}?action=image&type=member_profile&id=${item.id}" class="h-12 w-12 rounded object-cover border" alt="" />` : ''}</td>
        <td>${escapeHtml(item.names || '')}</td>
        <td>${escapeHtml(item.phone1 || '')}</td>
        <td>${escapeHtml(item.phone2 || '')}</td>
        <td>${escapeHtml(item.email || '')}</td>
        <td>${escapeHtml(item.nid_passport || '')}</td>
        <td>${item.is_member == 1 ? 'Member' : ''}</td>
        <td>${money(item.net_value || 0)}</td>
      </tr>
    `);
    return renderTable(['Image', 'Names', 'Phone 1', 'Phone 2', 'Email', 'NID/Passport', 'Type', 'Net'], rows);
  }

  function renderCashDetail(data) {
    const calc = data.calculation || {};
    const calcHtml = `
      <div class="mb-4 grid gap-3 md:grid-cols-4">
        <div class="rounded-lg bg-slate-50 p-3 text-sm"><b>Contributions</b><br>${money(calc.contributions || 0)}</div>
        <div class="rounded-lg bg-slate-50 p-3 text-sm"><b>Interest</b><br>${money(calc.interest || 0)}</div>
        <div class="rounded-lg bg-slate-50 p-3 text-sm"><b>Expenses</b><br>${money(calc.expenses || 0)}</div>
        <div class="rounded-lg bg-slate-50 p-3 text-sm"><b>Cash</b><br>${money(calc.cash || 0)}</div>
      </div>
    `;
    const rows = (data.items || []).map(item => `
      <tr>
        <td>${escapeHtml(item.tx_date || '')}</td>
        <td>${escapeHtml(item.type || '')}</td>
        <td>${escapeHtml(item.user_name || '')}</td>
        <td>${escapeHtml(item.account_name || '')}</td>
        <td>${escapeHtml(item.direction || '')}</td>
        <td>${money(item.amount || 0)}</td>
        <td>${escapeHtml(item.description || '')}</td>
        <td>${item.has_proof_image ? `<img src="${api}?action=image&type=proof&tx_id=${item.transaction_id}" class="h-10 w-10 rounded object-cover border" alt="" />` : (escapeHtml(item.proof_name || ''))}</td>
      </tr>
    `);
    return calcHtml + renderTable(['Date', 'Type', 'User', 'Account', 'Direction', 'Amount', 'Description', 'Proof'], rows);
  }

  function renderInterestDetail(data) {
    const rows = (data.items || []).map(item => `
      <tr>
        <td>${escapeHtml(item.tx_date || '')}</td>
        <td>${escapeHtml(item.user_name || '')}</td>
        <td>${item.loan_id ? '#LN-' + escapeHtml(item.loan_id) : ''}</td>
        <td>${escapeHtml(item.account_name || '')}</td>
        <td>${money(item.amount || 0)}</td>
        <td>${escapeHtml(item.description || '')}</td>
        <td>${item.has_proof_image ? `<img src="${api}?action=image&type=proof&tx_id=${item.transaction_id}" class="h-10 w-10 rounded object-cover border" alt="" />` : (escapeHtml(item.proof_name || ''))}</td>
      </tr>
    `);
    return renderTable(['Date', 'User', 'Loan', 'Account', 'Amount', 'Description', 'Proof'], rows);
  }

  function renderExpensesDetail(data) {
    const rows = (data.items || []).map(item => `
      <tr>
        <td>${escapeHtml(item.tx_date || '')}</td>
        <td>${escapeHtml(item.account_name || '')}</td>
        <td>${money(item.amount || 0)}</td>
        <td>${escapeHtml(item.description || '')}</td>
        <td>${escapeHtml(item.created_by_name || '')}</td>
        <td>${item.has_proof_image ? `<img src="${api}?action=image&type=proof&tx_id=${item.transaction_id}" class="h-10 w-10 rounded object-cover border" alt="" />` : (escapeHtml(item.proof_name || ''))}</td>
      </tr>
    `);
    return renderTable(['Date', 'Account', 'Amount', 'Description', 'Created By', 'Proof'], rows);
  }

  function renderLoansDetail(data) {
    const rows = (data.items || []).map(item => `
      <tr>
        <td>${item.loan_id ? '#LN-' + escapeHtml(item.loan_id) : ''}</td>
        <td>${escapeHtml(item.borrower_name || '')}</td>
        <td>${escapeHtml(item.borrower_phone || '')}</td>
        <td>${money(item.principal || 0)}</td>
        <td>${money(item.unpaid_principal || 0)}</td>
        <td>${escapeHtml(item.monthly_interest_rate || '')}</td>
        <td>${escapeHtml(item.interest_method || '')}</td>
        <td>${escapeHtml(item.status || '')}</td>
        <td>${escapeHtml(item.start_date || '')}</td>
        <td>${escapeHtml(item.end_date || '')}</td>
      </tr>
    `);
    return renderTable(['Loan', 'Borrower', 'Phone', 'Principal', 'Unpaid', 'Rate', 'Method', 'Status', 'Start Date', 'End Date'], rows);
  }

  function renderRequestedLoansDetail(data) {
    const rows = (data.items || []).map(item => `
      <tr>
        <td>${item.loan_id ? '#LN-' + escapeHtml(item.loan_id) : ''}</td>
        <td>${escapeHtml(item.borrower_name || '')}</td>
        <td>${escapeHtml(item.borrower_phone || '')}</td>
        <td>${money(item.principal || 0)}</td>
        <td>${escapeHtml(item.monthly_interest_rate || '')}</td>
        <td>${escapeHtml(item.interest_method || '')}</td>
        <td>${escapeHtml(item.status || '')}</td>
        <td>${escapeHtml(item.created_at || '')}</td>
      </tr>
    `);
    return renderTable(['Loan', 'Borrower', 'Phone', 'Principal', 'Rate', 'Method', 'Status', 'Created At'], rows);
  }

  function renderAssetsDetail(data) {
    const rows = (data.items || []).map(item => `
      <tr>
        <td>${item.asset_id ? '#AS-' + escapeHtml(item.asset_id) : ''}</td>
        <td>${escapeHtml(item.name || '')}</td>
        <td>${escapeHtml(item.purchase_date || '')}</td>
        <td>${money(item.purchase_value || 0)}</td>
        <td>${escapeHtml(item.location || '')}</td>
        <td>${escapeHtml(item.holders_count || 0)}</td>
        <td>${item.sold_value === null || item.sold_value === '' ? '' : money(item.sold_value)}</td>
      </tr>
    `);
    return renderTable(['Asset', 'Name', 'Purchase Date', 'Purchase Value', 'Location', 'Holders', 'Sold Value'], rows);
  }

  function renderGuarantorsDetail(data) {
    const rows = (data.items || []).map(item => `
      <tr>
        <td>${escapeHtml(item.names || '')}</td>
        <td>${escapeHtml(item.phone1 || '')}</td>
        <td>${money(item.total_guaranteed || 0)}</td>
        <td>${escapeHtml(item.active_loans || 0)}</td>
      </tr>
    `);
    return renderTable(['Names', 'Phone', 'Guaranteed Amount', 'Active Loans'], rows);
  }

  function renderDetailBody(type, data) {
    switch (type) {
      case 'members': return renderMembersDetail(data);
      case 'cash': return renderCashDetail(data);
      case 'interest': return renderInterestDetail(data);
      case 'expenses': return renderExpensesDetail(data);
      case 'loans': return renderLoansDetail(data);
      case 'requested_loans': return renderRequestedLoansDetail(data);
      case 'assets': return renderAssetsDetail(data);
      case 'guarantors': return renderGuarantorsDetail(data);
      default: return `<div class="text-sm text-slate-500">No detail renderer found.</div>`;
    }
  }

  async function loadDetail(type) {
    currentDetailType = type;
    detailBody.innerHTML = `<div class="text-sm text-slate-500">Loading...</div>`;
    detailTitle.textContent = 'Overview Details';
    detailSubtitle.textContent = '';
    openDetailModal();

    try {
      const res = await fetch(`${api}?action=detail&type=${encodeURIComponent(type)}`, {
        cache: 'no-store',
        credentials: 'include'
      });
      const json = await res.json();

      if (!json.success) {
        detailBody.innerHTML = `<div class="text-sm text-red-600">${escapeHtml(json.message || 'Failed')}</div>`;
        return;
      }

      detailTitle.textContent = json.title || 'Overview Details';
      detailSubtitle.textContent = json.subtitle || '';
      detailBody.innerHTML = renderDetailBody(type, json.data || {});
    } catch (e) {
      console.error(e);
      detailBody.innerHTML = `<div class="text-sm text-red-600">Failed to load details.</div>`;
    }
  }

  function renderPortfolioChart(chartData) {
    const chartEl = el('portfolioChart');
    if (!chartEl || !window.Chart) return;

    portfolioChartInstance = destroyChart(portfolioChartInstance);

    portfolioChartInstance = new Chart(chartEl, {
      type: 'line',
      data: {
        labels: chartData.labels || [],
        datasets: [
          { label: 'Contributions', data: chartData.contributions || [], borderColor: '#2F6B4F', backgroundColor: 'rgba(47,107,79,.10)', tension: 0.35, borderWidth: 2, fill: false },
          { label: 'Active Loans',  data: chartData.loans || [],         borderColor: '#E89C2C', backgroundColor: 'rgba(232,156,44,.08)', tension: 0.35, borderWidth: 2, fill: false },
          { label: 'Assets',        data: chartData.assets || [],        borderColor: '#6B4A2D', backgroundColor: 'rgba(107,74,45,.08)', tension: 0.35, borderWidth: 2, fill: false },
          { label: 'Interest',      data: chartData.interest || [],      borderColor: '#7C3AED', backgroundColor: 'rgba(124,58,237,.08)', tension: 0.35, borderWidth: 2, fill: false },
          { label: 'Expenses',      data: chartData.expenses || [],      borderColor: '#DC2626', backgroundColor: 'rgba(220,38,38,.06)', tension: 0.35, borderWidth: 2, fill: false }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
          tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${money(ctx.parsed.y || 0)}` } }
        },
        scales: {
          y: { ticks: { callback: (v) => money(v) }, grid: { color: 'rgba(148,163,184,.2)' } },
          x: { grid: { display: false } }
        }
      }
    });
  }

  function renderIncomeExpenseChart(chartData) {
    const chartEl = el('incomeExpenseChart');
    if (!chartEl || !window.Chart) return;

    incomeExpenseChartInstance = destroyChart(incomeExpenseChartInstance);

    incomeExpenseChartInstance = new Chart(chartEl, {
      type: 'bar',
      data: {
        labels: chartData.labels || [],
        datasets: [
          { label: 'Income', data: chartData.income || [], backgroundColor: 'rgba(47,107,79,.75)', borderColor: '#2F6B4F', borderWidth: 1 },
          { label: 'Expenses', data: chartData.expenses || [], backgroundColor: 'rgba(220,38,38,.70)', borderColor: '#DC2626', borderWidth: 1 }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${money(ctx.parsed.y || 0)}` } }
        },
        scales: {
          y: { ticks: { callback: (v) => money(v) }, grid: { color: 'rgba(148,163,184,.2)' } },
          x: { grid: { display: false } }
        }
      }
    });
  }

  function renderLoanStatusChart(chartData) {
    const chartEl = el('loanStatusChart');
    if (!chartEl || !window.Chart) return;

    loanStatusChartInstance = destroyChart(loanStatusChartInstance);

    loanStatusChartInstance = new Chart(chartEl, {
      type: 'doughnut',
      data: {
        labels: chartData.labels || [],
        datasets: [{
          data: chartData.values || [],
          backgroundColor: ['#F59E0B', '#10B981', '#64748B', '#DC2626', '#7C3AED'],
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${num(ctx.parsed || 0)}` } }
        }
      }
    });
  }

  async function loadOverview() {
    try {
      const res = await fetch(api, { cache: 'no-store', credentials: 'include' });
      const json = await res.json();

      if (!json.success) {
        console.error('overview', json.message);
        return;
      }

      const s = json.stats || {};

      setText('ov-members', num(s.total_members || 0));
      setText('ov-accounts-balance', money(s.total_cash || 0));
      setText('ov-interest', money(s.total_interest || 0));
      setText('ov-expenses', money(s.total_expenses || 0));
      setText('ov-loans-issued', money(s.total_loans_issued || 0));
      setText('ov-loans-requested', num(s.requested_loans || 0));
      setText('ov-assets-value', money(s.total_assets_value || 0));
      setText('ov-guarantors', num(s.total_guarantors || 0));

      const totalPortfolio =
        Number(s.total_cash || 0) +
        Number(s.total_loans_issued || 0) +
        Number(s.total_assets_value || 0);

      setText('ov-ratio-cash-assets', pct(safeDivide(s.total_cash || 0, s.total_assets_value || 0)));
      setText('ov-ratio-loans-portfolio', pct(safeDivide(s.total_loans_issued || 0, totalPortfolio)));
      setText('ov-ratio-expense-income', pct(safeDivide(s.total_expenses || 0, s.total_income || 0)));
      setText('ov-ratio-interest-yield', pct(safeDivide(s.total_interest || 0, s.total_loans_issued || 0)));

      renderRecentActivity(json.recent_activity || []);
      renderPortfolioChart(json.portfolio_chart || {});
      renderIncomeExpenseChart(json.income_expense_chart || {});
      renderLoanStatusChart(json.loan_status_chart || {});
    } catch (e) {
      console.error('loadOverview', e);
    }
  }

  document.querySelectorAll('.overview-card').forEach(card => {
    card.addEventListener('click', () => {
      const type = card.getAttribute('data-overview-detail');
      if (type) loadDetail(type);
    });
  });

  detailClose?.addEventListener('click', closeDetailModal);
  detailModal?.addEventListener('click', (e) => {
    if (e.target === detailModal) closeDetailModal();
  });

  detailPdf?.addEventListener('click', () => {
    if (!currentDetailType) return;
    window.open(`${api}?action=report&type=${encodeURIComponent(currentDetailType)}&format=pdf`, '_blank');
  });

  detailExcel?.addEventListener('click', () => {
    if (!currentDetailType) return;
    window.open(`${api}?action=report&type=${encodeURIComponent(currentDetailType)}&format=excel`, '_blank');
  });

  el('btn-download-portfolio-chart')?.addEventListener('click', () => downloadChart('portfolioChart', 'portfolio_chart.png'));
  el('btn-download-income-expense-chart')?.addEventListener('click', () => downloadChart('incomeExpenseChart', 'income_expense_chart.png'));
  el('btn-download-loan-status-chart')?.addEventListener('click', () => downloadChart('loanStatusChart', 'loan_status_chart.png'));

  loadOverview();
  document.querySelector('[data-section="overview"]')?.addEventListener('click', loadOverview);
})();

  // Assets management JS with holders
  (function () {
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

      const idInput = document.getElementById('asset-id');
      const nameInput = document.getElementById('asset-name');
      const purchaseDateInput = document.getElementById('asset-purchase-date');
      const purchaseValueInput = document.getElementById('asset-purchase-value');
      const locationInput = document.getElementById('asset-location');
      const notesInput = document.getElementById('asset-notes');
      const certificateNameInput = document.getElementById('asset-certificate-name');
      const certificateFileInput = document.getElementById('asset-certificate-file');
      const hasSoldCheckbox = document.getElementById('has-sold-date');
      const soldSection = document.getElementById('sold-section');
      const soldDateInput = document.getElementById('asset-sold-date');
      const soldValueInput = document.getElementById('asset-sold-value');

      const holdersList = document.getElementById('asset-holders-list');
      const btnAddHolder = document.getElementById('btn-add-holder');
      const holdersTotalEl = document.getElementById('asset-holders-total');
      const holdersRemainingEl = document.getElementById('asset-holders-remaining');
      const holdersValidationEl = document.getElementById('asset-holders-validation');

      const holdersViewModal = document.getElementById('asset-holders-view-modal');
      const holdersViewClose = document.getElementById('asset-holders-view-close');
      const holdersViewBody = document.getElementById('asset-holders-view-body');

      if (!tbody) return;

      let currentQuery = '';
      let searchTimer = null;

      function esc(v) {
        if (typeof globalEscapeHtml === 'function') return globalEscapeHtml(v ?? '');
        return String(v ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function money(n) {
        return `${Number(n || 0).toLocaleString('rw-RW', {
          minimumFractionDigits: 0,
          maximumFractionDigits: 2
        })} Frw`;
      }

      function openModal() {
        modal?.classList.remove('hidden');
        modal?.classList.add('flex');
      }

      function closeModal() {
        modal?.classList.add('hidden');
        modal?.classList.remove('flex');
      }

      function openHoldersView() {
        holdersViewModal?.classList.remove('hidden');
        holdersViewModal?.classList.add('flex');
      }

      function closeHoldersView() {
        holdersViewModal?.classList.add('hidden');
        holdersViewModal?.classList.remove('flex');
      }

      function resetForm() {
        form?.reset();
        if (idInput) idInput.value = '';
        if (hasSoldCheckbox) hasSoldCheckbox.checked = false;
        if (soldSection) soldSection.classList.add('hidden');
        if (soldDateInput) soldDateInput.value = '';
        if (soldValueInput) soldValueInput.value = '';
        if (holdersList) holdersList.innerHTML = '';
        addHolderRow();
        updateHolderTotals();
      }

      function toggleSoldSection(force = null) {
        if (!hasSoldCheckbox || !soldSection) return;
        const show = force !== null ? !!force : !!hasSoldCheckbox.checked;
        soldSection.classList.toggle('hidden', !show);

        if (!show) {
          if (soldDateInput) soldDateInput.value = '';
          if (soldValueInput) soldValueInput.value = '';
        }
      }

      async function readJsonResponse(res) {
        const text = await res.text();
        let json = null;
        try {
          json = text ? JSON.parse(text) : {};
        } catch (e) {
          console.error('Non-JSON response:', text);
          throw new Error('Server returned non-JSON response.');
        }
        return json;
      }

      function hexToBase64(hexStr) {
        if (!hexStr) return null;
        const bytes = [];
        for (let i = 0; i < hexStr.length; i += 2) {
          bytes.push(parseInt(hexStr.substr(i, 2), 16));
        }
        const binStr = String.fromCharCode(...bytes);
        return btoa(binStr);
      }

      function buildCertificateHtml(row) {
        if (!row.certificate_file || !row.certificate_mime) {
          return '<span class="text-xs text-slate-400">Nta certificate</span>';
        }

        const mimeType = row.certificate_mime;
        const fileName = esc(row.certificate_name || 'Document');

        if (mimeType.startsWith('image/')) {
          try {
            const b64 = hexToBase64(row.certificate_file);
            return `<img src="data:${mimeType};base64,${b64}" alt="Certificate" class="h-16 w-auto rounded border" />`;
          } catch (e) {
            return `<span class="text-xs text-slate-500">📄 ${fileName}</span>`;
          }
        }

        return `<span class="text-xs text-slate-500">📄 ${fileName}</span>`;
      }

      async function fetchAssets(q = currentQuery) {
        try {
          const url = `${api}?per_page=200` + (q ? `&q=${encodeURIComponent(q)}` : '');
          const res = await fetch(url, { credentials: 'include' });
          const json = await readJsonResponse(res);

          if (!res.ok) return;
          if (json.success) renderAssetsTable(json.data || []);
        } catch (err) {
          console.error('fetchAssets error', err);
        }
      }

      function renderAssetsTable(rows) {
        tbody.innerHTML = '';

        if (!rows || rows.length === 0) {
          tbody.innerHTML = `
            <tr>
              <td colspan="9" class="py-8 text-center text-sm text-slate-400">
                Nta mitungo yabonetse
              </td>
            </tr>
          `;
          return;
        }

        rows.forEach((r) => {
          const tr = document.createElement('tr');
          const certificateHtml = buildCertificateHtml(r);
          const holdersCount = Number(r.holders_count || 0);

          tr.innerHTML = `
            <td>${r.asset_id}</td>
            <td>${esc(r.name || '')}</td>
            <td>${esc(r.purchase_date || '')}</td>
            <td>${money(r.purchase_value || 0)}</td>
            <td>${esc(r.location || '')}</td>
            <td>${certificateHtml}</td>
            <td>
              <button class="text-blue-600 hover:underline btn-view-holders" data-id="${r.asset_id}">
                ${holdersCount}
              </button>
            </td>
            <td>${r.sold_value === null || r.sold_value === '' ? '-' : money(r.sold_value)}</td>
            <td>
              <button class="btn-ghost btn-edit-asset" data-id="${r.asset_id}">Hindura</button>
              <button class="btn-ghost-danger btn-delete-asset" data-id="${r.asset_id}">Siba</button>
            </td>
          `;
          tbody.appendChild(tr);
        });

        tbody.querySelectorAll('.btn-delete-asset').forEach((b) =>
          b.addEventListener('click', async () => {
            const id = b.getAttribute('data-id');
            if (!confirm('Urashaka gusiba iyi Mutungo?')) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);

            try {
              const res = await fetch(api, { method: 'POST', body: fd, credentials: 'include' });
              const json = await readJsonResponse(res);

              if (json.success) fetchAssets();
              else alert(json.message || 'Error');
            } catch (err) {
              console.error(err);
              alert('Network error');
            }
          })
        );

        tbody.querySelectorAll('.btn-edit-asset').forEach((b) =>
          b.addEventListener('click', async () => {
            const id = b.getAttribute('data-id');

            try {
              const res = await fetch(`${api}?id=${encodeURIComponent(id)}`, { credentials: 'include' });
              const json = await readJsonResponse(res);

              if (json.success && json.data) {
                const d = json.data;

                idInput.value = d.asset_id || '';
                nameInput.value = d.name || '';
                purchaseDateInput.value = d.purchase_date || '';
                purchaseValueInput.value = d.purchase_value || '';
                locationInput.value = d.location || '';
                notesInput.value = d.notes || '';
                certificateNameInput.value = d.certificate_name || '';

                const hasSold = d.sold_value !== null && d.sold_value !== '';
                hasSoldCheckbox.checked = hasSold;
                toggleSoldSection(hasSold);

                soldDateInput.value = d.sold_date || '';
                soldValueInput.value = d.sold_value ?? '';

                holdersList.innerHTML = '';
                if (Array.isArray(d.holders) && d.holders.length) {
                  d.holders.forEach(h => addHolderRow({
                    user_id: h.user_id,
                    display: `${h.names || ''}${h.phone1 ? ' · ' + h.phone1 : ''}`,
                    net_value: h.net_value || 0,
                    contribution: h.contribution_amount || '',
                    notes: h.notes || ''
                  }));
                } else {
                  addHolderRow();
                }

                updateHolderTotals();
                openModal();
              } else {
                alert(json.message || 'Not found');
              }
            } catch (err) {
              console.error(err);
              alert('Failed to load asset');
            }
          })
        );

        tbody.querySelectorAll('.btn-view-holders').forEach((b) =>
          b.addEventListener('click', async () => {
            const id = b.getAttribute('data-id');
            holdersViewBody.innerHTML = 'Loading...';

            try {
              const res = await fetch(`${api}?id=${encodeURIComponent(id)}`, { credentials: 'include' });
              const json = await readJsonResponse(res);

              if (!json.success || !json.data) {
                holdersViewBody.innerHTML = 'Not found';
                openHoldersView();
                return;
              }

              const d = json.data;
              const rows = (d.holders || []).map((h, idx) => `
                <tr>
                  <td>${idx + 1}</td>
                  <td>${esc(h.names || '')}</td>
                  <td>${esc(h.phone1 || h.phone2 || '')}</td>
                  <td>${money(h.net_value || 0)}</td>
                  <td>${money(h.contribution_amount || 0)}</td>
                </tr>
              `).join('');

              holdersViewBody.innerHTML = `
                <div class="space-y-3">
                  <div><b>Asset:</b> ${esc(d.name || '')}</div>
                  <div><b>Holders:</b> ${Number(d.holders_count || 0)}</div>
                  <div class="table-wrapper">
                    <table class="table">
                      <thead>
                        <tr>
                          <th>#</th>
                          <th>Name</th>
                          <th>Phone</th>
                          <th>Net</th>
                          <th>Contribution</th>
                        </tr>
                      </thead>
                      <tbody>${rows || `<tr><td colspan="5" class="text-sm text-slate-500">Nta holders</td></tr>`}</tbody>
                    </table>
                  </div>
                </div>
              `;
              openHoldersView();
            } catch (err) {
              console.error(err);
              holdersViewBody.innerHTML = 'Failed to load holders';
              openHoldersView();
            }
          })
        );
      }

      function getHolderRows() {
        return [...holdersList.querySelectorAll('.asset-holder-row')];
      }

      function updateHolderTotals() {
        const price = Number(purchaseValueInput?.value || 0);
        let total = 0;

        getHolderRows().forEach(row => {
          const v = Number(row.querySelector('.holder-contribution')?.value || 0);
          total += v;
        });

        const remaining = price - total;

        if (holdersTotalEl) holdersTotalEl.textContent = money(total);
        if (holdersRemainingEl) holdersRemainingEl.textContent = money(remaining);

        let msg = '';
        if (price <= 0) {
          msg = 'Shyiramo purchase value mbere.';
        } else if (getHolderRows().length === 0) {
          msg = 'Ongeramo nibura holder umwe.';
        } else if (Math.abs(remaining) > 0.009) {
          msg = 'Contributions za holders zigomba kungana n’agaciro k’umutungo.';
        }

        if (holdersValidationEl) {
          holdersValidationEl.textContent = msg;
          holdersValidationEl.classList.toggle('hidden', !msg);
        }
      }

      function addHolderRow(pref = null) {
        const row = document.createElement('div');
        row.className = 'asset-holder-row rounded-lg border p-3 space-y-2';

        row.innerHTML = `
          <div class="flex gap-2 items-start">
            <div class="flex-1">
              <label class="block text-xs font-medium text-slate-700">Shakisha Holder</label>
              <input type="text" class="holder-search mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Andika izina cyangwa phone..." />
              <div class="holder-results mt-1 hidden max-h-48 overflow-auto rounded-lg border bg-white shadow-sm"></div>

              <input type="hidden" class="holder-user-id" />
              <div class="mt-1 text-xs text-slate-600">
                Watoranyije: <span class="holder-selected font-semibold">Ntawe</span>
              </div>
              <div class="mt-1 text-xs">
                Net: <span class="holder-net font-semibold">-</span>
              </div>
            </div>

            <div class="w-40">
              <label class="block text-xs font-medium text-slate-700">Contribution</label>
              <input type="number" step="0.01" min="0" class="holder-contribution mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Frw" />
            </div>

            <div class="w-48">
              <label class="block text-xs font-medium text-slate-700">Notes</label>
              <input type="text" class="holder-notes mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Optional" />
            </div>

            <div class="pt-6">
              <button type="button" class="btn-ghost-danger text-xs btn-remove-holder">Siba</button>
            </div>
          </div>
        `;

        holdersList.appendChild(row);

        const holderSearchInput = row.querySelector('.holder-search');
        const resultsBox = row.querySelector('.holder-results');
        const hiddenInput = row.querySelector('.holder-user-id');
        const selectedEl = row.querySelector('.holder-selected');
        const netEl = row.querySelector('.holder-net');
        const contributionInput = row.querySelector('.holder-contribution');
        const notesField = row.querySelector('.holder-notes');
        const removeBtn = row.querySelector('.btn-remove-holder');

        let timer = null;

        removeBtn.addEventListener('click', () => {
          row.remove();
          updateHolderTotals();
        });

        contributionInput.addEventListener('input', updateHolderTotals);

        holderSearchInput.addEventListener('input', () => {
          clearTimeout(timer);
          const q = holderSearchInput.value.trim();

          timer = setTimeout(async () => {
            resultsBox.innerHTML = '';
            resultsBox.classList.add('hidden');
            if (q.length < 2) return;

            try {
              const res = await fetch(`${api}?action=search_members&q=${encodeURIComponent(q)}`, { credentials: 'include' });
              const json = await readJsonResponse(res);
              if (!json.success) return;

              resultsBox.classList.remove('hidden');

              (json.data || []).forEach(u => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'w-full px-3 py-2 text-left hover:bg-slate-50 text-sm';
                btn.textContent = `${u.names}${u.phone1 ? ' · ' + u.phone1 : ''} · net: ${money(u.net_value || 0)}`;
                btn.addEventListener('click', () => {
                  hiddenInput.value = u.id;
                  selectedEl.textContent = `${u.names}${u.phone1 ? ' · ' + u.phone1 : ''}`;
                  netEl.textContent = money(u.net_value || 0);
                  holderSearchInput.value = '';
                  resultsBox.innerHTML = '';
                  resultsBox.classList.add('hidden');
                  updateHolderTotals();
                });
                resultsBox.appendChild(btn);
              });
            } catch (err) {
              console.error(err);
            }
          }, 250);
        });

        if (pref) {
          hiddenInput.value = pref.user_id || '';
          selectedEl.textContent = pref.display || 'Ntawe';
          netEl.textContent = money(pref.net_value || 0);
          contributionInput.value = pref.contribution || '';
          notesField.value = pref.notes || '';
        }

        updateHolderTotals();
      }

      if (btnAddHolder) {
        btnAddHolder.addEventListener('click', (e) => {
          e.preventDefault();
          addHolderRow();
        });
      }

      if (btnNew) {
        btnNew.addEventListener('click', () => {
          resetForm();
          openModal();
          nameInput?.focus();
        });
      }

      if (btnRefresh) {
        btnRefresh.addEventListener('click', () => {
          currentQuery = '';
          if (searchInput) searchInput.value = '';
          fetchAssets();
        });
      }

      if (searchInput) {
        searchInput.addEventListener('input', (e) => {
          clearTimeout(searchTimer);
          searchTimer = setTimeout(() => {
            currentQuery = e.target.value.trim();
            fetchAssets(currentQuery);
          }, 300);
        });
      }

      if (searchBtn) {
        searchBtn.addEventListener('click', () => {
          currentQuery = searchInput ? searchInput.value.trim() : '';
          fetchAssets(currentQuery);
        });
      }

      modalClose?.addEventListener('click', closeModal);
      cancelBtn?.addEventListener('click', closeModal);
      modal?.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

      holdersViewClose?.addEventListener('click', closeHoldersView);
      holdersViewModal?.addEventListener('click', (e) => { if (e.target === holdersViewModal) closeHoldersView(); });

      hasSoldCheckbox?.addEventListener('change', () => toggleSoldSection());
      purchaseValueInput?.addEventListener('input', updateHolderTotals);

      if (saveBtn) {
        saveBtn.addEventListener('click', async () => {
          const id = idInput?.value || '';
          const hasSold = hasSoldCheckbox?.checked || false;

          if (!nameInput?.value.trim()) {
            alert('Shyiramo izina ry’umutungo.');
            return;
          }
          if (!purchaseDateInput?.value) {
            alert('Shyiramo itariki yaguriweho.');
            return;
          }
          if (!purchaseValueInput?.value || Number(purchaseValueInput.value) <= 0) {
            alert('Shyiramo purchase value irenze zero.');
            return;
          }

          if (hasSold && !soldDateInput?.value) {
            alert('Shyiramo sold date.');
            return;
          }

          if (!hasSold) {
            soldDateInput.value = '';
            soldValueInput.value = '';
          }

          const holders = [];
          let total = 0;
          const seen = new Set();

          for (const row of getHolderRows()) {
            const userId = row.querySelector('.holder-user-id')?.value || '';
            const contribution = Number(row.querySelector('.holder-contribution')?.value || 0);
            const notes = row.querySelector('.holder-notes')?.value || '';

            if (!userId || contribution <= 0) continue;

            if (seen.has(userId)) {
              alert('Hari holder washyizwemo kabiri.');
              return;
            }
            seen.add(userId);

            holders.push({
              user_id: userId,
              contribution,
              notes
            });
            total += contribution;
          }

          const price = Number(purchaseValueInput.value || 0);
          if (!holders.length) {
            alert('Ongeramo nibura holder umwe.');
            return;
          }
          if (Math.abs(total - price) > 0.009) {
            alert(`Contribution za holders zigomba kungana na ${money(price)}. Ubu ni ${money(total)}.`);
            return;
          }

          const fd = new FormData();
          fd.append('action', id ? 'update' : 'create');
          if (id) fd.append('id', id);

          fd.append('name', nameInput?.value || '');
          fd.append('purchase_date', purchaseDateInput?.value || '');
          fd.append('purchase_value', purchaseValueInput?.value || '');
          fd.append('location', locationInput?.value || '');
          fd.append('notes', notesInput?.value || '');
          fd.append('certificate_name', certificateNameInput?.value || '');

          if (certificateFileInput?.files?.[0]) {
            fd.append('certificate_file', certificateFileInput.files[0]);
          }

          fd.append('sold_date', hasSold ? (soldDateInput?.value || '') : '');
          fd.append('sold_value', hasSold ? (soldValueInput?.value || '') : '');
          fd.append('holders', JSON.stringify(holders));

          try {
            const res = await fetch(api, { method: 'POST', body: fd, credentials: 'include' });
            const json = await readJsonResponse(res);

            if (json.success) {
              closeModal();
              fetchAssets();
            } else {
              alert(json.message || 'Error saving');
            }
          } catch (err) {
            console.error(err);
            alert('Network error: ' + err.message);
          }
        });
      }

      resetForm();
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
    
    // SCRIPTS: Expenses management (expense-only backend)
(function () {

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

  const idInput = document.getElementById('expense-id');
  const dateInput = document.getElementById('expense-date');
  const categoryInput = document.getElementById('expense-category');
  const amountInput = document.getElementById('expense-amount');
  const descriptionInput = document.getElementById('expense-description');

  if (!tbody) return;

  let currentQuery = '';
  let searchTimer = null;

  function esc(v) {
    if (typeof globalEscapeHtml === 'function') return globalEscapeHtml(v ?? '');
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function money(n) {
    return `${Number(n || 0).toLocaleString('rw-RW', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} Frw`;
  }

  function toDatetimeLocal(mysqlDt) {
    if (!mysqlDt) return '';
    return String(mysqlDt).replace(' ', 'T').slice(0, 16);
  }

  function openModal() {
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
  }

  function resetForm() {
    if (form) form.reset();
    if (idInput) idInput.value = '';
    if (dateInput && !dateInput.value) {
      dateInput.value = new Date().toISOString().slice(0, 16);
    }
  }

  // Store category + notes inside description as: "CATEGORY :: notes"
  function packDesc(category, notes) {
    const c = String(category || '').trim();
    const n = String(notes || '').trim();
    return n ? `${c} :: ${n}` : c;
  }

  function unpackDesc(desc) {
    const s = String(desc || '');
    const parts = s.split('::');
    if (parts.length >= 2) {
      return {
        category: parts[0].trim(),
        notes: parts.slice(1).join('::').trim()
      };
    }
    return { category: s.trim(), notes: '' };
  }

  async function readJsonResponse(res) {
    const text = await res.text();
    let json = null;
    try {
      json = text ? JSON.parse(text) : {};
    } catch (e) {
      console.error('Non-JSON response:', text);
      throw new Error('Server returned non-JSON response.');
    }
    return json;
  }

  async function loadAccounts() {
    if (!accountSelect) return;

    try {
      const res = await fetch(`${api}?accounts=1`, { credentials: 'include' });
      const json = await readJsonResponse(res);

      if (!res.ok) {
        alert(json.message || ('HTTP ' + res.status));
        return;
      }

      if (json.success) {
        accountSelect.innerHTML = '<option value="">-- Hitamo Konto --</option>';
        (json.data || []).forEach(acc => {
          const opt = document.createElement('option');
          opt.value = acc.account_id;
          opt.textContent = `${acc.name}${acc.balance !== undefined ? ` (${money(acc.balance)})` : ''}`;
          accountSelect.appendChild(opt);
        });
      } else {
        alert(json.message || 'Failed to load accounts');
      }

    } catch (err) {
      console.error(err);
      alert('Failed to load accounts: ' + err.message);
    }
  }

  async function fetchExpenses(q = currentQuery) {
    try {
      const url = `${api}?per_page=200` + (q ? `&q=${encodeURIComponent(q)}` : '');
      const res = await fetch(url, { credentials: 'include' });
      const json = await readJsonResponse(res);

      if (!res.ok) {
        console.error('fetchExpenses', res.status, json);
        tbody.innerHTML = `
          <tr>
            <td colspan="7" class="text-center py-8 text-red-500 text-sm">
              Error loading expenses
            </td>
          </tr>
        `;
        return;
      }

      if (json.success) {
        renderExpensesTable(json.data || []);
      } else {
        console.error('expenses load', json);
      }

    } catch (err) {
      console.error('fetchExpenses error', err);
      tbody.innerHTML = `
        <tr>
          <td colspan="7" class="text-center py-8 text-red-500 text-sm">
            Network error while loading expenses
          </td>
        </tr>
      `;
    }
  }

  function renderExpensesTable(rows) {
    tbody.innerHTML = '';

    if (!rows || rows.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="7" class="text-center py-8 text-slate-400 text-sm">
            Nta expenses zagaragaye
          </td>
        </tr>
      `;
      return;
    }

    rows.forEach((r, idx) => {
      const listNo = idx + 1; // newest first -> #1 is latest
      const { category, notes } = unpackDesc(r.description || '');

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${listNo}</td>
        <td>${esc(r.account_name || '')}</td>
        <td>${esc((r.tx_date || '').replace('T', ' ').replace(/:00$/, ''))}</td>
        <td>${esc(category || '')}</td>
        <td>${money(r.amount || 0)}</td>
        <td>${esc(notes || '')}</td>
        <td>
          <button class="btn-ghost btn-edit-expense" data-id="${r.transaction_id}">Hindura</button>
          <button class="btn-ghost-danger btn-delete-expense" data-id="${r.transaction_id}">Siba</button>
        </td>
      `;

      tbody.appendChild(tr);
    });

    tbody.querySelectorAll('.btn-delete-expense').forEach(b =>
      b.addEventListener('click', async () => {
        const id = b.getAttribute('data-id');
        if (!confirm("Urashaka gusiba iyi Expense?")) return;

        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);

        try {
          const res = await fetch(api, { method: 'POST', body: fd, credentials: 'include' });
          const json = await readJsonResponse(res);

          if (json.success) {
            fetchExpenses();
          } else {
            alert(json.message || 'Error');
          }
        } catch (err) {
          console.error(err);
          alert('Network error');
        }
      })
    );

    tbody.querySelectorAll('.btn-edit-expense').forEach(b =>
      b.addEventListener('click', async () => {
        const id = b.getAttribute('data-id');

        try {
          const res = await fetch(`${api}?id=${encodeURIComponent(id)}`, { credentials: 'include' });
          const json = await readJsonResponse(res);

          if (json.success && json.data) {
            const d = json.data;
            const { category, notes } = unpackDesc(d.description || '');

            if (idInput) idInput.value = d.transaction_id || '';
            if (accountSelect) accountSelect.value = d.account_id || '';
            if (dateInput) dateInput.value = toDatetimeLocal(d.tx_date || '');
            if (categoryInput) categoryInput.value = category || '';
            if (amountInput) amountInput.value = d.amount || '';
            if (descriptionInput) descriptionInput.value = notes || '';

            openModal();
          } else {
            alert(json.message || 'Not found');
          }

        } catch (err) {
          console.error(err);
          alert('Failed to load expense');
        }
      })
    );
  }

  if (btnNew) {
    btnNew.addEventListener('click', () => {
      resetForm();
      openModal();
      if (accountSelect) accountSelect.focus();
    });
  }

  if (btnRefresh) {
    btnRefresh.addEventListener('click', () => {
      currentQuery = '';
      if (searchInput) searchInput.value = '';
      fetchExpenses();
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        currentQuery = e.target.value.trim();
        fetchExpenses(currentQuery);
      }, 300);
    });
  }

  if (searchBtn) {
    searchBtn.addEventListener('click', () => {
      currentQuery = searchInput ? searchInput.value.trim() : '';
      fetchExpenses(currentQuery);
    });
  }

  if (modalClose) modalClose.addEventListener('click', closeModal);
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  if (modal) {
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeModal();
    });
  }

  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      const id = idInput ? idInput.value : '';
      const account_id = accountSelect ? accountSelect.value : '';
      const tx_date = dateInput ? dateInput.value : '';
      const category = categoryInput ? categoryInput.value : '';
      const amount = amountInput ? amountInput.value : '';
      const notes = descriptionInput ? descriptionInput.value : '';

      if (!account_id) {
        alert('Hitamo konto.');
        return;
      }
      if (!tx_date) {
        alert('Shyiramo itariki n’igihe.');
        return;
      }
      if (!amount || Number(amount) <= 0) {
        alert('Shyiramo amount irenze zero.');
        return;
      }

      const fd = new FormData();
      fd.append('action', id ? 'update' : 'create');
      if (id) fd.append('id', id);
      fd.append('account_id', account_id);
      fd.append('tx_date', tx_date);
      fd.append('amount', amount);
      fd.append('description', packDesc(category, notes));

      try {
        const res = await fetch(api, { method: 'POST', body: fd, credentials: 'include' });
        const json = await readJsonResponse(res);

        if (!res.ok) {
          alert(`HTTP ${res.status}: ${json.message || 'Error'}`);
          return;
        }

        if (json.success) {
          closeModal();
          fetchExpenses();
        } else {
          alert(json.message || 'Error saving');
        }

      } catch (err) {
        console.error(err);
        alert('Network error: ' + err.message);
      }
    });
  }

  loadAccounts();
  fetchExpenses();

})();

/// SCRIPTS: loans.js
(function(){
  const api = 'loans_api.php';
  const tbodySelector = '#section-loans table tbody';

  const modal = document.getElementById('loan-modal');
  const form = document.getElementById('loan-form');
  const saveBtn = document.getElementById('loan-save');
  const cancelBtn = document.getElementById('loan-cancel');
  const modalClose = document.getElementById('loan-modal-close');

  const btnNewLoan = document.getElementById('btn-new-loan');
  const btnRefreshLoans = document.getElementById('btn-refresh-loans');

  const accountSelect = document.getElementById('loan-account');

  const borrowerSearch = document.getElementById('borrower-search');
  const borrowerResults = document.getElementById('borrower-results');
  const borrowerHidden = document.getElementById('loan-borrower-id');
  const borrowerSelected = document.getElementById('borrower-selected');

  const borrowerNetEl = document.getElementById('borrower-net');
  const borrowerUnpaidEl = document.getElementById('borrower-unpaid');
  const borrowerMemberEl = document.getElementById('borrower-member');

  const loanStatusBadge = document.getElementById('loan-current-status');

  const principalInput = document.getElementById('loan-principal');
  const rateInput = document.getElementById('loan-rate');
  const termInput = document.getElementById('loan-term');

  const INTEREST_METHOD = 'reducing';

  const guarantorsListEl = document.getElementById('loan-guarantors-list');
  const btnAddGuarantor = document.getElementById('btn-add-guarantor');

  const requiredGuaranteeEl = document.getElementById('required-guarantee');
  const guarantorsTotalEl = document.getElementById('guarantors-total');
  const validationMsgEl = document.getElementById('loan-validation-msg');

  const viewModal = document.getElementById('loan-view-modal');
  const viewClose = document.getElementById('loan-view-close');
  const viewBody = document.getElementById('loan-view-body');

  const statusModal = document.getElementById('loan-status-modal');
  const statusClose = document.getElementById('loan-status-close');
  const statusCancel = document.getElementById('loan-status-cancel');
  const statusSave = document.getElementById('loan-status-save');
  const statusSelect = document.getElementById('loan-status-select');
  const statusLoanId = document.getElementById('loan-status-loan-id');
  const statusCurrent = document.getElementById('loan-status-current');

  let guarantorCounter = 0;
  let borrowerSummary = null;

  function openModal(){ if(modal){ modal.classList.remove('hidden'); modal.classList.add('flex'); } }
  function closeModal(){ if(modal){ modal.classList.add('hidden'); modal.classList.remove('flex'); } }
  function openView(){ if(viewModal){ viewModal.classList.remove('hidden'); viewModal.classList.add('flex'); } }
  function closeView(){ if(viewModal){ viewModal.classList.add('hidden'); viewModal.classList.remove('flex'); } }
  function openStatus(){ if(statusModal){ statusModal.classList.remove('hidden'); statusModal.classList.add('flex'); } }
  function closeStatus(){ if(statusModal){ statusModal.classList.add('hidden'); statusModal.classList.remove('flex'); } }

  function money(n){
    const x = Number(n || 0);
    return x.toLocaleString('rw-RW', {minimumFractionDigits: 0, maximumFractionDigits: 2}) + ' Frw';
  }

  function esc(v){
    return (typeof globalEscapeHtml === 'function')
      ? globalEscapeHtml(v ?? '')
      : String(v ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
  }

  function setText(el, value){
    if(el) el.textContent = value;
  }

  async function readJsonResponse(res){
    const text = await res.text();
    if(!text || !text.trim()) throw new Error('Empty response');
    try{
      return JSON.parse(text);
    }catch(err){
      console.error('Non-JSON response:', text);
      throw new Error('Server returned non-JSON.');
    }
  }

  // ---------------------------
  // Accounts dropdown
  // ---------------------------
  async function loadAccounts(){
    if(!accountSelect) return;
    try{
      const res = await fetch('accounts_api.php', {credentials:'include'});
      const json = await readJsonResponse(res);

      if(!res.ok){
        alert(json.message || ('HTTP ' + res.status));
        return;
      }

      if(json.success){
        accountSelect.innerHTML = '<option value="">-- Hitamo Konto --</option>';
        (json.data || []).forEach(acc => {
          const opt = document.createElement('option');
          opt.value = acc.account_id;
          opt.textContent = acc.name;
          accountSelect.appendChild(opt);
        });
      } else {
        alert(json.message || 'Failed to load accounts');
      }
    }catch(err){
      console.error(err);
      alert('Failed to load accounts: ' + err.message);
    }
  }

  // ---------------------------
  // Borrower Search
  // ---------------------------
  let borrowerTimer = null;

  if(borrowerSearch){
    borrowerSearch.addEventListener('input', ()=>{
      clearTimeout(borrowerTimer);
      const q = borrowerSearch.value.trim();
      borrowerTimer = setTimeout(()=> searchBorrowers(q), 250);
    });
  }

  async function searchBorrowers(q){
    if(!borrowerResults) return;

    borrowerResults.innerHTML = '';
    borrowerResults.classList.add('hidden');
    if(q.length < 2) return;

    try{
      const res = await fetch(`${api}?action=search_users&q=${encodeURIComponent(q)}`, {credentials:'include'});
      const json = await readJsonResponse(res);
      if(!json.success) return;

      borrowerResults.classList.remove('hidden');
      (json.data || []).forEach(u => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'w-full text-left px-3 py-2 hover:bg-slate-50 text-sm';
        item.textContent = `${u.names} ${u.phone ? ' · ' + u.phone : ''} ${Number(u.is_member)===1 ? ' · member' : ' · non-member'}`;
        item.addEventListener('click', ()=> selectBorrower(u));
        borrowerResults.appendChild(item);
      });
    }catch(err){
      console.error(err);
    }
  }

  async function selectBorrower(u){
    if(borrowerHidden) borrowerHidden.value = u.id;
    setText(borrowerSelected, `${u.names} ${u.phone ? ' · ' + u.phone : ''}`);

    if(borrowerResults){
      borrowerResults.innerHTML = '';
      borrowerResults.classList.add('hidden');
    }
    if(borrowerSearch) borrowerSearch.value = '';

    await loadBorrowerSummary(u.id);
    validateFormRules();
  }

  async function loadBorrowerSummary(userId){
    borrowerSummary = null;
    setText(borrowerNetEl, '...');
    setText(borrowerUnpaidEl, '...');
    setText(borrowerMemberEl, '...');

    const detailsEl = document.getElementById('borrower-net-details');

    try{
      const res = await fetch(`${api}?action=borrower_summary&user_id=${encodeURIComponent(userId)}`, {credentials:'include'});
      const json = await readJsonResponse(res);
      if(!json.success) return;

      borrowerSummary = json.data;

      setText(borrowerNetEl, money(borrowerSummary.net_value));
      setText(
        borrowerUnpaidEl,
        `${money(borrowerSummary.unpaid_loans || 0)} + Int: ${money(borrowerSummary.unpaid_interest || 0)}`
      );
      setText(borrowerMemberEl, Number(borrowerSummary.is_member) === 1 ? 'Member' : 'Non-member');

      const b = borrowerSummary.net_breakdown || null;
      if(detailsEl){
        detailsEl.innerHTML = !b ? '' : `
          <div class="text-xs text-slate-600 mt-1 space-y-1">
            <div>Contrib: <b>${money(b.contrib)}</b></div>
            <div>Interest Share: <b>${money(b.calculated_interest || 0)}</b></div>
            <div>Withdrawals: <b>${money(b.withdrawals)}</b></div>
            <div>Loans Principal (unpaid): <b>${money(b.loans_principal)}</b></div>
            <div>Loans Interest (unpaid): <b>${money(b.loans_interest || 0)}</b></div>
            <div>Guaranteed: <b>${money(b.guaranteed_to_others)}</b></div>
            <div>Reserve: <b>${money(b.reserve)}</b></div>
            <div class="text-[11px] text-slate-500">Net raw: ${money(b.net_raw)} → Net: <b>${money(b.net)}</b></div>
          </div>
        `;
      }
    }catch(err){
      console.error(err);
    }

    validateFormRules();
  }

  document.addEventListener('click', (e)=>{
    if(borrowerResults && borrowerSearch){
      if(!borrowerResults.contains(e.target) && e.target !== borrowerSearch){
        borrowerResults.classList.add('hidden');
      }
    }
  });

  // ---------------------------
  // Guarantors rows
  // ---------------------------
  function getRequiredGuarantee(principal){
    const p = Number(principal || 0);
    if(!borrowerSummary || !borrowerSummary.id) return 0;

    const isMember = Number(borrowerSummary.is_member) === 1;
    const net = Math.max(0, Number(borrowerSummary.net_value || 0));

    return isMember ? Math.max(0, p - net) : p;
  }

  function sumGuarantors(){
    let sum = 0;
    document.querySelectorAll('.guarantor-row').forEach(row => {
      const amt = parseFloat(row.querySelector('.guarantee-amount')?.value || 0) || 0;
      sum += amt;
    });
    return sum;
  }

  function getGuarantorRowData(row){
    const select = row.querySelector('.guarantor-search');
    const hidden = row.querySelector('.guarantor-id');
    const amtInput = row.querySelector('.guarantee-amount');
    const netEl = row.querySelector('.guarantor-net');
    const nameEl = row.querySelector('.guarantor-selected');
    return {select, hidden, amtInput, netEl, nameEl};
  }

  async function addGuarantorRow(pref = null){
    if(!guarantorsListEl) return;

    guarantorCounter++;

    const row = document.createElement('div');
    row.className = 'guarantor-row rounded-lg border p-3 space-y-2';

    row.innerHTML = `
      <div class="flex gap-2 items-start">
        <div class="flex-1">
          <label class="block text-xs font-medium text-slate-700">Shakisha Umwishingizi</label>
          <input type="text" class="guarantor-search mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Andika izina cyangwa phone..." />
          <div class="guarantor-results mt-1 rounded-lg border bg-white shadow-sm hidden max-h-48 overflow-auto"></div>

          <input type="hidden" class="guarantor-id" />
          <div class="mt-1 text-xs text-slate-600">
            Watoranyije: <span class="guarantor-selected font-semibold">Ntawe</span>
          </div>
          <div class="mt-1 text-xs">
            Net y'umwishingizi: <span class="guarantor-net font-semibold">-</span>
          </div>
        </div>

        <div class="w-40">
          <label class="block text-xs font-medium text-slate-700">Amount</label>
          <input type="number" step="0.01" min="0" class="guarantee-amount mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Frw" />
        </div>

        <div class="pt-6">
          <button type="button" class="btn-ghost-danger text-xs btn-remove-guarantor">Siba</button>
        </div>
      </div>
    `;

    guarantorsListEl.appendChild(row);

    const {select, amtInput} = getGuarantorRowData(row);
    const resultsBox = row.querySelector('.guarantor-results');
    const removeBtn = row.querySelector('.btn-remove-guarantor');

    removeBtn.addEventListener('click', ()=>{
      row.remove();
      validateFormRules();
    });

    let timer = null;
    select.addEventListener('input', ()=>{
      clearTimeout(timer);
      const q = select.value.trim();
      timer = setTimeout(()=> searchGuarantorsForRow(row, q), 250);
    });

    amtInput.addEventListener('input', ()=> validateFormRules());

    if(pref && pref.user_id){
      await setGuarantorForRow(row, pref.user_id, pref.display || '', pref.net_value || 0);
      amtInput.value = pref.amount || '';
    }

    validateFormRules();

    async function searchGuarantorsForRow(row, q){
      resultsBox.innerHTML = '';
      resultsBox.classList.add('hidden');

      const borrowerId = borrowerHidden?.value || '';
      if(!borrowerId || q.length < 2) return;

      try{
        const res = await fetch(`${api}?action=eligible_guarantors&borrower_id=${encodeURIComponent(borrowerId)}&q=${encodeURIComponent(q)}`, {credentials:'include'});
        const json = await readJsonResponse(res);
        if(!json.success) return;

        resultsBox.classList.remove('hidden');

        (json.data || []).forEach(g => {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'w-full text-left px-3 py-2 hover:bg-slate-50 text-sm';
          item.textContent = `${g.names}${g.phone ? ' · ' + g.phone : ''} · net: ${money(g.net_value)}${Number(g.calculated_interest || 0) > 0 ? ' · int: ' + money(g.calculated_interest) : ''}`;
          item.addEventListener('click', async ()=>{
            await setGuarantorForRow(row, g.id, `${g.names}${g.phone ? ' · ' + g.phone : ''}`, g.net_value);
            resultsBox.innerHTML = '';
            resultsBox.classList.add('hidden');
          });
          resultsBox.appendChild(item);
        });
      }catch(err){
        console.error(err);
      }
    }

    async function setGuarantorForRow(row, id, display, net){
      const {select, hidden, netEl, nameEl} = getGuarantorRowData(row);
      hidden.value = id;
      nameEl.textContent = display || ('#' + id);
      netEl.textContent = money(net);
      select.value = '';
      validateFormRules();
    }
  }

  // ---------------------------
  // Form validation rules
  // ---------------------------
  function validateFormRules(){
    const borrowerId = borrowerHidden?.value || '';
    const principal = parseFloat(principalInput?.value || 0) || 0;

    let ok = true;
    let msg = '';

    if(!accountSelect || !accountSelect.value){
      ok = false; msg = 'Hitamo konti (aho amafaranga aturuka).';
    } else if(!borrowerId){
      ok = false; msg = 'Hitamo uwasabye inguzanyo (borrower).';
    } else if(principal <= 0){
      ok = false; msg = 'Shyiramo umubare w’inguzanyo (loan amount).';
    } else if(!borrowerSummary){
      ok = false; msg = 'Tegereza borrower info (net/unpaid)...';
    } else {
      const required = getRequiredGuarantee(principal);
      const totalG = sumGuarantors();

      setText(requiredGuaranteeEl, money(required));
      setText(guarantorsTotalEl, money(totalG));

      if(required > 0){
        if(totalG + 0.00001 < required){
          ok = false;
          msg = `Abamwishingizi ntibahagije. Bisabwa: ${money(required)}. Batanze: ${money(totalG)}.`;
        }

        const used = new Set();
        document.querySelectorAll('.guarantor-row').forEach(row=>{
          const gid = row.querySelector('.guarantor-id')?.value || '';
          const amt = parseFloat(row.querySelector('.guarantee-amount')?.value || 0) || 0;

          if(!gid || amt <= 0){
            ok = false;
            if(!msg) msg = 'Hitamo umwishingizi kandi ushyireho amount.';
          } else {
            if(used.has(gid)){
              ok = false;
              msg = 'Hari umwishingizi washyizwemo kabiri.';
            }
            used.add(gid);

            if(gid === borrowerId){
              ok = false;
              msg = 'Borrower ntashobora kwiyishingira.';
            }
          }
        });
      } else {
        setText(requiredGuaranteeEl, money(0));
        setText(guarantorsTotalEl, money(sumGuarantors()));
      }
    }

    if(validationMsgEl){
      validationMsgEl.textContent = msg;
      validationMsgEl.classList.toggle('hidden', !msg);
    }

    if(saveBtn){
      saveBtn.disabled = !ok;
      saveBtn.classList.toggle('opacity-50', !ok);
      saveBtn.classList.toggle('cursor-not-allowed', !ok);
    }
  }

  if(principalInput) principalInput.addEventListener('input', validateFormRules);

  // ---------------------------
  // Loans list
  // ---------------------------
  async function fetchLoans(q=''){
    try{
      const res = await fetch(api + '?per_page=200' + (q ? ('&q=' + encodeURIComponent(q)) : ''), {
        credentials:'include',
        cache:'no-store'
      });
      const json = await readJsonResponse(res);

      if(!res.ok){
        alert(json.message || ('HTTP ' + res.status));
        return;
      }

      if(json.success) renderLoans(json.data || []);
      else alert(json.message || 'Error loading loans');
    }catch(err){
      console.error(err);
      alert(err.message || 'Error loading loans');
    }
  }

  function statusBadge(status){
    if(status === 'approved') return '<span class="badge badge-success">approved</span>';
    if(status === 'closed') return '<span class="badge badge-gray">closed</span>';
    if(status === 'defaulted') return '<span class="badge badge-danger">defaulted</span>';
    if(status === 'rejected') return '<span class="badge badge-danger">rejected</span>';
    return '<span class="badge badge-warning">requested</span>';
  }

  function renderLoans(rows){
    const tbody = document.querySelector(tbodySelector);
    if(!tbody) return;

    tbody.innerHTML = '';
    rows.forEach((r, index) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${index + 1}</td>
        <td>
          <div class="font-semibold">${esc(r.borrower_name || '')}</div>
          <div class="text-xs text-slate-600">${esc(r.borrower_phone || '')}</div>
        </td>
        <td>${esc(r.account_name || '')}</td>
        <td>${money(r.principal || 0)}</td>
        <td>
          <div>${money(r.unpaid_principal || 0)}</div>
          <div class="text-xs text-slate-500">Int: ${money(r.unpaid_interest || 0)}</div>
          <div class="text-xs text-slate-700 font-semibold">Total: ${money(r.total_due || 0)}</div>
        </td>
        <td>${esc(r.start_date || '')}</td>
        <td>${statusBadge(r.status || 'requested')}</td>
        <td>${esc(r.end_date || 'Not Yet')}</td>
        <td class="space-x-1">
          <button class="btn-ghost text-xs btn-view-loan" data-id="${r.loan_id}">Reba</button>
          <button class="btn-ghost text-xs btn-edit-loan" data-id="${r.loan_id}">Hindura</button>
          <button class="btn-ghost-danger text-xs btn-delete-loan" data-id="${r.loan_id}">Siba</button>
          <button class="btn-secondary text-xs btn-status-loan" data-id="${r.loan_id}" data-status="${r.status || 'requested'}">Change Status</button>
        </td>
      `;
      tbody.appendChild(tr);
    });
  }

  const tbody = document.querySelector(tbodySelector);
  if(tbody){
    tbody.addEventListener('click', async (e)=>{
      const viewBtn = e.target.closest('.btn-view-loan');
      const editBtn = e.target.closest('.btn-edit-loan');
      const delBtn  = e.target.closest('.btn-delete-loan');
      const stBtn   = e.target.closest('.btn-status-loan');

      if(viewBtn) await openLoanView(viewBtn.dataset.id);
      if(editBtn) await openLoanEdit(editBtn.dataset.id);

      if(delBtn){
        const id = delBtn.dataset.id;
        if(!confirm('Urashaka gusiba iyi nguzanyo?')) return;

        try{
          const fd = new FormData();
          fd.append('action', 'delete');
          fd.append('id', id);

          const res = await fetch(api, {method:'POST', body: fd, credentials:'include'});
          const json = await readJsonResponse(res);

          if(json.success) fetchLoans();
          else alert(json.message || 'Error');
        }catch(err){
          console.error(err);
          alert(err.message || 'Error');
        }
      }

      if(stBtn){
        if(statusLoanId) statusLoanId.value = stBtn.dataset.id;
        setText(statusCurrent, stBtn.dataset.status || 'requested');
        if(statusSelect) statusSelect.value = stBtn.dataset.status || 'requested';
        openStatus();
      }
    });
  }

  async function openLoanView(id){
    if(viewBody) viewBody.innerHTML = 'Loading...';

    try{
      const res = await fetch(api + '?id=' + encodeURIComponent(id), {credentials:'include'});
      const json = await readJsonResponse(res);

      if(!json.success){
        if(viewBody) viewBody.innerHTML = 'Not found';
        openView();
        return;
      }

      const d = json.data;

      const gHtml = (d.guarantors || []).map(g => `
        <tr>
          <td>${esc(g.guarantor_name || '')}</td>
          <td>${esc(g.guarantor_phone || '')}</td>
          <td>${money(g.guarantee_amount || 0)}</td>
          <td>${money(g.guarantor_net || 0)}</td>
          <td>${esc(g.status || '')}</td>
        </tr>
      `).join('');

      if(viewBody){
        viewBody.innerHTML = `
          <div class="space-y-2">
            <div><b>Loan:</b> #LN-${d.loan_id}</div>
            <div><b>Borrower:</b> ${esc(d.borrower_name || '')} · ${esc(d.borrower_phone || '')}</div>
            <div><b>Account (source):</b> ${esc(d.account_name || '')}</div>
            <div><b>Amount:</b> ${money(d.principal || 0)}</div>
            <div><b>Monthly rate:</b> ${esc(d.monthly_interest_rate || '')}</div>
            <div><b>Interest method:</b> ${esc(d.interest_method || '')}</div>
            <div><b>Term:</b> ${esc(d.term_months || '')}</div>
            <div><b>Status:</b> ${esc(d.status || 'requested')}</div>
            <div><b>Start date:</b> ${esc(d.start_date || '')}</div>
            <div><b>End date:</b> ${esc(d.end_date || '')}</div>

            <div class="mt-3 rounded-lg border p-3 bg-slate-50">
              <div><b>Paid Principal:</b> ${money(d.paid_principal || 0)}</div>
              <div><b>Unpaid Principal:</b> ${money(d.unpaid_principal || 0)}</div>
              <div><b>Initial Interest Due:</b> ${money(d.initial_interest_due || 0)}</div>
              <div><b>Paid Interest:</b> ${money(d.paid_interest || 0)}</div>
              <div><b>Unpaid Interest:</b> ${money(d.unpaid_interest || 0)}</div>
              <div class="font-semibold"><b>Total Due:</b> ${money(d.total_due || 0)}</div>
            </div>

            <div class="mt-3">
              <b>Guarantors</b>
              <div class="mt-2 table-wrapper">
                <table class="table">
                  <thead><tr><th>Name</th><th>Phone</th><th>Amount</th><th>Guarantor Net</th><th>Status</th></tr></thead>
                  <tbody>${gHtml || `<tr><td colspan="5" class="text-sm text-slate-600">Nta bamwishingizi</td></tr>`}</tbody>
                </table>
              </div>
            </div>

            <div class="mt-2"><b>Notes:</b><br/>${esc(d.notes || '')}</div>
          </div>
        `;
      }

      openView();
    }catch(err){
      console.error(err);
      if(viewBody) viewBody.innerHTML = 'Error loading loan';
      openView();
    }
  }

  async function openLoanEdit(id){
    try{
      const res = await fetch(api + '?id=' + encodeURIComponent(id), {credentials:'include'});
      const json = await readJsonResponse(res);

      if(!json.success){
        alert(json.message || 'Not found');
        return;
      }

      const d = json.data;

      if(form) form.reset();
      const loanIdEl = document.getElementById('loan-id');
      const notesEl = document.getElementById('loan-notes');

      if(loanIdEl) loanIdEl.value = d.loan_id;
      if(accountSelect) accountSelect.value = d.account_id || '';

      if(borrowerHidden) borrowerHidden.value = d.borrower_user_id || '';
      setText(borrowerSelected, `${d.borrower_name || ''}${d.borrower_phone ? ' · ' + d.borrower_phone : ''}`);

      if(principalInput) principalInput.value = d.principal || '';
      if(rateInput) rateInput.value = d.monthly_interest_rate || '';
      if(termInput) termInput.value = d.term_months || '';
      if(notesEl) notesEl.value = d.notes || '';

      setText(loanStatusBadge, d.status || 'requested');

      await loadBorrowerSummary(d.borrower_user_id);

      if(guarantorsListEl) guarantorsListEl.innerHTML = '';
      guarantorCounter = 0;

      if(d.guarantors && d.guarantors.length){
        for(const g of d.guarantors){
          await addGuarantorRow({
            user_id: g.guarantor_user_id,
            amount: g.guarantee_amount,
            display: `${g.guarantor_name || ''}${g.guarantor_phone ? ' · ' + g.guarantor_phone : ''}`,
            net_value: g.guarantor_net || 0
          });
        }
      } else {
        await addGuarantorRow();
      }

      validateFormRules();
      openModal();
    }catch(err){
      console.error(err);
      alert(err.message || 'Error loading loan');
    }
  }

  if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
  if(modalClose) modalClose.addEventListener('click', closeModal);
  if(modal) modal.addEventListener('click', (e)=>{ if(e.target === modal) closeModal(); });

  if(viewClose) viewClose.addEventListener('click', closeView);
  if(viewModal) viewModal.addEventListener('click', (e)=>{ if(e.target === viewModal) closeView(); });

  if(statusClose) statusClose.addEventListener('click', closeStatus);
  if(statusCancel) statusCancel.addEventListener('click', closeStatus);
  if(statusModal) statusModal.addEventListener('click', (e)=>{ if(e.target === statusModal) closeStatus(); });

  if(statusSave){
    statusSave.addEventListener('click', async ()=>{
      const id = statusLoanId?.value || '';
      const st = statusSelect?.value || '';

      try{
        const fd = new FormData();
        fd.append('action', 'change_status');
        fd.append('id', id);
        fd.append('status', st);

        const res = await fetch(api, {method:'POST', body: fd, credentials:'include'});
        const json = await readJsonResponse(res);

        if(json.success){
          closeStatus();
          fetchLoans();
        } else {
          alert(json.message || 'Error changing status');
        }
      }catch(err){
        console.error(err);
        alert(err.message || 'Error changing status');
      }
    });
  }

  if(btnNewLoan){
    btnNewLoan.addEventListener('click', async ()=>{
      if(form) form.reset();

      const loanIdEl = document.getElementById('loan-id');
      if(loanIdEl) loanIdEl.value = '';
      if(accountSelect) accountSelect.value = '';

      if(borrowerHidden) borrowerHidden.value = '';
      setText(borrowerSelected, 'Ntawe');

      borrowerSummary = null;
      setText(borrowerNetEl, '-');
      setText(borrowerUnpaidEl, '-');
      setText(borrowerMemberEl, '-');

      const detailsEl = document.getElementById('borrower-net-details');
      if(detailsEl) detailsEl.innerHTML = '';

      setText(loanStatusBadge, 'requested');

      if(guarantorsListEl) guarantorsListEl.innerHTML = '';
      guarantorCounter = 0;
      await addGuarantorRow();

      validateFormRules();
      openModal();
    });
  }

  if(btnRefreshLoans) btnRefreshLoans.addEventListener('click', ()=> fetchLoans());

  if(btnAddGuarantor){
    btnAddGuarantor.addEventListener('click', async (e)=>{
      e.preventDefault();
      await addGuarantorRow();
      validateFormRules();
    });
  }

  if(saveBtn){
    saveBtn.addEventListener('click', async ()=>{
      if(saveBtn.disabled) return;

      try{
        const id = document.getElementById('loan-id')?.value || '';
        const notesEl = document.getElementById('loan-notes');

        const fd = new FormData();
        fd.append('action', id ? 'update' : 'create');
        if(id) fd.append('id', id);

        fd.append('account_id', accountSelect?.value || '');
        fd.append('borrower_user_id', borrowerHidden?.value || '');
        fd.append('principal', principalInput?.value || '');
        fd.append('monthly_interest_rate', rateInput?.value || '');
        fd.append('interest_method', INTEREST_METHOD);
        fd.append('term_months', termInput?.value || '');
        fd.append('notes', notesEl?.value || '');

        const guarantorsArray = [];
        document.querySelectorAll('.guarantor-row').forEach(row=>{
          const gid = row.querySelector('.guarantor-id')?.value || '';
          const amt = row.querySelector('.guarantee-amount')?.value || '';
          if(gid && amt) guarantorsArray.push({user_id: gid, amount: amt});
        });
        fd.append('guarantors', JSON.stringify(guarantorsArray));

        const res = await fetch(api, {method:'POST', body: fd, credentials:'include'});
        const json = await readJsonResponse(res);

        if(!res.ok){
          alert(json.message || ('HTTP Error ' + res.status));
          return;
        }

        if(json.success){
          closeModal();
          fetchLoans();
        } else {
          alert(json.message || 'Error saving');
        }
      }catch(err){
        console.error(err);
        alert(err.message || 'Server returned non-JSON.');
      }
    });
  }

  ['change', 'input'].forEach(evt => {
    if(form) form.addEventListener(evt, ()=> validateFormRules());
  });

  loadAccounts();
  fetchLoans();
})();

/**
 * Transaction Management Module
 * Architecture: Module Pattern with State-Management
 */
(function () {
  // --- 1. Configuration & Constants ---
  const CONFIG = {
    api: "transactions_api.php",
    currency: "Frw",
    locale: "rw-RW",
    reserveMin: 120000,
    debounceTime: 250
  };

  // --- 2. Application State ---
  const STATE = {
    accounts: [],
    selectedUser: null,
    userLoans: [],
    loanSummary: null,
    saving: false
  };

  // --- 3. DOM Cache ---
  const DOM = {
    tbody:      document.getElementById("transactions-tbody"),
    modal:      document.getElementById("transaction-modal"),
    form:       document.getElementById("transaction-form"),
    id:         document.getElementById("transaction-id"),
    date:       document.getElementById("transaction-date"),
    user:       document.getElementById("transaction-user"),
    userSearch: document.getElementById("transaction-user-search"),
    account:    document.getElementById("transaction-account"),
    typeWrap:   document.getElementById("transaction-type-wrapper"),
    type:       document.getElementById("transaction-type"),
    direction:  document.getElementById("transaction-direction"),
    loanWrap:   document.getElementById("loan-id-wrapper"),
    loanId:     document.getElementById("transaction-loan-id"),
    amount:     document.getElementById("transaction-amount"),
    desc:       document.getElementById("transaction-description"),
    proof:      document.getElementById("transaction-proof"),
    hint:       document.getElementById("transaction-proof-hint"),
    infoBox:    document.getElementById("transaction-info-box"),
    btnNew:     document.getElementById("btn-new-transaction"),
    btnRefresh: document.getElementById("btn-refresh-transactions"),
    btnSave:    document.getElementById("transaction-save"),
    btnCancel:  document.getElementById("transaction-cancel"),
    btnClose:   document.getElementById("transaction-modal-close")
  };

  // --- 4. Utilities & Formatters ---
  const Format = {
    money: (n) => `${Number(n || 0).toLocaleString(CONFIG.locale)} ${CONFIG.currency}`,

    typeLabel: (t) => {
      const labels = {
        contribution:   "Contribution",
        withdrawal:     "Withdrawal",
        loan_principal: "Loan Payment (Principal)",
        loan_interest:  "Loan Interest",
        expense:        "Expense",
        other_income:   "Other Income",
        other_out:      "Other Out"
      };
      return labels[(t || "").toLowerCase()] || t;
    },

    toDTL: (mysqlDt) => (mysqlDt ? mysqlDt.replace(" ", "T").slice(0, 16) : ""),

    escape: (s) => String(s ?? "").replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[m]))
  };

  const Utils = {
    debounce: (fn, wait = CONFIG.debounceTime) => {
      let t;
      return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); };
    }
  };

  // --- 5. API Layer ---
  async function apiRequest(url, options = {}) {
    const res  = await fetch(url, { credentials: "include", ...options });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json.success) throw new Error(json.message || `HTTP Error ${res.status}`);
    return json;
  }

  // --- 6. UI Logic ---
  const UI = {

    setSaveState: (enabled, reason = "") => {
      const isDisabled = !enabled || STATE.saving;
      DOM.btnSave.disabled = isDisabled;
      DOM.btnSave.classList.toggle("opacity-50", isDisabled);
      DOM.btnSave.classList.toggle("cursor-not-allowed", isDisabled);
      if (DOM.hint) DOM.hint.textContent = reason || "Itegeko kuri transaction nshya.";
    },

    toggleModal: (show = true) => {
      DOM.modal.classList.toggle("hidden", !show);
      DOM.modal.classList.toggle("flex", show);
      if (!show) {
        DOM.form.reset();
        STATE.selectedUser = STATE.loanSummary = null;
        STATE.userLoans = [];
        UI.updateInfoBox("");
        UI.syncLogic();
      }
    },

    updateInfoBox: (html) => { if (DOM.infoBox) DOM.infoBox.innerHTML = html; },

    renderRows: (rows) => {
      if (!DOM.tbody) return;
      if (!rows || rows.length === 0) {
        DOM.tbody.innerHTML = `<tr><td colspan="8" class="text-center py-8 text-slate-400 text-sm">
          Nta transactions zibonetse
        </td></tr>`;
        return;
      }
      DOM.tbody.innerHTML = rows.map((r, i) => `
        <tr class="border-b hover:bg-gray-50 text-sm">
          <td class="p-3">${i + 1}</td>
          <td class="p-3 whitespace-nowrap">${Format.escape(Format.toDTL(r.tx_date).replace("T", " "))}</td>
          <td class="p-3">
            <div class="font-bold text-slate-700">${Format.escape(r.user_name || "System")}</div>
            ${r.loan_id ? `<span class="text-[10px] bg-blue-100 text-blue-700 px-1 rounded">#LN-${r.loan_id}</span>` : ""}
          </td>
          <td class="p-3 text-slate-600">${Format.escape(r.account_name || "N/A")}</td>
          <td class="p-3">${Format.escape(Format.typeLabel(r.type))}</td>
          <td class="p-3">
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${
              r.direction === "IN" ? "bg-green-100 text-green-700" : "bg-red-100 text-red-700"
            }">${Format.escape(r.direction || "")}</span>
          </td>
          <td class="p-3 font-mono font-bold">${Format.money(r.amount)}</td>
          <td class="p-3 space-x-1">
            <button class="text-emerald-600 hover:underline btn-edit" data-id="${r.transaction_id}">Hindura</button>
            <button class="text-red-600 hover:underline btn-delete" data-id="${r.transaction_id}">Siba</button>
          </td>
        </tr>
      `).join("");

      // wire edit/delete on rendered rows
      DOM.tbody.querySelectorAll(".btn-edit").forEach(btn => {
        btn.onclick = () => openEdit(Number(btn.dataset.id));
      });
      DOM.tbody.querySelectorAll(".btn-delete").forEach(btn => {
        btn.onclick = () => deleteTransaction(Number(btn.dataset.id));
      });
    },

    syncLogic: async () => {
      const type        = (DOM.type.value || "").toLowerCase();
      const userSelected = !!DOM.user.value;

      UI.renderTypeOptions();

      // Show/hide type wrapper
      if (DOM.typeWrap) DOM.typeWrap.classList.toggle("hidden", !userSelected && type !== "expense");

      // Show/hide loan wrapper
      const isLoan = ["loan_principal", "loan_interest"].includes(type);
      if (DOM.loanWrap) DOM.loanWrap.classList.toggle("hidden", !isLoan);

      // Auto-set direction
      DOM.direction.value = ["contribution", "loan_principal", "loan_interest", "other_income"].includes(type) ? "IN" : "OUT";

      if (isLoan && userSelected) await UI.loadUserLoans();
      UI.validateAll();
    },

    renderTypeOptions: () => {
      const types = [
        { v: "",               t: "-- Ubwoko --" },
        { v: "contribution",   t: "Contribution" },
        { v: "withdrawal",     t: "Withdrawal" },
        { v: "loan_principal", t: "Loan Payment (Principal)" },
        { v: "loan_interest",  t: "Loan Interest" },
        { v: "expense",        t: "Expense" },
        { v: "other_income",   t: "Other Income" },
        { v: "other_out",      t: "Other Out" }
      ];

      const userSelected = !!DOM.user.value;
      const current      = DOM.type.value;
      const allowed      = userSelected ? types : types.filter(x => x.v === "" || x.v === "expense");

      DOM.type.innerHTML = allowed.map(x =>
        `<option value="${x.v}">${Format.escape(x.t)}</option>`
      ).join("");
      DOM.type.value = [...DOM.type.options].some(o => o.value === current) ? current : "";
    },

    validateAll: () => {
      const type   = (DOM.type.value || "").toLowerCase();
      const amount = Number(DOM.amount.value || 0);
      const accId  = Number(DOM.account.value || 0);
      let valid = true, reason = "";

      if (!accId)                                   { valid = false; reason = "Hitamo konti."; }
      else if (!type)                               { valid = false; reason = "Hitamo ubwoko."; }
      else if (amount <= 0)                         { valid = false; reason = "Umubare ugomba kuba > 0."; }
      else if (type !== "expense" && !DOM.user.value) { valid = false; reason = "Hitamo umunyamuryango."; }

      // Balance check for OUT types
      if (valid && ["withdrawal", "expense", "other_out"].includes(type)) {
        const acc = STATE.accounts.find(a => Number(a.account_id) === accId);
        if (acc && amount > Number(acc.balance || 0)) {
          valid = false; reason = `Amafaranga ari kuri konti ntahagije (${Format.money(acc.balance)}).`;
        }
      }

      UI.setSaveState(valid, reason);
      return valid;
    },

    loadUserLoans: async () => {
      try {
        const userId = DOM.user.value;
        const json   = await apiRequest(`loans_api.php?per_page=100`);
        STATE.userLoans = (json.data || []).filter(l =>
          String(l.borrower_user_id) === String(userId) && l.status === "approved"
        );
        DOM.loanId.innerHTML = '<option value="">-- Hitamo Loan --</option>' +
          STATE.userLoans.map(l =>
            `<option value="${l.loan_id}">#LN-${l.loan_id} – ${Format.escape(l.borrower_name || "")}</option>`
          ).join("");
      } catch (e) {
        console.error("loadUserLoans failed:", e.message);
      }
    }
  };

  // --- 7. Edit / Delete helpers ---
  async function openEdit(id) {
    try {
      const json = await apiRequest(`${CONFIG.api}?id=${id}`);
      const r    = json.data;

      UI.toggleModal(true);
      DOM.id.value        = r.transaction_id;
      DOM.date.value      = Format.toDTL(r.tx_date);
      DOM.account.value   = r.account_id;
      DOM.direction.value = r.direction;
      DOM.amount.value    = r.amount;
      DOM.desc.value      = r.description || "";

      // Populate user dropdown with the existing user so it's selectable
      if (r.user_id) {
        DOM.user.innerHTML = `<option value="${r.user_id}">${Format.escape(r.user_name || r.user_id)}</option>`;
        DOM.user.value = r.user_id;
      }

      // Show type wrapper, set type
      if (DOM.typeWrap) DOM.typeWrap.classList.remove("hidden");
      DOM.type.innerHTML = `<option value="${r.type}">${Format.escape(Format.typeLabel(r.type))}</option>`;
      DOM.type.value = r.type;

      if (r.loan_id) {
        if (DOM.loanWrap) DOM.loanWrap.classList.remove("hidden");
        DOM.loanId.innerHTML = `<option value="${r.loan_id}">#LN-${r.loan_id}</option>`;
        DOM.loanId.value = r.loan_id;
      }

      // Hide proof required hint on edit
      if (DOM.hint) DOM.hint.textContent = "Injiza dosiye nshya gusa niba ushaka kuyisubiraho.";
      if (DOM.proof) DOM.proof.removeAttribute("required");

      document.getElementById("transaction-modal-title").textContent = "Hindura Transaction";
      UI.setSaveState(true);

    } catch (e) {
      alert("Kugerageza kohereza amakuru: " + e.message);
    }
  }

  async function deleteTransaction(id) {
    if (!confirm(`Uremeza gusiba transaction #${id}?`)) return;
    try {
      const fd = new FormData();
      fd.set("action", "delete");
      fd.set("id", id);
      await apiRequest(CONFIG.api, { method: "POST", body: fd });
      loadList();
    } catch (e) {
      alert("Gusiba byanze: " + e.message);
    }
  }

  async function loadList() {
    try {
      DOM.tbody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-slate-400 text-sm">
        <i class="fas fa-spinner fa-spin mr-2"></i>Gutegura...
      </td></tr>`;
      const json = await apiRequest(`${CONFIG.api}?per_page=100`);
      UI.renderRows(json.data || []);
    } catch (e) {
      console.error("loadList failed:", e.message);
      DOM.tbody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-red-500 text-sm">
        Gufungura urutonde byanze: ${Format.escape(e.message)}
      </td></tr>`;
    }
  }

  // --- 8. Event Wiring ---
  function bindEvents() {

    DOM.btnNew.onclick = () => {
      // Reset modal title and proof requirement
      document.getElementById("transaction-modal-title").textContent = "Ongeza Transaction";
      if (DOM.proof) DOM.proof.setAttribute("required", "required");
      if (DOM.hint)  DOM.hint.textContent = "Itegeko kuri transaction nshya.";
      UI.toggleModal(true);
      DOM.date.value = new Date().toISOString().slice(0, 16);
      DOM.id.value   = "";
      UI.syncLogic();
    };

    DOM.btnClose.onclick  = () => UI.toggleModal(false);
    DOM.btnCancel.onclick = () => UI.toggleModal(false);
    DOM.btnRefresh.onclick = loadList;

    // User search
    DOM.userSearch.oninput = Utils.debounce(async (e) => {
      const q = e.target.value.trim();
      if (q.length < 2) return;
      try {
        const json = await apiRequest(`users_api.php?q=${encodeURIComponent(q)}&per_page=20`);
        DOM.user.innerHTML = '<option value="">-- Hitamo User --</option>' +
          (json.data || []).map(u =>
            `<option value="${u.id}">${Format.escape(u.names)} | ${u.phone1 || ""}</option>`
          ).join("");
      } catch (e) { console.error("User search failed:", e.message); }
    }, CONFIG.debounceTime);

    // User selected
    DOM.user.onchange = () => UI.syncLogic();

    // Loan selected — show info
    DOM.loanId.onchange = async () => {
      const loanId = DOM.loanId.value;
      if (!loanId) return;
      const loan = STATE.userLoans.find(l => String(l.loan_id) === String(loanId));
      if (loan) {
        UI.updateInfoBox(`
          <div class="mt-2 text-xs rounded border border-blue-200 bg-blue-50 p-2">
            <p class="font-bold mb-1">Loan #${loan.loan_id} – ${Format.escape(loan.borrower_name || "")}</p>
            <p>Principal: <b>${Format.money(loan.principal_amount)}</b></p>
            <p>Status: <b>${Format.escape(loan.status)}</b></p>
          </div>
        `);
      }
      UI.validateAll();
    };

    [DOM.type, DOM.amount, DOM.account, DOM.date].forEach(el => {
      if (el) el.onchange = () => UI.syncLogic();
    });

    // Save
    DOM.btnSave.onclick = async () => {
      if (!UI.validateAll()) return;
      STATE.saving = true;
      UI.setSaveState(false, "Kubika...");

      try {
        const fd     = new FormData(DOM.form);
        const isEdit = !!DOM.id.value;

        // loan_principal goes as create (API handles split internally)
        fd.set("action", isEdit ? "update" : "create");

        const res = await apiRequest(CONFIG.api, { method: "POST", body: fd });

        // If API returned split info, show it
        if (res.data?.interest_paid !== undefined) {
          alert(
            `Byishyuwe:\n` +
            `• Interest: ${Format.money(res.data.interest_paid)}\n` +
            `• Principal: ${Format.money(res.data.principal_paid)}`
          );
        }

        UI.toggleModal(false);
        loadList();
      } catch (e) {
        alert("Kubika byanze: " + e.message);
      } finally {
        STATE.saving = false;
        UI.setSaveState(true);
      }
    };
  }

  // --- 9. Initialization ---
  (async function init() {
    try {
      // Load accounts
      try {
        const accJson = await apiRequest("accounts_api.php");
        STATE.accounts = accJson.data || [];
        DOM.account.innerHTML = '<option value="">-- Hitamo Konti --</option>' +
          STATE.accounts.map(a =>
            `<option value="${a.account_id}">${Format.escape(a.name)}${a.balance !== undefined ? ` (${Format.money(a.balance)})` : ""}</option>`
          ).join("");
      } catch (e) {
        console.error("Accounts load failed:", e.message);
        DOM.account.innerHTML = '<option value="">-- Konti ntizashoboka --</option>';
      }

      bindEvents();
      await loadList();

    } catch (e) {
      console.error("Transaction module init failed:", e);
      if (DOM.tbody) {
        DOM.tbody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-red-500 text-sm">
          Module yanze gutangira: ${Format.escape(e.message)}
        </td></tr>`;
      }
    }
  })();

})();