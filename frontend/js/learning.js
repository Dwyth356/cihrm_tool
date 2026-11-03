// Learning scenarios, submissions, grading, and student progress.
async function scenarios() {
  const rows = await api("/scenarios");
  const canCreate = ["instructor", "admin"].includes(currentUser.role);
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">EXPERIENTIAL LEARNING</span><h2>Learning Scenarios</h2><p>Complete a written response for instructor assessment.</p></div>${canCreate ? '<button class="btn primary" id="addScenario">＋ Add scenario</button>' : ""}</div><div class="stats">${rows
      .map((s) => {
        // A scenario's next action depends on its assessment status.
        const status = s.submission_status || "Not Started";
        let action = "";
        if (status === "In Progress")
          action = `<form class="scenario-submit" data-scenario-form="${esc(s.scenario_id)}"><label>Your response<textarea name="submission_text" rows="5" maxlength="20000" required>${esc(s.submission_text || "")}</textarea></label><button class="btn primary" type="submit">Submit for review</button></form>`;
        else if (status === "Submitted")
          action = `<p class="role-note">Submitted for instructor review.</p>${s.score === null ? `<button class="btn secondary" data-delete-submission="${esc(s.assessment_id)}">Delete submission</button>` : ""}`;
        else
          action = `<button class="btn primary" data-scenario-start="${esc(s.scenario_id)}">${status === "Graded" ? "Try again" : "Start scenario"}</button>`;
        return `<article class="stat scenario-card"><span class="label">${esc(s.difficulty)} · ${s.target_points} points · ${esc(status)}</span><strong style="font-size:18px">${esc(s.title)}</strong><small>${esc(s.learning_objectives || "Practical hotel operations")}</small><p class="role-note">${esc(s.description || "")}<br><b>Instructions:</b> ${esc(s.instructions || "")}</p>${s.score !== null ? `<p><b>Score:</b> ${esc(s.score)} / ${esc(s.target_points)}</p><p class="role-note">${esc(s.feedback || "")}</p>` : ""}${action}</article>`;
      })
      .join("")}</div>`;
  $("#content").onclick = async (e) => {
    // Scenario creation and submission actions share this delegated click handler.
    if (e.target.closest("#addScenario")) {
      const title = prompt("Scenario title:");
      if (title === null) return;
      const difficulty = prompt(
        "Difficulty: Beginner, Intermediate, or Advanced",
        "Beginner",
      );
      if (difficulty === null) return;
      const learning_objectives = prompt("Learning objectives:");
      if (learning_objectives === null) return;
      const target_points = prompt("Target points (1-10,000):", "100");
      if (target_points === null) return;
      const description = prompt("Scenario description:");
      if (description === null) return;
      const instructions = prompt("Student instructions:");
      if (instructions === null) return;
      try {
        const result = await api("/scenarios", {
          method: "POST",
          body: JSON.stringify({
            title,
            difficulty,
            learning_objectives,
            target_points: Number(target_points),
            description,
            instructions,
          }),
        });
        toast(result.message);
        await scenarios();
      } catch (error) {
        toast(error.message);
      }
      return;
    }
    const deleteButton = e.target.closest("[data-delete-submission]");
    if (deleteButton) {
      if (!confirm("Delete this ungraded submission? This cannot be undone."))
        return;
      try {
        const result = await api(
          `/assessments/${deleteButton.dataset.deleteSubmission}`,
          { method: "DELETE" },
        );
        toast(result.message);
        await scenarios();
      } catch (error) {
        toast(error.message);
      }
      return;
    }
    const button = e.target.closest("[data-scenario-start]");
    if (!button) return;
    try {
      const result = await api(
        `/scenarios/${encodeURIComponent(button.dataset.scenarioStart)}/start`,
        { method: "POST", body: "{}" },
      );
      toast(result.message);
      await scenarios();
    } catch (error) {
      toast(error.message);
    }
  };
  $("#content").onsubmit = async (e) => {
    const form = e.target.closest("[data-scenario-form]");
    if (!form) return;
    e.preventDefault();
    const submission_text = new FormData(form).get("submission_text");
    try {
      const result = await api(
        `/scenarios/${encodeURIComponent(form.dataset.scenarioForm)}/submit`,
        { method: "POST", body: JSON.stringify({ submission_text }) },
      );
      toast(result.message);
      await scenarios();
    } catch (error) {
      toast(error.message);
    }
  };
}
async function assessments() {
  const rows = await api("/assessments"),
    canGrade = ["admin", "instructor"].includes(currentUser.role);
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">STUDENT DEVELOPMENT</span><h2>Assessments & Submissions</h2><p>${canGrade ? "Review student work and provide a score and feedback." : "Track submitted work and instructor feedback."}</p></div></div><div class="panel">${rows.length ? `<div class="table-wrap"><table><thead><tr><th>Student</th><th>Scenario</th><th>Submission</th><th>Score</th><th>Status</th><th>Feedback / Action</th></tr></thead><tbody>${rows.map((a) => `<tr><td>${esc(a.student_name)}</td><td>${esc(a.scenario_title)}</td><td>${esc(a.submission_text || "—")}</td><td>${a.score === null ? "—" : `${esc(a.score)} / ${esc(a.target_points)}`}</td><td>${esc(a.submission_status)}</td><td>${a.submission_status === "Submitted" ? `${canGrade ? `<button class="btn primary" data-grade-assessment="${a.assessment_id}" data-target="${a.target_points}">Grade</button> ` : ""}<button class="btn secondary" data-delete-assessment="${a.assessment_id}">Delete</button>` : esc(a.feedback || "—")}</td></tr>`).join("")}</tbody></table></div>` : '<div class="empty">No assessment submissions yet.</div>'}</div>`;
  $("#content").onclick = async (e) => {
    const deleteButton = e.target.closest("[data-delete-assessment]");
    if (deleteButton) {
      if (
        !confirm(
          "Delete this ungraded submission? Graded work cannot be deleted.",
        )
      )
        return;
      try {
        const result = await api(
          `/assessments/${deleteButton.dataset.deleteAssessment}`,
          { method: "DELETE" },
        );
        toast(result.message);
        await assessments();
      } catch (error) {
        toast(error.message);
      }
      return;
    }
    const button = e.target.closest("[data-grade-assessment]");
    if (!button) return;
    const score = prompt(`Score from 0 to ${button.dataset.target}:`);
    if (score === null) return;
    const feedback = prompt("Feedback for the student:");
    if (feedback === null) return;
    try {
      const result = await api(
        `/assessments/${button.dataset.gradeAssessment}/grade`,
        {
          method: "POST",
          body: JSON.stringify({ score: Number(score), feedback }),
        },
      );
      toast(result.message);
      await assessments();
    } catch (error) {
      toast(error.message);
    }
  };
}
async function learningProgress() {
  const progress = await api("/progress");
  $("#content").innerHTML =
    `<div class="welcome"><div><span class="eyebrow">STUDENT DEVELOPMENT</span><h2>My Learning Progress</h2><p>Your submitted and graded scenario work.</p></div></div><div class="stats"><div class="stat"><span class="label">Graded scenarios</span><strong>${progress.completed} / ${progress.total_scenarios}</strong></div><div class="stat"><span class="label">Points earned</span><strong>${progress.points_earned} / ${progress.points_possible}</strong></div><div class="stat"><span class="label">Average score</span><strong>${progress.average_percent === null ? "—" : `${progress.average_percent}%`}</strong></div></div><div class="panel"><div class="table-wrap"><table><thead><tr><th>Scenario</th><th>Difficulty</th><th>Status</th><th>Score</th><th>Completed</th><th>Feedback</th></tr></thead><tbody>${progress.scenarios.map((s) => `<tr><td>${esc(s.title)}</td><td>${esc(s.difficulty)}</td><td>${esc(s.submission_status)}</td><td>${s.score === null ? "—" : `${esc(s.score)} / ${esc(s.target_points)}`}</td><td>${esc(s.completed_at || "—")}</td><td>${esc(s.feedback || "—")}</td></tr>`).join("")}</tbody></table></div></div>`;
}
