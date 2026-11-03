// Shared state, theme, authentication, navigation, dashboard, and helpers.
// Keep DOM lookups short and store the signed-in user for feature modules.
const $ = (s) => document.querySelector(s);
let currentUser = null;
const themeStorageKey = "cihrm-theme";

// Restore the user's theme when possible; storage can be unavailable in private browsing.
function applyTheme(theme) {
  const isDark = theme === "dark";
  document.documentElement.dataset.theme = isDark ? "dark" : "light";
  $("#themeIcon").textContent = isDark ? "☀" : "☾";
  $("#themeLabel").textContent = isDark ? "Light mode" : "Dark mode";
  $("#themeToggle").setAttribute(
    "aria-label",
    `Switch to ${isDark ? "light" : "dark"} mode`,
  );
  $("#themeToggle").title = `Switch to ${isDark ? "light" : "dark"} mode`;
}
let savedTheme = "light";
try {
  savedTheme =
    localStorage.getItem(themeStorageKey) === "dark" ? "dark" : "light";
} catch (error) {
  console.warn("Theme preference could not be loaded.", error);
}
applyTheme(savedTheme);
$("#themeToggle").addEventListener("click", () => {
  const nextTheme =
    document.documentElement.dataset.theme === "dark" ? "light" : "dark";
  applyTheme(nextTheme);
  try {
    localStorage.setItem(themeStorageKey, nextTheme);
  } catch (error) {
    console.warn("Theme preference could not be saved.", error);
  }
});

// Each role gets its own navigation list; page content is routed below.
const navByRole = {
  student: [
    ["Dashboard", "▦"],
    ["Room Availability", "▣"],
    ["Guest Management", "♙"],
    ["Reservations", "▤"],
    ["Check-in / Check-out", "⇄"],
    ["Billing and Folio", "₱"],
    ["Housekeeping", "✧"],
    ["Learning Scenarios", "◇"],
    ["Assessments", "✓"],
    ["My Learning Progress", "◷"],
    ["Activity History", "≡"],
    ["My Profile", "◎"],
  ],
  instructor: [
    ["Dashboard", "▦"],
    ["Reservations", "▤"],
    ["Check-in / Check-out", "⇄"],
    ["Billing and Folio", "₱"],
    ["Housekeeping", "✧"],
    ["Student Management", "♙"],
    ["Learning Scenarios", "◇"],
    ["Assessment Management", "✓"],
    ["Student Submissions", "▤"],
    ["Performance Monitoring", "◷"],
    ["Activity & Reports", "▧"],
    ["My Profile", "◎"],
  ],
  admin: [
    ["Dashboard", "▦"],
    ["User Management", "♙"],
    ["Room Management", "▣"],
    ["Guest Management", "♧"],
    ["Reservation Management", "▤"],
    ["Check-in / Check-out", "⇄"],
    ["Billing and Folio", "₱"],
    ["Housekeeping", "✧"],
    ["Learning Scenarios", "◇"],
    ["Assessment Management", "✓"],
    ["System Settings", "⚙"],
    ["Activity & Reports", "▧"],
    ["My Profile", "◎"],
  ],
};

