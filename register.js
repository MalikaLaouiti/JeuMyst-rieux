const $ = (id) => document.getElementById(id);

$("register").onsubmit = async (e) => {
  e.preventDefault();
  if ($("pwd").value !== $("pwd2").value) return ($("err").textContent = "Les mots de passe ne correspondent pas.");
  try {
    const u = await api("register", { pseudo: $("pseudo").value.trim(), pwd: $("pwd").value }); // connecte aussi
    Session.start(u.pseudo);
    location.replace("exc.html");
  } catch (e) {
    $("err").textContent = e.message;
  }
};
