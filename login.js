const $ = (id) => document.getElementById(id);

$("pseudo").value = localStorage.getItem("lastPseudo") ?? ""; // pré-remplissage
if ($("pseudo").value) $("pwd").focus();

// Cookie de session encore valide (ex. nouvel onglet) -> on reprend la session sans se reconnecter.
api("me").then((u) => { Session.start(u.pseudo); location.replace("exc.html"); }).catch(() => {});

$("login").onsubmit = async (e) => {
  e.preventDefault();
  try {
    const u = await api("login", { pseudo: $("pseudo").value.trim(), pwd: $("pwd").value });
    Session.start(u.pseudo);
    location.replace("exc.html");
  } catch (e) {
    $("err").textContent = e.message;
  }
};
