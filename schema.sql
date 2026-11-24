CREATE DATABASE IF NOT EXISTS nombre_mysterieux CHARACTER SET utf8mb4;
USE nombre_mysterieux;

CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  pseudo        VARCHAR(20)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,            -- bcrypt/argon2, jamais en clair
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Une partie = un nombre secret partagé par tous les joueurs.
CREATE TABLE games (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(6) NOT NULL UNIQUE,       -- code à partager avec les amis
  max_players   INT NOT NULL DEFAULT 2,           -- la partie démarre quand c'est complet
  secret_number INT NOT NULL,
  max_tries     INT NOT NULL DEFAULT 5,           -- par joueur
  status        ENUM('waiting', 'playing', 'finished') NOT NULL DEFAULT 'waiting',
  created_by    INT NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at   TIMESTAMP NULL,
  CHECK (secret_number BETWEEN 1 AND 100),
  CHECK (max_tries > 0),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 1 joueur = 1 ligne par partie (solo = 1 ligne, multi = N lignes) + son score.
CREATE TABLE game_players (
  game_id   INT NOT NULL,
  user_id   INT NOT NULL,
  score     INT NOT NULL DEFAULT 0,
  tries     INT NOT NULL DEFAULT 0,               -- essais utilisés
  history   TEXT NULL,                            -- JSON [{"v":42,"r":"too_low"},...] affiché aux autres joueurs
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (game_id, user_id),
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Classement global.
CREATE VIEW leaderboard AS
SELECT u.pseudo, COUNT(gp.game_id) AS parties, SUM(gp.score) AS score_total
FROM users u JOIN game_players gp ON gp.user_id = u.id
GROUP BY u.id, u.pseudo ORDER BY score_total DESC;
