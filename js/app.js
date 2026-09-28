/* ============================================================
   SHARED JAVASCRIPT  (js/app.js)
   Small helpers used across the app. Plain JavaScript - no
   build tools needed. Bootstrap's own JS is loaded separately.
   ============================================================ */

/* ---- Live clock shown at the top-right of pages ---- */
function startClock() {
    function tick() {
        const now = new Date();
        const time = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        const date = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        const c  = document.getElementById('clock');
        const cd = document.getElementById('clock-date');
        if (c)  c.textContent = time;
        if (cd) cd.textContent = date;
    }
    tick();
    setInterval(tick, 1000);
}

/* ---- A simple month calendar drawn into #calendar ---- */
function buildCalendar(year, month) {
    const el = document.getElementById('calendar');
    if (!el) return;

    const today = new Date();
    year  = year  ?? today.getFullYear();
    month = month ?? today.getMonth();          // 0 = January

    const monthNames = ['January','February','March','April','May','June',
                        'July','August','September','October','November','December'];
    const firstDay = new Date(year, month, 1).getDay();      // 0 = Sunday
    const daysIn   = new Date(year, month + 1, 0).getDate();

    let html = `<div class="d-flex justify-content-between align-items-center mb-2">
                  <button class="btn btn-sm btn-light" data-keep-text title="Previous month" onclick="buildCalendar(${month===0?year-1:year}, ${month===0?11:month-1})">‹</button>
                  <strong>${monthNames[month]} ${year}</strong>
                  <button class="btn btn-sm btn-light" data-keep-text title="Next month" onclick="buildCalendar(${month===11?year+1:year}, ${month===11?0:month+1})">›</button>
                </div>
                <table style="width:100%;text-align:center;font-size:.82rem;border-collapse:collapse;">
                <tr style="color:#94a3b8;">
                  <td>Su</td><td>Mo</td><td>Tu</td><td>We</td><td>Th</td><td>Fr</td><td>Sa</td>
                </tr><tr>`;

    // empty cells before the 1st
    for (let i = 0; i < firstDay; i++) html += '<td></td>';

    const pickable = (typeof window.calendarPick === 'function');
    for (let d = 1; d <= daysIn; d++) {
        const isToday = (d === today.getDate() && month === today.getMonth() && year === today.getFullYear());
        const ds = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
        const cls = 'cal-day' + (isToday ? ' is-today' : '') + (ds === window.calSelected ? ' is-picked' : '') + (pickable ? ' pickable' : '');
        html += `<td class="${cls}" data-date="${ds}">${d}<span class="cal-dot"></span></td>`;
        if ((firstDay + d) % 7 === 0) html += '</tr><tr>';
    }
    html += '</tr></table>';
    el.innerHTML = html;

    // A page can react to a clicked date (window.calendarPick) and mark the
    // days that have something on them (window.calendarMonthLoaded).
    if (pickable) {
        el.querySelectorAll('td.cal-day').forEach(td => td.addEventListener('click', () => {
            window.calSelected = td.dataset.date;
            el.querySelectorAll('td.is-picked').forEach(x => x.classList.remove('is-picked'));
            td.classList.add('is-picked');
            window.calendarPick(td.dataset.date);
        }));
    }
    if (typeof window.calendarMonthLoaded === 'function') window.calendarMonthLoaded(year, month);
}

/* ---- Odontogram: when a tooth is clicked, show its info ---- */
/* Used on odontogram.php and the patient's My Dental Chart.    */
function selectTooth(num, status) {
    const panel = document.getElementById('tooth-panel');
    if (panel) {
        panel.innerHTML =
            `<h5 class="mb-1">Tooth #${num}</h5>
             <p class="text-muted2 mb-2">Current condition</p>
             <span class="badge-pill b-${status === 'Healthy' ? 'active' : 'pending'}">${status}</span>`;
    }

    // If there is an editable status dropdown still on the page, sync it.
    const sel = document.getElementById('edit-status');
    const hid = document.getElementById('edit-tooth');
    if (sel && hid) { sel.value = status; hid.value = num; }

    // On the editable Odontogram page, clicking a tooth counts as "starting
    // to edit" — expand the chart layout so the side panel is visible too.
    if (typeof toggleOdoEdit === 'function' && document.getElementById('odo-right-col')) {
        toggleOdoEdit(true);
    }

    // On the editable Odontogram page, a tooth click opens a small popup
    // with a dropdown + OK button, instead of making the dentist scroll to
    // the side panel to change the condition.
    const modalEl = document.getElementById('toothModal');
    if (modalEl && window.bootstrap) {
        const modalNum   = document.getElementById('modal-tooth-num');
        const modalLabel = document.getElementById('modal-tooth-label');
        const modalSel   = document.getElementById('modal-status');
        if (modalNum)   modalNum.value = num;
        if (modalLabel) modalLabel.textContent = num;
        if (modalSel)   modalSel.value = status;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

/* ---- Odontogram: expand/collapse the chart to make room for editing ---- */
/* By default the chart takes the full width. Clicking "Edit Chart" shrinks */
/* it back down and reveals "Select a Tooth" / "This Visit" / "Chart Summary" */
/* on the right, since those are only needed while actively editing.        */
function toggleOdoEdit(show) {
    const left   = document.getElementById('odo-left-col');
    const right  = document.getElementById('odo-right-col');
    const editBtn = document.getElementById('odo-edit-btn');
    const doneBtn = document.getElementById('odo-done-btn');
    if (!left || !right) return;

    if (show) {
        left.classList.remove('col-lg-12');
        left.classList.add('col-lg-8');
        right.style.display = '';
    } else {
        left.classList.remove('col-lg-8');
        left.classList.add('col-lg-12');
        right.style.display = 'none';
    }
    if (editBtn) editBtn.style.display = show ? 'none' : '';
    if (doneBtn) doneBtn.style.display = show ? '' : 'none';
}

/* ---- Odontogram: show/hide the "This Visit" edit form ---- */
/* Keeps the date/label/notes fields tucked away until the dentist */
/* actually wants to change them, instead of always being on screen. */
function toggleVisitEdit(show) {
    const view = document.getElementById('visit-view');
    const edit = document.getElementById('visit-edit');
    if (!view || !edit) return;
    view.style.display = show ? 'none' : '';
    edit.style.display = show ? '' : 'none';
}

/* ---- Confirm before deleting (used on tables) ---- */
function confirmDelete(message) {
    return confirm(message || 'Are you sure you want to delete this?');
}
