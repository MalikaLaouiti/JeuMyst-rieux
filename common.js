// Thème partagé (persistant, localStorage)
const applyTheme = () =>
  document.body.classList.toggle("dark-mode", localStorage.getItem("theme") === "sombre");
const toggleTheme = () => {
  localStorage.setItem("theme", localStorage.getItem("theme") === "sombre" ? "clair" : "sombre");
  applyTheme();
};
applyTheme();
document.getElementById("theme").onclick = toggleTheme;

// Où va chaque donnée :
//  - cookie PHPSESSID (serveur) : qui est connecté (vérité, HttpOnly)
//  - sessionStorage : pseudo affiché dans CET onglet (évite une requête, vidé à la déconnexion)
//  - localStorage   : préférences durables : theme, lastPseudo (pré-remplit la connexion), scores (cache du classement)
const Session = {
  start(pseudo) {
    sessionStorage.setItem("pseudo", pseudo);
    localStorage.setItem("lastPseudo", pseudo);
  },
  pseudo: () => sessionStorage.getItem("pseudo"),
  async end() {
    await api("logout");
    sessionStorage.clear();
  },
};

// Appel du serveur : sérialisation JSON (stringify -> PHP json_decode -> json_encode -> parse).
async function api(action, data = {}) {
  const r = await fetch("api.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action, ...data }),
  });
  const j = await r.json().catch(() => {
    throw new Error("Réponse vide : la page doit être ouverte via PHP (php -S localhost:8000), pas Live Server ni file://");
  });
  if (!r.ok) throw Object.assign(new Error(j.error || "Erreur"), { status: r.status });
  return j;
}
