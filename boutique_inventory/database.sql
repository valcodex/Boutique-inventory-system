--  products table

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(50),
    size VARCHAR(10),
    color VARCHAR(30),
    price DECIMAL(10,2),
    quantity INT DEFAULT 0,
    shelf VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

--login table

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL
);


-- username and password for login

INSERT INTO users (username, password)
VALUES ('admin', 'admin123');