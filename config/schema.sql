-- RS Pharmacy Management System Database Schema
-- Create and use database
CREATE DATABASE IF NOT EXISTS rs_pharmacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rs_pharmacy;

-- Categories table
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Suppliers table
CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    payment_terms VARCHAR(50),
    rating DECIMAL(2,1) DEFAULT 5.0,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','pharmacist','staff','patient') DEFAULT 'patient',
    phone VARCHAR(20),
    department_id VARCHAR(20),
    status ENUM('active','inactive') DEFAULT 'active',
    last_login TIMESTAMP NULL,
    session_duration INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Pharmacists table
CREATE TABLE IF NOT EXISTS pharmacists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    license_number VARCHAR(50) UNIQUE NOT NULL,
    specialization VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Patients table
CREATE TABLE IF NOT EXISTS patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    date_of_birth DATE,
    sex ENUM('Male','Female','Other'),
    blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-'),
    phone VARCHAR(20),
    address TEXT,
    medical_history TEXT,
    allergies TEXT,
    emergency_contact_name VARCHAR(100),
    emergency_contact_phone VARCHAR(20),
    assigned_doctor VARCHAR(100),
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Products table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT,
    supplier_id INT,
    name VARCHAR(150) NOT NULL,
    generic_name VARCHAR(150),
    brand_type ENUM('Generic','Brand') DEFAULT 'Generic',
    product_type ENUM('OTC','Rx') DEFAULT 'OTC',
    dosage_form ENUM('Tablet','Capsule','Syrup','Injectable','Cream','Drops','Inhaler','Patch','Other') DEFAULT 'Tablet',
    price DECIMAL(10,2) NOT NULL,
    requires_prescription TINYINT(1) DEFAULT 0,
    sku VARCHAR(50) UNIQUE,
    shelf_location VARCHAR(50),
    description TEXT,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
);

-- Inventory table
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    batch_number VARCHAR(50),
    quantity INT DEFAULT 0,
    reorder_level INT DEFAULT 10,
    manufacturing_date DATE,
    expiry_date DATE,
    storage_location VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Stock movements table
CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    movement_type ENUM('in','out','adjustment') NOT NULL,
    quantity INT NOT NULL,
    reference_id INT,
    reference_type VARCHAR(50),
    notes TEXT,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Stock supply transactions
CREATE TABLE IF NOT EXISTS stock_supply (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT,
    transaction_id VARCHAR(20) UNIQUE,
    status ENUM('Ordered','In Transit','Received','Cancelled','Pending') DEFAULT 'Pending',
    frequency ENUM('weekly','monthly','quarterly','one-time') DEFAULT 'monthly',
    payment_method ENUM('bank','cash','credit','check') DEFAULT 'cash',
    agent_name VARCHAR(100),
    order_date DATE,
    delivery_date DATE,
    invoice_number VARCHAR(50),
    total_cost DECIMAL(10,2) DEFAULT 0,
    estimated_profit DECIMAL(10,2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
);

-- Prescriptions table
CREATE TABLE IF NOT EXISTS prescriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rx_number VARCHAR(20) UNIQUE,
    patient_id INT,
    pharmacist_id INT,
    doctor_name VARCHAR(100),
    doctor_license VARCHAR(50),
    issue_date DATE,
    expiry_date DATE,
    status ENUM('Pending','Issued','Fulfilled','Cancelled') DEFAULT 'Pending',
    source ENUM('Online Order','Reservation','Walk-In') DEFAULT 'Walk-In',
    prescription_type ENUM('regular','yellow_pad','controlled') DEFAULT 'regular',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL,
    FOREIGN KEY (pharmacist_id) REFERENCES pharmacists(id) ON DELETE SET NULL
);

-- Prescription items table
CREATE TABLE IF NOT EXISTS prescription_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT NOT NULL,
    product_id INT,
    medication_name VARCHAR(150),
    dosage VARCHAR(100),
    frequency VARCHAR(100),
    duration VARCHAR(100),
    quantity INT DEFAULT 1,
    instructions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

-- Orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) UNIQUE,
    patient_id INT,
    pharmacist_id INT,
    prescription_id INT,
    order_type ENUM('walk-in','online','reservation') DEFAULT 'walk-in',
    status ENUM('Pending','Processing','Completed','Cancelled') DEFAULT 'Pending',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL,
    FOREIGN KEY (pharmacist_id) REFERENCES pharmacists(id) ON DELETE SET NULL,
    FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Order items table
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT,
    product_name VARCHAR(150),
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    discount DECIMAL(5,2) DEFAULT 0,
    subtotal DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

