-- Historie dotazů AI asistenta (Účetnictví → AI asistent). Konverzace patří
-- uživateli v rámci firmy; zpráva asistenta nese celou strukturovanou odpověď
-- a značky, které návrhy už uživatel zaúčtoval, založil nebo nahrál.

CREATE TABLE IF NOT EXISTS ai_assistant_conversations (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  title       VARCHAR(255) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ai_conv_owner (supplier_id, user_id, updated_at),
  CONSTRAINT fk_ai_conv_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_conv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_assistant_messages (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  supplier_id     INT UNSIGNED NOT NULL,
  role            ENUM('user','assistant') NOT NULL,
  content         MEDIUMTEXT NOT NULL,
  attachments_json LONGTEXT NULL,
  response_json   LONGTEXT NULL,
  applied_json    LONGTEXT NULL,
  model           VARCHAR(100) NULL,
  input_tokens    INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens   INT UNSIGNED NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ai_msg_conv (conversation_id, id),
  KEY idx_ai_msg_supplier (supplier_id),
  CONSTRAINT fk_ai_msg_conv FOREIGN KEY (conversation_id) REFERENCES ai_assistant_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_msg_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
