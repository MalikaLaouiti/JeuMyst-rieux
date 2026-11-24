const $ = (id) => document.getElementById(id);

function draw({ me, rows }) {
  $("rows").replaceChildren(...rows.map((r, i) => {
    const tr = document.createElement("tr");
    tr.className = r.pseudo === me ? "me" : "";
    tr.append(...[i + 1, r.pseudo, r.parties, r.victoires, r.score].map((v) =>
      Object.assign(document.createElement("td"), { textContent: v })));
    return tr;
  }));
}

async function refresh() {
  try {
    const d = await api("scores");
    localStorage.setItem("scores", JSON.stringify(d)); // cache pour un affichage instantané
    draw(d);
    $("err").textContent = "";
    $("upd").textContent = "Mis à jour à " + new Date().toLocaleTimeString();
  } catch (e) {
    if (e.status === 401) location.replace("login.html");
    else $("err").textContent = e.message;
  }
}

const cached = localStorage.getItem("scores");
if (cached) draw(JSON.parse(cached));
refresh();
setInterval(refresh, 5000); // actualisation automatique toutes les 5 s
