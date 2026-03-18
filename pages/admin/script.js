const apiUrl = 'users_api.php';

const globalEscapeHtml = (s) => {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>'"]/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])
  );
};
window.globalEscapeHtml = globalEscapeHtml;

const _money  = (v) => Number(v || 0).toLocaleString('rw-RW') + ' Frw';
const _orDash = (v) =>
  (v !== null && v !== undefined && String(v).trim() !== '')
    ? globalEscapeHtml(v)
    : '<span class="text-slate-300">—</span>';

const _pill = (s) => {
  const map = {
    approved : 'spill spill-green',
    closed   : 'spill spill-slate',
    defaulted: 'spill spill-red',
    requested: 'spill spill-amber',
    rejected : 'spill spill-red',
    accepted : 'spill spill-green',
    pending  : 'spill spill-amber',
  };
  const cls = map[(s || '').toLowerCase()] || 'spill spill-slate';
  return `<span class="${cls}">${globalEscapeHtml(s)}</span>`;
};

function userProfileImageUrl(id) {
  return `${apiUrl}?id=${encodeURIComponent(id)}&image=profile&t=${Date.now()}`;
}

function userNidImageUrl(id) {
  return `${apiUrl}?id=${encodeURIComponent(id)}&image=nid&t=${Date.now()}`;
}

function setImagePreview(imgEl, placeholderEl, url, showImage = true) {
  if (!imgEl || !placeholderEl) return;

  if (showImage && url) {
    imgEl.src = url;
    imgEl.classList.remove('hidden');
    placeholderEl.classList.add('hidden');
  } else {
    imgEl.src = '';
    imgEl.classList.add('hidden');
    placeholderEl.classList.remove('hidden');
  }
}

function clearFileInput(id) {
  const el = document.getElementById(id);
  if (el) el.value = '';
}

/* ─────────────────────────────────────────────────────────────
   Optional sidebar navigation
───────────────────────────────────────────────────────────── */
(function () {
  const menu = document.getElementById('admin-menu');
  if (!menu) return;

  const sections = [
    'overview','members','users','loans','accounts','shares',
    'payments','expenses','transactions','assets','notifications','reports','settings',
  ];

  const titles = {
    overview    : "Isuzuma rusange ry'Ikimina",
    members     : "Urutonde n'imicungire y'abanyamuryango",
    users       : "Imicungire y'abakoresha (Create / Edit / Delete)",
    accounts    : "Amafaranga (Accounts) - Imicungire y'ama konti",
    loans       : "Inguzanyo zose z'Ikimina",
    shares      : "Imigabane n'inyungu zayo",
    payments    : "Kwishyura kw'inguzanyo",
    expenses    : "Expenses z'Ikimina",
    transactions: "Transactions - Izafari z'amafaranga",
    assets      : "Imutungo (Assets) y'Ikimina",
    notifications:"Notifications",
    reports     : "Raporo z'ingenzi",
    settings    : "Igenamiterere",
  };

  menu.querySelectorAll('button[data-section]').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.getAttribute('data-section');

      menu.querySelectorAll('button[data-section]').forEach(b => {
        b.classList.remove('sidebar-link-active');
      });
      btn.classList.add('sidebar-link-active');

      sections.forEach(s => {
        const el = document.getElementById(`section-${s}`);
        if (el) el.classList.toggle('hidden', s !== key);
      });

      const titleEl = document.getElementById('section-title');
      if (titleEl && titles[key]) titleEl.textContent = titles[key];
    });
  });
})();

