CREATE TABLE events (
    id        INT AUTO_INCREMENT NOT NULL,
    from_user VARCHAR(32) NOT NULL,
    to_user   VARCHAR(32) NOT NULL,
    points    INT NOT NULL,
    channel   VARCHAR(32) NOT NULL,
    timestamp DATETIME NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_events_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
