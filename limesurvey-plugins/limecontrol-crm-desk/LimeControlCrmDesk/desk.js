// SPDX-License-Identifier: GPL-2.0-or-later
document.getElementById('lcdesk-add')?.addEventListener('click', () => {
    const body = document.getElementById('lcdesk-lines');
    if (body.rows.length >= 100) return;
    const row = body.rows[0].cloneNode(true);
    for (const field of row.querySelectorAll('input')) field.value = '';
    body.append(row);
    row.querySelector('input').focus();
});
