
CREATE TABLE IF NOT EXISTS mascotas (
  id_alumno INT UNSIGNED PRIMARY KEY,
  nombre VARCHAR(20) NOT NULL,
  especie ENUM('gato','perro','conejo','dragon') NOT NULL,
  saciedad TINYINT UNSIGNED NOT NULL DEFAULT 70,          -- 0 (hambre) a 100 (llena)
  ultima_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_alumno) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS tienda_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(40) NOT NULL UNIQUE,
  tipo ENUM('comida','accesorio') NOT NULL,
  precio INT UNSIGNED NOT NULL,
  efecto TINYINT UNSIGNED NULL,                            
  slot ENUM('cabeza','cuello','cara','cuerpo') NULL,      
  emoji VARCHAR(16) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS inventario (
  id_alumno INT UNSIGNED NOT NULL,
  id_item INT UNSIGNED NOT NULL,
  cantidad INT UNSIGNED NOT NULL DEFAULT 1,
  equipado TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id_alumno, id_item),
  FOREIGN KEY (id_alumno) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (id_item) REFERENCES tienda_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS transacciones_monedas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_alumno INT UNSIGNED NOT NULL,
  cantidad INT NOT NULL,
  motivo VARCHAR(80) NOT NULL,
  creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_alumno_fecha (id_alumno, creado),
  FOREIGN KEY (id_alumno) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;


INSERT IGNORE INTO tienda_items (nombre, tipo, precio, efecto, slot, emoji) VALUES
 ('Galleta',          'comida',    10, 10, NULL,     '🍪'),
 ('Manzana',          'comida',    15, 20, NULL,     '🍎'),
 ('Pescado',          'comida',    30, 40, NULL,     '🐟'),
 ('Pizza',            'comida',    40, 50, NULL,     '🍕'),
 ('Pastel',           'comida',    60, 80, NULL,     '🍰'),
 ('Lentes de sol',    'accesorio', 100, NULL, 'cara',   '🕶️'),
 ('Moño',             'accesorio',  80, NULL, 'cuello', '🎀'),
 ('Bufanda',          'accesorio', 150, NULL, 'cuello', '🧣'),
 ('Gorra',            'accesorio', 120, NULL, 'cabeza', '🧢'),
 ('Sombrero de mago', 'accesorio', 180, NULL, 'cabeza', '🎩'),
 ('Corona',           'accesorio', 300, NULL, 'cabeza', '👑'),
 ('Chamarra',         'accesorio', 200, NULL, 'cuerpo', '🧥');