-- Transactions/Payments table
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(20) UNIQUE,
    order_id INT,
    cashier_id INT,
    subtotal DECIMAL(10,2) NOT NULL,
    discount DECIMAL(10,2) DEFAULT 0,
    tax DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('Cash','Card','GCash') DEFAULT 'Cash',
    payment_status ENUM('Completed','Pending','Refunded','Void') DEFAULT 'Completed',
    channel ENUM('POS','Online') DEFAULT 'POS',
    receipt_number VARCHAR(30),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Appointments table
CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT,
    pharmacist_id INT,
    doctor_name VARCHAR(100),
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    duration_minutes INT DEFAULT 30,
    purpose VARCHAR(200),
    service_type VARCHAR(100),
    status ENUM('Pending','Confirmed','Completed','Cancelled') DEFAULT 'Pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL,
    FOREIGN KEY (pharmacist_id) REFERENCES pharmacists(id) ON DELETE SET NULL
);

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    type ENUM('info','warning','alert','success') DEFAULT 'info',
    title VARCHAR(200),
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Audit logs table
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    audit_id VARCHAR(20) UNIQUE,
    user_id INT,
    user_name VARCHAR(150),
    user_role VARCHAR(50),
    action VARCHAR(200) NOT NULL,
    action_type ENUM('CREATE','READ','UPDATE','DELETE','LOGIN','LOGOUT','STOCK_IN','STOCK_OUT','SALE','WARNING','CRITICAL') DEFAULT 'READ',
    severity ENUM('INFO','WARNING','CRITICAL') DEFAULT 'INFO',
    affected_table VARCHAR(100),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ==========================================
-- SEED DATA
-- ==========================================

-- Categories
INSERT INTO categories (name, description, status) VALUES
('Medicines', 'Prescription and OTC medicines', 'active'),
('Vitamins & Supplements', 'Health supplements and vitamins', 'active'),
('Personal Care', 'Personal hygiene and care products', 'active'),
('Medical Supplies', 'Medical devices and supplies', 'active'),
('First Aid', 'First aid and wound care', 'active');

-- Suppliers
INSERT INTO suppliers (name, contact_person, phone, email, address, payment_terms, rating) VALUES
('PharmaCo Distributors', 'Juan Santos', '09171234567', 'juan@pharmaco.ph', 'Manila, Philippines', 'Net 30', 4.8),
('MedSupply Inc.', 'Maria Reyes', '09281234567', 'maria@medsupply.ph', 'Quezon City, Philippines', 'Net 15', 4.5),
('HealthLine Trading', 'Pedro Cruz', '09351234567', 'pedro@healthline.ph', 'Makati, Philippines', 'Net 45', 4.2);