// Send API requests to the local PHP server in development and the same host in production.
const api = async (url, options = {}) => {
  const isLocalhost = ["localhost", "127.0.0.1"].includes(
    window.location.hostname,
  );
  const apiBase =
    isLocalhost && window.location.port !== "3000"
      ? "http://127.0.0.1:3000/backend/api.php"
      : new URL("../backend/api.php", window.location.href);
  const endpoint = new URL(apiBase);
  endpoint.searchParams.set("route", url);
  const res = await fetch(endpoint, {
    credentials: "include",
    headers: { "Content-Type": "application/json", ...(options.headers || {}) },
    ...options,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.message || "Request failed");
  return data;
};

// Shared UI helpers used by all feature modules.
function toast(msg) {
  const t = $("#toast");
  t.textContent = msg;
  t.classList.add("show");
  setTimeout(() => t.classList.remove("show"), 2600);
}
function showApp(user) {
  currentUser = user;
  $("#loginView").classList.add("hidden");
  $("#appView").classList.remove("hidden");
  $("#topName").textContent = user.full_name;
  $("#topRole").textContent = user.role;
  $("#avatar").textContent = user.full_name[0].toUpperCase();
  $("#sideUser").textContent = `${user.full_name} · ${user.role}`;
  $(".topbar").appendChild($("#themeToggle"));
  $("#navigation").innerHTML = navByRole[user.role]
    .map(
      ([n, i]) =>
        `<button class="nav-link" data-page="${n}"><span>${i}</span><span>${n}</span></button>`,
    )
    .join("");
  $("#navigation").addEventListener("click", (e) => {
    const b = e.target.closest("[data-page]");
    if (b) renderPage(b.dataset.page);
  });
  renderPage("Dashboard");
}
function showLogin() {
  currentUser = null;
  $("#appView").classList.add("hidden");
  $("#loginView").classList.remove("hidden");
  document.body.prepend($("#themeToggle"));
}
function setAuthMode(mode) {
  const signup = mode === "signup";
  $("#loginForm").classList.toggle("hidden", signup);
  $("#signupForm").classList.toggle("hidden", !signup);
  $("#authHeading").textContent = signup
    ? "Create your account"
    : "Welcome back";
  $("#authDescription").textContent = signup
    ? "Sign up as a student to access the training workspace."
    : "Sign in to your hotel operations training workspace.";
  $("#authSwitchPrompt").textContent = signup
    ? "Already registered?"
    : "New here?";
  $("#authSwitch").textContent = signup
    ? "Sign in"
    : "Create a student account";
  $("#loginError").textContent = "";
  $("#signupError").textContent = "";
}

// Authentication forms call the PHP API and keep server messages next to each form.
$("#authSwitch").addEventListener("click", () =>
  setAuthMode(
    $("#signupForm").classList.contains("hidden") ? "signup" : "login",
  ),
);
$("#loginForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  $("#loginError").textContent = "";
  try {
    const d = await api("/auth/login", {
      method: "POST",
      body: JSON.stringify({
        email: $("#email").value,
        password: $("#password").value,
      }),
    });
    showApp(d.user);
  } catch (err) {
    $("#loginError").textContent =
      err instanceof TypeError
        ? "Cannot reach the C.I.H.R.M. server. Start the PHP server and open http://127.0.0.1:3000."
        : err.message;
  }
});
$("#signupForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  $("#signupError").textContent = "";
  const password = $("#signupPassword").value;
  if (password !== $("#signupConfirmPassword").value) {
    $("#signupError").textContent = "Passwords do not match.";
    return;
  }
  try {
    const data = await api("/auth/register", {
      method: "POST",
      body: JSON.stringify({
        full_name: $("#signupName").value,
        email: $("#signupEmail").value,
        password,
      }),
    });
    showApp(data.user);
  } catch (err) {
    $("#signupError").textContent =
      err instanceof TypeError
        ? "Cannot reach the C.I.H.R.M. server. Start the PHP server and open http://127.0.0.1:3000."
        : err.message;
  }
});

$("#togglePassword").onclick = () => {
  const p = $("#password");
  p.type = p.type === "password" ? "text" : "password";
  $("#togglePassword").textContent = p.type === "password" ? "Show" : "Hide";
};
$("#logoutBtn").onclick = async () => {
  try {
    await api("/auth/logout", { method: "POST" });
  } finally {
    showLogin();
  }
};
$("#menuBtn").onclick = () => $("#sidebar").classList.toggle("open");

// Dispatch navigation labels to the matching feature-page renderer.
async function renderPage(page) {
  $("#pageHeading").textContent = page;
  document
    .querySelectorAll(".nav-link")
    .forEach((b) => b.classList.toggle("active", b.dataset.page === page));
  $("#sidebar").classList.remove("open");
  const content = $("#content");
  content.innerHTML = '<div class="panel empty">Loading workspace…</div>';
  try {
    if (page === "Dashboard") return await dashboard();
    if (page === "Check-in / Check-out") return await frontDesk();
    if (page === "Billing and Folio") return await billing();
    if (page === "Housekeeping") return await housekeeping();
    if (page === "My Learning Progress") return await learningProgress();
    if (page.includes("Room")) return await rooms(page);
    if (page.includes("Guest")) return await guests();
    if (page.includes("Reservation")) return await reservations();
    if (page === "Activity & Reports") return await reports();
    if (page.includes("Activity")) return await activity(page);
    if (page.includes("Scenario")) return await scenarios();
    if (
      page.includes("Assessment") ||
      page.includes("Submission") ||
      page.includes("Performance")
    )
      return await assessments();
    if (page === "Student Management") return await members();
    if (page === "User Management") return await users();
    if (page === "My Profile") return profile();
    content.innerHTML = `<div class="welcome"><div><span class="eyebrow">TRAINING WORKSPACE</span><h2>${page}</h2><p>This module is part of the C.I.H.R.M. learning workflow.</p></div></div><div class="panel"><h3>${page}</h3><p class="role-note">This module is not available in this build.</p></div>`;
  } catch (err) {
    content.innerHTML = `<div class="panel"><p class="error">${err.message}</p></div>`;
  }
}

