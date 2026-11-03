// Resolve the existing session only after shared and feature scripts are loaded.
(async () => {
  try {
    const d = await api("/auth/me");
    showApp(d.user);
  } catch (_) {
    showLogin();
  }
})();