/* ─────────────────────────────────────────────────────────────
   USER VIEW MODAL
───────────────────────────────────────────────────────────── */
(function () {
  const uvModal = document.getElementById('user-view-modal');
  if (!uvModal) return;

  let currentUserFullData = null;

  const openUVM  = () => { uvModal.classList.remove('hidden'); uvModal.classList.add('flex'); };
  const closeUVM = () => { uvModal.classList.add('hidden'); uvModal.classList.remove('flex'); };

  const viewModalClose = document.getElementById('view-modal-close');
  const viewClose = document.getElementById('view-close');

  if (viewModalClose) viewModalClose.addEventListener('click', closeUVM);
  if (viewClose) viewClose.addEventListener('click', closeUVM);
  uvModal.addEventListener('click', e => { if (e.target === uvModal) closeUVM(); });

  uvModal.querySelectorAll('.uvm-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      uvModal.querySelectorAll('.uvm-tab').forEach(b => b.classList.remove('uvm-tab-active'));
      uvModal.querySelectorAll('.uvm-panel').forEach(p => p.classList.add('hidden'));
      btn.classList.add('uvm-tab-active');
      const panel = document.getElementById('uvm-tab-' + btn.dataset.tab);
      if (panel) panel.classList.remove('hidden');
    });
  });

  function setHTML(id, html) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = html;
  }

  function buildTable(headers, rows, empty = 'Nta makuru abonetse') {
    if (!rows.length) {
      return `<p class="py-8 text-center text-sm text-slate-400">${empty}</p>`;
    }
    return `
      <div class="uvm-tbl-wrap">
        <table>
          <thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead>
          <tbody>${rows.join('')}</tbody>
        </table>
      </div>`;
  }

  function fillProfile(d) {
    const initials = (d.names || '?')
      .split(' ')
      .map(w => w[0])
      .slice(0, 2)
      .join('')
      .toUpperCase();

    const avatarText = document.getElementById('uvm-avatar');
    const avatarImg  = document.getElementById('uvm-avatar-img');
    const nidImg     = document.getElementById('uvm-nid-img');
    const nidWrap    = document.getElementById('uvm-nid-wrap');
    const profileFullImg = document.getElementById('uvm-profile-full-img');
    const profilePlaceholder = document.getElementById('uvm-profile-placeholder');

    if (avatarText) avatarText.textContent = initials;

    const nameEl = document.getElementById('uvm-name');
    const emailEl = document.getElementById('uvm-email');
    if (nameEl) nameEl.textContent = d.names || '—';
    if (emailEl) emailEl.textContent = d.email || '—';

    const memberBadge = document.getElementById('uvm-badge-member');
    const adminBadge = document.getElementById('uvm-badge-admin');
    if (memberBadge) memberBadge.classList.toggle('hidden', !d.is_member);
    if (adminBadge) adminBadge.classList.toggle('hidden', !d.is_admin);

    setHTML('p-names',  _orDash(d.names));
    setHTML('p-nid',    _orDash(d.nid_passport));
    setHTML('p-email',  _orDash(d.email));
    setHTML('p-ph1',    _orDash(d.phone1));
    setHTML('p-ph2',    _orDash(d.phone2));
    setHTML(
      'p-roles',
      (d.is_member ? '<span class="spill spill-green">Member</span> ' : '') +
      (d.is_admin  ? '<span class="spill spill-blue">Admin</span>' : '')
    );
    setHTML('p-gname',  _orDash(d.guarantee_name));
    setHTML('p-gnid',   _orDash(d.guarantee_nid_passport));
    setHTML('p-gemail', _orDash(d.guarantee_email));
    setHTML('p-gph1',   _orDash(d.guarantee_phone1));
    setHTML('p-gph2',   _orDash(d.guarantee_phone2));

    if (d.has_profile_image && d.id) {
      const profileUrl = userProfileImageUrl(d.id);
      setImagePreview(avatarImg, avatarText, profileUrl, true);

      if (profileFullImg) {
        profileFullImg.src = profileUrl;
        profileFullImg.classList.remove('hidden');
      }
      if (profilePlaceholder) {
        profilePlaceholder.classList.add('hidden');
      }
    } else {
      setImagePreview(avatarImg, avatarText, '', false);

      if (profileFullImg) {
        profileFullImg.src = '';
        profileFullImg.classList.add('hidden');
      }
      if (profilePlaceholder) {
        profilePlaceholder.classList.remove('hidden');
      }
    }

    if (nidWrap && nidImg) {
      if (d.has_nid_image && d.id) {
        nidWrap.classList.remove('hidden');
        nidImg.src = userNidImageUrl(d.id);
      } else {
        nidWrap.classList.add('hidden');
        nidImg.src = '';
      }
    }
  }

  function fillTransactions(items) {
    setHTML('uvm-tx-count', `${items.length} record${items.length !== 1 ? 's' : ''}`);

    const rows = items.map(t => `
      <tr>
        <td>${_orDash(t.tx_date)}</td>
        <td><span class="spill spill-slate">${globalEscapeHtml(t.type)}</span></td>
        <td><span class="${t.direction === 'IN' ? 'spill spill-green' : 'spill spill-red'}">${globalEscapeHtml(t.direction)}</span></td>
        <td class="text-right font-semibold ${t.direction === 'IN' ? 'text-emerald-700' : 'text-rose-600'}">
          ${t.direction === 'IN' ? '+' : '−'}${_money(t.amount)}</td>
        <td class="text-right">${_money(t.running_balance || 0)}</td>
        <td>${_orDash(t.account_name)}</td>
        <td>${t.loan_id ? `<span class="font-mono text-xs">#LN-${globalEscapeHtml(t.loan_id)}</span>` : _orDash(null)}</td>
        <td class="max-w-[12rem] truncate">${_orDash(t.description)}</td>
      </tr>`);

    setHTML('uvm-tx-body', buildTable(
      ['Date','Type','Dir','Amount','Balance','Account','Loan','Description'], rows
    ));
  }

  function fillContributions(items) {
    const relevant = items.filter(t =>
      (t.type === 'contribution' && t.direction === 'IN') ||
      (t.type === 'expense' && t.direction === 'OUT')
    );

    const totalContrib = relevant
      .filter(t => t.type === 'contribution')
      .reduce((s, t) => s + Number(t.amount || 0), 0);

    const totalExpense = relevant
      .filter(t => t.type === 'expense')
      .reduce((s, t) => s + Number(t.amount || 0), 0);

    const net = totalContrib - totalExpense;

    setHTML('uvm-contrib-total', 'Net: ' + _money(net));

    const rows = relevant.map(t => `
      <tr>
        <td>${_orDash(t.tx_date)}</td>
        <td><span class="spill spill-slate">${globalEscapeHtml(t.type)}</span></td>
        <td><span class="${t.direction === 'IN' ? 'spill spill-green' : 'spill spill-red'}">${globalEscapeHtml(t.direction)}</span></td>
        <td class="text-right font-semibold ${t.direction === 'IN' ? 'text-emerald-700' : 'text-rose-600'}">
          ${t.direction === 'IN' ? '+' : '−'}${_money(t.amount)}</td>
        <td class="text-right">${_money(t.running_balance || 0)}</td>
        <td class="max-w-[12rem] truncate">${_orDash(t.description)}</td>
      </tr>`);

    setHTML('uvm-contrib-body', buildTable(
      ['Date','Type','Direction','Amount','Balance','Description'],
      rows,
      'Nta contribution / expense history ibonetse'
    ));
  }

  function fillLoans(loans) {
    setHTML('uvm-loans-count', `${loans.length} loan${loans.length !== 1 ? 's' : ''}`);

    const rows = loans.map(l => `
      <tr>
        <td class="font-mono text-xs">#LN-${globalEscapeHtml(l.loan_id)}</td>
        <td class="text-right font-semibold">${_money(l.principal)}</td>
        <td class="text-right font-semibold text-rose-600">${_money(l.unpaid_principal)}</td>
        <td>${_pill(l.status)}</td>
        <td>${l.interest_rate != null ? globalEscapeHtml(l.interest_rate) + '%' : _orDash(null)}</td>
        <td>${_orDash(l.start_date)}</td>
        <td>${_orDash(l.end_date)}</td>
      </tr>`);

    let paymentSections = '';
    loans.forEach(l => {
      const payments = l.payments || [];
      paymentSections += `
        <div class="mt-4 rounded-xl border border-slate-200 p-4">
          <p class="mb-2 text-sm font-semibold text-slate-700">Loan #LN-${globalEscapeHtml(l.loan_id)} Payment History</p>
          ${buildTable(
            ['Date','Type','Direction','Amount','Description'],
            payments.map(p => `
              <tr>
                <td>${_orDash(p.tx_date)}</td>
                <td>${_orDash(p.type)}</td>
                <td>${_orDash(p.direction)}</td>
                <td class="text-right">${_money(p.amount)}</td>
                <td>${_orDash(p.description)}</td>
              </tr>
            `),
            'Nta payment history ibonetse'
          )}
        </div>
      `;
    });

    setHTML('uvm-loans-body',
      buildTable(['Loan #','Principal','Unpaid','Status','Rate','Start','End'], rows, 'Nta loans abonetse') +
      paymentSections
    );
  }

  function fillGuaranteed(items) {
    const total = items.reduce((s, g) => s + Number(g.guarantee_amount || 0), 0);
    setHTML('uvm-guar-total', 'Total: ' + _money(total));

    const rows = items.map(g => `
      <tr>
        <td class="font-mono text-xs">#LN-${globalEscapeHtml(g.loan_id)}</td>
        <td>${_orDash(g.borrower_name)}</td>
        <td class="text-right font-semibold">${_money(g.guarantee_amount)}</td>
        <td class="text-right">${_money(g.loan_principal)}</td>
        <td>${_pill(g.status)}</td>
        <td>${_pill(g.loan_status)}</td>
        <td>${_orDash(g.since)}</td>
      </tr>`);

    setHTML('uvm-guar-body', buildTable(
      ['Loan #','Borrower','My Guarantee','Loan Principal','My Status','Loan Status','Since'],
      rows,
      'Ntacyo yishingiye'
    ));
  }

  function fillAssets(items) {
    const rows = items.map(a => `
      <tr>
        <td class="font-mono text-xs">#AS-${globalEscapeHtml(a.asset_id)}</td>
        <td>${_orDash(a.name)}</td>
        <td>${_orDash(a.purchase_date)}</td>
        <td class="text-right font-semibold">${_money(a.purchase_value)}</td>
        <td>${_orDash(a.location)}</td>
        <td>${(a.sold_value !== null && a.sold_value !== '')
              ? _money(a.sold_value)
              : '<span class="spill spill-green">Active</span>'}</td>
      </tr>`);

    setHTML('uvm-assets-body', buildTable(
      ['Asset #','Name','Purchase Date','Value','Location','Sold'],
      rows,
      'Nta assets abonetse'
    ));
  }

  function fillSummary(transactions, loans, guaranteed, summaryData = {}) {
    const contribs = transactions.filter(t => t.type === 'contribution' && t.direction === 'IN');
    const withdrawals = transactions.filter(t =>
      ['withdrawal', 'withdrawal_deduction'].includes(t.type) && t.direction === 'OUT'
    );

    const totalContrib = Number(summaryData.total_contribution || contribs.reduce((s, t) => s + Number(t.amount || 0), 0));
    const totalWithdraw = Number(summaryData.total_withdraw || withdrawals.reduce((s, t) => s + Number(t.amount || 0), 0));
    const totalExpenseShare = Number(summaryData.expense_portion || 0);
    const totalInterestGained = Number(summaryData.interest_gained || 0);

    const totalPrincipalPaid = transactions
      .filter(t => t.type === 'loan_principal' && t.direction === 'IN')
      .reduce((s, t) => s + Number(t.amount || 0), 0);

    const totalInterestPaid = transactions
      .filter(t => t.type === 'loan_interest' && t.direction === 'IN')
      .reduce((s, t) => s + Number(t.amount || 0), 0);

    const totalPrincipal = loans.reduce((s, l) => s + Number(l.principal || 0), 0);
    const totalUnpaid = loans.reduce((s, l) => s + Number(l.unpaid_principal || 0), 0);
    const totalGuaranteed = guaranteed.reduce((s, g) => s + Number(g.guarantee_amount || 0), 0);
    const activeLoans = loans.filter(l => ['approved', 'defaulted'].includes(l.status));

    const netVal = (totalContrib + totalInterestGained) - (totalExpenseShare + totalWithdraw);

    setHTML('sum-contrib', _money(totalContrib));
    setHTML('sum-withdraw', _money(totalWithdraw));
    setHTML('sum-expense-share', _money(totalExpenseShare));
    setHTML('sum-interest', _money(totalInterestPaid));
    setHTML('sum-interest-gained', _money(totalInterestGained));
    setHTML('sum-principal-paid', _money(totalPrincipalPaid));
    setHTML('sum-active-loans', activeLoans.length + ' loan' + (activeLoans.length !== 1 ? 's' : ''));
    setHTML('sum-loan-principal', _money(totalPrincipal));
    setHTML('sum-unpaid', _money(totalUnpaid));
    setHTML('sum-guaranteed', _money(totalGuaranteed));
    setHTML('sum-net', _money(netVal));

    const recent = [...transactions]
      .slice()
      .sort((a, b) => {
        const cmp = String(b.tx_date || '').localeCompare(String(a.tx_date || ''));
        if (cmp !== 0) return cmp;
        return Number(b.transaction_id || 0) - Number(a.transaction_id || 0);
      })
      .slice(0, 6);

    setHTML('sum-recent', recent.length
      ? recent.map(t => `
          <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
            <div class="flex flex-wrap items-center gap-2">
              <span class="spill spill-slate">${globalEscapeHtml(t.type)}</span>
              <span class="text-[11px] text-slate-400">${globalEscapeHtml(t.tx_date || '')}</span>
              ${t.description
                ? `<span class="hidden text-[11px] text-slate-400 sm:inline truncate max-w-[10rem]">${globalEscapeHtml(t.description)}</span>`
                : ''}
            </div>
            <span class="text-xs font-bold ${t.direction === 'IN' ? 'text-emerald-700' : 'text-rose-600'}">
              ${t.direction === 'IN' ? '+' : '−'}${_money(t.amount)}
            </span>
          </div>`).join('')
      : '<p class="text-xs text-slate-400">Nta bikorwa biheruka</p>'
    );
  }

  function downloadUserPdfReport() {
    if (!currentUserFullData) {
      alert('No user data loaded yet.');
      return;
    }

    const d = currentUserFullData.user || {};
    const s = currentUserFullData.summary || {};
    const tx = currentUserFullData.transactions || [];
    const loans = currentUserFullData.loans || [];
    const guaranteed = currentUserFullData.guaranteed || [];
    const assets = currentUserFullData.assets || [];
    const interestHistory = currentUserFullData.interest_history || [];
    const expenseHistory = currentUserFullData.expense_history || [];

    const contribTx = tx.filter(t => t.type === 'contribution' && t.direction === 'IN');
    const expenseTx = tx.filter(t => t.type === 'expense' && t.direction === 'OUT');
    const withdrawTx = tx.filter(t =>
      ['withdrawal', 'withdrawal_deduction'].includes(t.type) && t.direction === 'OUT'
    );

    const netValue = (Number(s.total_contribution || 0) + Number(s.interest_gained || 0)) -
                     (Number(s.expense_portion || 0) + Number(s.total_withdraw || 0));

    const printable = `
      <html>
      <head>
        <title>User Report - ${globalEscapeHtml(d.names || 'User')}</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 24px; color: #222; font-size: 12px; }
          h1, h2, h3 { margin: 0 0 10px; }
          h1 { font-size: 22px; margin-bottom: 6px; }
          h2 { font-size: 16px; margin-top: 24px; border-bottom: 1px solid #ddd; padding-bottom: 6px; }
          h3 { font-size: 13px; margin-top: 16px; }
          p { margin: 4px 0; }
          .muted { color: #666; }
          .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
          .box { border: 1px solid #ddd; padding: 10px; border-radius: 6px; }
          table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 11px; }
          th, td { border: 1px solid #ddd; padding: 6px; text-align: left; vertical-align: top; }
          th { background: #f5f5f5; }
          .right { text-align: right; }
          .section-space { margin-top: 18px; }
        </style>
      </head>
      <body>
        <h1>User Detailed Financial Report</h1>
        <p class="muted">Generated on ${new Date().toLocaleString()}</p>

        <h2>Profile Information</h2>
        <div class="grid">
          <div class="box">
            <p><strong>Name:</strong> ${globalEscapeHtml(d.names || '')}</p>
            <p><strong>Email:</strong> ${globalEscapeHtml(d.email || '')}</p>
            <p><strong>NID / Passport:</strong> ${globalEscapeHtml(d.nid_passport || '')}</p>
            <p><strong>Phone 1:</strong> ${globalEscapeHtml(d.phone1 || '')}</p>
            <p><strong>Phone 2:</strong> ${globalEscapeHtml(d.phone2 || '')}</p>
          </div>
          <div class="box">
            <p><strong>Member:</strong> ${d.is_member ? 'Yes' : 'No'}</p>
            <p><strong>Admin:</strong> ${d.is_admin ? 'Yes' : 'No'}</p>
            <p><strong>Total Contribution:</strong> ${_money(s.total_contribution || 0)}</p>
            <p><strong>Total Withdraw:</strong> ${_money(s.total_withdraw || 0)}</p>
            <p><strong>Interest Gained:</strong> ${_money(s.interest_gained || 0)}</p>
            <p><strong>Expense Portion:</strong> ${_money(s.expense_portion || 0)}</p>
            <p><strong>Net Value:</strong> ${_money(netValue)}</p>
          </div>
        </div>

        <h2>Guarantor Information</h2>
        <p><strong>Name:</strong> ${globalEscapeHtml(d.guarantee_name || '')}</p>
        <p><strong>NID:</strong> ${globalEscapeHtml(d.guarantee_nid_passport || '')}</p>
        <p><strong>Email:</strong> ${globalEscapeHtml(d.guarantee_email || '')}</p>
        <p><strong>Phone 1:</strong> ${globalEscapeHtml(d.guarantee_phone1 || '')}</p>
        <p><strong>Phone 2:</strong> ${globalEscapeHtml(d.guarantee_phone2 || '')}</p>

        <h2>Summary</h2>
        <div class="grid">
          <div class="box"><strong>Total Contributions</strong><br>${_money(s.total_contribution || 0)}</div>
          <div class="box"><strong>Total Withdrawals</strong><br>${_money(s.total_withdraw || 0)}</div>
          <div class="box"><strong>Interest Gained</strong><br>${_money(s.interest_gained || 0)}</div>
          <div class="box"><strong>Expense Portion</strong><br>${_money(s.expense_portion || 0)}</div>
          <div class="box"><strong>Net Value</strong><br>${_money(netValue)}</div>
        </div>

        <h2>Client Statement / Running Balance History</h2>
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Type</th>
              <th>Direction</th>
              <th>Description</th>
              <th class="right">Amount</th>
              <th class="right">Running Balance</th>
            </tr>
          </thead>
          <tbody>
            ${tx.length ? tx.map(t => `
              <tr>
                <td>${globalEscapeHtml(t.tx_date || '')}</td>
                <td>${globalEscapeHtml(t.type || '')}</td>
                <td>${globalEscapeHtml(t.direction || '')}</td>
                <td>${globalEscapeHtml(t.description || '')}</td>
                <td class="right">${_money(t.amount || 0)}</td>
                <td class="right">${_money(t.running_balance || 0)}</td>
              </tr>
            `).join('') : '<tr><td colspan="6">No transactions found</td></tr>'}
          </tbody>
        </table>

        <h2>Contribution History</h2>
        <table>
          <thead><tr><th>Date</th><th>Description</th><th class="right">Amount</th></tr></thead>
          <tbody>
            ${contribTx.length ? contribTx.map(t => `
              <tr>
                <td>${globalEscapeHtml(t.tx_date || '')}</td>
                <td>${globalEscapeHtml(t.description || '')}</td>
                <td class="right">${_money(t.amount || 0)}</td>
              </tr>
            `).join('') : '<tr><td colspan="3">No contributions</td></tr>'}
          </tbody>
        </table>
        
        <h2>Withdrawal History</h2>
        <table>
          <thead><tr><th>Date</th><th>Description</th><th class="right">Amount</th></tr></thead>
          <tbody>
            ${withdrawTx.length ? withdrawTx.map(t => `
              <tr>
                <td>${globalEscapeHtml(t.tx_date || '')}</td>
                <td>${globalEscapeHtml(t.description || '')}</td>
                <td class="right">${_money(t.amount || 0)}</td>
              </tr>
            `).join('') : '<tr><td colspan="3">No withdrawals</td></tr>'}
          </tbody>
        </table>

        <h2>Interest Gained History</h2>
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Loan</th>
              <th>Borrower</th>
              <th class="right">Interest Received</th>
              <th class="right">My Base</th>
              <th class="right">Total NIG Base</th>
              <th class="right">My Share</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
            ${interestHistory.length ? interestHistory.map(h => `
              <tr>
                <td>${globalEscapeHtml(h.tx_date || '')}</td>
                <td>${h.loan_id ? '#LN-' + globalEscapeHtml(h.loan_id) : ''}</td>
                <td>${globalEscapeHtml(h.borrower_name || '')}</td>
                <td class="right">${_money(h.source_amount || 0)}</td>
                <td class="right">${_money(h.member_base || 0)}</td>
                <td class="right">${_money(h.total_base || 0)}</td>
                <td class="right">${_money(h.member_share || 0)}</td>
                <td>${globalEscapeHtml(h.description || '')}</td>
              </tr>
            `).join('') : '<tr><td colspan="8">No interest gained history</td></tr>'}
          </tbody>
        </table>

        <h2>Expense Portion History</h2>
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th class="right">Expense Amount</th>
              <th class="right">My Base</th>
              <th class="right">Total NIG Base</th>
              <th class="right">My Portion</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
            ${expenseHistory.length ? expenseHistory.map(h => `
              <tr>
                <td>${globalEscapeHtml(h.tx_date || '')}</td>
                <td class="right">${_money(h.source_amount || 0)}</td>
                <td class="right">${_money(h.member_base || 0)}</td>
                <td class="right">${_money(h.total_base || 0)}</td>
                <td class="right">${_money(h.member_share || 0)}</td>
                <td>${globalEscapeHtml(h.description || '')}</td>
              </tr>
            `).join('') : '<tr><td colspan="6">No expense portion history</td></tr>'}
          </tbody>
        </table>

        <h2>Loans and Payment History</h2>
        ${loans.length ? loans.map(l => `
          <div class="section-space">
            <h3>Loan #LN-${globalEscapeHtml(l.loan_id)}</h3>
            <p><strong>Principal:</strong> ${_money(l.principal || 0)}</p>
            <p><strong>Interest Rate:</strong> ${globalEscapeHtml(l.interest_rate || '')}%</p>
            <p><strong>Status:</strong> ${globalEscapeHtml(l.status || '')}</p>
            <p><strong>Start:</strong> ${globalEscapeHtml(l.start_date || '')}</p>
            <p><strong>End:</strong> ${globalEscapeHtml(l.end_date || '')}</p>
            <p><strong>Unpaid Principal:</strong> ${_money(l.unpaid_principal || 0)}</p>

            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Type</th>
                  <th>Direction</th>
                  <th>Description</th>
                  <th class="right">Amount</th>
                </tr>
              </thead>
              <tbody>
                ${(l.payments && l.payments.length) ? l.payments.map(p => `
                  <tr>
                    <td>${globalEscapeHtml(p.tx_date || '')}</td>
                    <td>${globalEscapeHtml(p.type || '')}</td>
                    <td>${globalEscapeHtml(p.direction || '')}</td>
                    <td>${globalEscapeHtml(p.description || '')}</td>
                    <td class="right">${_money(p.amount || 0)}</td>
                  </tr>
                `).join('') : '<tr><td colspan="5">No payment history</td></tr>'}
              </tbody>
            </table>
          </div>
        `).join('') : '<p>No loans found.</p>'}

        <h2>Guaranteed Loans</h2>
        <table>
          <thead>
            <tr>
              <th>Loan #</th>
              <th>Borrower</th>
              <th class="right">Guarantee Amount</th>
              <th>My Guarantee Status</th>
              <th>Loan Status</th>
              <th>Since</th>
            </tr>
          </thead>
          <tbody>
            ${guaranteed.length ? guaranteed.map(g => `
              <tr>
                <td>#LN-${globalEscapeHtml(g.loan_id)}</td>
                <td>${globalEscapeHtml(g.borrower_name || '')}</td>
                <td class="right">${_money(g.guarantee_amount || 0)}</td>
                <td>${globalEscapeHtml(g.status || '')}</td>
                <td>${globalEscapeHtml(g.loan_status || '')}</td>
                <td>${globalEscapeHtml(g.since || '')}</td>
              </tr>
            `).join('') : '<tr><td colspan="6">No active guaranteed loans</td></tr>'}
          </tbody>
        </table>

        <h2>Assets</h2>
        <table>
          <thead>
            <tr>
              <th>Asset #</th>
              <th>Name</th>
              <th>Purchase Date</th>
              <th class="right">Value</th>
              <th>Location</th>
            </tr>
          </thead>
          <tbody>
            ${assets.length ? assets.map(a => `
              <tr>
                <td>#AS-${globalEscapeHtml(a.asset_id)}</td>
                <td>${globalEscapeHtml(a.name || '')}</td>
                <td>${globalEscapeHtml(a.purchase_date || '')}</td>
                <td class="right">${_money(a.purchase_value || 0)}</td>
                <td>${globalEscapeHtml(a.location || '')}</td>
              </tr>
            `).join('') : '<tr><td colspan="5">No assets</td></tr>'}
          </tbody>
        </table>
      </body>
      </html>
    `;

    const win = window.open('', '_blank');
    if (!win) {
      alert('Popup blocked. Please allow popups for PDF download.');
      return;
    }

    win.document.open();
    win.document.write(printable);
    win.document.close();

    win.onload = function () {
      win.focus();
      win.print();
    };
  }

  document.addEventListener('click', function (e) {
    const target = e.target.closest('#uvm-download-pdf');
    if (target) {
      downloadUserPdfReport();
    }
  });

  async function viewUserDetails(id) {
    uvModal.querySelectorAll('.uvm-tab').forEach(b => b.classList.remove('uvm-tab-active'));
    uvModal.querySelectorAll('.uvm-panel').forEach(p => p.classList.add('hidden'));
    const firstTab = uvModal.querySelector('.uvm-tab[data-tab="profile"]');
    if (firstTab) firstTab.classList.add('uvm-tab-active');

    const avatarText = document.getElementById('uvm-avatar');
    const nameEl = document.getElementById('uvm-name');
    const emailEl = document.getElementById('uvm-email');
    if (avatarText) avatarText.textContent = '…';
    if (nameEl) nameEl.textContent = 'Loading…';
    if (emailEl) emailEl.textContent = '';

    const memberBadge = document.getElementById('uvm-badge-member');
    const adminBadge = document.getElementById('uvm-badge-admin');
    if (memberBadge) memberBadge.classList.add('hidden');
    if (adminBadge) adminBadge.classList.add('hidden');

    const avatarImg = document.getElementById('uvm-avatar-img');
    const nidImg    = document.getElementById('uvm-nid-img');
    const nidWrap   = document.getElementById('uvm-nid-wrap');
    const profileFullImg = document.getElementById('uvm-profile-full-img');
    const profilePlaceholder = document.getElementById('uvm-profile-placeholder');

    if (avatarImg) {
      avatarImg.src = '';
      avatarImg.classList.add('hidden');
    }
    if (nidImg) nidImg.src = '';
    if (nidWrap) nidWrap.classList.add('hidden');

    if (profileFullImg) {
      profileFullImg.src = '';
      profileFullImg.classList.add('hidden');
    }
    if (profilePlaceholder) {
      profilePlaceholder.classList.remove('hidden');
    }

    const loadingEl = document.getElementById('uvm-loading');
    const errorEl = document.getElementById('uvm-error');
    if (loadingEl) loadingEl.classList.remove('hidden');
    if (errorEl) errorEl.classList.add('hidden');

    openUVM();

    try {
      const res = await fetch(`${apiUrl}?id=${encodeURIComponent(id)}&full=1`, {
        cache: 'no-store',
        credentials: 'include',
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);

      const json = await res.json();
      console.log('USER FULL JSON:', json);

      if (loadingEl) loadingEl.classList.add('hidden');

      if (!json.success || !json.data) {
        if (errorEl) {
          errorEl.textContent = json.message || 'Failed to load user data.';
          errorEl.classList.remove('hidden');
        }
        return;
      }

      const transactions = json.transactions || [];
      const loans        = json.loans || [];
      const guaranteed   = json.guaranteed || [];
      const assets       = json.assets || [];

      currentUserFullData = {
        user: json.data,
        transactions,
        loans,
        guaranteed,
        assets,
        summary: json.summary || {},
        interest_history: json.interest_history || [],
        expense_history: json.expense_history || []
      };

      const profilePanel = document.getElementById('uvm-tab-profile');
      if (profilePanel) profilePanel.classList.remove('hidden');

      fillProfile(json.data);
      fillTransactions(transactions);
      fillContributions(transactions);
      fillLoans(loans);
      fillGuaranteed(guaranteed);
      fillAssets(assets);
      fillSummary(transactions, loans, guaranteed, json.summary || {});
    } catch (err) {
      console.error('[UVM]', err);
      if (loadingEl) loadingEl.classList.add('hidden');
      if (errorEl) {
        errorEl.textContent = 'Network error: ' + err.message;
        errorEl.classList.remove('hidden');
      }
    }
  }

  window.viewUserDetails = viewUserDetails;

  document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-view');
    if (btn) {
      viewUserDetails(btn.dataset.id);
    }
  });
})();

/* ─────────────────────────────────────────────────────────────
   USERS CRUD TABLE
───────────────────────────────────────────────────────────── */
(function () {
  const form       = document.getElementById('user-form');
  const tbody      = document.getElementById('users-tbody');
  const btnNew     = document.getElementById('btn-new-user');
  const btnRefresh = document.getElementById('btn-refresh-users');
  const saveBtn    = document.getElementById('user-save');
  const modal      = document.getElementById('user-modal');
  const modalTitle = document.getElementById('modal-title');
  const modalClose = document.getElementById('modal-close');

  if (!form || !tbody || !modal) return;

  let currentPage  = 1;
  let perPage      = 10;
  let currentQuery = '';
  let lastTotal    = 0;

  const esc = globalEscapeHtml;
  function openModal()  { modal.classList.remove('hidden'); modal.classList.add('flex'); }
  function closeModal() { modal.classList.add('hidden'); modal.classList.remove('flex'); }

  async function fetchUsers(page = currentPage, q = currentQuery) {
    try {
      const url  = `${apiUrl}?page=${page}&per_page=${perPage}` + (q ? `&q=${encodeURIComponent(q)}` : '');
      const res  = await fetch(url, { cache: 'no-store', credentials: 'include' });

      if (!res.ok) {
        if (res.status === 403) {
          alert('Unauthorized. Please log in as admin.');
          return;
        }
        alert('Error loading users: ' + res.status);
        return;
      }

      const json = await res.json();
      if (json.success) {
        currentPage = json.page || page;
        perPage     = json.per_page || perPage;
        lastTotal   = json.total || 0;
        renderTable(json.data || []);
        updatePagination();
      } else {
        alert(json.message || 'Error loading users');
      }
    } catch (e) {
      console.error(e);
      alert('Network error');
    }
  }

  function renderTable(users) {
    tbody.innerHTML = '';
    users.forEach((u, idx) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${(currentPage - 1) * perPage + idx + 1}</td>
        <td class="font-medium">${esc(u.names)}</td>
        <td>${esc(u.email || '')}</td>
        <td>${esc(u.phone1 || '')}</td>
        <td>${u.is_member ? '<span class="spill spill-green">Yes</span>' : '<span class="spill spill-slate">No</span>'}</td>
        <td>${u.is_admin  ? '<span class="spill spill-blue">Yes</span>'  : '<span class="spill spill-slate">No</span>'}</td>
        <td>${u.has_profile_image ? '<span class="spill spill-green">Profile</span>' : '<span class="spill spill-slate">No Profile</span>'}</td>
        <td>${u.has_nid_image ? '<span class="spill spill-amber">NID</span>' : '<span class="spill spill-slate">No NID</span>'}</td>
        <td class="flex flex-wrap gap-1">
          <button class="btn-ghost btn-view" data-id="${u.id}">👁 Reba</button>
          <button class="btn-ghost btn-edit" data-id="${u.id}">✏ Hindura</button>
          <button class="btn-ghost-danger btn-delete" data-id="${u.id}">🗑 Siba</button>
        </td>`;
      tbody.appendChild(tr);
    });

    tbody.querySelectorAll('.btn-edit').forEach(b => b.addEventListener('click', onEdit));
    tbody.querySelectorAll('.btn-delete').forEach(b => b.addEventListener('click', onDelete));
  }

  function updatePagination() {
    const totalPages = Math.max(1, Math.ceil(lastTotal / perPage));
    const pageEl = document.getElementById('users-page');
    const prevEl = document.getElementById('users-prev');
    const nextEl = document.getElementById('users-next');

    if (pageEl) pageEl.textContent = `${currentPage} / ${totalPages}`;
    if (prevEl) prevEl.disabled = currentPage <= 1;
    if (nextEl) nextEl.disabled = currentPage >= totalPages;
  }

  function clearForm() {
    form.reset();

    document.getElementById('user-id').value = '';
    if (saveBtn) saveBtn.textContent = 'Bika';
    if (modalTitle) modalTitle.textContent = 'Umunyamukoresha Mushya';

    const hasPhone2 = document.getElementById('has-phone2');
    const phone2Section = document.getElementById('phone2-section');
    if (hasPhone2) hasPhone2.checked = false;
    if (phone2Section) phone2Section.classList.add('hidden');

    const hasGuarantor = document.getElementById('has-guarantor');
    const guarantorSection = document.getElementById('guarantor-section');
    if (hasGuarantor) hasGuarantor.checked = false;
    if (guarantorSection) guarantorSection.classList.add('hidden');

    const hasGuaranteePhone2 = document.getElementById('has-guarantee-phone2');
    const guaranteePhone2Section = document.getElementById('guarantee-phone2-section');
    if (hasGuaranteePhone2) hasGuaranteePhone2.checked = false;
    if (guaranteePhone2Section) guaranteePhone2Section.classList.add('hidden');

    clearFileInput('user-profile-image');
    clearFileInput('user-nid-image');

    const profilePreview = document.getElementById('user-profile-preview');
    const nidPreview     = document.getElementById('user-nid-preview');
    const profileLink    = document.getElementById('user-current-profile-link');
    const nidLink        = document.getElementById('user-current-nid-link');

    if (profilePreview) {
      profilePreview.src = '';
      profilePreview.classList.add('hidden');
    }
    if (nidPreview) {
      nidPreview.src = '';
      nidPreview.classList.add('hidden');
    }
    if (profileLink) {
      profileLink.href = '#';
      profileLink.classList.add('hidden');
    }
    if (nidLink) {
      nidLink.href = '#';
      nidLink.classList.add('hidden');
    }
  }

  function fillForm(u) {
    const setVal = (id, v) => {
      const el = document.getElementById(id);
      if (el) el.value = v || '';
    };
    const setChk = (id, v) => {
      const el = document.getElementById(id);
      if (el) el.checked = !!v;
    };

    setVal('user-id', u.id);
    setVal('user-names', u.names);
    setVal('user-nid', u.nid_passport);
    setVal('user-email', u.email);
    setVal('user-phone1', u.phone1);
    setVal('user-password', '');
    setChk('user-is-member', u.is_member);
    setChk('user-is-admin', u.is_admin);

    const hasPhone2 = !!(u.phone2 && u.phone2.trim());
    setChk('has-phone2', hasPhone2);
    document.getElementById('phone2-section')?.classList.toggle('hidden', !hasPhone2);
    setVal('user-phone2', u.phone2);

    const hasGuarantor = !!(u.guarantee_name && u.guarantee_name.trim());
    setChk('has-guarantor', hasGuarantor);
    document.getElementById('guarantor-section')?.classList.toggle('hidden', !hasGuarantor);
    setVal('user-guarantee-name', u.guarantee_name);
    setVal('user-guarantee-nid', u.guarantee_nid_passport);
    setVal('user-guarantee-email', u.guarantee_email);
    setVal('user-guarantee-phone1', u.guarantee_phone1);

    const hasGPh2 = !!(u.guarantee_phone2 && u.guarantee_phone2.trim());
    setChk('has-guarantee-phone2', hasGPh2);
    document.getElementById('guarantee-phone2-section')?.classList.toggle('hidden', !hasGPh2);
    setVal('user-guarantee-phone2', u.guarantee_phone2);

    clearFileInput('user-profile-image');
    clearFileInput('user-nid-image');

    const profilePreview = document.getElementById('user-profile-preview');
    const nidPreview     = document.getElementById('user-nid-preview');
    const profileLink    = document.getElementById('user-current-profile-link');
    const nidLink        = document.getElementById('user-current-nid-link');

    if (u.has_profile_image) {
      const pUrl = userProfileImageUrl(u.id);
      if (profilePreview) {
        profilePreview.src = pUrl;
        profilePreview.classList.remove('hidden');
      }
      if (profileLink) {
        profileLink.href = pUrl;
        profileLink.classList.remove('hidden');
      }
    } else {
      if (profilePreview) {
        profilePreview.src = '';
        profilePreview.classList.add('hidden');
      }
      if (profileLink) {
        profileLink.href = '#';
        profileLink.classList.add('hidden');
      }
    }

    if (u.has_nid_image) {
      const nUrl = userNidImageUrl(u.id);
      if (nidPreview) {
        nidPreview.src = nUrl;
        nidPreview.classList.remove('hidden');
      }
      if (nidLink) {
        nidLink.href = nUrl;
        nidLink.classList.remove('hidden');
      }
    } else {
      if (nidPreview) {
        nidPreview.src = '';
        nidPreview.classList.add('hidden');
      }
      if (nidLink) {
        nidLink.href = '#';
        nidLink.classList.add('hidden');
      }
    }

    if (saveBtn) saveBtn.textContent = 'Hindura';
    if (modalTitle) modalTitle.textContent = 'Guhindura Umukoresha';
    openModal();
  }

  async function onEdit(e) {
    const id = e.currentTarget.dataset.id;
    try {
      const res = await fetch(`${apiUrl}?id=${encodeURIComponent(id)}`, {
        cache: 'no-store',
        credentials: 'include'
      });
      const json = await res.json();
      if (json.success && json.data) fillForm(json.data);
      else alert(json.message || 'Failed to load user');
    } catch (err) {
      console.error(err);
      alert('Network error');
    }
  }

  async function onDelete(e) {
    const id = e.currentTarget.dataset.id;
    if (!confirm('Urashaka koko gusiba uyu mukoresha?')) return;

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);

    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        body: fd,
        credentials: 'include'
      });
      const json = await res.json();
      if (json.success) fetchUsers(1, currentQuery);
      else alert(json.message || 'Error deleting');
    } catch (err) {
      console.error(err);
      alert('Network error');
    }
  }

  form.addEventListener('submit', async ev => {
    ev.preventDefault();

    const id = document.getElementById('user-id')?.value || '';
    const fd = new FormData(form);
    fd.append('action', id ? 'update' : 'create');

    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        body: fd,
        credentials: 'include'
      });
      const json = await res.json();

      if (json.success) {
        fetchUsers(1, currentQuery);
        clearForm();
        closeModal();
      } else {
        alert(json.message || 'Error saving user');
      }
    } catch (e) {
      console.error(e);
      alert('Network error');
    }
  });

  if (btnNew) {
    btnNew.addEventListener('click', () => {
      clearForm();
      openModal();
      document.getElementById('user-names')?.focus();
    });
  }

  if (btnRefresh) btnRefresh.addEventListener('click', () => fetchUsers(1, currentQuery));

  const cancelBtn = document.getElementById('user-cancel');
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  if (modalClose) modalClose.addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

  if (saveBtn) {
    saveBtn.addEventListener('click', () => {
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    });
  }

  document.getElementById('has-phone2')?.addEventListener('change', e => {
    document.getElementById('phone2-section')?.classList.toggle('hidden', !e.target.checked);
  });

  document.getElementById('has-guarantor')?.addEventListener('change', e => {
    document.getElementById('guarantor-section')?.classList.toggle('hidden', !e.target.checked);
  });

  document.getElementById('has-guarantee-phone2')?.addEventListener('change', e => {
    document.getElementById('guarantee-phone2-section')?.classList.toggle('hidden', !e.target.checked);
  });

  const profileInput = document.getElementById('user-profile-image');
  const nidInput     = document.getElementById('user-nid-image');

  if (profileInput) {
    profileInput.addEventListener('change', e => {
      const file = e.target.files?.[0];
      const preview = document.getElementById('user-profile-preview');
      if (!preview) return;

      if (file) {
        preview.src = URL.createObjectURL(file);
        preview.classList.remove('hidden');
      } else {
        preview.src = '';
        preview.classList.add('hidden');
      }
    });
  }

  if (nidInput) {
    nidInput.addEventListener('change', e => {
      const file = e.target.files?.[0];
      const preview = document.getElementById('user-nid-preview');
      if (!preview) return;

      if (file) {
        preview.src = URL.createObjectURL(file);
        preview.classList.remove('hidden');
      } else {
        preview.src = '';
        preview.classList.add('hidden');
      }
    });
  }

  const searchInput = document.getElementById('users-search');
  let searchTimer = null;

  if (searchInput) {
    searchInput.addEventListener('input', e => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        currentQuery = e.target.value.trim();
        fetchUsers(1, currentQuery);
      }, 300);
    });
  }

  document.getElementById('users-search-btn')?.addEventListener('click', () => {
    currentQuery = searchInput?.value.trim() || '';
    fetchUsers(1, currentQuery);
  });

  document.getElementById('users-prev')?.addEventListener('click', () => {
    if (currentPage > 1) {
      currentPage--;
      fetchUsers(currentPage, currentQuery);
    }
  });

  document.getElementById('users-next')?.addEventListener('click', () => {
    const totalPages = Math.max(1, Math.ceil(lastTotal / perPage));
    if (currentPage < totalPages) {
      currentPage++;
      fetchUsers(currentPage, currentQuery);
    }
  });

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
  const accountInput = document.getElementById('asset-account');
  const nameInput = document.getElementById('asset-name');
  const purchaseDateInput = document.getElementById('asset-purchase-date');
  const purchaseValueInput = document.getElementById('asset-purchase-value');
  const locationInput = document.getElementById('asset-location');
  const notesInput = document.getElementById('asset-notes');
  const certificateNameInput = document.getElementById('asset-certificate-name');
  const certificateFileInput = document.getElementById('asset-certificate-file');
  const certificateHint = document.getElementById('asset-certificate-hint');
  const certificateExisting = document.getElementById('asset-certificate-existing');
  const certificateLocalPreview = document.getElementById('asset-certificate-local-preview');
  const certificateRemoveWrap = document.getElementById('asset-certificate-remove-wrap');
  const removeCertificate = document.getElementById('asset-remove-certificate');

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

  const CONFIG = {
    maxCertificateSize: 10 * 1024 * 1024,
    allowedCertificateTypes: [
      'image/jpeg',
      'image/png',
      'image/gif',
      'image/webp',
      'application/pdf'
    ]
  };

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

  function fileSize(bytes) {
    const n = Number(bytes || 0);
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(2)} MB`;
  }

  function isImageType(mime) {
    return /^image\//i.test(String(mime || ''));
  }

  function isPdfType(mime) {
    return String(mime || '').toLowerCase() === 'application/pdf';
  }

  function resetFileInput(input) {
    if (input) input.value = '';
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

  function clearExistingCertificate() {
    if (certificateExisting) {
      certificateExisting.classList.add('hidden');
      certificateExisting.innerHTML = '';
    }
    if (certificateRemoveWrap) {
      certificateRemoveWrap.classList.add('hidden');
      certificateRemoveWrap.classList.remove('flex');
    }
  }

  function clearLocalCertificatePreview() {
    if (certificateLocalPreview) {
      certificateLocalPreview.classList.add('hidden');
      certificateLocalPreview.innerHTML = '';
    }
  }

  function showExistingCertificate(row) {
    if (!certificateExisting) return;

    const hasCertificate = Number(row?.has_certificate || 0) === 1;
    if (!hasCertificate) {
      clearExistingCertificate();
      return;
    }

    const type = row.certificate_mime || '';
    const name = row.certificate_name || 'certificate';
    const viewUrl = row.certificate_view_url || `${api}?action=view_certificate&id=${row.asset_id}`;
    const downloadUrl = row.certificate_download_url || `${api}?action=download_certificate&id=${row.asset_id}`;

    let previewHtml = '';
    if (isImageType(type)) {
      previewHtml = `
        <div class="mt-2">
          <img
            src="${esc(viewUrl)}"
            alt="Certificate"
            class="max-h-48 rounded border border-slate-200 object-contain bg-white"
          >
        </div>
      `;
    } else if (isPdfType(type)) {
      previewHtml = `
        <div class="mt-2">
          <a href="${esc(viewUrl)}" target="_blank" class="text-blue-700 underline">
            Fungura PDF
          </a>
        </div>
      `;
    }

    certificateExisting.innerHTML = `
      <div class="font-semibold text-slate-700">Certificate iriho</div>
      <div class="mt-1 text-slate-600">
        <div><b>File:</b> ${esc(name)}</div>
        <div><b>Type:</b> ${esc(type || 'unknown')}</div>
      </div>
      <div class="mt-2 flex gap-3">
        <a href="${esc(viewUrl)}" target="_blank" class="text-blue-700 underline">Reba</a>
        <a href="${esc(downloadUrl)}" target="_blank" class="text-emerald-700 underline">Download</a>
      </div>
      ${previewHtml}
    `;
    certificateExisting.classList.remove('hidden');

    if (certificateRemoveWrap) {
      certificateRemoveWrap.classList.remove('hidden');
      certificateRemoveWrap.classList.add('flex');
    }
  }

  function previewSelectedCertificate() {
    clearLocalCertificatePreview();

    const file = certificateFileInput?.files?.[0];
    if (!file || !certificateLocalPreview) return;

    if (!CONFIG.allowedCertificateTypes.includes(file.type)) {
      alert('Dosiye yemerewe ni JPG, PNG, GIF, WEBP cyangwa PDF.');
      resetFileInput(certificateFileInput);
      return;
    }

    if (file.size > CONFIG.maxCertificateSize) {
      alert('Dosiye irarengeje 10 MB.');
      resetFileInput(certificateFileInput);
      return;
    }

    const metaHtml = `
      <div class="text-xs text-slate-700 mb-2">
        <div><b>Selected:</b> ${esc(file.name)}</div>
        <div><b>Type:</b> ${esc(file.type || 'unknown')}</div>
        <div><b>Size:</b> ${esc(fileSize(file.size))}</div>
      </div>
    `;

    if (isImageType(file.type)) {
      const reader = new FileReader();
      reader.onload = () => {
        certificateLocalPreview.innerHTML = `
          ${metaHtml}
          <img
            src="${reader.result}"
            alt="Certificate Preview"
            class="max-h-48 rounded border border-slate-200 object-contain bg-white"
          >
        `;
        certificateLocalPreview.classList.remove('hidden');
      };
      reader.readAsDataURL(file);
    } else if (isPdfType(file.type)) {
      certificateLocalPreview.innerHTML = `
        ${metaHtml}
        <div class="text-blue-700">PDF yatoranyijwe neza.</div>
      `;
      certificateLocalPreview.classList.remove('hidden');
    } else {
      certificateLocalPreview.innerHTML = metaHtml;
      certificateLocalPreview.classList.remove('hidden');
    }

    if (removeCertificate) removeCertificate.checked = false;
  }

  function resetForm() {
    form?.reset();
    if (idInput) idInput.value = '';
    if (accountInput) accountInput.value = '';
    if (hasSoldCheckbox) hasSoldCheckbox.checked = false;
    if (soldSection) soldSection.classList.add('hidden');
    if (soldDateInput) soldDateInput.value = '';
    if (soldValueInput) soldValueInput.value = '';
    if (holdersList) holdersList.innerHTML = '';
    clearExistingCertificate();
    clearLocalCertificatePreview();
    resetFileInput(certificateFileInput);
    if (removeCertificate) removeCertificate.checked = false;
    if (certificateHint) certificateHint.textContent = 'Shyiraho certificate file niba ihari.';
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

  function buildCertificateHtml(row) {
    const hasCertificate = Number(row?.has_certificate || 0) === 1;
    if (!hasCertificate) {
      return '<span class="text-xs text-slate-400">Nta certificate</span>';
    }

    const mimeType = row.certificate_mime || '';
    const fileName = esc(row.certificate_name || 'Document');
    const viewUrl = row.certificate_view_url || `${api}?action=view_certificate&id=${row.asset_id}`;
    const downloadUrl = row.certificate_download_url || `${api}?action=download_certificate&id=${row.asset_id}`;

    if (mimeType.startsWith('image/')) {
      return `
        <div class="flex flex-col items-center gap-2">
          <a href="${esc(viewUrl)}" target="_blank" class="block">
            <img src="${esc(viewUrl)}" alt="Certificate" class="h-16 w-auto rounded border bg-white" />
          </a>
          <div class="flex gap-2 text-xs">
            <a href="${esc(viewUrl)}" target="_blank" class="text-blue-700 underline">Reba</a>
            <a href="${esc(downloadUrl)}" target="_blank" class="text-emerald-700 underline">Download</a>
          </div>
        </div>
      `;
    }

    if (mimeType === 'application/pdf') {
      return `
        <div class="flex flex-col items-center gap-2">
          <a href="${esc(viewUrl)}" target="_blank" class="flex h-16 w-16 items-center justify-center rounded border bg-red-50 text-xs font-bold text-red-700">
            PDF
          </a>
          <div class="flex gap-2 text-xs">
            <a href="${esc(viewUrl)}" target="_blank" class="text-blue-700 underline">Reba</a>
            <a href="${esc(downloadUrl)}" target="_blank" class="text-emerald-700 underline">Download</a>
          </div>
        </div>
      `;
    }

    return `<span class="text-xs text-slate-500">📄 ${fileName}</span>`;
  }

  async function loadAccounts() {
    if (!accountInput) return;
    try {
      const res = await fetch('accounts_api.php', { credentials: 'include' });
      const json = await readJsonResponse(res);

      if (!res.ok || !json.success) return;

      accountInput.innerHTML = '<option value="">-- Hitamo Konto --</option>';
      (json.data || []).forEach(acc => {
        const opt = document.createElement('option');
        opt.value = acc.account_id;
        opt.textContent = acc.name;
        accountInput.appendChild(opt);
      });
    } catch (err) {
      console.error('loadAccounts error', err);
    }
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
          <td colspan="10" class="py-8 text-center text-sm text-slate-400">
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
        <td>${esc(r.account_name || '')}</td>
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
            if (accountInput) accountInput.value = d.account_id || '';
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
                expense_partition: h.expense_partition || 0,
                participation_net: h.participation_net || 0,
                contribution: h.contribution_amount || '',
                notes: h.notes || ''
              }));
            } else {
              addHolderRow();
            }

            showExistingCertificate(d);
            clearLocalCertificatePreview();
            resetFileInput(certificateFileInput);
            if (removeCertificate) removeCertificate.checked = false;
            if (certificateHint) certificateHint.textContent = 'Injiza dosiye nshya gusa niba ushaka kuyisimbuza.';

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
              <td>${money(h.expense_partition || 0)}</td>
              <td>${money(h.contribution_amount || 0)}</td>
            </tr>
          `).join('');

          holdersViewBody.innerHTML = `
            <div class="space-y-3">
              <div><b>Asset:</b> ${esc(d.name || '')}</div>
              <div><b>Account:</b> ${esc(d.account_name || '')}</div>
              <div><b>Holders:</b> ${Number(d.holders_count || 0)}</div>
              <div class="table-wrapper">
                <table class="table">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Name</th>
                      <th>Phone</th>
                      <th>Net</th>
                      <th>Expense Partition</th>
                      <th>Contribution</th>
                    </tr>
                  </thead>
                  <tbody>${rows || `<tr><td colspan="6" class="text-sm text-slate-500">Nta holders</td></tr>`}</tbody>
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
          <div class="mt-1 text-xs">
            Expense Partition: <span class="holder-expense font-semibold">-</span>
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
    const expenseEl = row.querySelector('.holder-expense');
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
            btn.textContent = `${u.names}${u.phone1 ? ' · ' + u.phone1 : ''} · net: ${money(u.net_value || 0)} · exp: ${money(u.expense_partition || 0)}`;
            btn.addEventListener('click', () => {
              hiddenInput.value = u.id;
              selectedEl.textContent = `${u.names}${u.phone1 ? ' · ' + u.phone1 : ''}`;
              netEl.textContent = money(u.net_value || 0);
              expenseEl.textContent = money(u.expense_partition || 0);
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
      expenseEl.textContent = money(pref.expense_partition || 0);
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

  certificateFileInput?.addEventListener('change', () => {
    previewSelectedCertificate();
  });

  removeCertificate?.addEventListener('change', () => {
    if (removeCertificate.checked) {
      resetFileInput(certificateFileInput);
      clearLocalCertificatePreview();
    }
  });

  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      const id = idInput?.value || '';
      const hasSold = hasSoldCheckbox?.checked || false;

      if (!accountInput?.value) {
        alert('Hitamo konti.');
        return;
      }
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

      const certFile = certificateFileInput?.files?.[0];
      if (certFile) {
        if (!CONFIG.allowedCertificateTypes.includes(certFile.type)) {
          alert('Certificate yemerewe ni JPG, PNG, GIF, WEBP cyangwa PDF.');
          return;
        }
        if (certFile.size > CONFIG.maxCertificateSize) {
          alert('Certificate file ntigomba kurenga 10 MB.');
          return;
        }
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

      fd.append('account_id', accountInput?.value || '');
      fd.append('name', nameInput?.value || '');
      fd.append('purchase_date', purchaseDateInput?.value || '');
      fd.append('purchase_value', purchaseValueInput?.value || '');
      fd.append('location', locationInput?.value || '');
      fd.append('notes', notesInput?.value || '');
      fd.append('certificate_name', certificateNameInput?.value || '');

      if (certFile) {
        fd.append('certificate_file', certFile);
      }

      if (id && removeCertificate?.checked) {
        fd.append('remove_certificate', '1');
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
  loadAccounts();
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
    
    /**
 * Expense Management Module
 * Final version
 * Supports:
 * - expense CRUD
 * - proof image/pdf upload to DB
 * - proof preview before save
 * - existing proof preview/download on edit
 * - image thumbnail in table view
 * - remove existing proof on update
 */
(function () {
  const CONFIG = {
    api: "expenses_api.php",
    currency: "Frw",
    locale: "rw-RW",
    debounceTime: 250,
    maxProofSize: 10 * 1024 * 1024,
    allowedProofTypes: [
      "image/jpeg",
      "image/png",
      "image/gif",
      "image/webp",
      "application/pdf"
    ]
  };

  const STATE = {
    accounts: [],
    saving: false
  };

  const DOM = {
    tbody: document.getElementById("expenses-tbody"),
    modal: document.getElementById("expense-modal"),
    form: document.getElementById("expense-form"),
    id: document.getElementById("expense-id"),
    date: document.getElementById("expense-date"),
    account: document.getElementById("expense-account"),
    amount: document.getElementById("expense-amount"),
    desc: document.getElementById("expense-description"),
    proof: document.getElementById("expense-proof"),
    hint: document.getElementById("expense-proof-hint"),
    infoBox: document.getElementById("expense-info-box"),
    btnNew: document.getElementById("btn-new-expense"),
    btnRefresh: document.getElementById("btn-refresh-expenses"),
    btnSave: document.getElementById("expense-save"),
    btnCancel: document.getElementById("expense-cancel"),
    btnClose: document.getElementById("expense-modal-close"),
    modalTitle: document.getElementById("expense-modal-title"),
    q: document.getElementById("expense-search"),
    filterAccount: document.getElementById("expense-filter-account"),
    proofExisting: document.getElementById("expense-proof-existing"),
    proofLocalPreview: document.getElementById("expense-proof-local-preview"),
    proofRemoveWrap: document.getElementById("expense-proof-remove-wrap"),
    removeProof: document.getElementById("expense-remove-proof"),
    proofRequiredStar: document.getElementById("expense-proof-required-star")
  };

  const Format = {
    money(n) {
      return `${Number(n || 0).toLocaleString(CONFIG.locale)} ${CONFIG.currency}`;
    },

    toDTL(mysqlDt) {
      return mysqlDt ? mysqlDt.replace(" ", "T").slice(0, 16) : "";
    },

    escape(s) {
      return String(s ?? "").replace(/[&<>"']/g, (m) => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
      }[m]));
    },

    fileSize(bytes) {
      const n = Number(bytes || 0);
      if (n < 1024) return `${n} B`;
      if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
      return `${(n / (1024 * 1024)).toFixed(2)} MB`;
    }
  };

  const Utils = {
    debounce(fn, wait = CONFIG.debounceTime) {
      let t;
      return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), wait);
      };
    },

    isImageType(mime) {
      return /^image\//i.test(String(mime || ""));
    },

    isPdfType(mime) {
      return String(mime || "").toLowerCase() === "application/pdf";
    },

    resetFileInput(input) {
      if (!input) return;
      input.value = "";
    },

    getLocalDateTimeValue() {
      const now = new Date();
      const pad = (n) => String(n).padStart(2, "0");
      return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    }
  };

  async function apiRequest(url, options = {}) {
    const res = await fetch(url, {
      credentials: "include",
      ...options
    });

    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json.success) {
      throw new Error(json.message || `HTTP Error ${res.status}`);
    }
    return json;
  }

  const UI = {
    setSaveState(enabled, reason = "") {
      const disabled = !enabled || STATE.saving;
      if (DOM.btnSave) {
        DOM.btnSave.disabled = disabled;
        DOM.btnSave.classList.toggle("opacity-50", disabled);
        DOM.btnSave.classList.toggle("cursor-not-allowed", disabled);
      }
      if (DOM.hint) {
        DOM.hint.textContent = reason || "Itegeko kuri expense nshya.";
      }
    },

    setProofRequiredUi(required) {
      if (DOM.proof) {
        if (required) DOM.proof.setAttribute("required", "required");
        else DOM.proof.removeAttribute("required");
      }
      if (DOM.proofRequiredStar) {
        DOM.proofRequiredStar.classList.toggle("hidden", !required);
      }
    },

    toggleModal(show = true) {
      if (!DOM.modal) return;

      DOM.modal.classList.toggle("hidden", !show);
      DOM.modal.classList.toggle("flex", show);

      if (!show) {
        DOM.form?.reset();
        UI.updateInfoBox("");
        UI.clearExistingProof();
        UI.clearLocalProofPreview();
        if (DOM.removeProof) DOM.removeProof.checked = false;
        UI.setProofRequiredUi(false);
      }
    },

    updateInfoBox(html) {
      if (DOM.infoBox) DOM.infoBox.innerHTML = html || "";
    },

    clearExistingProof() {
      if (!DOM.proofExisting) return;
      DOM.proofExisting.classList.add("hidden");
      DOM.proofExisting.innerHTML = "";
      if (DOM.proofRemoveWrap) {
        DOM.proofRemoveWrap.classList.add("hidden");
        DOM.proofRemoveWrap.classList.remove("flex");
      }
    },

    showExistingProof(row) {
      if (!DOM.proofExisting) return;

      const hasProof = Number(row?.has_proof || 0) === 1;
      if (!hasProof) {
        UI.clearExistingProof();
        return;
      }

      const type = row.proof_type || "";
      const name = row.proof_name || "proof";
      const size = row.proof_size || 0;
      const viewUrl = row.proof_view_url || `${CONFIG.api}?action=view_proof&id=${row.transaction_id}`;
      const downloadUrl = row.proof_download_url || `${CONFIG.api}?action=download_proof&id=${row.transaction_id}`;

      let previewHtml = "";
      if (Utils.isImageType(type)) {
        previewHtml = `
          <div class="mt-2">
            <img
              src="${Format.escape(viewUrl)}"
              alt="Proof"
              class="max-h-48 rounded border border-slate-200 object-contain bg-white"
            >
          </div>
        `;
      } else if (Utils.isPdfType(type)) {
        previewHtml = `
          <div class="mt-2">
            <a href="${Format.escape(viewUrl)}" target="_blank" class="text-blue-700 underline">
              Fungura PDF
            </a>
          </div>
        `;
      }

      DOM.proofExisting.innerHTML = `
        <div class="font-semibold text-slate-700">Proof iriho</div>
        <div class="mt-1 text-slate-600">
          <div><b>File:</b> ${Format.escape(name)}</div>
          <div><b>Type:</b> ${Format.escape(type || "unknown")}</div>
          <div><b>Size:</b> ${Format.escape(Format.fileSize(size))}</div>
        </div>
        <div class="mt-2 flex gap-3">
          <a href="${Format.escape(viewUrl)}" target="_blank" class="text-blue-700 underline">Reba</a>
          <a href="${Format.escape(downloadUrl)}" target="_blank" class="text-emerald-700 underline">Download</a>
        </div>
        ${previewHtml}
      `;
      DOM.proofExisting.classList.remove("hidden");

      if (DOM.proofRemoveWrap) {
        DOM.proofRemoveWrap.classList.remove("hidden");
        DOM.proofRemoveWrap.classList.add("flex");
      }
    },

    clearLocalProofPreview() {
      if (!DOM.proofLocalPreview) return;
      DOM.proofLocalPreview.classList.add("hidden");
      DOM.proofLocalPreview.innerHTML = "";
    },

    previewSelectedProof() {
      UI.clearLocalProofPreview();

      const file = DOM.proof?.files?.[0];
      if (!file || !DOM.proofLocalPreview) return;

      if (!CONFIG.allowedProofTypes.includes(file.type)) {
        alert("Dosiye yemerewe ni JPG, PNG, GIF, WEBP cyangwa PDF.");
        Utils.resetFileInput(DOM.proof);
        UI.validateAll();
        return;
      }

      if (file.size > CONFIG.maxProofSize) {
        alert("Dosiye irarengeje 10 MB.");
        Utils.resetFileInput(DOM.proof);
        UI.validateAll();
        return;
      }

      const metaHtml = `
        <div class="text-xs text-slate-700 mb-2">
          <div><b>Selected:</b> ${Format.escape(file.name)}</div>
          <div><b>Type:</b> ${Format.escape(file.type || "unknown")}</div>
          <div><b>Size:</b> ${Format.escape(Format.fileSize(file.size))}</div>
        </div>
      `;

      if (Utils.isImageType(file.type)) {
        const reader = new FileReader();
        reader.onload = () => {
          DOM.proofLocalPreview.innerHTML = `
            ${metaHtml}
            <img
              src="${reader.result}"
              alt="Preview"
              class="max-h-48 rounded border border-slate-200 object-contain bg-white"
            >
          `;
          DOM.proofLocalPreview.classList.remove("hidden");
        };
        reader.readAsDataURL(file);
      } else if (Utils.isPdfType(file.type)) {
        DOM.proofLocalPreview.innerHTML = `
          ${metaHtml}
          <div class="text-blue-700">PDF yatoranyijwe neza.</div>
        `;
        DOM.proofLocalPreview.classList.remove("hidden");
      } else {
        DOM.proofLocalPreview.innerHTML = metaHtml;
        DOM.proofLocalPreview.classList.remove("hidden");
      }

      if (DOM.removeProof) DOM.removeProof.checked = false;
    },

    renderProofCell(r) {
      const hasProof = Number(r.has_proof || 0) === 1;
      if (!hasProof) {
        return `<span class="text-slate-400 text-xs">Nta proof</span>`;
      }

      const viewUrl = r.proof_view_url || `${CONFIG.api}?action=view_proof&id=${r.transaction_id}`;
      const downloadUrl = r.proof_download_url || `${CONFIG.api}?action=download_proof&id=${r.transaction_id}`;
      const proofType = String(r.proof_type || "").toLowerCase();

      if (proofType.startsWith("image/")) {
        return `
          <div class="flex flex-col items-center gap-2">
            <a href="${Format.escape(viewUrl)}" target="_blank" class="block">
              <img
                src="${Format.escape(viewUrl)}"
                alt="Proof"
                class="h-20 w-20 rounded-lg border border-slate-200 object-cover bg-white shadow-sm hover:scale-105 transition-transform"
              >
            </a>
            <div class="flex flex-wrap justify-center gap-2 text-xs">
              <a class="text-blue-700 underline" target="_blank" href="${Format.escape(viewUrl)}">Reba</a>
              <a class="text-emerald-700 underline" target="_blank" href="${Format.escape(downloadUrl)}">Download</a>
            </div>
          </div>
        `;
      }

      if (proofType === "application/pdf") {
        return `
          <div class="flex flex-col items-center gap-2">
            <a
              href="${Format.escape(viewUrl)}"
              target="_blank"
              class="flex h-20 w-20 items-center justify-center rounded-lg border border-slate-200 bg-red-50 text-xs font-bold text-red-700 shadow-sm"
            >
              PDF
            </a>
            <div class="flex flex-wrap justify-center gap-2 text-xs">
              <a class="text-blue-700 underline" target="_blank" href="${Format.escape(viewUrl)}">Reba</a>
              <a class="text-emerald-700 underline" target="_blank" href="${Format.escape(downloadUrl)}">Download</a>
            </div>
          </div>
        `;
      }

      return `
        <div class="flex flex-col items-center gap-2">
          <a class="text-blue-700 underline text-xs" target="_blank" href="${Format.escape(viewUrl)}">Reba proof</a>
          <a class="text-emerald-700 underline text-xs" target="_blank" href="${Format.escape(downloadUrl)}">Download</a>
        </div>
      `;
    },

    renderRows(rows) {
      if (!DOM.tbody) return;

      if (!rows || rows.length === 0) {
        DOM.tbody.innerHTML = `
          <tr>
            <td colspan="7" class="text-center py-8 text-slate-400 text-sm">
              Nta expenses zibonetse
            </td>
          </tr>
        `;
        return;
      }

      DOM.tbody.innerHTML = rows.map((r, i) => `
        <tr class="border-b hover:bg-gray-50 text-sm">
          <td class="p-3">${i + 1}</td>
          <td class="p-3 whitespace-nowrap">${Format.escape(Format.toDTL(r.tx_date).replace("T", " "))}</td>
          <td class="p-3 font-medium">${Format.escape(r.account_name || "-")}</td>
          <td class="p-3">${Format.escape(r.description || "-")}</td>
          <td class="p-3 font-mono font-bold">${Format.money(r.amount)}</td>
          <td class="p-3 text-center">${UI.renderProofCell(r)}</td>
          <td class="p-3 space-x-1">
            <button class="text-emerald-600 hover:underline btn-edit" data-id="${r.transaction_id}" type="button">Hindura</button>
            <button class="text-red-600 hover:underline btn-delete" data-id="${r.transaction_id}" type="button">Siba</button>
          </td>
        </tr>
      `).join("");

      DOM.tbody.querySelectorAll(".btn-edit").forEach((btn) => {
        btn.onclick = () => openEdit(Number(btn.dataset.id));
      });

      DOM.tbody.querySelectorAll(".btn-delete").forEach((btn) => {
        btn.onclick = () => deleteExpense(Number(btn.dataset.id));
      });
    },

    validateAll() {
      const amount = Number(DOM.amount?.value || 0);
      const accId = Number(DOM.account?.value || 0);
      const isEdit = !!DOM.id?.value;
      const file = DOM.proof?.files?.[0];

      let valid = true;
      let reason = "";

      if (!accId) {
        valid = false;
        reason = "Hitamo konti.";
      } else if (amount <= 0) {
        valid = false;
        reason = "Umubare ugomba kuba > 0.";
      }

      if (valid) {
        const acc = STATE.accounts.find((a) => Number(a.account_id) === accId);
        if (acc && amount > Number(acc.balance || 0)) {
          valid = false;
          reason = `Amafaranga ari kuri konti ntahagije (${Format.money(acc.balance)}).`;
        }
      }

      if (valid && !isEdit && !file) {
        valid = false;
        reason = "Shyiraho proof file.";
      }

      if (valid && file) {
        if (!CONFIG.allowedProofTypes.includes(file.type)) {
          valid = false;
          reason = "Proof yemerewe ni JPG, PNG, GIF, WEBP cyangwa PDF.";
        } else if (file.size > CONFIG.maxProofSize) {
          valid = false;
          reason = "Proof file ntigomba kurenga 10 MB.";
        }
      }

      UI.setSaveState(valid, reason || "Itegeko kuri expense nshya.");
      return valid;
    }
  };

  async function openEdit(id) {
    try {
      const json = await apiRequest(`${CONFIG.api}?id=${id}`);
      const r = json.data || {};

      UI.toggleModal(true);

      if (DOM.modalTitle) DOM.modalTitle.textContent = "Hindura Expense";
      if (DOM.id) DOM.id.value = r.transaction_id || "";
      if (DOM.date) DOM.date.value = Format.toDTL(r.tx_date);
      if (DOM.account) DOM.account.value = r.account_id || "";
      if (DOM.amount) DOM.amount.value = r.amount || "";
      if (DOM.desc) DOM.desc.value = r.description || "";

      UI.setProofRequiredUi(false);
      if (DOM.hint) DOM.hint.textContent = "Injiza dosiye nshya gusa niba ushaka kuyisimbuza.";
      if (DOM.removeProof) DOM.removeProof.checked = false;

      UI.showExistingProof(r);
      UI.clearLocalProofPreview();
      Utils.resetFileInput(DOM.proof);
      UI.validateAll();
    } catch (e) {
      alert("Kugerageza gufungura expense byanze: " + e.message);
    }
  }

  async function deleteExpense(id) {
    if (!confirm(`Uremeza gusiba expense #${id}?`)) return;

    try {
      const fd = new FormData();
      fd.set("action", "delete");
      fd.set("id", id);

      await apiRequest(CONFIG.api, {
        method: "POST",
        body: fd
      });

      await loadList();
    } catch (e) {
      alert("Gusiba byanze: " + e.message);
    }
  }

  async function loadAccounts() {
    const json = await apiRequest(`${CONFIG.api}?accounts=1`);
    STATE.accounts = json.data || [];

    const opts = '<option value="">-- Hitamo Konti --</option>' +
      STATE.accounts.map((a) =>
        `<option value="${a.account_id}">${Format.escape(a.name)} (${Format.money(a.balance)})</option>`
      ).join("");

    if (DOM.account) DOM.account.innerHTML = opts;

    if (DOM.filterAccount) {
      DOM.filterAccount.innerHTML =
        '<option value="">-- Konti zose --</option>' +
        STATE.accounts.map((a) =>
          `<option value="${a.account_id}">${Format.escape(a.name)}</option>`
        ).join("");
    }
  }

  async function loadList() {
    try {
      if (DOM.tbody) {
        DOM.tbody.innerHTML = `
          <tr>
            <td colspan="7" class="text-center py-6 text-slate-400 text-sm">
              <i class="fas fa-spinner fa-spin mr-2"></i>Gutegura...
            </td>
          </tr>
        `;
      }

      const q = DOM.q?.value?.trim() || "";
      const accountId = DOM.filterAccount?.value || "";
      const params = new URLSearchParams({ per_page: "100" });
      if (q) params.set("q", q);
      if (accountId) params.set("account_id", accountId);

      const json = await apiRequest(`${CONFIG.api}?${params.toString()}`);
      UI.renderRows(json.data || []);
    } catch (e) {
      console.error("loadList failed:", e.message);
      if (DOM.tbody) {
        DOM.tbody.innerHTML = `
          <tr>
            <td colspan="7" class="text-center py-6 text-red-500 text-sm">
              Gufungura urutonde byanze: ${Format.escape(e.message)}
            </td>
          </tr>
        `;
      }
    }
  }

  function bindEvents() {
    if (DOM.btnNew) {
      DOM.btnNew.onclick = () => {
        if (DOM.modalTitle) DOM.modalTitle.textContent = "Ongeza Expense";

        UI.toggleModal(true);

        if (DOM.date) DOM.date.value = Utils.getLocalDateTimeValue();
        if (DOM.id) DOM.id.value = "";
        if (DOM.hint) DOM.hint.textContent = "Itegeko kuri expense nshya.";
        if (DOM.removeProof) DOM.removeProof.checked = false;

        UI.setProofRequiredUi(true);
        UI.clearExistingProof();
        UI.clearLocalProofPreview();
        Utils.resetFileInput(DOM.proof);
        UI.validateAll();
      };
    }

    if (DOM.btnClose) DOM.btnClose.onclick = () => UI.toggleModal(false);
    if (DOM.btnCancel) DOM.btnCancel.onclick = () => UI.toggleModal(false);
    if (DOM.btnRefresh) DOM.btnRefresh.onclick = loadList;

    [DOM.amount, DOM.account, DOM.date, DOM.desc].forEach((el) => {
      if (el) {
        el.onchange = () => UI.validateAll();
        el.oninput = () => UI.validateAll();
      }
    });

    if (DOM.q) DOM.q.oninput = Utils.debounce(loadList, CONFIG.debounceTime);
    if (DOM.filterAccount) DOM.filterAccount.onchange = loadList;

    if (DOM.proof) {
      DOM.proof.onchange = () => {
        UI.previewSelectedProof();
        UI.validateAll();
      };
    }

    if (DOM.removeProof) {
      DOM.removeProof.onchange = () => {
        if (DOM.removeProof.checked && DOM.proof) {
          Utils.resetFileInput(DOM.proof);
          UI.clearLocalProofPreview();
        }
        UI.validateAll();
      };
    }

    if (DOM.btnSave) {
      DOM.btnSave.onclick = async () => {
        if (!UI.validateAll()) return;

        STATE.saving = true;
        UI.setSaveState(false, "Kubika...");

        try {
          const fd = new FormData(DOM.form);
          const isEdit = !!DOM.id?.value;
          fd.set("action", isEdit ? "update" : "create");

          if (!isEdit) fd.delete("remove_proof");

          await apiRequest(CONFIG.api, {
            method: "POST",
            body: fd
          });

          UI.toggleModal(false);
          await loadAccounts();
          await loadList();
        } catch (e) {
          alert("Kubika byanze: " + e.message);
        } finally {
          STATE.saving = false;
          UI.validateAll();
        }
      };
    }
  }

  (async function init() {
    try {
      await loadAccounts();
      bindEvents();
      await loadList();
    } catch (e) {
      console.error("Expense module init failed:", e);
      if (DOM.tbody) {
        DOM.tbody.innerHTML = `
          <tr>
            <td colspan="7" class="text-center py-6 text-red-500 text-sm">
              Module yanze gutangira: ${Format.escape(e.message)}
            </td>
          </tr>
        `;
      }
    }
  })();
})();

