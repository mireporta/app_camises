-- init_db.sql
CREATE DATABASE IF NOT EXISTS inventari_camises CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE inventari_camises;

CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(100) DEFAULT NULL,
  location VARCHAR(150) DEFAULT NULL,
  stock INT DEFAULT 0,
  min_stock INT DEFAULT 0,
  life_expectancy INT DEFAULT 0,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS operations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  type ENUM('entry','exit','decommission') NOT NULL,
  quantity INT NOT NULL,
  source VARCHAR(150) DEFAULT NULL,
  destination VARCHAR(150) DEFAULT NULL,
  machine VARCHAR(150) DEFAULT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  created_by VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

-- Dades d'exemple
INSERT INTO items (sku,name,category,location,stock,min_stock,life_expectancy,active) VALUES
('ENR001','Motor ventilador','Mecànica','A1',25,5,100,1),
('ENR002','Sensor de temperatura','Electrònica','B2',8,4,200,1),
('ENR003','Corretja transmissió','Mecànica','A3',3,10,50,1),
('ENR004','Vàlvula de seguretat','Hidràulica','C1',12,2,500,1),
('ENR005','Filtre d''aire','Mecànica','A2',1,5,30,1);

-- Exemples d'operacions (algunes sortides per generar top)
INSERT INTO operations (item_id, type, quantity, source, destination, machine, reason, created_by) VALUES
(1,'exit',5,'A1','Maq01','Maq01',NULL,'system'),
(2,'exit',3,'B2','Maq02','Maq02',NULL,'system'),
(1,'exit',2,'A1','Maq03','Maq03',NULL,'system'),
(3,'entry',20,NULL,'A3','Maq02',NULL,'system');
