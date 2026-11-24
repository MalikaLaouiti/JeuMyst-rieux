# Nombre Mystérieux

Jeu web multijoueur : tous les joueurs d'une partie cherchent **le même nombre secret (1 à 100)**, avec **3 essais chacun**. Le premier qui trouve gagne ; le score dépend du nombre d'essais utilisés.

Stack : HTML / CSS / JavaScript natif (sans framework) + PHP (PDO) + MySQL.

## Fonctionnalités

- Inscription / connexion (mot de passe haché, session PHP par cookie).
- Partie solo (1 joueur) ou multijoueur (2 à 8) via un **code de 5 caractères** à partager.
- Historique des essais visible par tous les joueurs de la partie.
- Classement global (parties, victoires, score total).
- Thème clair / sombre mémorisé.

## Fichiers

| Fichier | Rôle |
|---|---|
| `api.php` | API unique (JSON, `POST`) : auth, parties, essais, scores |
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
- Chaque essai renvoie `too_low`, `too_high` ou `correct`.
- Score si trouvé : **30** (1er essai), **20** (2e), **10** (3e) ; sinon 0.
- La partie se termine dès qu'un joueur trouve, ou quand tous ont épuisé leurs essais. Le secret n'est révélé qu'à la fin.
- Quitter une partie n'est possible qu'en attente ; elle est supprimée si elle devient vide.

## API (`POST api.php`, corps JSON `{ "action": ..., ... }`)

Réponses JSON ; en cas d'erreur : `{ "error": "message" }` avec le code HTTP adapté.

| Action | Paramètres | Auth | Réponse |
|---|---|---|---|
| `register` | `pseudo` (1-20 car.), `pwd` (≥ 4 car.) | non | `{pseudo}` (409 si pseudo pris) |
| `login` | `pseudo`, `pwd` | non | `{pseudo}` (401 si incorrect) |
| `logout` | – | non | `{ok:true}` |
| `me` | – | oui | `{pseudo}` |
| `state` | – | oui | `{game}` (dernière partie du joueur, ou `null`) |
| `create` | `players` (entier 1-8) | oui | `{game}` |
| `join` | `code` | oui | `{game}` (404 si code invalide / partie commencée) |
| `leave` | – | oui | `{game}` |
| `guess` | `value` (entier 1-100) | oui | `{game, result}` |
| `scores` | – | oui | `{me, rows:[{pseudo, parties, victoires, score}]}` (top 100) |

Objet `game` : `id, status (waiting|playing|finished), max_tries, code, max_players, players[{pseudo, tries, score, history[{v,r}], me}], secret` (`secret` = `null` tant que la partie n'est pas finie).

## Base de données

- `users` : `id`, `pseudo` (unique), `password_hash`, `created_at`
- `games` : `id`, `code` (unique), `max_players`, `secret_number`, `max_tries`, `status`, `created_by`, `created_at`, `finished_at`
- `game_players` : clé `(game_id, user_id)`, `score`, `tries`, `history` (JSON), `joined_at`
- Vue `leaderboard` : classement par score total (non utilisée par l'API, qui calcule son propre classement).

## Fonctionnement côté client

- Pas de WebSocket : `game.js` interroge `state` **toutes les 2 s** pour voir les autres joueurs.
- Stockage : cookie `PHPSESSID` (HttpOnly) = authentification ; `sessionStorage` = pseudo de l'onglet ; `localStorage` = thème, dernier pseudo, cache du classement.
- Un `401` redirige vers `login.html`.

## Points à connaître

- `schema.sql` définit `max_tries` à 5 par défaut, mais l'API force 3 (`MAX_TRIES` dans `api.php`) : c'est 3 qui s'applique.
- `api.php` renvoie le détail des erreurs SQL (pratique en développement) : à retirer en production, tout comme le compte `root` sans mot de passe.
