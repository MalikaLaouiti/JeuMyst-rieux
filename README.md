# Nombre Mystérieux

Jeu web multijoueur : tous les joueurs d'une partie cherchent **le même nombre secret (1 à 100)**, avec **3 essais chacun**. Les joueurs jouent **chacun leur tour** (20 s par tour). Le premier qui trouve gagne ; le score dépend du nombre d'essais utilisés.

Stack : HTML / CSS / JavaScript natif (sans framework) + PHP (PDO) + MySQL.

## Fonctionnalités

- Inscription / connexion (mot de passe haché, session PHP par cookie).
- Partie solo (1 joueur) ou multijoueur (2 à 8) via un **code de 5 caractères** à partager.
- **Jeu au tour par tour** : un seul joueur peut proposer à la fois (le créateur d'abord, puis les autres dans l'ordre d'arrivée) ; les autres voient « Au tour de X… » et leur bouton « Vérifier » est désactivé.
- **Chronomètre de 20 s par tour**, géré côté serveur et affiché à tous. Tour expiré = essai perdu ; **2 tours manqués = exclusion** de la partie.
- **Bouton « Quitter la partie »** en attente comme en cours de partie (avec confirmation).
- **Victoire automatique à 2 joueurs** : si l'un quitte ou est exclu, l'autre gagne (score maximal) ; le joueur parti voit « Vous avez perdu la partie, dommage ».
- Historique des essais visible par tous les joueurs de la partie (les tours manqués y apparaissent : « ⏱ Temps écoulé »).
- Classement global (parties, victoires, score total).
- Thème clair / sombre mémorisé.

## Fichiers

| Fichier | Rôle |
|---|---|
| `api.php` | API unique (JSON, `POST`) : auth, parties, tours, essais, scores |
| `schema.sql` | Création de la base `nombre_mysterieux` (tables + vue) |
| `login.html` / `login.js` | Connexion |
| `register.html` / `register.js` | Inscription |
| `exc.html` / `game.js` | Page de jeu (lobby + partie) |
| `scores.html` / `scores.js` | Classement (rafraîchi toutes les 5 s) |
| `common.js` | Thème, objet `Session`, helper `api(action, data)` |
| `style.css` | Styles (dont `.dark-mode`) |

## Installation

Prérequis : PHP 8.1+ (extension PDO MySQL) et MySQL/MariaDB.

1. Importer la base :
   ```
   mysql -u root < schema.sql
   ```
2. Vérifier les identifiants dans `api.php` (par défaut : hôte `localhost`, base `nombre_mysterieux`, utilisateur `root`, mot de passe vide).
3. Lancer le serveur PHP depuis le dossier du projet :
   ```
   php -S localhost:8000
   ```
4. Ouvrir <http://localhost:8000/login.html>.

> Ne pas utiliser Live Server ni `file://` : le PHP ne serait pas exécuté (le client affiche alors « Réponse vide »).

## Règles du jeu

- Un joueur ne peut avoir qu'**une partie non terminée** à la fois.
- Création : choisir 1 à 8 joueurs. En solo la partie démarre tout de suite ; sinon elle reste en `waiting` jusqu'à ce que tous aient rejoint.
- **Tour par tour** : le joueur qui doit jouer est celui qui a le moins d'essais (créateur en premier, puis ordre d'arrivée) ; ceux qui n'ont plus d'essais sont sautés. Une proposition hors tour est refusée (409).
- **Chrono** : `TURN_SECS` = 20 s par tour (`api.php`). Si le tour expire, le joueur perd un essai (noté `timeout` dans son historique) et le tour passe au suivant. Au **2e tour manqué**, il est exclu. L'expiration est constatée par toute requête `state`/`guess` d'un joueur de la partie.
- Chaque essai renvoie `too_low`, `too_high` ou `correct`.
- Score si trouvé : **30** (1er essai), **20** (2e), **10** (3e) ; sinon 0.
- La partie se termine dès qu'un joueur trouve, ou quand tous ont épuisé leurs essais. Le secret n'est révélé qu'à la fin.
- On peut quitter une partie en attente **ou en cours** (`leave`) : le joueur est retiré de la partie, qui est supprimée si elle devient vide, ou terminée si les joueurs restants n'ont plus d'essais.
- **Partie à 2 joueurs** : si l'un quitte ou est exclu, l'autre gagne automatiquement (score `max_tries × 10` = 30) et la partie se termine.
- Une partie quittée ou dont on est exclu ne compte plus dans le classement du joueur parti.

