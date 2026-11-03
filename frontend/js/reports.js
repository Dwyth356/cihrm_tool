// Combined instructor/admin learning and activity reports.
async function reports() {
  const data = await api("/reports"),
    summary = data.summary;
  const stats = [
    ["Students", summary.students],
    ["Scenarios", summary.scenarios],
    ["Submissions", summary.submissions],
    ["Pending reviews", summary.pending_reviews || 0],
    ["Graded", summary.graded || 0],
    [
      "Average grade",
      summary.average_percent === null ? "—" : `${summary.average_percent}%`,
    ],
  ];
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">INSTRUCTOR OVERVIEW</span><h2>Activity &amp; Reports</h2><p>Student learning outcomes and the latest system activity.</p></div><button class="btn primary" id="exportReports">Export CSV</button></div><div class="stats">${stats.map(([label, value]) => `<div class="stat"><span class="label">${esc(label)}</span><strong>${esc(value ?? 0)}</strong></div>`).join("")}</div><div class="panel"><div class="panel-head"><div><h3>Learning report</h3><p>Scenario submissions and assessment results</p></div></div>${data.assessments.length ? `<div class="table-wrap"><table><thead><tr><th>Student</th><th>Email</th><th>Scenario</th><th>Status</th><th>Score</th><th>Completed</th></tr></thead><tbody>${data.assessments.map((row) => `<tr><td>${esc(row.student_name)}</td><td>${esc(row.email)}</td><td>${esc(row.scenario_title)}</td><td>${esc(row.submission_status)}</td><td>${row.score === null ? "—" : `${esc(row.score)} / ${esc(row.target_points)}`}</td><td>${esc(row.completed_at || "—")}</td></tr>`).join("")}</tbody></table></div>` : '<div class="empty">No student submissions to report yet.</div>'}</div><div class="panel"><div class="panel-head"><div><h3>Activity log</h3><p>Most recent 200 recorded actions</p></div></div>${data.activity.length ? `<div class="table-wrap"><table><thead><tr><th>User</th><th>Action</th><th>Timestamp</th></tr></thead><tbody>${data.activity.map((row) => `<tr><td>${esc(row.full_name)}</td><td>${esc(row.action)}</td><td>${esc(row.timestamp)}</td></tr>`).join("")}</tbody></table></div>` : '<div class="empty">No activity recorded yet.</div>'}</div>`;
  $("#exportReports").onclick = () => {
    const rows = [
      [
        "Report",
        "Student",
        "Email",
        "Scenario",
        "Status",
        "Score",
        "Target points",
        "Completed",
        "Activity user",
        "Action",
        "Timestamp",
      ],
    ];
    data.assessments.forEach((row) =>
      rows.push([
        "Learning",
        row.student_name,
        row.email,
        row.scenario_title,
        row.submission_status,
        row.score ?? "",
        row.target_points,
        row.completed_at ?? "",
        "",
        "",
        "",
      ]),
    );
    data.activity.forEach((row) =>
      rows.push([
        "Activity",
        "",
        "",
        "",
        "",
        "",
        "",
        "",
        row.full_name,
        row.action,
        row.timestamp,
      ]),
    );
    const csv = rows
      .map((row) =>
        row
          .map((value) => {
            let cell = String(value ?? "");
            // Prevent spreadsheet programs from treating exported text as formulas.
            if (/^[=+\-@\t\r]/.test(cell)) cell = `'${cell}`;
            return `"${cell.replace(/"/g, '""')}"`;
          })
          .join(","),
      )
      .join("\r\n");
    const link = document.createElement("a"),
      url = URL.createObjectURL(
        new Blob(["\uFEFF", csv], { type: "text/csv;charset=utf-8" }),
      );
    link.href = url;
    link.download = "cihrm-activity-and-reports.csv";
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  };
}