-- Users (passwords are hashed 'password123')
INSERT INTO users (first_name, last_name, email, password, role, phone, department_id, status) VALUES
('Admin', 'User', 'admin@rspharmacy.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', '09171111111', 'ADM-001', 'active'),
('Robyn', 'Fernandez', 'robyn@rspharmacy.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'pharmacist', '09172222222', 'PHA-001', 'active'),
('Alice', 'Tan', 'alice@rspharmacy.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff', '09173333333', 'STA-001', 'active'),
('Juan', 'Dela Cruz', 'juan@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'patient', '09174444444', NULL, 'active'),
('Maria', 'Santos', 'maria@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'patient', '09175555555', NULL, 'active');

-- Pharmacists
INSERT INTO pharmacists (user_id, license_number, specialization) VALUES
(2, 'PH-2024-0012', 'Clinical Pharmacy');

-- Patients
INSERT INTO patients (user_id, first_name, last_name, date_of_birth, sex, blood_type, phone, allergies, medical_history, assigned_doctor, status) VALUES
(4, 'Juan', 'Dela Cruz', '1990-05-15', 'Male', 'O+', '09174444444', 'Penicillin', 'Hypertension', 'Dr. Maria Cruz', 'active'),
(5, 'Maria', 'Santos', '1985-08-22', 'Female', 'A+', '09175555555', 'None', 'Diabetes Type 2', 'Dr. Jose Ramos', 'active'),
(NULL, 'Pedro', 'Reyes', '1978-12-10', 'Male', 'B+', '09176666666', 'Sulfa drugs', 'Routine checkup', 'Dr. Ana Lopez', 'active'),
(NULL, 'Ana', 'Lim', '1995-03-25', 'Female', 'AB+', '09177777777', 'Aspirin', 'High blood sugar', 'Dr. Maria Cruz', 'active');

-- Products
INSERT INTO products (category_id, supplier_id, name, generic_name, brand_type, product_type, dosage_form, price, requires_prescription, sku, shelf_location) VALUES
(1, 1, 'Paracetamol 500mg', 'Paracetamol', 'Generic', 'OTC', 'Tablet', 12.00, 0, 'SKU-0001', 'A1'),
(2, 1, 'Vitamin C 500mg', 'Ascorbic Acid', 'Generic', 'OTC', 'Tablet', 8.00, 0, 'SKU-0002', 'B2'),
(1, 2, 'Amoxicillin 500mg', 'Amoxicillin', 'Generic', 'Rx', 'Capsule', 25.00, 1, 'SKU-0003', 'C1'),
(1, 2, 'Omeprazole 20mg', 'Omeprazole', 'Generic', 'Rx', 'Capsule', 18.00, 1, 'SKU-0004', 'C2'),
(1, 1, 'Metformin 500mg', 'Metformin HCl', 'Generic', 'Rx', 'Tablet', 15.00, 1, 'SKU-0005', 'C3'),
(1, 3, 'Cough Syrup 60ml', 'Dextromethorphan', 'Generic', 'OTC', 'Syrup', 45.00, 0, 'SKU-0006', 'A3'),
(1, 2, 'Insulin 10ml', 'Insulin Glargine', 'Brand', 'Rx', 'Injectable', 350.00, 1, 'SKU-0007', 'D1'),
(4, 3, 'Digital Thermometer', 'N/A', 'Brand', 'OTC', 'Other', 250.00, 0, 'SKU-0008', 'E1'),
(5, 1, 'Bandage Roll 5cm', 'N/A', 'Generic', 'OTC', 'Other', 35.00, 0, 'SKU-0009', 'A5'),
(3, 3, 'Alcohol 70% 500ml', 'Isopropyl Alcohol', 'Generic', 'OTC', 'Other', 55.00, 0, 'SKU-0010', 'A4');

-- Inventory
INSERT INTO inventory (product_id, batch_number, quantity, reorder_level, manufacturing_date, expiry_date, storage_location) VALUES
(1, 'BATCH-2024-001', 150, 20, '2024-01-01', '2026-12-31', 'Shelf A1'),
(2, 'BATCH-2024-002', 200, 30, '2024-02-01', '2026-11-30', 'Shelf B2'),
(3, 'BATCH-2024-003', 5, 15, '2024-03-01', '2025-09-30', 'Shelf C1'),
(4, 'BATCH-2024-004', 80, 10, '2024-01-15', '2026-08-31', 'Shelf C2'),
(5, 'BATCH-2024-005', 7, 10, '2024-02-15', '2026-03-31', 'Shelf C3'),
(6, 'BATCH-2024-006', 40, 10, '2024-03-01', '2025-12-31', 'Shelf A3'),
(7, 'BATCH-2024-007', 30, 5, '2024-01-01', '2026-06-30', 'Cold D1'),
(8, 'BATCH-2024-008', 15, 5, '2024-01-01', '2027-01-01', 'Shelf E1'),
(9, 'BATCH-2024-009', 60, 15, '2024-02-01', '2026-12-31', 'Shelf A5'),
(10, 'BATCH-2024-010', 25, 10, '2024-03-01', '2025-10-31', 'Shelf A4');

-- Prescriptions
INSERT INTO prescriptions (rx_number, patient_id, pharmacist_id, doctor_name, issue_date, expiry_date, status, source) VALUES
('RX-101', 1, 1, 'Dr. Maria Cruz', '2026-04-01', '2026-05-01', 'Issued', 'Walk-In'),
('RX-102', 2, 1, 'Dr. Jose Ramos', '2026-04-15', '2026-05-15', 'Fulfilled', 'Online Order'),
('RX-103', 3, 1, 'Dr. Ana Lopez', '2026-05-01', '2026-06-01', 'Pending', 'Walk-In'),
('RX-045', 1, 1, 'Dr. Santos', '2026-04-01', '2026-05-31', 'Issued', 'Walk-In'),
('RX-046', 4, 1, 'Dr. Cruz', '2026-05-01', '2026-06-30', 'Pending', 'Online Order');

-- Orders
INSERT INTO orders (order_number, patient_id, order_type, status, created_by) VALUES
('ORD-1001', 1, 'walk-in', 'Pending', 3),
('ORD-1002', 2, 'online', 'Completed', 2),
('ORD-1003', 3, 'walk-in', 'Cancelled', 3);

-- Order items
INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, discount, subtotal) VALUES
(1, 1, 'Paracetamol 500mg', 2, 12.00, 0, 24.00),
(2, 2, 'Vitamin C 500mg', 10, 8.00, 0, 80.00),
(3, 3, 'Amoxicillin 500mg', 1, 25.00, 0, 25.00);

-- Transactions
INSERT INTO transactions (transaction_id, order_id, cashier_id, subtotal, discount, tax, total_amount, payment_method, payment_status, channel, receipt_number) VALUES
('TXN-001', 1, 3, 100.00, 0, 0, 100.00, 'Cash', 'Completed', 'POS', 'R-1001'),
('TXN-002', 2, 2, 120.00, 0, 0, 120.00, 'GCash', 'Completed', 'Online', 'R-1002'),
('TXN-003', 3, 3, 75.00, 0, 0, 75.00, 'Card', 'Void', 'POS', 'R-1003'),
('TXN-004', NULL, 2, 295.00, 0, 0, 295.00, 'Cash', 'Completed', 'POS', 'R-1004'),
('TXN-005', NULL, 3, 450.00, 0, 0, 450.00, 'GCash', 'Completed', 'Online', 'R-1005'),
('TXN-006', NULL, 2, 555.00, 0, 0, 555.00, 'Card', 'Completed', 'POS', 'R-1006');

-- Appointments
INSERT INTO appointments (patient_id, pharmacist_id, doctor_name, appointment_date, appointment_time, duration_minutes, purpose, service_type, status) VALUES
(1, 1, 'Dr. Maria Cruz', '2026-05-03', '09:00:00', 30, 'Routine checkup', 'Doctor Consultation', 'Confirmed'),
(2, 1, 'Dr. Jose Ramos', '2026-05-06', '10:00:00', 30, 'Medication review', 'Prescription Management', 'Confirmed'),
(3, NULL, 'Dr. Ana Lopez', '2026-05-05', '14:00:00', 30, 'Follow-up', 'Doctor Consultation', 'Pending'),
(4, NULL, 'Dr. Maria Cruz', '2026-04-18', '10:00:00', 30, 'Medication consultation', 'Doctor Consultation', 'Completed');

-- Stock supply
INSERT INTO stock_supply (supplier_id, transaction_id, status, frequency, payment_method, agent_name, order_date, delivery_date, invoice_number, total_cost, estimated_profit, notes) VALUES
(1, 'TX-1001', 'Received', 'monthly', 'bank', 'Juan Santos', '2026-04-01', '2026-04-05', 'INV-2024-001', 1500.00, 800.00, 'Initial stock received'),
(2, 'TX-1002', 'Ordered', 'weekly', 'cash', 'Maria Reyes', '2026-05-01', '2026-05-07', 'INV-2024-002', 980.00, 650.00, 'Regular weekly order'),
(3, 'TX-1003', 'In Transit', 'monthly', 'credit', 'Pedro Cruz', '2026-05-05', '2026-05-12', 'INV-2024-003', 1200.00, 450.00, 'Cold chain delivery - refrigerated'),
(1, 'TX-1004', 'Cancelled', 'one-time', 'bank', 'Juan Santos', '2026-05-10', NULL, 'INV-2024-004', 400.00, 200.00, 'Cancelled due to stock availability');

-- Audit logs
INSERT INTO audit_logs (audit_id, user_id, user_name, user_role, action, action_type, severity, affected_table, notes, ip_address) VALUES
('A-1001', 1, 'Admin User', 'admin', 'Initial stock received - Paracetamol batch', 'STOCK_IN', 'INFO', 'inventory', 'Initial stock received', '192.168.1.1'),
('A-1002', 2, 'Robyn Fernandez', 'pharmacist', 'Product price updated - Vitamin C', 'UPDATE', 'INFO', 'products', NULL, '192.168.1.2'),
('A-1003', 3, 'Alice Tan', 'staff', 'Customer requested but out of stock - Amoxicillin', 'WARNING', 'WARNING', 'inventory', 'Customer requested but out of stock', '192.168.1.3'),
('A-1004', 1, 'Admin User', 'admin', 'User account created - alice@rspharmacy.com', 'CREATE', 'INFO', 'users', NULL, '192.168.1.1'),
('A-1005', 2, 'Robyn Fernandez', 'pharmacist', 'Prescription fulfilled - RX-102', 'UPDATE', 'INFO', 'prescriptions', NULL, '192.168.1.2'),
('A-1006', 1, 'Admin User', 'admin', 'System login', 'LOGIN', 'INFO', 'users', NULL, '192.168.1.1'),
('A-1007', 3, 'Alice Tan', 'staff', 'Sale processed - TXN-001', 'SALE', 'INFO', 'transactions', NULL, '192.168.1.3'),
('A-1008', 2, 'Robyn Fernandez', 'pharmacist', 'Low stock alert - Amoxicillin 500mg', 'CRITICAL', 'CRITICAL', 'inventory', 'Stock fell below reorder level', '192.168.1.2');

-- Notifications
INSERT INTO notifications (user_id, type, title, message, is_read) VALUES
(1, 'alert', 'Low Stock Alert', 'Amoxicillin 500mg is running low (5 units left).', 0),
(1, 'warning', 'Expiry Alert', 'Metformin 500mg batch expires in 30 days.', 0),
(2, 'info', 'New Prescription', 'New prescription RX-103 awaiting fulfillment.', 0),
(3, 'info', 'New Order', 'Walk-in order ORD-1001 created and pending.', 1),
(1, 'alert', 'Low Stock Alert', 'Cough Syrup 60ml stock is low.', 0);