// The dashboard chooses its summary cards based on the current user's role.
async function dashboard() {
  const d = await api("/dashboard");
  const isAdmin = currentUser.role === "admin",
    isInst = currentUser.role === "instructor";
  const stats = isAdmin
    ? [
        ["Total Users", d.users.totalUsers],
        ["Students", d.users.students],
        ["Instructors", d.users.instructors],
        ["Total Rooms", d.rooms.totalRooms],
        ["Available Rooms", d.rooms.availableRooms || 0],
        ["Occupied Rooms", d.rooms.occupiedRooms || 0],
        ["Reservations", d.reservations.totalReservations],
        ["Pending Reservations", d.reservations.pendingReservations || 0],
      ]
    : isInst
      ? [
          ["Total Students", d.users.students],
          ["Learning Scenarios", (await api("/scenarios")).length],
          ["Assessments", (await api("/assessments")).length],
          ["Active Rooms", d.rooms.availableRooms || 0],
        ]
      : [
          ["Total Reservations", d.reservations.totalReservations],
          ["Available Rooms", d.rooms.availableRooms || 0],
          ["Occupied Rooms", d.rooms.occupiedRooms || 0],
          ["Pending Reservations", d.reservations.pendingReservations || 0],
          ["Completed Check-ins", d.reservations.checkedIn || 0],
          ["Completed Check-outs", d.reservations.checkedOut || 0],
        ];
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">OVERVIEW / ${currentUser.role.toUpperCase()}</span><h2>Good day, ${currentUser.full_name.split(" ")[0]}.</h2><p>Here is your hotel operations learning overview.</p></div><span class="pill">● Training environment</span></div><div class="stats">${stats.map(([l, v]) => `<div class="stat"><span class="label">${l}</span><strong>${v ?? 0}</strong><small>Live database data</small></div>`).join("")}</div><div class="grid-two"><div class="panel"><div class="panel-head"><div><h3>Recent reservations</h3><p>Latest reservation activity</p></div><span class="pill">Updated live</span></div>${reservationTable(d.recent)}</div><div class="panel"><div class="panel-head"><div><h3>Quick actions</h3><p>Common front-desk tasks</p></div></div><div class="quick-grid"><button class="quick" onclick="renderPage('Guest Management')">＋ Register guest<span>Create a guest profile</span></button><button class="quick" onclick="renderPage('Reservations')">▤ New reservation<span>Book an available room</span></button><button class="quick" onclick="renderPage('Room Availability')">▣ View rooms<span>Check room readiness</span></button><button class="quick" onclick="renderPage('Learning Scenarios')">◇ Practice scenario<span>Build hospitality skills</span></button></div></div></div>`;
}
function reservationTable(rows) {
  if (!rows.length)
    return '<div class="empty">No reservations recorded yet.</div>';
  return `<div class="table-wrap"><table><thead><tr><th>Guest</th><th>Room</th><th>Stay</th><th>Status</th></tr></thead><tbody>${rows.map((r) => `<tr><td>${esc(r.guest_name)}</td><td>${esc(r.room_number)}</td><td>${String(r.check_in_date).slice(0, 10)} – ${String(r.check_out_date).slice(0, 10)}</td><td><span class="status ${statusClass(r.reservation_status)}">${r.reservation_status}</span></td></tr>`).join("")}</tbody></table></div>`;
}
function esc(s) {
  // Escape database text before inserting it into HTML templates.
  return String(s ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ],
  );
}
function statusClass(s) {
  return String(s || "")
    .toLowerCase()
    .replace(/[^a-z]/g, "");
}
