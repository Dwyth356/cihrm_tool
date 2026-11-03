// Room, guest, reservation, check-in/out, and personal activity pages.
async function rooms(page) {
  const data = await api("/rooms");
  const canEdit = currentUser.role === "admin";
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">ROOM INVENTORY</span><h2>${page}</h2><p>Room readiness and occupancy overview.</p></div>${canEdit ? '<button class="btn primary" id="addRoom">＋ Add room</button>' : ""}</div><div class="panel"><div class="toolbar"><input id="roomSearch" placeholder="Search room number or type…"><select id="roomFilter"><option value="">All housekeeping statuses</option><option>Clean</option><option>Dirty</option><option>Cleaning</option><option>Maintenance</option></select></div><div id="roomTable"></div></div>`;
  const draw = () => {
    // Apply the search text and housekeeping filter together.
    const q = $("#roomSearch").value.toLowerCase(),
      f = $("#roomFilter").value;
    const list = data.filter(
      (r) =>
        (r.room_number + r.room_type).toLowerCase().includes(q) &&
        (!f || r.housekeeping_status === f),
    );
    $("#roomTable").innerHTML =
      `<div class="table-wrap"><table><thead><tr><th>Room</th><th>Type</th><th>Floor</th><th>Rate / night</th><th>Capacity</th><th>Housekeeping</th><th>Occupancy</th>${canEdit ? "<th>Action</th>" : ""}</tr></thead><tbody>${list.map((r) => `<tr><td><b>${esc(r.room_number)}</b></td><td>${esc(r.room_type)}</td><td>${r.floor}</td><td>₱${Number(r.rate).toLocaleString()}</td><td>${r.max_occupancy}</td><td>${esc(r.housekeeping_status)}</td><td><span class="status ${statusClass(r.occupancy_status)}">${r.occupancy_status}</span></td>${canEdit ? `<td><button class="btn secondary" onclick="editRoom('${r.room_id}')">Edit</button></td>` : ""}</tr>`).join("")}</tbody></table></div>` ||
      '<div class="empty">No rooms found.</div>';
  };
  $("#roomSearch").oninput = draw;
  $("#roomFilter").onchange = draw;
  draw();
  if (canEdit) $("#addRoom").onclick = () => roomForm();
  window.editRoom = (id) => {
    const r = data.find((x) => x.room_id === id);
    roomForm(r);
  };
}
function roomForm(r = {}) {
  const modal = prompt(
    r.room_id
      ? "Edit housekeeping status (Clean, Dirty, Cleaning, Maintenance):"
      : "Enter room number, room type, floor, rate, max occupancy separated by commas:",
    r.room_id ? r.housekeeping_status : "",
  );
  if (modal === null) return;
  if (r.room_id) {
    const status = modal.trim();
    api("/rooms/" + r.room_id, {
      method: "PUT",
      body: JSON.stringify({ ...r, housekeeping_status: status }),
    })
      .then((x) => {
        toast(x.message);
        renderPage("Room Management");
      })
      .catch((e) => toast(e.message));
  } else {
    const [room_number, room_type, floor, rate, max_occupancy] = modal
      .split(",")
      .map((x) => x.trim());
    api("/rooms", {
      method: "POST",
      body: JSON.stringify({
        room_number,
        room_type,
        floor,
        rate,
        max_occupancy,
      }),
    })
      .then((x) => {
        toast(x.message);
        renderPage("Room Management");
      })
      .catch((e) => toast(e.message));
  }
}
async function guests() {
  const rows = await api("/guests");
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">GUEST RELATIONS</span><h2>Guest Management</h2><p>Maintain guest profiles for reservation practice.</p></div><button class="btn primary" id="newGuest">＋ Register guest</button></div><div class="panel"><div class="toolbar"><input id="guestSearch" placeholder="Search guest name or phone…"></div><div id="guestTable"></div></div>`;
  const draw = () => {
    const q = $("#guestSearch").value.toLowerCase(),
      list = rows.filter((g) =>
        (g.full_name + g.phone).toLowerCase().includes(q),
      );
    $("#guestTable").innerHTML =
      `<div class="table-wrap"><table><thead><tr><th>Guest name</th><th>Email</th><th>Phone</th><th>Address</th><th>ID type</th></tr></thead><tbody>${list.map((g) => `<tr><td><b>${esc(g.full_name)}</b></td><td>${esc(g.email || "—")}</td><td>${esc(g.phone)}</td><td>${esc(g.address || "—")}</td><td>${esc(g.id_type || "—")}</td></tr>`).join("")}</tbody></table></div>`;
  };
  $("#guestSearch").oninput = draw;
  draw();
  $("#newGuest").onclick = async () => {
    const full_name = prompt("Guest full name:");
    if (!full_name) return;
    const phone = prompt("Phone number:");
    if (!phone) return;
    const email = prompt("Email (optional):") || "";
    const address = prompt("Address (optional):") || "";
    try {
      const d = await api("/guests", {
        method: "POST",
        body: JSON.stringify({ full_name, phone, email, address }),
      });
      toast(d.message);
      renderPage("Guest Management");
    } catch (e) {
      toast(e.message);
    }
  };
}
async function reservations() {
  // Load the data needed by the form together to avoid serial network requests.
  const [rows, guestsList, roomsList] = await Promise.all([
    api("/reservations"),
    api("/guests"),
    api("/rooms"),
  ]);
  const guestsOptions = guestsList
    .map(
      (g) =>
        `<option value="${esc(g.guest_id)}">${esc(g.full_name)} · ${esc(g.phone)}</option>`,
    )
    .join("");
  const roomsOptions = roomsList
    // Only clean rooms can be selected for a new reservation.
    .filter((r) => r.housekeeping_status === "Clean")
    .map(
      (r) =>
        `<option value="${esc(r.room_id)}">Room ${esc(r.room_number)} · ${esc(r.room_type)} · ${Number(r.rate).toLocaleString()} / night · max ${r.max_occupancy}</option>`,
    )
    .join("");
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">FRONT DESK</span><h2>Reservation Management</h2><p>Create and manage reservations; date overlaps and room capacity are checked.</p></div></div><div class="panel"><h3>New reservation</h3><form id="reservationForm" class="form-grid"><label>Guest<select name="guest_id" required><option value="">Select guest</option>${guestsOptions}</select></label><label>Room<select name="room_id" required><option value="">Select clean room</option>${roomsOptions}</select></label><label>Check-in date<input name="check_in_date" type="date" required></label><label>Check-out date<input name="check_out_date" type="date" required></label><label>Number of guests<input name="pax" type="number" min="1" value="1" required></label><label>Booking type<select name="booking_type"><option>Advance</option><option>Walk-in</option></select></label><div class="form-actions"><button class="btn primary" type="submit">Create reservation</button></div><p id="reservationError" class="error"></p></form></div><div class="panel"><h3>Reservations</h3><div id="reservationList"></div></div>`;
  const now = new Date(),
    today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
  $('#reservationForm [name="check_in_date"]').min = today;
  $('#reservationForm [name="check_out_date"]').min = today;
  drawReservations(rows, "reservationList");
  $("#reservationForm").onsubmit = async (e) => {
    e.preventDefault();
    const form = new FormData(e.currentTarget),
      payload = Object.fromEntries(form.entries());
    payload.pax = Number(payload.pax);
    try {
      const result = await api("/reservations", {
        method: "POST",
        body: JSON.stringify(payload),
      });
      toast(result.message);
      await reservations();
    } catch (error) {
      $("#reservationError").textContent = error.message;
    }
  };
  $("#reservationList").onclick = handleReservationAction;
}
async function frontDesk() {
  const rows = await api("/reservations");
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">FRONT DESK</span><h2>Check-in / Check-out</h2><p>Check in arriving guests and check out current occupants.</p></div></div><div class="panel"><div id="frontDeskList"></div></div>`;
  drawReservations(rows, "frontDeskList");
  $("#frontDeskList").onclick = handleReservationAction;
}
function drawReservations(rows, containerId) {
  const container = $(`#${containerId}`);
  if (!rows.length) {
    container.innerHTML =
      '<div class="empty">No reservations recorded yet.</div>';
    return;
  }
  container.innerHTML = `<div class="table-wrap"><table><thead><tr><th>Guest</th><th>Room</th><th>Stay</th><th>Guests</th><th>Status</th><th>Actions</th></tr></thead><tbody>${rows
    .map((r) => {
      // Available actions depend on the reservation's current state.
      let action = "";
      if (["Pending", "Confirmed"].includes(r.reservation_status)) {
        action = `<button class="btn secondary" data-reservation-action="check-in" data-id="${esc(r.reservation_id)}">Check in</button> <button class="btn secondary" data-reservation-action="cancel" data-id="${esc(r.reservation_id)}">Cancel</button>`;
      } else if (r.reservation_status === "Checked-in") {
        action = `<button class="btn primary" data-reservation-action="check-out" data-id="${esc(r.reservation_id)}">Check out</button>`;
      } else action = "—";
      return `<tr><td><b>${esc(r.guest_name)}</b></td><td>${esc(r.room_number)} · ${esc(r.room_type)}</td><td>${esc(String(r.check_in_date).slice(0, 10))} – ${esc(String(r.check_out_date).slice(0, 10))}</td><td>${r.pax}</td><td><span class="status ${statusClass(r.reservation_status)}">${esc(r.reservation_status)}</span></td><td>${action}</td></tr>`;
    })
    .join("")}</tbody></table></div>`;
}
async function handleReservationAction(event) {
  const button = event.target.closest("[data-reservation-action]");
  if (!button) return;
  const action = button.dataset.reservationAction,
    id = button.dataset.id;
  if (action === "cancel" && !confirm("Cancel this reservation?")) return;
  try {
    const result = await api(
      `/reservations/${encodeURIComponent(id)}/${action}`,
      { method: "POST", body: "{}" },
    );
    toast(result.message);
    if ($("#pageHeading").textContent === "Check-in / Check-out")
      await frontDesk();
    else await reservations();
  } catch (error) {
    toast(error.message);
  }
}
async function activity(page) {
  const rows = await api("/activity");
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">AUDIT TRAIL</span><h2>${page}</h2><p>Your recorded actions in the training system.</p></div></div><div class="panel"><div class="table-wrap"><table><thead><tr><th>Action</th><th>Timestamp</th></tr></thead><tbody>${rows.map((r) => `<tr><td>${esc(r.action)}</td><td>${esc(r.timestamp)}</td></tr>`).join("")}</tbody></table></div></div>`;
}