// SCRIPTS: loans.js
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

  const referenceInput = document.getElementById('loan-reference-file');
  const referenceHint = document.getElementById('loan-reference-hint');
  const referenceExisting = document.getElementById('loan-reference-existing');
  const referenceLocalPreview = document.getElementById('loan-reference-local-preview');
  const referenceRemoveWrap = document.getElementById('loan-reference-remove-wrap');
  const removeReference = document.getElementById('loan-remove-reference');
  const referenceRequiredStar = document.getElementById('loan-reference-required-star');

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

  const CONFIG = {
    maxReferenceSize: 10 * 1024 * 1024,
    allowedReferenceTypes: [
      'image/jpeg',
      'image/png',
      'image/gif',
      'image/webp',
      'application/pdf'
    ]
  };

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

  function fileSize(bytes){
    const n = Number(bytes || 0);
    if(n < 1024) return `${n} B`;
    if(n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(2)} MB`;
  }

  function isImageType(mime){
    return /^image\//i.test(String(mime || ''));
  }

  function isPdfType(mime){
    return String(mime || '').toLowerCase() === 'application/pdf';
  }

  function resetFileInput(input){
    if(input) input.value = '';
  }

  function clearExistingReference(){
    if(referenceExisting){
      referenceExisting.classList.add('hidden');
      referenceExisting.innerHTML = '';
    }
    if(referenceRemoveWrap){
      referenceRemoveWrap.classList.add('hidden');
      referenceRemoveWrap.classList.remove('flex');
    }
  }

  function clearLocalReferencePreview(){
    if(referenceLocalPreview){
      referenceLocalPreview.classList.add('hidden');
      referenceLocalPreview.innerHTML = '';
    }
  }

  function setReferenceRequiredUi(required){
    if(referenceInput){
      if(required) referenceInput.setAttribute('required', 'required');
      else referenceInput.removeAttribute('required');
    }
    if(referenceRequiredStar){
      referenceRequiredStar.classList.toggle('hidden', !required);
    }
  }

  function showExistingReference(d){
    if(!referenceExisting) return;

    const hasReference = Number(d?.has_reference || 0) === 1;
    if(!hasReference){
      clearExistingReference();
      return;
    }

    const type = d.reference_mime || '';
    const name = d.reference_name || 'reference';
    const viewUrl = d.reference_view_url || `${api}?action=view_reference&id=${d.loan_id}`;
    const downloadUrl = d.reference_download_url || `${api}?action=download_reference&id=${d.loan_id}`;

    let previewHtml = '';
    if(isImageType(type)){
      previewHtml = `
        <div class="mt-2">
          <img
            src="${esc(viewUrl)}"
            alt="Reference"
            class="max-h-48 rounded border border-slate-200 object-contain bg-white"
          >
        </div>
      `;
    } else if(isPdfType(type)){
      previewHtml = `
        <div class="mt-2">
          <a href="${esc(viewUrl)}" target="_blank" class="text-blue-700 underline">
            Fungura PDF
          </a>
        </div>
      `;
    }

    referenceExisting.innerHTML = `
      <div class="font-semibold text-slate-700">Reference iriho</div>
      <div class="mt-1 text-slate-600">
        <div><b>File:</b> ${esc(name)}</div>
        <div><b>Type:</b> ${esc(type || 'unknown')}</div>
      </div>
      <div class="mt-2 flex gap-3">
        <a href="${esc(viewUrl)}" target="_blank" class="text-blue-700 underline">Reba</a>
        <a href="${esc(downloadUrl)}" target="_blank" class="text-emerald-700 underline">Download</a>
      </div>
      ${previewHtml}
    `;
    referenceExisting.classList.remove('hidden');

    if(referenceRemoveWrap){
      referenceRemoveWrap.classList.remove('hidden');
      referenceRemoveWrap.classList.add('flex');
    }
  }

  function previewSelectedReference(){
    clearLocalReferencePreview();
    const file = referenceInput?.files?.[0];
    if(!file || !referenceLocalPreview) return;

    if(!CONFIG.allowedReferenceTypes.includes(file.type)){
      alert('Dosiye yemerewe ni JPG, PNG, GIF, WEBP cyangwa PDF.');
      resetFileInput(referenceInput);
      validateFormRules();
      return;
    }

    if(file.size > CONFIG.maxReferenceSize){
      alert('Dosiye irarengeje 10 MB.');
      resetFileInput(referenceInput);
      validateFormRules();
      return;
    }

    const metaHtml = `
      <div class="text-xs text-slate-700 mb-2">
        <div><b>Selected:</b> ${esc(file.name)}</div>
        <div><b>Type:</b> ${esc(file.type || 'unknown')}</div>
        <div><b>Size:</b> ${esc(fileSize(file.size))}</div>
      </div>
    `;

    if(isImageType(file.type)){
      const reader = new FileReader();
      reader.onload = () => {
        referenceLocalPreview.innerHTML = `
          ${metaHtml}
          <img
            src="${reader.result}"
            alt="Reference Preview"
            class="max-h-48 rounded border border-slate-200 object-contain bg-white"
          >
        `;
        referenceLocalPreview.classList.remove('hidden');
      };
      reader.readAsDataURL(file);
    } else if(isPdfType(file.type)){
      referenceLocalPreview.innerHTML = `
        ${metaHtml}
        <div class="text-blue-700">PDF yatoranyijwe neza.</div>
      `;
      referenceLocalPreview.classList.remove('hidden');
    } else {
      referenceLocalPreview.innerHTML = metaHtml;
      referenceLocalPreview.classList.remove('hidden');
    }

    if(removeReference) removeReference.checked = false;
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
            <div>Expense Partition: <b>${money(b.expense_partition || 0)}</b></div>
            <div>Withdrawals: <b>${money(b.withdrawals)}</b></div>
            <div>Participation Net: <b>${money(b.participation_net || 0)}</b></div>
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
          item.textContent = `${g.names}${g.phone ? ' · ' + g.phone : ''} · net: ${money(g.net_value)}${Number(g.calculated_interest || 0) > 0 ? ' · int: ' + money(g.calculated_interest) : ''}${Number(g.expense_partition || 0) > 0 ? ' · exp: ' + money(g.expense_partition) : ''}`;
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
    const isEdit = !!(document.getElementById('loan-id')?.value || '');
    const refFile = referenceInput?.files?.[0];

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

    if(ok && !isEdit && !refFile){
      ok = false;
      msg = 'Shyiraho reference file.';
    }

    if(ok && refFile){
      if(!CONFIG.allowedReferenceTypes.includes(refFile.type)){
        ok = false;
        msg = 'Reference yemerewe ni JPG, PNG, GIF, WEBP cyangwa PDF.';
      } else if(refFile.size > CONFIG.maxReferenceSize){
        ok = false;
        msg = 'Reference file ntigomba kurenga 10 MB.';
      }
    }

    if(validationMsgEl){
      validationMsgEl.textContent = msg;
      validationMsgEl.classList.toggle('hidden', !msg);
    }

    if(referenceHint && msg){
      referenceHint.textContent = msg;
    }

    if(saveBtn){
      saveBtn.disabled = !ok;
      saveBtn.classList.toggle('opacity-50', !ok);
      saveBtn.classList.toggle('cursor-not-allowed', !ok);
    }
  }

  if(principalInput) principalInput.addEventListener('input', validateFormRules);

  if(referenceInput){
    referenceInput.addEventListener('change', ()=>{
      previewSelectedReference();
      validateFormRules();
    });
  }

  if(removeReference){
    removeReference.addEventListener('change', ()=>{
      if(removeReference.checked && referenceInput){
        resetFileInput(referenceInput);
        clearLocalReferencePreview();
      }
      validateFormRules();
    });
  }

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

  function renderReferenceCell(r){
    const hasRef = Number(r.has_reference || 0) === 1;
    if(!hasRef) return `<span class="text-slate-400 text-xs">Nta file</span>`;

    const viewUrl = r.reference_view_url || `${api}?action=view_reference&id=${r.loan_id}`;
    const downloadUrl = r.reference_download_url || `${api}?action=download_reference&id=${r.loan_id}`;
    const mime = String(r.reference_mime || '').toLowerCase();

    if(mime.startsWith('image/')){
      return `
        <div class="flex flex-col items-center gap-2">
          <a href="${esc(viewUrl)}" target="_blank" class="block">
            <img
              src="${esc(viewUrl)}"
              alt="Reference"
              class="h-16 w-16 rounded-lg border border-slate-200 object-cover bg-white shadow-sm hover:scale-105 transition-transform"
            >
          </a>
          <div class="flex flex-wrap justify-center gap-2 text-xs">
            <a class="text-blue-700 underline" target="_blank" href="${esc(viewUrl)}">Reba</a>
            <a class="text-emerald-700 underline" target="_blank" href="${esc(downloadUrl)}">Download</a>
          </div>
        </div>
      `;
    }

    if(mime === 'application/pdf'){
      return `
        <div class="flex flex-col items-center gap-2">
          <a
            href="${esc(viewUrl)}"
            target="_blank"
            class="flex h-16 w-16 items-center justify-center rounded-lg border border-slate-200 bg-red-50 text-xs font-bold text-red-700 shadow-sm"
          >
            PDF
          </a>
          <div class="flex flex-wrap justify-center gap-2 text-xs">
            <a class="text-blue-700 underline" target="_blank" href="${esc(viewUrl)}">Reba</a>
            <a class="text-emerald-700 underline" target="_blank" href="${esc(downloadUrl)}">Download</a>
          </div>
        </div>
      `;
    }

    return `
      <div class="flex flex-col items-center gap-2">
        <a class="text-blue-700 underline text-xs" target="_blank" href="${esc(viewUrl)}">Reba file</a>
        <a class="text-emerald-700 underline text-xs" target="_blank" href="${esc(downloadUrl)}">Download</a>
      </div>
    `;
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
        <td class="text-center">${renderReferenceCell(r)}</td>
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

      let refHtml = '<div class="text-sm text-slate-500">Nta reference file</div>';
      if(Number(d.has_reference || 0) === 1){
        const viewUrl = d.reference_view_url || `${api}?action=view_reference&id=${d.loan_id}`;
        const downloadUrl = d.reference_download_url || `${api}?action=download_reference&id=${d.loan_id}`;
        const mime = String(d.reference_mime || '').toLowerCase();

        if(mime.startsWith('image/')){
          refHtml = `
            <div class="space-y-2">
              <img src="${esc(viewUrl)}" alt="Reference" class="max-h-56 rounded border border-slate-200 bg-white">
              <div class="flex gap-3 text-sm">
                <a href="${esc(viewUrl)}" target="_blank" class="text-blue-700 underline">Reba</a>
                <a href="${esc(downloadUrl)}" target="_blank" class="text-emerald-700 underline">Download</a>
              </div>
            </div>
          `;
        } else if(mime === 'application/pdf'){
          refHtml = `
            <div class="space-y-2">
              <a href="${esc(viewUrl)}" target="_blank" class="text-blue-700 underline">Fungura PDF</a>
              <div class="flex gap-3 text-sm">
                <a href="${esc(viewUrl)}" target="_blank" class="text-blue-700 underline">Reba</a>
                <a href="${esc(downloadUrl)}" target="_blank" class="text-emerald-700 underline">Download</a>
              </div>
            </div>
          `;
        } else {
          refHtml = `
            <div class="flex gap-3 text-sm">
              <a href="${esc(viewUrl)}" target="_blank" class="text-blue-700 underline">Reba file</a>
              <a href="${esc(downloadUrl)}" target="_blank" class="text-emerald-700 underline">Download</a>
            </div>
          `;
        }
      }

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
              <b>Reference File</b>
              <div class="mt-2">${refHtml}</div>
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

      setReferenceRequiredUi(false);
      if(referenceHint) referenceHint.textContent = 'Injiza dosiye nshya gusa niba ushaka kuyisimbuza.';
      if(removeReference) removeReference.checked = false;
      showExistingReference(d);
      clearLocalReferencePreview();
      resetFileInput(referenceInput);

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

      setReferenceRequiredUi(true);
      clearExistingReference();
      clearLocalReferencePreview();
      resetFileInput(referenceInput);
      if(removeReference) removeReference.checked = false;
      if(referenceHint) referenceHint.textContent = 'Shyiraho reference file.';
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

        const fd = new FormData(form || undefined);
        fd.set('action', id ? 'update' : 'create');
        if(id) fd.set('id', id);

        fd.set('account_id', accountSelect?.value || '');
        fd.set('borrower_user_id', borrowerHidden?.value || '');
        fd.set('principal', principalInput?.value || '');
        fd.set('monthly_interest_rate', rateInput?.value || '');
        fd.set('interest_method', INTEREST_METHOD);
        fd.set('term_months', termInput?.value || '');
        fd.set('notes', notesEl?.value || '');

        if(!id){
          fd.delete('remove_reference');
        }

        const guarantorsArray = [];
        document.querySelectorAll('.guarantor-row').forEach(row=>{
          const gid = row.querySelector('.guarantor-id')?.value || '';
          const amt = row.querySelector('.guarantee-amount')?.value || '';
          if(gid && amt) guarantorsArray.push({user_id: gid, amount: amt});
        });
        fd.set('guarantors', JSON.stringify(guarantorsArray));

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
 * Final version
 * Supports:
 * - proof image/pdf upload to DB
 * - proof preview before save
 * - existing proof preview/download on edit
 * - image thumbnail in table view
 * - remove existing proof on update
 * - borrower-wide loan payment handling
 * - safer create/update flow
 */
(function () {
  const CONFIG = {
    api: "transactions_api.php",
    loansApi: "loans_api.php",
    usersApi: "users_api.php",
    accountsApi: "accounts_api.php",
    currency: "Frw",
    locale: "rw-RW",
    debounceTime: 250,
    maxProofSize: 10 * 1024 * 1024,
    allowedProofTypes: [
      "image/jpeg",
      "image/png",
      "image/gif",
      "image/webp",
      "application/pdf"
    ]
  };

  const STATE = {
    accounts: [],
    userLoans: [],
    saving: false
  };

  const DOM = {
    tbody: document.getElementById("transactions-tbody"),
    modal: document.getElementById("transaction-modal"),
    form: document.getElementById("transaction-form"),
    id: document.getElementById("transaction-id"),
    date: document.getElementById("transaction-date"),
    user: document.getElementById("transaction-user"),
    userSearch: document.getElementById("transaction-user-search"),
    account: document.getElementById("transaction-account"),
    typeWrap: document.getElementById("transaction-type-wrapper"),
    type: document.getElementById("transaction-type"),
    direction: document.getElementById("transaction-direction"),
    loanWrap: document.getElementById("loan-id-wrapper"),
    loanId: document.getElementById("transaction-loan-id"),
    amount: document.getElementById("transaction-amount"),
    desc: document.getElementById("transaction-description"),
    proof: document.getElementById("transaction-proof"),
    hint: document.getElementById("transaction-proof-hint"),
    infoBox: document.getElementById("transaction-info-box"),
    btnNew: document.getElementById("btn-new-transaction"),
    btnRefresh: document.getElementById("btn-refresh-transactions"),
    btnSave: document.getElementById("transaction-save"),
    btnCancel: document.getElementById("transaction-cancel"),
    btnClose: document.getElementById("transaction-modal-close"),
    modalTitle: document.getElementById("transaction-modal-title"),
    proofExisting: document.getElementById("transaction-proof-existing"),
    proofLocalPreview: document.getElementById("transaction-proof-local-preview"),
    proofRemoveWrap: document.getElementById("transaction-proof-remove-wrap"),
    removeProof: document.getElementById("transaction-remove-proof"),
    proofRequiredStar: document.getElementById("transaction-proof-required-star")
  };

  const Format = {
    money(n) {
      return `${Number(n || 0).toLocaleString(CONFIG.locale)} ${CONFIG.currency}`;
    },

    typeLabel(t) {
      const labels = {
        contribution: "Contribution",
        withdrawal: "Withdrawal",
        loan_principal: "Loan Payment (Principal)",
        loan_interest: "Loan Interest",
        expense: "Expense",
        other_income: "Other Income",
        other_out: "Other Out"
      };
      return labels[(t || "").toLowerCase()] || t;
    },

    toDTL(mysqlDt) {
      return mysqlDt ? mysqlDt.replace(" ", "T").slice(0, 16) : "";
    },

    escape(s) {
      return String(s ?? "").replace(/[&<>"']/g, (m) => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
      }[m]));
    },

    fileSize(bytes) {
      const n = Number(bytes || 0);
      if (n < 1024) return `${n} B`;
      if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
      return `${(n / (1024 * 1024)).toFixed(2)} MB`;
    }
  };

  const Utils = {
    debounce(fn, wait = CONFIG.debounceTime) {
      let t;
      return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), wait);
      };
    },

    isImageType(mime) {
      return /^image\//i.test(String(mime || ""));
    },

    isPdfType(mime) {
      return String(mime || "").toLowerCase() === "application/pdf";
    },

    resetFileInput(input) {
      if (!input) return;
      input.value = "";
    },

    getLocalDateTimeValue() {
      const now = new Date();
      const pad = (n) => String(n).padStart(2, "0");
      return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    }
  };

  async function apiRequest(url, options = {}) {
    const res = await fetch(url, {
      credentials: "include",
      ...options
    });

    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json.success) {
      throw new Error(json.message || `HTTP Error ${res.status}`);
    }
    return json;
  }

  const UI = {
    setSaveState(enabled, reason = "") {
      const disabled = !enabled || STATE.saving;
      if (DOM.btnSave) {
        DOM.btnSave.disabled = disabled;
        DOM.btnSave.classList.toggle("opacity-50", disabled);
        DOM.btnSave.classList.toggle("cursor-not-allowed", disabled);
      }
      if (DOM.hint && reason) {
        DOM.hint.textContent = reason;
      }
    },

    setProofRequiredUi(required) {
      if (DOM.proof) {
        if (required) DOM.proof.setAttribute("required", "required");
        else DOM.proof.removeAttribute("required");
      }
      if (DOM.proofRequiredStar) {
        DOM.proofRequiredStar.classList.toggle("hidden", !required);
      }
    },

    toggleModal(show = true) {
      if (!DOM.modal) return;

      DOM.modal.classList.toggle("hidden", !show);
      DOM.modal.classList.toggle("flex", show);

      if (!show) {
        DOM.form?.reset();
        STATE.userLoans = [];
        UI.updateInfoBox("");
        UI.clearExistingProof();
        UI.clearLocalProofPreview();

        if (DOM.removeProof) DOM.removeProof.checked = false;
        UI.setProofRequiredUi(false);
        UI.syncLogic();
      }
    },

    updateInfoBox(html) {
      if (DOM.infoBox) DOM.infoBox.innerHTML = html || "";
    },

    clearExistingProof() {
      if (!DOM.proofExisting) return;
      DOM.proofExisting.classList.add("hidden");
      DOM.proofExisting.innerHTML = "";
      if (DOM.proofRemoveWrap) {
        DOM.proofRemoveWrap.classList.add("hidden");
        DOM.proofRemoveWrap.classList.remove("flex");
      }
    },

    showExistingProof(row) {
      if (!DOM.proofExisting) return;

      const hasProof = Number(row?.has_proof || 0) === 1;
      if (!hasProof) {
        UI.clearExistingProof();
        return;
      }

      const type = row.proof_type || "";
      const name = row.proof_name || "proof";
      const size = row.proof_size || 0;
      const viewUrl = row.proof_view_url || `${CONFIG.api}?action=view_proof&id=${row.transaction_id}`;
      const downloadUrl = row.proof_download_url || `${CONFIG.api}?action=download_proof&id=${row.transaction_id}`;

      let previewHtml = "";
      if (Utils.isImageType(type)) {
        previewHtml = `
          <div class="mt-2">
            <img
              src="${Format.escape(viewUrl)}"
              alt="Proof"
              class="max-h-48 rounded border border-slate-200 object-contain bg-white"
            >
          </div>
        `;
      } else if (Utils.isPdfType(type)) {
        previewHtml = `
          <div class="mt-2">
            <a href="${Format.escape(viewUrl)}" target="_blank" class="text-blue-700 underline">
              Fungura PDF
            </a>
          </div>
        `;
      }

      DOM.proofExisting.innerHTML = `
        <div class="font-semibold text-slate-700">Proof iriho</div>
        <div class="mt-1 text-slate-600">
          <div><b>File:</b> ${Format.escape(name)}</div>
          <div><b>Type:</b> ${Format.escape(type || "unknown")}</div>
          <div><b>Size:</b> ${Format.escape(Format.fileSize(size))}</div>
        </div>
        <div class="mt-2 flex gap-3">
          <a href="${Format.escape(viewUrl)}" target="_blank" class="text-blue-700 underline">Reba</a>
          <a href="${Format.escape(downloadUrl)}" target="_blank" class="text-emerald-700 underline">Download</a>
        </div>
        ${previewHtml}
      `;
      DOM.proofExisting.classList.remove("hidden");

      if (DOM.proofRemoveWrap) {
        DOM.proofRemoveWrap.classList.remove("hidden");
        DOM.proofRemoveWrap.classList.add("flex");
      }
    },

    clearLocalProofPreview() {
      if (!DOM.proofLocalPreview) return;
      DOM.proofLocalPreview.classList.add("hidden");
      DOM.proofLocalPreview.innerHTML = "";
    },

    previewSelectedProof() {
      UI.clearLocalProofPreview();

      const file = DOM.proof?.files?.[0];
      if (!file || !DOM.proofLocalPreview) return;

      if (!CONFIG.allowedProofTypes.includes(file.type)) {
        alert("Dosiye yemerewe ni JPG, PNG, GIF, WEBP cyangwa PDF.");
        Utils.resetFileInput(DOM.proof);
        UI.validateAll();
        return;
      }

      if (file.size > CONFIG.maxProofSize) {
        alert("Dosiye irarengeje 10 MB.");
        Utils.resetFileInput(DOM.proof);
        UI.validateAll();
        return;
      }

      const metaHtml = `
        <div class="text-xs text-slate-700 mb-2">
          <div><b>Selected:</b> ${Format.escape(file.name)}</div>
          <div><b>Type:</b> ${Format.escape(file.type || "unknown")}</div>
          <div><b>Size:</b> ${Format.escape(Format.fileSize(file.size))}</div>
        </div>
      `;

      if (Utils.isImageType(file.type)) {
        const reader = new FileReader();
        reader.onload = () => {
          DOM.proofLocalPreview.innerHTML = `
            ${metaHtml}
            <img
              src="${reader.result}"
              alt="Preview"
              class="max-h-48 rounded border border-slate-200 object-contain bg-white"
            >
          `;
          DOM.proofLocalPreview.classList.remove("hidden");
        };
        reader.readAsDataURL(file);
      } else if (Utils.isPdfType(file.type)) {
        DOM.proofLocalPreview.innerHTML = `
          ${metaHtml}
          <div class="text-blue-700">PDF yatoranyijwe neza.</div>
        `;
        DOM.proofLocalPreview.classList.remove("hidden");
      } else {
        DOM.proofLocalPreview.innerHTML = metaHtml;
        DOM.proofLocalPreview.classList.remove("hidden");
      }

      if (DOM.removeProof) DOM.removeProof.checked = false;
    },

    renderProofCell(r) {
      const hasProof = Number(r.has_proof || 0) === 1;
      if (!hasProof) {
        return `<span class="text-slate-400 text-xs">Nta proof</span>`;
      }

      const viewUrl = r.proof_view_url || `${CONFIG.api}?action=view_proof&id=${r.transaction_id}`;
      const downloadUrl = r.proof_download_url || `${CONFIG.api}?action=download_proof&id=${r.transaction_id}`;
      const proofType = String(r.proof_type || "").toLowerCase();

      if (proofType.startsWith("image/")) {
        return `
          <div class="flex flex-col items-center gap-2">
            <a href="${Format.escape(viewUrl)}" target="_blank" class="block">
              <img
                src="${Format.escape(viewUrl)}"
                alt="Proof"
                class="h-20 w-20 rounded-lg border border-slate-200 object-cover bg-white shadow-sm hover:scale-105 transition-transform"
              >
            </a>
            <div class="flex flex-wrap justify-center gap-2 text-xs">
              <a class="text-blue-700 underline" target="_blank" href="${Format.escape(viewUrl)}">Reba</a>
              <a class="text-emerald-700 underline" target="_blank" href="${Format.escape(downloadUrl)}">Download</a>
            </div>
          </div>
        `;
      }

      if (proofType === "application/pdf") {
        return `
          <div class="flex flex-col items-center gap-2">
            <a
              href="${Format.escape(viewUrl)}"
              target="_blank"
              class="flex h-20 w-20 items-center justify-center rounded-lg border border-slate-200 bg-red-50 text-xs font-bold text-red-700 shadow-sm"
            >
              PDF
            </a>
            <div class="flex flex-wrap justify-center gap-2 text-xs">
              <a class="text-blue-700 underline" target="_blank" href="${Format.escape(viewUrl)}">Reba</a>
              <a class="text-emerald-700 underline" target="_blank" href="${Format.escape(downloadUrl)}">Download</a>
            </div>
          </div>
        `;
      }

      return `
        <div class="flex flex-col items-center gap-2">
          <a class="text-blue-700 underline text-xs" target="_blank" href="${Format.escape(viewUrl)}">Reba proof</a>
          <a class="text-emerald-700 underline text-xs" target="_blank" href="${Format.escape(downloadUrl)}">Download</a>
        </div>
      `;
    },

    renderRows(rows) {
      if (!DOM.tbody) return;

      if (!rows || rows.length === 0) {
        DOM.tbody.innerHTML = `
          <tr>
            <td colspan="8" class="text-center py-8 text-slate-400 text-sm">
              Nta transactions zibonetse
            </td>
          </tr>
        `;
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
          <td class="p-3">${Format.escape(Format.typeLabel(r.type))}</td>
          <td class="p-3">
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${r.direction === "IN" ? "bg-green-100 text-green-700" : "bg-red-100 text-red-700"}">
              ${Format.escape(r.direction || "")}
            </span>
          </td>
          <td class="p-3 font-mono font-bold">${Format.money(r.amount)}</td>
          <td class="p-3 text-center">${UI.renderProofCell(r)}</td>
          <td class="p-3 space-x-1">
            <button class="text-emerald-600 hover:underline btn-edit" data-id="${r.transaction_id}" type="button">Hindura</button>
            <button class="text-red-600 hover:underline btn-delete" data-id="${r.transaction_id}" type="button">Siba</button>
          </td>
        </tr>
      `).join("");

      DOM.tbody.querySelectorAll(".btn-edit").forEach((btn) => {
        btn.onclick = () => openEdit(Number(btn.dataset.id));
      });

      DOM.tbody.querySelectorAll(".btn-delete").forEach((btn) => {
        btn.onclick = () => deleteTransaction(Number(btn.dataset.id));
      });
    },

    renderTypeOptions() {
      const types = [
        { v: "", t: "-- Ubwoko --" },
        { v: "contribution", t: "Contribution" },
        { v: "withdrawal", t: "Withdrawal" },
        { v: "loan_principal", t: "Loan Payment (Principal)" },
        { v: "loan_interest", t: "Loan Interest" },
        { v: "expense", t: "Expense" },
        { v: "other_income", t: "Other Income" },
        { v: "other_out", t: "Other Out" }
      ];

      const userSelected = !!DOM.user?.value;
      const current = DOM.type?.value || "";
      const allowed = userSelected ? types : types.filter((x) => x.v === "" || x.v === "expense");

      if (!DOM.type) return;
      DOM.type.innerHTML = allowed.map((x) => `<option value="${x.v}">${Format.escape(x.t)}</option>`).join("");
      DOM.type.value = [...DOM.type.options].some((o) => o.value === current) ? current : "";
    },

    async syncLogic() {
      const type = (DOM.type?.value || "").toLowerCase();
      const userSelected = !!DOM.user?.value;

      UI.renderTypeOptions();

      if (DOM.typeWrap) {
        DOM.typeWrap.classList.toggle("hidden", !userSelected && type !== "expense");
      }

      const isLoan = ["loan_principal", "loan_interest"].includes(type);
      if (DOM.loanWrap) {
        DOM.loanWrap.classList.toggle("hidden", !isLoan);
      }

      if (DOM.direction) {
        DOM.direction.value =
          ["contribution", "loan_principal", "loan_interest", "other_income"].includes(type)
            ? "IN"
            : "OUT";
      }

      if (type === "loan_principal") {
        if (DOM.loanWrap) DOM.loanWrap.classList.remove("hidden");
        if (DOM.loanId) {
          DOM.loanId.innerHTML = `<option value="">-- System izagabanya ku nguzanyo zose z'uyu muntu --</option>`;
        }
      } else if (isLoan && userSelected) {
        await UI.loadUserLoans();
      } else if (DOM.loanId && !isLoan) {
        DOM.loanId.innerHTML = `<option value="">-- Hitamo Loan --</option>`;
      }

      UI.validateAll();
    },

    validateAll() {
      const type = (DOM.type?.value || "").toLowerCase();
      const amount = Number(DOM.amount?.value || 0);
      const accId = Number(DOM.account?.value || 0);
      const isEdit = !!DOM.id?.value;
      const file = DOM.proof?.files?.[0];

      let valid = true;
      let reason = "";

      if (!accId) {
        valid = false;
        reason = "Hitamo konti.";
      } else if (!type) {
        valid = false;
        reason = "Hitamo ubwoko.";
      } else if (amount <= 0) {
        valid = false;
        reason = "Umubare ugomba kuba > 0.";
      } else if (type !== "expense" && !DOM.user?.value) {
        valid = false;
        reason = "Hitamo user.";
      } else if (type === "loan_interest" && !DOM.loanId?.value) {
        valid = false;
        reason = "Hitamo loan kuri loan interest.";
      }

      if (valid && ["withdrawal", "expense", "other_out"].includes(type)) {
        const acc = STATE.accounts.find((a) => Number(a.account_id) === accId);
        if (acc && amount > Number(acc.balance || 0)) {
          valid = false;
          reason = `Amafaranga ari kuri konti ntahagije (${Format.money(acc.balance)}).`;
        }
      }

      if (valid && !isEdit && !file) {
        valid = false;
        reason = "Shyiraho proof file.";
      }

      if (valid && file) {
        if (!CONFIG.allowedProofTypes.includes(file.type)) {
          valid = false;
          reason = "Proof yemerewe ni JPG, PNG, GIF, WEBP cyangwa PDF.";
        } else if (file.size > CONFIG.maxProofSize) {
          valid = false;
          reason = "Proof file ntigomba kurenga 10 MB.";
        }
      }

      UI.setSaveState(valid, reason || "Itegeko kuri transaction nshya.");
      return valid;
    },

    async loadUserLoans() {
      try {
        const userId = DOM.user?.value;
        if (!userId || !DOM.loanId) return;

        const json = await apiRequest(`${CONFIG.loansApi}?per_page=100`);
        STATE.userLoans = (json.data || []).filter((l) =>
          String(l.borrower_user_id) === String(userId) &&
          ["approved", "defaulted", "closed"].includes(String(l.status || "").toLowerCase())
        );

        const currentType = (DOM.type?.value || "").toLowerCase();
        if (currentType === "loan_principal") {
          DOM.loanId.innerHTML = `<option value="">-- System izagabanya ku nguzanyo zose z'uyu muntu --</option>`;
          return;
        }

        DOM.loanId.innerHTML = '<option value="">-- Hitamo Loan --</option>' +
          STATE.userLoans.map((l) =>
            `<option value="${l.loan_id}">#LN-${l.loan_id} – ${Format.escape(l.borrower_name || "")}</option>`
          ).join("");
      } catch (e) {
        console.error("loadUserLoans failed:", e.message);
      }
    }
  };

  async function openEdit(id) {
    try {
      const json = await apiRequest(`${CONFIG.api}?id=${id}`);
      const r = json.data || {};

      UI.toggleModal(true);

      if (DOM.modalTitle) DOM.modalTitle.textContent = "Hindura Transaction";
      if (DOM.id) DOM.id.value = r.transaction_id || "";
      if (DOM.date) DOM.date.value = Format.toDTL(r.tx_date);
      if (DOM.account) DOM.account.value = r.account_id || "";
      if (DOM.direction) DOM.direction.value = r.direction || "";
      if (DOM.amount) DOM.amount.value = r.amount || "";
      if (DOM.desc) DOM.desc.value = r.description || "";

      if (r.user_id && DOM.user) {
        DOM.user.innerHTML = `<option value="${r.user_id}">${Format.escape(r.user_name || r.user_id)}</option>`;
        DOM.user.value = r.user_id;
      } else if (DOM.user) {
        DOM.user.innerHTML = `<option value="">-- Hitamo User --</option>`;
        DOM.user.value = "";
      }

      if (DOM.typeWrap) DOM.typeWrap.classList.remove("hidden");
      UI.renderTypeOptions();
      if (DOM.type) DOM.type.value = r.type || "";

      if (r.loan_id && DOM.loanId) {
        if (DOM.loanWrap) DOM.loanWrap.classList.remove("hidden");
        DOM.loanId.innerHTML = `<option value="${r.loan_id}">#LN-${r.loan_id}</option>`;
        DOM.loanId.value = r.loan_id;
      } else if (DOM.loanId) {
        DOM.loanId.innerHTML = `<option value="">-- Hitamo Loan --</option>`;
      }

      UI.setProofRequiredUi(false);
      if (DOM.hint) DOM.hint.textContent = "Injiza dosiye nshya gusa niba ushaka kuyisimbuza.";
      if (DOM.removeProof) DOM.removeProof.checked = false;

      UI.showExistingProof(r);
      UI.clearLocalProofPreview();
      Utils.resetFileInput(DOM.proof);

      await UI.syncLogic();
      UI.validateAll();
    } catch (e) {
      alert("Kugerageza gufungura transaction byanze: " + e.message);
    }
  }

  async function deleteTransaction(id) {
    if (!confirm(`Uremeza gusiba transaction #${id}?`)) return;

    try {
      const fd = new FormData();
      fd.set("action", "delete");
      fd.set("id", id);

      await apiRequest(CONFIG.api, {
        method: "POST",
        body: fd
      });

      await loadList();
    } catch (e) {
      alert("Gusiba byanze: " + e.message);
    }
  }

  async function loadList() {
    try {
      if (DOM.tbody) {
        DOM.tbody.innerHTML = `
          <tr>
            <td colspan="8" class="text-center py-6 text-slate-400 text-sm">
              <i class="fas fa-spinner fa-spin mr-2"></i>Gutegura...
            </td>
          </tr>
        `;
      }

      const json = await apiRequest(`${CONFIG.api}?per_page=100`);
      UI.renderRows(json.data || []);
    } catch (e) {
      console.error("loadList failed:", e.message);
      if (DOM.tbody) {
        DOM.tbody.innerHTML = `
          <tr>
            <td colspan="8" class="text-center py-6 text-red-500 text-sm">
              Gufungura urutonde byanze: ${Format.escape(e.message)}
            </td>
          </tr>
        `;
      }
    }
  }

  function bindEvents() {
    if (DOM.btnNew) {
      DOM.btnNew.onclick = () => {
        if (DOM.modalTitle) DOM.modalTitle.textContent = "Ongeza Transaction";

        UI.toggleModal(true);

        if (DOM.date) DOM.date.value = Utils.getLocalDateTimeValue();
        if (DOM.id) DOM.id.value = "";
        if (DOM.user) DOM.user.value = "";
        if (DOM.loanId) DOM.loanId.innerHTML = `<option value="">-- Hitamo Loan --</option>`;
        if (DOM.hint) DOM.hint.textContent = "Itegeko kuri transaction nshya.";
        if (DOM.removeProof) DOM.removeProof.checked = false;

        UI.setProofRequiredUi(true);
        UI.clearExistingProof();
        UI.clearLocalProofPreview();
        Utils.resetFileInput(DOM.proof);

        UI.syncLogic();
      };
    }

    if (DOM.btnClose) DOM.btnClose.onclick = () => UI.toggleModal(false);
    if (DOM.btnCancel) DOM.btnCancel.onclick = () => UI.toggleModal(false);
    if (DOM.btnRefresh) DOM.btnRefresh.onclick = loadList;

    if (DOM.userSearch) {
      DOM.userSearch.oninput = Utils.debounce(async (e) => {
        const q = e.target.value.trim();
        if (q.length < 2 || !DOM.user) return;

        try {
          const json = await apiRequest(`${CONFIG.usersApi}?q=${encodeURIComponent(q)}&per_page=20`);
          DOM.user.innerHTML = '<option value="">-- Hitamo User --</option>' +
            (json.data || []).map((u) =>
              `<option value="${u.id}">${Format.escape(u.names)} | ${Format.escape(u.phone1 || "")}</option>`
            ).join("");
        } catch (err) {
          console.error("User search failed:", err.message);
        }
      }, CONFIG.debounceTime);
    }

    if (DOM.user) {
      DOM.user.onchange = () => UI.syncLogic();
    }

    if (DOM.loanId) {
      DOM.loanId.onchange = () => {
        const loanId = DOM.loanId.value;
        if (!loanId) {
          UI.updateInfoBox("");
          UI.validateAll();
          return;
        }

        const loan = STATE.userLoans.find((l) => String(l.loan_id) === String(loanId));
        if (loan) {
          UI.updateInfoBox(`
            <div class="mt-2 text-xs rounded border border-blue-200 bg-blue-50 p-2">
              <p class="font-bold mb-1">Loan #${loan.loan_id} – ${Format.escape(loan.borrower_name || "")}</p>
              <p>Principal: <b>${Format.money(loan.principal ?? loan.principal_amount ?? 0)}</b></p>
              <p>Status: <b>${Format.escape(loan.status)}</b></p>
            </div>
          `);
        }

        UI.validateAll();
      };
    }

    [DOM.type, DOM.amount, DOM.account, DOM.date].forEach((el) => {
      if (el) {
        el.onchange = () => UI.syncLogic();
        el.oninput = () => UI.validateAll();
      }
    });

    if (DOM.proof) {
      DOM.proof.onchange = () => {
        UI.previewSelectedProof();
        UI.validateAll();
      };
    }

    if (DOM.removeProof) {
      DOM.removeProof.onchange = () => {
        if (DOM.removeProof.checked && DOM.proof) {
          Utils.resetFileInput(DOM.proof);
          UI.clearLocalProofPreview();
        }
        UI.validateAll();
      };
    }

    if (DOM.btnSave) {
      DOM.btnSave.onclick = async () => {
        if (!UI.validateAll()) return;

        STATE.saving = true;
        UI.setSaveState(false, "Kubika...");

        try {
          const fd = new FormData(DOM.form);
          const isEdit = !!DOM.id?.value;
          fd.set("action", isEdit ? "update" : "create");

          if (!isEdit) {
            fd.delete("remove_proof");
          }

          const res = await apiRequest(CONFIG.api, {
            method: "POST",
            body: fd
          });

          if (Array.isArray(res.data?.saved_rows) && res.data.saved_rows.length) {
            const totalSaved = res.data.saved_rows.length;
            const remain = Number(res.data.remaining_unallocated || 0);
            alert(
              `Byabitswe neza.\n` +
              `Transactions zakozwe: ${totalSaved}\n` +
              `Amafaranga atasaranganyijwe: ${Format.money(remain)}`
            );
          }

          UI.toggleModal(false);
          await loadList();
        } catch (e) {
          alert("Kubika byanze: " + e.message);
        } finally {
          STATE.saving = false;
          UI.validateAll();
        }
      };
    }
  }

  (async function init() {
    try {
      try {
        const accJson = await apiRequest(CONFIG.accountsApi);
        STATE.accounts = accJson.data || [];

        if (DOM.account) {
          DOM.account.innerHTML = '<option value="">-- Hitamo Konti --</option>' +
            STATE.accounts.map((a) =>
              `<option value="${a.account_id}">${Format.escape(a.name)}${a.balance !== undefined ? ` (${Format.money(a.balance)})` : ""}</option>`
            ).join("");
        }
      } catch (e) {
        console.error("Accounts load failed:", e.message);
        if (DOM.account) {
          DOM.account.innerHTML = '<option value="">-- Konti ntizashoboka --</option>';
        }
      }

      bindEvents();
      await loadList();
    } catch (e) {
      console.error("Transaction module init failed:", e);
      if (DOM.tbody) {
        DOM.tbody.innerHTML = `
          <tr>
            <td colspan="8" class="text-center py-6 text-red-500 text-sm">
              Module yanze gutangira: ${Format.escape(e.message)}
            </td>
          </tr>
        `;
      }
    }
  })();
})();