## API (`POST api.php`, corps JSON `{ "action": ..., ... }`)

Réponses JSON ; en cas d'erreur : `{ "error": "message" }` avec le code HTTP adapté.

| Action | Paramètres | Auth | Réponse |
|---|---|---|---|
| `register` | `pseudo` (1-20 car.), `pwd` (≥ 4 car.) | non | `{pseudo}` (409 si pseudo pris) |
| `login` | `pseudo`, `pwd` | non | `{pseudo}` (401 si incorrect) |
| `logout` | – | non | `{ok:true}` |
| `me` | – | oui | `{pseudo}` |
| `state` | – | oui | `{game}` (dernière partie du joueur, ou `null`) ; applique l'expiration du tour |
| `create` | `players` (entier 1-8) | oui | `{game}` |
| `join` | `code` | oui | `{game}` (404 si code invalide / partie commencée) |
| `leave` | – | oui | `{game}` (partie en attente ou en cours ; exclusion/victoire auto, voir règles) |
| `guess` | `value` (entier 1-100) | oui | `{game, result}` (409 « Ce n'est pas votre tour » hors tour) |
| `scores` | – | oui | `{me, rows:[{pseudo, parties, victoires, score}]}` (top 100) |

Objet `game` : `id, status (waiting|playing|finished), max_tries, code, max_players, players[{pseudo, tries, score, history[{v,r}], me}], secret` (`secret` = `null` tant que la partie n'est pas finie). Champs de tour : `turn` (pseudo du joueur dont c'est le tour), `my_turn` (bool), `secs_left` (secondes restantes). Une entrée d'historique manquée vaut `{v:null, r:"timeout"}`.

## Base de données

- `users` : `id`, `pseudo` (unique), `password_hash`, `created_at`
- `games` : `id`, `code` (unique), `max_players`, `secret_number`, `max_tries`, `status`, `created_by`, `created_at`, `finished_at`, `turn_at` (début du tour en cours, base du chrono)
- `game_players` : clé `(game_id, user_id)`, `score`, `tries`, `history` (JSON), `joined_at`
- Vue `leaderboard` : classement par score total (non utilisée par l'API, qui calcule son propre classement).

## Fonctionnement côté client

- Pas de WebSocket : `game.js` interroge `state` **toutes les secondes** pour voir les autres joueurs, le tour et le chrono.
- Les requêtes passent par `call()` : une réponse plus ancienne que celle déjà affichée est ignorée (pas d'écrasement par un sondage lent).
- Si la partie du joueur disparaît (il a quitté ou été exclu), retour automatique au lobby avec un message de défaite.
- Stockage : cookie `PHPSESSID` (HttpOnly) = authentification ; `sessionStorage` = pseudo de l'onglet ; `localStorage` = thème, dernier pseudo, cache du classement.
- Un `401` redirige vers `login.html`.

## Points à connaître

- **Migration** si la base existait déjà avant le jeu au tour par tour :
  `ALTER TABLE games ADD turn_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;`
- `schema.sql` définit `max_tries` à 5 par défaut, mais l'API force 3 (`MAX_TRIES` dans `api.php`) : c'est 3 qui s'applique.
- `api.php` renvoie le détail des erreurs SQL (pratique en développement) : à retirer en production, tout comme le compte `root` sans mot de passe.
