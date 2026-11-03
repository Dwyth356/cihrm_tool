// Account profile, admin user directory, and instructor member records.
async function users() {
  const rows = await api("/admin/users");
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">ACCESS CONTROL</span><h2>User Management</h2><p>Administrator-only account directory. Display IDs are numeric labels; database keys remain unchanged.</p></div><button class="btn primary" id="addUser">＋ Create account</button></div><div class="panel"><div class="table-wrap"><table><thead><tr><th>User ID</th><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th></tr></thead><tbody>${rows.map((u) => `<tr><td>${esc(u.display_id)}</td><td>${esc(u.full_name)}</td><td>${esc(u.email)}</td><td>${esc(u.role)}</td><td>${esc(u.department || "—")}</td><td>${u.is_active ? "Active" : "Inactive"}</td></tr>`).join("")}</tbody></table></div></div>`;
  $("#addUser").onclick = async () => {
    const full_name = prompt("Full name:");
    if (!full_name) return;
    const email = prompt("Email:");
    if (!email) return;
    const password = prompt("Temporary password (at least 8 characters):");
    if (!password) return;
    const role = prompt("Role: student, instructor, or admin", "student");
    try {
      const d = await api("/admin/users", {
        method: "POST",
        body: JSON.stringify({ full_name, email, password, role }),
      });
      toast(d.message);
      renderPage("User Management");
    } catch (e) {
      toast(e.message);
    }
  };
}
function profile() {
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">ACCOUNT</span><h2>My Profile</h2><p>Your signed-in training account.</p></div></div><div class="panel"><h3>${esc(currentUser.full_name)}</h3><p class="role-note">User ID: ${esc(currentUser.display_id ?? "—")}<br>${esc(currentUser.email)}<br>Role: ${esc(currentUser.role)}<br>Department: ${esc(currentUser.department || "Not specified")}<br>Year level: ${esc(currentUser.year_level || "Not specified")}</p>${currentUser.role === "instructor" ? '<button class="btn primary" id="viewMembers">Members</button>' : ""}</div>`;
  $("#viewMembers")?.addEventListener("click", members);
}
async function members() {
  const rows = await api("/instructor/members");
  // Render each related record type with the same empty-state and table structure.
  const details = (title, items, render) =>
    `<h4>${title} (${items.length})</h4>${items.length ? `<div class="table-wrap"><table><tbody>${items.map(render).join("")}</tbody></table></div>` : '<p class="role-note">No records.</p>'}`;
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">INSTRUCTOR ACCESS</span><h2>Student Members</h2><p>Student profiles, assessment submissions, activity, and reservations.</p></div><button class="btn secondary" id="backToProfile">Back to profile</button></div>${rows.length ? rows.map((student) => `<details class="panel"><summary><b>User ID ${esc(student.display_id)} · ${esc(student.full_name)}</b> · ${esc(student.email)} · ${student.is_active ? "Active" : "Inactive"}</summary><p class="role-note">Department: ${esc(student.department || "Not specified")} · Year level: ${esc(student.year_level || "Not specified")} · Joined: ${esc(student.created_at)}</p>${details("Learning records", student.assessments, (row) => `<tr><td>${esc(row.scenario_title)}</td><td>${esc(row.submission_status)}</td><td>${row.score === null ? "—" : `${esc(row.score)} / ${esc(row.target_points)}`}</td><td>${esc(row.submission_text || "No submission")}</td><td>${esc(row.feedback || "")}</td><td>${esc(row.completed_at || "—")}</td></tr>`)}${details("Reservations", student.reservations, (row) => `<tr><td>${esc(row.reservation_id)}</td><td>${esc(row.guest_name)}</td><td>Room ${esc(row.room_number)}</td><td>${esc(row.check_in_date)} – ${esc(row.check_out_date)}</td><td>${esc(row.reservation_status)}</td><td>${esc(row.created_at)}</td></tr>`)}${details("Activity", student.activity, (row) => `<tr><td>${esc(row.timestamp)}</td><td>${esc(row.action)}</td></tr>`)}</details>`).join("") : '<div class="panel empty">No student accounts found.</div>'}`;
  $("#backToProfile").onclick = profile;
}
