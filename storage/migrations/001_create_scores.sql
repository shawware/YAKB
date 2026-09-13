CREATE TABLE scores (
    user_id  VARCHAR(32) NOT NULL,
    username VARCHAR(255) NULL,
    score    INT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
