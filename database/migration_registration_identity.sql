-- Official reference data and verified registration details.
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(120) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    course_name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_course_department (department_id, course_name),
    CONSTRAINT fk_course_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;
CREATE TABLE organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NULL,
    organization_name VARCHAR(150) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_org_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;
ALTER TABLE users ADD COLUMN university_id VARCHAR(50) NULL AFTER email;
ALTER TABLE users ADD COLUMN affiliation ENUM('student','ubms_staff') NULL AFTER university_id;
ALTER TABLE users ADD COLUMN department_id INT NULL AFTER affiliation;
ALTER TABLE users ADD COLUMN course_id INT NULL AFTER department_id;
ALTER TABLE users ADD COLUMN organization_id INT NULL AFTER course_id;
ALTER TABLE users ADD COLUMN office_unit VARCHAR(150) NULL AFTER organization_id;
ALTER TABLE users ADD CONSTRAINT fk_user_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;
ALTER TABLE users ADD CONSTRAINT fk_user_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL;
ALTER TABLE users ADD CONSTRAINT fk_user_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;
INSERT INTO departments (department_name) VALUES ('CITE'), ('CBEA');
INSERT INTO courses (department_id, course_name) SELECT id, 'BS Information Technology' FROM departments WHERE department_name = 'CITE';
INSERT INTO courses (department_id, course_name) SELECT id, 'BS Business Administration' FROM departments WHERE department_name = 'CBEA';
