CREATE DATABASE IF NOT EXISTS cihrm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cihrm_db;

-- Core account, guest, and room records.
CREATE TABLE IF NOT EXISTS `USER` (
  user_id VARCHAR(40) PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL,
  department VARCHAR(120),
  year_level VARCHAR(40),
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_user_role CHECK (role IN ('student','instructor','admin')),
  INDEX idx_user_role (role)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS GUEST (
  guest_id VARCHAR(40) PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190),
  phone VARCHAR(40) NOT NULL,
  address VARCHAR(255),
  id_type VARCHAR(80),
  preferences TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_guest_name (full_name),
  INDEX idx_guest_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ROOM (
  room_id VARCHAR(40) PRIMARY KEY,
  room_number VARCHAR(20) NOT NULL UNIQUE,
  room_type VARCHAR(80) NOT NULL,
  floor INT NOT NULL DEFAULT 1,
  rate DECIMAL(10,2) NOT NULL,
  max_occupancy INT NOT NULL,
  housekeeping_status VARCHAR(30) NOT NULL DEFAULT 'Clean',
  occupancy_status VARCHAR(30) NOT NULL DEFAULT 'Available',
  CONSTRAINT chk_room_rate CHECK (rate >= 0),
  CONSTRAINT chk_room_occupancy CHECK (max_occupancy > 0),
  INDEX idx_room_status (occupancy_status, housekeeping_status),
  INDEX idx_room_type (room_type)
) ENGINE=InnoDB;

-- Reservations connect users, guests, and rooms; related records use foreign keys.
CREATE TABLE IF NOT EXISTS RESERVATION (
  reservation_id VARCHAR(40) PRIMARY KEY,
  guest_id VARCHAR(40) NOT NULL,
  room_id VARCHAR(40) NOT NULL,
  user_id VARCHAR(40) NOT NULL,
  booking_type VARCHAR(30) NOT NULL,
  check_in_date DATE NOT NULL,
  check_out_date DATE NOT NULL,
  number_of_nights INT NOT NULL,
  pax INT NOT NULL,
  reservation_status VARCHAR(30) NOT NULL DEFAULT 'Pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_res_guest FOREIGN KEY (guest_id) REFERENCES GUEST(guest_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_res_room FOREIGN KEY (room_id) REFERENCES ROOM(room_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_res_user FOREIGN KEY (user_id) REFERENCES `USER`(user_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_res_dates CHECK (check_out_date > check_in_date),
  INDEX idx_res_room_dates (room_id, check_in_date, check_out_date, reservation_status),
  INDEX idx_res_status (reservation_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS FOLIO (
  folio_id VARCHAR(40) PRIMARY KEY,
  reservation_id VARCHAR(40) NOT NULL UNIQUE,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount_type VARCHAR(40),
  discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_status VARCHAR(30) NOT NULL DEFAULT 'Unpaid',
  CONSTRAINT fk_folio_res FOREIGN KEY (reservation_id) REFERENCES RESERVATION(reservation_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS FOLIO_ITEM (
  item_id INT AUTO_INCREMENT PRIMARY KEY,
  folio_id VARCHAR(40) NOT NULL,
  description VARCHAR(255) NOT NULL,
  category VARCHAR(80) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_item_folio FOREIGN KEY (folio_id) REFERENCES FOLIO(folio_id) ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX idx_folio_item (folio_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ACTIVITY_LOG (
  log_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(40) NOT NULL,
  action VARCHAR(255) NOT NULL,
  timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES `USER`(user_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_log_time (timestamp)
) ENGINE=InnoDB;

-- Learning scenarios and student submissions.
CREATE TABLE IF NOT EXISTS SCENARIO (
  scenario_id VARCHAR(40) PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  difficulty VARCHAR(30) NOT NULL,
  learning_objectives TEXT,
  target_points INT NOT NULL DEFAULT 100,
  description TEXT,
  instructions TEXT,
  created_by VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_scenario_creator FOREIGN KEY (created_by) REFERENCES `USER`(user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS STUDENT_ASSESSMENT (
  assessment_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(40) NOT NULL,
  scenario_id VARCHAR(40) NOT NULL,
  score INT NULL,
  submission_status VARCHAR(40) NOT NULL DEFAULT 'Not Started',
  submission_text TEXT NULL,
  completed_at DATETIME NULL,
  feedback TEXT,
  CONSTRAINT fk_assess_user FOREIGN KEY (user_id) REFERENCES `USER`(user_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_assess_scenario FOREIGN KEY (scenario_id) REFERENCES SCENARIO(scenario_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_assess_user (user_id),
  INDEX idx_assess_scenario (scenario_id)
) ENGINE=InnoDB;

-- Starter records make the room, guest, and scenario pages usable after setup.
INSERT IGNORE INTO ROOM (room_id,room_number,room_type,floor,rate,max_occupancy,housekeeping_status,occupancy_status) VALUES
('RM-101','101','Standard',1,1800.00,2,'Clean','Available'),
('RM-102','102','Standard',1,1800.00,2,'Clean','Available'),
('RM-201','201','Deluxe',2,2600.00,3,'Clean','Available'),
('RM-202','202','Deluxe',2,2600.00,3,'Clean','Available'),
('RM-301','301','Suite',3,4200.00,4,'Clean','Available'),
('RM-302','302','Family',3,3500.00,5,'Clean','Available');

INSERT IGNORE INTO GUEST (guest_id,full_name,email,phone,address,id_type,preferences) VALUES
('G-DEMO-001','Maria Santos','maria@example.com','09170000001','Laoag City, Ilocos Norte','Government ID','Non-smoking room'),
('G-DEMO-002','Juan Dela Cruz','juan@example.com','09170000002','Batac City, Ilocos Norte','Passport','Near elevator');

INSERT IGNORE INTO SCENARIO (scenario_id,title,difficulty,learning_objectives,target_points,description,instructions) VALUES
('SC-WALKIN','Walk-in Guest Reservation','Beginner','Register a guest and create a walk-in reservation.',100,'Practice handling a guest who arrives without an advance booking.','Register or select a guest, check room availability, and create a reservation.'),
('SC-CHECKIN','Guest Check-in Procedure','Beginner','Verify reservation and complete front-desk check-in.',100,'Practice the standard guest arrival procedure.','Locate a confirmed reservation and verify guest and room details.'),
('SC-BILLING','Guest Billing and Folio','Intermediate','Understand room charges, discounts, taxes, and totals.',100,'Practice preparing a guest folio.','Review room charges and calculate a simulated bill.'),
('SC-HOUSEKEEP','Housekeeping Status Management','Intermediate','Update room status and understand room readiness.',100,'Practice room status coordination.','Identify a room requiring cleaning and update its housekeeping status.');
