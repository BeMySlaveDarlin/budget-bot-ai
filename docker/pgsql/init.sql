CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    telegram_id BIGINT UNIQUE NOT NULL,
    username VARCHAR(255),
    first_name VARCHAR(255),
    enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS chats (
    id SERIAL PRIMARY KEY,
    telegram_chat_id BIGINT UNIQUE NOT NULL,
    title VARCHAR(255),
    type VARCHAR(50),
    settings JSONB DEFAULT '{"mode": "shared"}',
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS messages (
    id SERIAL PRIMARY KEY,
    chat_id INT REFERENCES chats(id) ON DELETE CASCADE,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    telegram_message_id BIGINT,
    raw_text TEXT NOT NULL,
    amount DECIMAL(15,2),
    currency VARCHAR(10) DEFAULT 'THB',
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_messages_chat_date ON messages(chat_id, created_at DESC);
CREATE INDEX idx_messages_user ON messages(user_id);

CREATE TABLE IF NOT EXISTS exchange_rates (
    id SERIAL PRIMARY KEY,
    currency_from VARCHAR(10) NOT NULL,
    currency_to VARCHAR(10) DEFAULT 'THB',
    rate DECIMAL(15,6) NOT NULL,
    fetched_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_rates_lookup ON exchange_rates(currency_from, fetched_at DESC);

CREATE TABLE IF NOT EXISTS command_logs (
    id SERIAL PRIMARY KEY,
    chat_id INT REFERENCES chats(id) ON DELETE CASCADE,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    command VARCHAR(50),
    params TEXT,
    input_tokens INT DEFAULT 0,
    output_tokens INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_command_logs_date ON command_logs(created_at DESC);
