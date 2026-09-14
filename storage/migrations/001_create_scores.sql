-- Copyright © 2026 shawware.com.au

CREATE TABLE scores (
    user_id VARCHAR(32) NOT NULL,
    score   INT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
