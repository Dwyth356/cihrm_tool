// Housekeeping and folio/billing pages.
async function housekeeping() {
  const rows = await api("/housekeeping");
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">ROOM OPERATIONS</span><h2>Housekeeping</h2><p>Update room readiness; occupied rooms cannot be marked clean or under maintenance.</p></div></div><div class="panel"><div class="table-wrap"><table><thead><tr><th>Room</th><th>Type</th><th>Occupancy</th><th>Current status</th><th>Current guest</th><th>Update</th></tr></thead><tbody>${rows.map((r) => `<tr><td>${esc(r.room_number)}</td><td>${esc(r.room_type)}</td><td>${esc(r.occupancy_status)}</td><td><span class="status ${statusClass(r.housekeeping_status)}">${esc(r.housekeeping_status)}</span></td><td>${esc(r.guest_name || "—")}</td><td><select data-housekeeping-room="${esc(r.room_id)}"><option${r.housekeeping_status === "Clean" ? " selected" : ""}>Clean</option><option${r.housekeeping_status === "Dirty" ? " selected" : ""}>Dirty</option><option${r.housekeeping_status === "Cleaning" ? " selected" : ""}>Cleaning</option><option${r.housekeeping_status === "Maintenance" ? " selected" : ""}>Maintenance</option></select> <button class="btn secondary" data-save-housekeeping="${esc(r.room_id)}">Save</button></td></tr>`).join("")}</tbody></table></div></div>`;
  $("#content").onclick = async (e) => {
    // Use one handler for all room-status save buttons in the table.
    const button = e.target.closest("[data-save-housekeeping]");
    if (!button) return;
    const roomId = button.dataset.saveHousekeeping;
    const status = $(`[data-housekeeping-room="${CSS.escape(roomId)}"]`).value;
    try {
      const result = await api(`/housekeeping/${encodeURIComponent(roomId)}`, {
        method: "PUT",
        body: JSON.stringify({ housekeeping_status: status }),
      });
      toast(result.message);
      await housekeeping();
    } catch (error) {
      toast(error.message);
    }
  };
}
const money = (value) =>
  `₱${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
async function billing() {
  const rows = await api("/folios");
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">GUEST ACCOUNTS</span><h2>Billing and Folio</h2><p>Review room charges, add simulated charges, apply tax/discount, and record simulated payment.</p></div></div>${rows.length ? rows.map((f) => `<div class="panel"><div class="panel-head"><div><h3>Folio · ${esc(f.guest_name)} · Room ${esc(f.room_number)}</h3><p>Reservation ${esc(f.reservation_id)} · ${esc(f.check_in_date)} – ${esc(f.check_out_date)} · ${esc(f.reservation_status)}</p></div><span class="status ${statusClass(f.payment_status)}">${esc(f.reservation_status === "Cancelled" ? "Cancelled" : f.payment_status)}</span></div><div class="table-wrap"><table><thead><tr><th>Description</th><th>Category</th><th>Amount</th></tr></thead><tbody>${f.items.map((i) => `<tr><td>${esc(i.description)}</td><td>${esc(i.category)}</td><td>${money(i.amount)}</td></tr>`).join("") || '<tr><td colspan="3">No items yet.</td></tr>'}</tbody></table></div><p class="role-note">Subtotal: ${money(f.subtotal)} · Discount (${esc(f.discount_type || "None")}): ${f.discount_type === "Percent" ? `${Number(f.discount_amount).toFixed(2)}%` : money(f.discount_amount)} · Tax (${Number(f.tax_rate).toFixed(2)}%): ${money(f.tax_amount)} · <b>Total: ${money(f.grand_total)}</b></p><div class="form-actions">${f.reservation_status === "Cancelled" ? '<span class="role-note">Cancelled reservation; payment is not available.</span>' : f.payment_status !== "Paid" ? `<button class="btn secondary" data-folio-action="charge" data-id="${esc(f.folio_id)}">Add charge</button><button class="btn secondary" data-folio-action="adjust" data-id="${esc(f.folio_id)}">Discount / tax</button><button class="btn primary" data-folio-action="pay" data-id="${esc(f.folio_id)}">Mark paid</button>` : '<span class="role-note">Paid folios are locked.</span>'}</div></div>`).join("") : '<div class="panel empty">No folios yet. A folio is created automatically with each reservation.</div>'}`;
  $("#content").onclick = async (e) => {
    const button = e.target.closest("[data-folio-action]");
    if (!button) return;
    const action = button.dataset.folioAction,
      id = button.dataset.id,
      folio = rows.find((item) => item.folio_id === id);
    try {
      let result;
      // Folio payments are simulated; no payment provider is contacted.
      if (action === "charge") {
        const description = prompt("Charge description:");
        if (!description) return;
        const category = prompt(
          "Category (e.g. Food, Laundry, Service):",
          "Service",
        );
        if (!category) return;
        const amount = prompt("Amount:", "0");
        if (amount === null) return;
        result = await api(`/folios/${encodeURIComponent(id)}/items`, {
          method: "POST",
          body: JSON.stringify({
            description,
            category,
            amount: Number(amount),
          }),
        });
      } else if (action === "adjust") {
        const discount_type = prompt(
          "Discount type: None, Fixed, or Percent",
          folio.discount_type || "None",
        );
        if (discount_type === null) return;
        const normalized = discount_type === "None" ? "" : discount_type;
        const discount_amount = prompt(
          normalized === "Percent"
            ? "Discount percent (0-100):"
            : "Discount amount:",
          String(normalized ? folio.discount_amount : 0),
        );
        if (discount_amount === null) return;
        const tax_rate = prompt(
          "Tax percent (0-100):",
          String(folio.tax_rate || 0),
        );
        if (tax_rate === null) return;
        result = await api(`/folios/${encodeURIComponent(id)}`, {
          method: "PUT",
          body: JSON.stringify({
            discount_type: normalized,
            discount_amount: Number(discount_amount),
            tax_rate: Number(tax_rate),
          }),
        });
      } else {
        if (
          !confirm(
            `Record this folio as paid (${money(folio.grand_total)})? This is a simulation; no real payment will be processed.`,
          )
        )
          return;
        result = await api(`/folios/${encodeURIComponent(id)}/payment`, {
          method: "POST",
          body: JSON.stringify({ payment_status: "Paid" }),
        });
      }
      toast(result.message);
      await billing();
    } catch (error) {
      toast(error.message);
    }
  };
}
