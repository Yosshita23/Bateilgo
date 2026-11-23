
CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(20) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  rol ENUM('alumno','profesor') NOT NULL,
  monedas INT UNSIGNED NOT NULL DEFAULT 0,
  token_hash CHAR(64) NULL,
  creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_token (token_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS materias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL,
  codigo CHAR(8) NOT NULL UNIQUE,            -- BTL-XXXX
  id_profesor INT UNSIGNED NOT NULL,
  creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_profesor) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clase_alumnos (
  id_materia INT UNSIGNED NOT NULL,
  id_alumno INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_materia, id_alumno),
  FOREIGN KEY (id_materia) REFERENCES materias(id) ON DELETE CASCADE,
  FOREIGN KEY (id_alumno) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tareas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_materia INT UNSIGNED NOT NULL,
  titulo VARCHAR(100) NOT NULL,
  descripcion TEXT NULL,
  fecha_entrega DATETIME NOT NULL,
  creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_materia_fecha (id_materia, fecha_entrega),
  FOREIGN KEY (id_materia) REFERENCES materias(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS entregas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_tarea INT UNSIGNED NOT NULL,
  id_alumno INT UNSIGNED NOT NULL,
  archivo VARCHAR(100) NULL,
  link VARCHAR(500) NULL,
  comentario VARCHAR(500) NULL,
  fecha_envio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tarea_alumno (id_tarea, id_alumno),   -- evita entregas y monedas duplicadas
  FOREIGN KEY (id_tarea) REFERENCES tareas(id) ON DELETE CASCADE,
  FOREIGN KEY (id_alumno) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;
