<?php
// Cookie de session PHP (PHPSESSID) : seule source de vérité de l'authentification.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']); // illisible par JS, non envoyé cross-site
session_start();
header('Content-Type: application/json; charset=utf-8');

const MAX_TRIES = 3;

function out(array $d, int $code = 200): never {
  http_response_code($code);
  echo json_encode($d);
  exit;
}

try {
  $pdo = new PDO('mysql:host=localhost;dbname=nombre_mysterieux;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
} catch (PDOException $e) {
  out(['error' => 'Base de données inaccessible : ' . $e->getMessage()], 500);
}

function q(PDO $pdo, string $sql, array $p = []): PDOStatement {
  $s = $pdo->prepare($sql);
  $s->execute($p);
  return $s;
}

// Dernière partie du joueur + tous ses joueurs. Le secret n'est révélé qu'à la fin.
function state(PDO $pdo, int $uid): array {
  $g = q($pdo, 'SELECT g.* FROM games g JOIN game_players gp ON gp.game_id = g.id
                WHERE gp.user_id = ? ORDER BY g.id DESC LIMIT 1', [$uid])->fetch();
  if (!$g) return ['game' => null];
  $players = q($pdo, 'SELECT u.pseudo, gp.tries, gp.score, gp.history, (gp.user_id = ?) AS me
                      FROM game_players gp JOIN users u ON u.id = gp.user_id
                      WHERE gp.game_id = ? ORDER BY gp.joined_at', [$uid, $g['id']])->fetchAll();
  foreach ($players as &$p) $p['history'] = json_decode($p['history'] ?? '[]', true); // [{v, r}, ...]
  unset($p);
  return ['game' => [
    'id' => $g['id'], 'status' => $g['status'], 'max_tries' => $g['max_tries'],
    'code' => $g['code'], 'max_players' => $g['max_players'],
    'players' => $players,
    'secret' => $g['status'] === 'finished' ? $g['secret_number'] : null,
  ]];
}

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $in['action'] ?? '';

try {
  if ($action === 'register' || $action === 'login') {
    $pseudo = trim($in['pseudo'] ?? '');
    $pwd = (string)($in['pwd'] ?? '');
    if ($pseudo === '' || mb_strlen($pseudo) > 20 || strlen($pwd) < 4)
      out(['error' => 'Pseudo (1-20 car.) et mot de passe (4 car. min.) requis'], 400);

    if ($action === 'register') {
      if (q($pdo, 'SELECT 1 FROM users WHERE pseudo = ?', [$pseudo])->fetch())
        out(['error' => 'Pseudo déjà pris'], 409);
      q($pdo, 'INSERT INTO users (pseudo, password_hash) VALUES (?, ?)', [$pseudo, password_hash($pwd, PASSWORD_DEFAULT)]);
      $u = ['id' => (int)$pdo->lastInsertId(), 'pseudo' => $pseudo];
    } else {
      $u = q($pdo, 'SELECT * FROM users WHERE pseudo = ?', [$pseudo])->fetch();
      if (!$u || !password_verify($pwd, $u['password_hash']))
        out(['error' => 'Pseudo ou mot de passe incorrect'], 401);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['pseudo'] = $u['pseudo'];
    out(['pseudo' => $u['pseudo']]);
  }

  if ($action === 'logout') {
    session_destroy();
    out(['ok' => true]);
  }

  $uid = $_SESSION['uid'] ?? null;
  if (!$uid) out(['error' => 'Non connecté'], 401);

  switch ($action) {
    case 'me':
      out(['pseudo' => $_SESSION['pseudo']]);

    case 'scores': // score du compte = somme de ses scores sur les parties terminées
      out(['me' => $_SESSION['pseudo'], 'rows' => q($pdo,
        "SELECT u.pseudo, COUNT(gp.game_id) AS parties, COALESCE(SUM(gp.score > 0), 0) AS victoires,
                COALESCE(SUM(gp.score), 0) AS score
         FROM users u
         LEFT JOIN game_players gp ON gp.user_id = u.id
              AND gp.game_id IN (SELECT id FROM games WHERE status = 'finished')
         GROUP BY u.id, u.pseudo ORDER BY score DESC, victoires DESC, u.pseudo LIMIT 100")->fetchAll()]);

    case 'state':
      out(state($pdo, $uid));

    case 'create': // crée une partie pour N joueurs + code à partager
    case 'join':   // rejoint une partie en attente grâce à son code
      $pdo->beginTransaction();
      if (q($pdo, "SELECT 1 FROM games g JOIN game_players gp ON gp.game_id = g.id
                   WHERE gp.user_id = ? AND g.status <> 'finished' LIMIT 1", [$uid])->fetch()) {
        $pdo->rollBack();
        out(['error' => 'Vous avez déjà une partie en cours'], 409);
      }
      if ($action === 'create') {
        $n = $in['players'] ?? null;
        if (!is_int($n) || $n < 1 || $n > 8) { $pdo->rollBack(); out(['error' => 'Nombre de joueurs : 1 à 8'], 400); }
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sans 0/O/1/I
        do {
          $code = '';
          for ($i = 0; $i < 5; $i++) $code .= $alpha[random_int(0, strlen($alpha) - 1)];
        } while (q($pdo, 'SELECT 1 FROM games WHERE code = ?', [$code])->fetch());
        q($pdo, 'INSERT INTO games (code, max_players, status, secret_number, max_tries, created_by) VALUES (?, ?, ?, ?, ?, ?)',
          [$code, $n, $n === 1 ? 'playing' : 'waiting', random_int(1, 100), MAX_TRIES, $uid]);
        $gid = $pdo->lastInsertId();
      } else {
        $code = strtoupper(trim((string)($in['code'] ?? '')));
        $g = q($pdo, "SELECT id, max_players FROM games WHERE code = ? AND status = 'waiting' FOR UPDATE", [$code])->fetch();
        if (!$g) { $pdo->rollBack(); out(['error' => 'Code invalide ou partie déjà commencée'], 404); }
        $gid = $g['id'];
      }
      q($pdo, 'INSERT INTO game_players (game_id, user_id) VALUES (?, ?)', [$gid, $uid]);
      q($pdo, "UPDATE games SET status = 'playing'
               WHERE id = ? AND (SELECT COUNT(*) FROM game_players WHERE game_id = ?) >= max_players", [$gid, $gid]);
      $pdo->commit();
      out(state($pdo, $uid));

    case 'leave': // quitter une partie en attente ou en cours (la supprime si vide)
      $pdo->beginTransaction();
      $g = q($pdo, "SELECT g.id, g.max_tries FROM games g JOIN game_players gp ON gp.game_id = g.id
                    WHERE gp.user_id = ? AND g.status IN ('waiting', 'playing') FOR UPDATE", [$uid])->fetch();
      if ($g) {
        q($pdo, 'DELETE FROM game_players WHERE game_id = ? AND user_id = ?', [$g['id'], $uid]);
        q($pdo, 'DELETE FROM games WHERE id = ? AND NOT EXISTS (SELECT 1 FROM game_players WHERE game_id = ?)', [$g['id'], $g['id']]);
        // si les joueurs restants n'ont plus d'essais, la partie se termine
        q($pdo, "UPDATE games SET status = 'finished', finished_at = NOW()
                 WHERE id = ? AND status = 'playing' AND NOT EXISTS
                   (SELECT 1 FROM game_players WHERE game_id = ? AND tries < ?)", [$g['id'], $g['id'], $g['max_tries']]);
      }
      $pdo->commit();
      out(state($pdo, $uid));

    case 'guess':
      $v = $in['value'] ?? null;
      if (!is_int($v) || $v < 1 || $v > 100) out(['error' => 'Entrez un entier entre 1 et 100'], 400);

      $pdo->beginTransaction();
      $g = q($pdo, "SELECT g.* FROM games g JOIN game_players gp ON gp.game_id = g.id
                    WHERE gp.user_id = ? AND g.status <> 'finished' FOR UPDATE", [$uid])->fetch();
      if (!$g || $g['status'] !== 'playing') {
        $pdo->rollBack();
        out(['error' => $g ? "En attente d'un adversaire…" : 'Aucune partie en cours'], 409);
      }
      $row = q($pdo, 'SELECT tries, history FROM game_players WHERE game_id = ? AND user_id = ?', [$g['id'], $uid])->fetch();
      $tries = (int)$row['tries'];
      $history = json_decode($row['history'] ?? '[]', true);
      if ($tries >= $g['max_tries']) {
        $pdo->rollBack();
        out(['error' => 'Plus d\'essais'], 409);
      }
      $tries++;
      $result = $v === (int)$g['secret_number'] ? 'correct' : ($v > $g['secret_number'] ? 'too_high' : 'too_low');
      $score = $result === 'correct' ? ($g['max_tries'] - $tries + 1) * 10 : 0; // 30 / 20 / 10
      $history[] = ['v' => $v, 'r' => $result];
      q($pdo, 'UPDATE game_players SET tries = ?, history = ?, score = ? WHERE game_id = ? AND user_id = ?',
        [$tries, json_encode($history), $score, $g['id'], $uid]);

      $left = q($pdo, 'SELECT COUNT(*) FROM game_players WHERE game_id = ? AND tries < ?', [$g['id'], $g['max_tries']])->fetchColumn();
      if ($result === 'correct' || !$left)
        q($pdo, "UPDATE games SET status = 'finished', finished_at = NOW() WHERE id = ?", [$g['id']]);
      $pdo->commit();
      out(state($pdo, $uid) + ['result' => $result]);

    default:
      out(['error' => 'Action inconnue'], 400);
  }
} catch (PDOException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  out(['error' => 'Erreur base de données : ' . $e->getMessage()], 500);
}
