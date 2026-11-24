const $ = (id) => document.getElementById(id);
const HINT = { too_low: "Trop petit ⬆", too_high: "Trop grand ⬇", correct: "Bravo ! 🎉" };

let last = { game: null };
let wantLobby = false; // le joueur a cliqué « Nouvelle partie » après une partie terminée

const toLogin = () => location.replace("login.html");
const guard = (e) => (e.status === 401 ? toLogin() : ($("lobbyerr").textContent = $("msg").value = e.message));

function render(s) {
  last = s;
  const g = s.game;
  const lobby = !g || (g.status === "finished" && wantLobby);
  $("lobby").hidden = !lobby;
  $("play").hidden = lobby;
  if (lobby) return;

  const me = g.players.find((p) => p.me);
  const left = g.max_tries - me.tries;

  $("essai").value = left;
  $("rep").value = g.secret ?? "";
  $("check").disabled = g.status !== "playing" || left <= 0;
  $("leave").hidden = g.status !== "waiting";
  $("again").hidden = g.status !== "finished";
  $("gcode").textContent = g.status === "waiting" ? g.code : "";

  const best = Math.max(...g.players.map((p) => p.score));
  $("status").textContent =
    g.status === "waiting" ? `⏳ Partagez ce code — ${g.players.length}/${g.max_players} joueurs` :
    g.status === "playing" ? (left > 0 ? "À vous de jouer !" : "Plus d'essais, attendez les autres…") :
    best === 0 ? "Personne n'a trouvé." :
    g.players.find((p) => p.score === best).me ? "🏆 Vous avez gagné !" : "Perdu, un autre joueur a trouvé.";

  $("players").innerHTML = "";
  for (const p of g.players) {
    const li = document.createElement("li");
    li.className = p.me ? "me" : "";
    li.textContent = `${p.pseudo}${p.me ? " (vous)" : ""} — essais ${p.tries}/${g.max_tries} — score : ${p.score}`;
    for (const h of p.history) { // chaque essai : nombre + indice, visible par tous
      const d = document.createElement("div");
      d.className = "try";
      d.textContent = `${h.v} → ${HINT[h.r]}`;
      li.append(d);
    }
    $("players").append(li);
  }
}

// Lance une action qui renvoie l'état (create/join/leave) ; en cas de succès on affiche la partie.
async function run(action, data) {
  try {
    $("lobbyerr").textContent = $("msg").value = $("prop").value = "";
    wantLobby = false;
    render(await api(action, data));
  } catch (e) { guard(e); }
}

$("create").onclick = () => run("create", { players: Number($("nplayers").value) });
$("join").onclick = () => run("join", { code: $("code").value });
$("leave").onclick = () => run("leave");
$("again").onclick = () => { wantLobby = true; render(last); };
$("logout").onclick = async () => { await Session.end(); toLogin(); };

$("jeu").onsubmit = async (e) => {
  e.preventDefault();
  const value = Number($("prop").value);
  if (!Number.isInteger(value) || value < 1 || value > 100) {
    $("msg").value = "Entrez un entier entre 1 et 100";
    return;
  }
  try {
    const s = await api("guess", { value });
    $("msg").value = HINT[s.result];
    $("prop").select();
    render(s);
  } catch (e) { guard(e); }
};

(async () => {
  try {
    $("who").textContent = "👤 " + (Session.pseudo() ?? ""); // affichage immédiat depuis sessionStorage
    const u = await api("me"); // le cookie de session reste l'autorité (401 -> login)
    Session.start(u.pseudo);
    $("who").textContent = "👤 " + u.pseudo;
    render(await api("state"));
    // pas de push serveur : on interroge toutes les 2 s pour voir les autres joueurs
    setInterval(async () => { try { render(await api("state")); } catch (e) { guard(e); } }, 2000);
  } catch (e) { guard(e); }
})();
