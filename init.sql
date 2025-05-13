-- Create database
CREATE DATABASE IF NOT EXISTS noteapp;

-- Switch to database
USE noteapp;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_activated TINYINT DEFAULT 0,
    activation_token VARCHAR(255),
    reset_otp VARCHAR(6),
    reset_token VARCHAR(255),
    reset_expiry DATETIME,
    avatar VARCHAR(255),
    preferences JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Notes table
CREATE TABLE IF NOT EXISTS notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    is_pinned TINYINT DEFAULT 0,
    pinned_at DATETIME NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    is_locked TINYINT DEFAULT 0, -- Added to support password protection flag
    is_trashed TINYINT(1) DEFAULT 0, -- Supports trash view
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Note Images table
CREATE TABLE IF NOT EXISTS note_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
);

-- Labels table
CREATE TABLE IF NOT EXISTS labels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Note-Labels mapping table
CREATE TABLE IF NOT EXISTS note_labels (
    note_id INT NOT NULL,
    label_id INT NOT NULL,
    PRIMARY KEY (note_id, label_id),
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
);

-- Note Shares table
CREATE TABLE IF NOT EXISTS note_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note_id INT NOT NULL,
    recipient_user_id INT NOT NULL,
    permission ENUM('read', 'edit') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (note_id, recipient_user_id)
);

-- Create or update the noteapp_user
CREATE USER IF NOT EXISTS 'noteapp_user'@'%' IDENTIFIED WITH mysql_native_password BY 'YourStrong@Passw0rd';
GRANT ALL PRIVILEGES ON noteapp.* TO 'noteapp_user'@'%';
FLUSH PRIVILEGES;