-- Links Firebase Auth UID (token `sub`) for federated login (Facebook, Google, etc.).
ALTER TABLE users
  ADD COLUMN firebase_uid VARCHAR(128) DEFAULT NULL AFTER email;

CREATE UNIQUE INDEX idx_users_firebase_uid ON users (firebase_uid);
