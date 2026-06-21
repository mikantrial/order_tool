-- order-support システム用テーブル
-- さくらのphpMyAdminまたはSSHで実行する

CREATE TABLE IF NOT EXISTS stores (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  slug       VARCHAR(32)  NOT NULL UNIQUE COMMENT 'URLのランダム識別子',
  name       VARCHAR(100) NOT NULL        COMMENT '店名（画面に表示）',
  tax_rate   DECIMAL(4,1) NOT NULL DEFAULT 10.0 COMMENT '消費税率（%）',
  password   VARCHAR(255) NOT NULL        COMMENT 'ハッシュ化済みパスワード',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menus (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  store_id   INT          NOT NULL        COMMENT 'stores.id への外部キー',
  name       VARCHAR(100) NOT NULL        COMMENT '品名',
  reading    VARCHAR(100) NOT NULL DEFAULT '' COMMENT '読みがな（ルビ用）',
  price      INT          NOT NULL DEFAULT 0  COMMENT '価格（税込・円）',
  aliases    VARCHAR(500) NOT NULL DEFAULT '' COMMENT '呼び名（カンマ区切り）',
  sort_order INT          NOT NULL DEFAULT 0  COMMENT '表示順（小さい順）',
  is_stopped TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '提供中止フラグ 0=通常 1=中止',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- インデックス（検索を速くする）
CREATE INDEX idx_menus_store ON menus (store_id, sort_order);
